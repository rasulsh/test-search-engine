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
     * One row of the page applied on its own (a fetch from the page's script). It is the full form's validation
     * and save with a single key posted: SettingsValidator on the effective config with just that key changed
     * (cross-key conflicts included), the same optimistic version check, atomic write, backup and audit row
     * (key name only). Validation outcomes answer 200 (`ok` says whether anything was applied); a stale file
     * is 409, a form that is not ours 400, a busy lock 503, a write failure 500. Only the key's own value,
     * its editable-key warnings and Persian sentences leave this method, never any other key or secret.
     *
     * @param array<string, mixed> $post key, value, version and the optional reset / empty / confirm = '1' flags
     * @return array{http: int, body: array<string, mixed>}
     */
    public function applyOne(array $post): array
    {
        $key = $post['key'] ?? null;
        $value = $post['value'] ?? '';
        $version = $post['version'] ?? null;
        if (
            !is_string($key) || !isset(SettingRules::table()[$key]) || !is_string($value) || !is_string($version)
            || strlen($value) > self::MAX_VALUE_BYTES
        ) {
            return $this->reply(400, 'rejected');
        }
        $flag = static fn (string $name): bool => ($post[$name] ?? '') === '1';
        $form = [
            'version' => $version, 'confirm' => $flag('confirm') ? '1' : '0',
            'v' => [$key => $value],
            'r' => $flag('reset') ? [$key => '1'] : [],
            'e' => $flag('empty') ? [$key => '1'] : [],
        ];

        if (!hash_equals($this->store->read()['version'], $version)) {
            return $this->reply(409, 'stale');
        }
        $dry = $this->submit($form, false);
        if ($dry['status'] === 'invalid') {
            return $this->reply(200, 'invalid', $key, $dry);
        }
        if ($dry['warnings'] !== [] && !$flag('confirm')) {
            return $this->reply(200, 'confirm', $key, $dry);
        }
        if ($dry['changes'] === []) {
            return $this->reply(200, 'no_change', $key, $dry, ['version' => $version]);
        }
        $saved = $this->submit($form, true);
        $http = ['stale' => 409, 'busy' => 503, 'io' => 500][$saved['status']] ?? 200;
        if ($saved['status'] !== 'saved') {
            return $this->reply($http, $saved['status'] === 'confirm' ? 'confirm' : $saved['status']);
        }

        return $this->reply(200, 'saved', $key, $saved, ['version' => $saved['version'], 'flush' => $saved['flush']]);
    }

    /**
     * @param array<string, mixed> $result the validator's lists (errors, warnings), when there are any
     * @param array<string, mixed> $extra
     * @return array{http: int, body: array<string, mixed>}
     */
    private function reply(int $http, string $status, string $key = '', array $result = [], array $extra = []): array
    {
        $t = AdminStrings::fa()['settings'];
        $body = array_replace([
            'ok' => in_array($status, ['saved', 'no_change'], true),
            'status' => $status,
            'message' => $t['apply']['messages'][$status] ?? '',
            'errors' => [], 'warnings' => [], 'needs_confirm' => $status === 'confirm',
            'flush' => false,
            'reload' => $status === 'stale',
        ], $extra);
        $describe = static function (array $items, string $codes) use ($t): array {
            $out = [];
            foreach ($items as $item) {
                $itemKey = (string) ($item['key'] ?? '');
                if (SettingRules::for($itemKey) === null) {
                    continue;
                }
                $out[] = [
                    'key' => $itemKey, 'code' => (string) $item['code'], 'args' => array_map(
                        'strval',
                        (array) ($item['args'] ?? [])
                    ),
                    'text' => AdminSettingsViews::describe($t[$codes], $item),
                ];
            }

            return $out;
        };
        $body['errors'] = $describe((array) ($result['errors'] ?? []), 'error_codes');
        $body['warnings'] = $describe((array) ($result['warnings'] ?? []), 'warning_codes');
        if (in_array($status, ['saved', 'no_change'], true)) {
            $body += $this->snapshot($key);
        }

        return ['http' => $http, 'body' => $body];
    }

    /**
     * The key's state after a save, in the page's words: value, source (default / config.php / panel), code default.
     *
     * @return array<string, string>
     */
    private function snapshot(string $key): array
    {
        $type = Config::schema()[$key];
        $stored = $this->store->read()['values'];
        $given = Config::flatten($this->fileOverrides);
        $source = array_key_exists($key, $stored)
            ? 'panel'
            : (array_key_exists($key, $given) ? 'config.php' : 'default');
        $t = AdminStrings::fa()['settings']['source'];

        return [
            'key' => $key,
            'value' => SettingsValidator::text($type, $stored[$key] ?? $this->inherited()[$key]),
            'source' => $source,
            'source_label' => $t[['config.php' => 'file'][$source] ?? $source],
            'default' => SettingsValidator::text($type, Config::flatten(Config::defaults())[$key]),
        ];
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
