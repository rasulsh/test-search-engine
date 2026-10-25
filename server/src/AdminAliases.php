<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * The alias manager's actions (M41): save a group, delete a group, save the whole file, restore a
 * backup. Each goes through {@see AliasValidator} and {@see AliasStore} (atomic, versioned) and a
 * successful change leaves one audit row (never the file's content, only counts and the changed
 * terms). Nothing here touches the bundle, the reload lock or the result cache: the search reads
 * the file on its next request, and the result-cache key carries the file's version.
 */
final class AdminAliases
{
    /**
     * Terms listed in an audit row (each cut short); the rest is a count. admin_audit.detail holds 512
     * characters and cuts there, which would leave broken JSON, so a row is built to stay far below it.
     */
    private const AUDIT_TERMS = 4;
    private const AUDIT_TERM_CHARS = 24;

    private AliasStore $store;

    /**
     * @param array<string, mixed> $config the loaded server config
     */
    public function __construct(
        private readonly array $config,
        private readonly PDO $pdo,
        private readonly Audit $audit,
        private readonly string $ip,
        ?AliasStore $store = null
    ) {
        $this->store = $store ?? AliasStore::fromConfig($config);
    }

    public function store(): AliasStore
    {
        return $this->store;
    }

    /**
     * The stored file for an editor. The first visit creates it from the live bundle's aliases.json
     * (or an empty list), so editing starts from what the search is serving.
     *
     * @return array{groups: list<list<string>>, version: string, exists: bool, valid: bool, raw: string, seeded: bool}
     */
    public function state(): array
    {
        $seeded = false;
        try {
            $seeded = $this->store->seedIfMissing();
        } catch (AliasStoreException) {
            // an unwritable storage directory: the page says so; reading still works from the bundle file
        }

        return $this->store->read() + ['seeded' => $seeded];
    }

    /** Products one term matches by itself, capped (see {@see AliasCounts}). */
    public function counts(): AliasCounts
    {
        return AliasCounts::fromConfig($this->pdo, $this->config);
    }

    /**
     * @param 'group'|'delete'|'raw' $mode
     * @param array<string, mixed> $input group: index (''= new), terms; delete: index; raw: json;
     *        always: version (the file version the form was loaded with), confirm ('1' = warnings accepted)
     * @return array{
     *     status: string,
     *     errors?: list<array<string, mixed>>,
     *     warnings?: list<array<string, mixed>>,
     *     version?: string
     * } status: saved | invalid | confirm | stale | busy | io | bad_group
     */
    public function save(string $mode, array $input): array
    {
        $state = $this->store->read();
        $current = $state['groups'];
        $version = (string) ($input['version'] ?? '');

        if ($mode === 'raw') {
            $decoded = AliasValidator::decode((string) ($input['json'] ?? ''));
            if ($decoded['groups'] === null) {
                return ['status' => 'invalid', 'errors' => $decoded['errors']];
            }
            $proposal = $decoded['groups'];
        } else {
            $index = ($input['index'] ?? '') === '' ? null : (int) $input['index'];
            if ($index !== null && !isset($current[$index])) {
                return ['status' => 'bad_group'];
            }
            $proposal = $current;
            if ($mode === 'delete') {
                if ($index === null) {
                    return ['status' => 'bad_group'];
                }
                array_splice($proposal, $index, 1);
            } else {
                $group = self::splitTerms((string) ($input['terms'] ?? ''));
                $index === null ? $proposal[] = $group : $proposal[$index] = $group;
            }
        }

        // A stored group that was already broken must not make a delete of another group impossible
        // to confirm, but it does block saving until fixed: the validator checks the whole file.
        $result = $this->validator()->validate($proposal, $current);
        if ($result['errors'] !== []) {
            return ['status' => 'invalid', 'errors' => $result['errors']];
        }
        if ($result['warnings'] !== [] && ($input['confirm'] ?? '') !== '1') {
            return ['status' => 'confirm', 'warnings' => $result['warnings']];
        }

        try {
            $saved = $this->store->save($result['groups'], $version);
        } catch (AliasStoreException $e) {
            return ['status' => $e->reason() === 'unknown_backup' ? 'io' : $e->reason()];
        }
        $this->audit->write($this->ip, Audit::ALIAS_SAVE, (string) json_encode(
            self::summary($current, $result['groups'], $mode),
            JSON_UNESCAPED_UNICODE
        ));

        return ['status' => 'saved', 'version' => $saved];
    }

    /**
     * @return array{status: string, version?: string}
     */
    public function restore(string $backup, string $version): array
    {
        $before = $this->store->read()['groups'];
        try {
            $new = $this->store->restore($backup, $version);
        } catch (AliasStoreException $e) {
            return ['status' => $e->reason()];
        }
        $after = $this->store->read()['groups'];
        $this->audit->write($this->ip, Audit::ALIAS_RESTORE, (string) json_encode(
            ['backup' => $backup, 'groups' => count($after)] + self::summary($before, $after, 'restore'),
            JSON_UNESCAPED_UNICODE
        ));

        return ['status' => 'restored', 'version' => $new];
    }

    private function validator(): AliasValidator
    {
        $counts = null;
        try {
            $counts = $this->counts();
        } catch (\Throwable) {
            // no database for the keyword tier: the other checks still run
        }

        return AliasValidator::fromConfig($this->config, $counts === null ? null : $counts->count(...));
    }

    /**
     * Terms from the editor's text: one per line; the Persian comma and "," also separate.
     *
     * @return list<string>
     */
    public static function splitTerms(string $text): array
    {
        $terms = preg_split('/[\r\n]+|[\x{060C},]/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $terms), static fn (string $term): bool => $term !== ''));
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private static function brief(array $terms): array
    {
        return array_map(
            static fn (string $term): string => mb_substr($term, 0, self::AUDIT_TERM_CHARS),
            array_slice($terms, 0, self::AUDIT_TERMS)
        );
    }

    /**
     * @param list<list<string>> $before
     * @param list<list<string>> $after
     * @return array<string, mixed> the facts one audit row keeps
     */
    private static function summary(array $before, array $after, string $mode): array
    {
        $phrases = static fn (array $group): array => array_map([AliasValidator::class, 'phrase'], $group);
        $terms = static fn (array $groups): array => array_values(array_unique(array_merge(
            ...array_map($phrases, $groups ?: [[]])
        )));
        $old = $terms($before);
        $new = $terms($after);
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));

        return [
            'mode' => $mode,
            'groups' => count($after),
            'terms' => count($new),
            'added' => self::brief($added),
            'removed' => self::brief($removed),
            'added_total' => count($added),
            'removed_total' => count($removed),
        ];
    }
}
