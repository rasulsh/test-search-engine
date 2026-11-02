<?php

declare(strict_types=1);

namespace App;

/**
 * The settings page's actions (M46): check (a dry run), save and restore. Each goes through
 * {@see SettingsValidator} and {@see SettingsStore} (atomic, versioned, backed up); a saved change leaves one
 * audit row holding only the names of the changed keys. Nothing here touches config.php, a credential, the
 * bundle or the result cache: the next request reads settings.json, and the page tells the owner when the
 * result cache is worth flushing.
 */
final class AdminSettings
{
    /** Fields a form may send: the editable keys are ~80; a request with far more is not our form. */
    private const MAX_FIELDS = 300;
    private const MAX_VALUE_BYTES = 8192;
    /** admin_audit.detail holds 512 characters and cuts there, which would leave broken JSON. */
    private const AUDIT_BUDGET = 400;

    private SettingsStore $store;

    /**
     * @param array<string, mixed> $config the loaded server config (all layers)
     * @param array<string, mixed> $fileOverrides config.php's raw overrides
     */
    public function __construct(
        private readonly array $config,
        private readonly array $fileOverrides,
        private readonly Audit $audit,
        private readonly string $ip,
        ?SettingsStore $store = null
    ) {
        $this->store = $store ?? SettingsStore::fromConfig($config);
    }

    public function store(): SettingsStore
    {
        return $this->store;
    }

    /**
     * What the page shows besides the form.
     *
     * @return array{stored: array<string, mixed>, inherited: array<string, mixed>, effective: array<string, mixed>,
     *         defaults: array<string, mixed>, file: array<string, mixed>}
     *         flat 'dotted.key' => typed value maps: inherited (code + config.php), effective (all layers),
     *         defaults (code), file (the keys config.php sets)
     */
    public function state(): array
    {
        return [
            'stored' => $this->store->read(),
            'inherited' => $this->inherited(),
            'effective' => Config::flatten($this->config),
            'defaults' => Config::flatten(Config::defaults()),
            'file' => Config::flatten($this->fileOverrides),
        ];
    }

    /**
     * A dry run or a save of a posted form.
     *
     * @param bool $write false = check only
     * @param array<string, mixed> $post the request's POST array
     * @return array<string, mixed> status: rejected | invalid | confirm | checked | stale | busy | io | saved, plus
     *         the validator's errors / warnings / info / changes / form; saved adds `flush` (see affectsResults)
     */
    public function submit(array $post, bool $write): array
    {
        $parsed = self::parsePost($post);
        if ($parsed === null) {
            return ['status' => 'rejected'];
        }
        $stored = $this->store->read();
        $result = SettingsValidator::validate(
            $parsed['v'],
            $parsed['r'],
            $parsed['e'],
            $this->inherited(),
            $stored['values'],
            $this->fileOverrides
        );
        // The version the form was loaded with, not the file's current one: a changed file is "stale".
        $version = (string) ($post['version'] ?? '');
        $result['version'] = $version;

        if ($result['errors'] !== []) {
            return ['status' => 'invalid'] + $result;
        }
        if (!$write) {
            return ['status' => 'checked'] + $result;
        }
        if ($result['warnings'] !== [] && ($post['confirm'] ?? '') !== '1') {
            return ['status' => 'confirm'] + $result;
        }
        try {
            $new = $this->store->save($result['values'], $version);
        } catch (SettingsStoreException $e) {
            return ['status' => $e->reason() === 'unknown_backup' ? 'io' : $e->reason()] + $result;
        }
        $keys = array_column($result['changes'], 'key');
        $this->audit->write($this->ip, Audit::SETTINGS_SAVE, self::auditDetail($keys, [
            'reset' => count(array_filter($result['changes'], static fn (array $c): bool => $c['reset'])),
        ]));

        return [
            'status' => 'saved',
            'version' => $new,
            'flush' => array_filter($keys, [SettingRules::class, 'affectsResults']) !== [],
        ] + $result;
    }

    /**
     * @return array{status: string, version?: string, flush?: bool}
     */
    public function restore(string $backup, string $version): array
    {
        $before = $this->store->read()['values'];
        try {
            $new = $this->store->restore($backup, $version);
        } catch (SettingsStoreException $e) {
            return ['status' => $e->reason()];
        }
        $keys = self::differing($before, $this->store->read()['values']);
        $this->audit->write($this->ip, Audit::SETTINGS_RESTORE, self::auditDetail($keys, ['backup' => $backup]));

        return [
            'status' => 'restored',
            'version' => $new,
            'flush' => array_filter($keys, [SettingRules::class, 'affectsResults']) !== [],
        ];
    }

    /**
     * Keys whose value differs between two panel value maps, or that only one of them holds.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return list<string>
     */
    public static function differing(array $a, array $b): array
    {
        $keys = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if (!array_key_exists($key, $a) || !array_key_exists($key, $b) || $a[$key] !== $b[$key]) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * The posted form, or null when it is not ours (too many fields, a key that is not an editable one, a value
     * that is not text, a huge value). Nothing about a rejected field is echoed: it may name a credential.
     *
     * @param array<string, mixed> $post
     * @return array{v: array<string, string>, r: array<string, bool>, e: array<string, bool>}|null
     */
    public static function parsePost(array $post): ?array
    {
        $rules = SettingRules::table();
        $out = ['v' => [], 'r' => [], 'e' => []];
        $count = 0;
        foreach (['v', 'r', 'e'] as $group) {
            $fields = $post[$group] ?? [];
            if (!is_array($fields)) {
                return null;
            }
            $count += count($fields);
            if ($count > self::MAX_FIELDS) {
                return null;
            }
            foreach ($fields as $key => $value) {
                $key = (string) $key;
                if (!isset($rules[$key]) || !is_string($value) || strlen($value) > self::MAX_VALUE_BYTES) {
                    return null;
                }
                $out[$group][$key] = $group === 'v' ? $value : true;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> every schema key's typed value from the code defaults + config.php */
    private function inherited(): array
    {
        return Config::flatten(Config::merge($this->fileOverrides));
    }

    /**
     * The audit row's detail: the changed key names (as many as fit) and their count, never a value.
     *
     * @param list<string> $keys
     * @param array<string, mixed> $extra
     */
    private static function auditDetail(array $keys, array $extra): string
    {
        $shown = [];
        $length = strlen((string) json_encode($extra));
        foreach ($keys as $key) {
            $length += strlen($key) + 4;
            if ($length > self::AUDIT_BUDGET) {
                break;
            }
            $shown[] = $key;
        }

        return (string) json_encode(
            ['keys' => $shown, 'total' => count($keys)] + $extra,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
