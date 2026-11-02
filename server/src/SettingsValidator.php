<?php

declare(strict_types=1);

namespace App;

/**
 * Checks what the settings page posts (M46). Pure: no file, no database, no output of its own; the page
 * words every code through {@see AdminStrings}.
 *
 * Input is the text of each field the form sent. A blank field (or its "reset" box) means "back to the
 * inherited value" (config.php's or the code default), never an error. Everything is evaluated against the
 * EFFECTIVE config the proposal would produce (defaults + config.php + the proposed panel values), so a
 * conflict with a key config.php sets, or with a credential this page cannot edit, is seen.
 *
 * Errors block saving. Warnings need the owner's explicit confirmation. Info is shown and changes nothing.
 * Messages carry key names and numbers of keys that are editable here; credentials are never named: the
 * doctor lines about them are translated into a sentence without the key.
 */
final class SettingsValidator
{
    /**
     * @param array<string, string> $posted 'dotted.path' => the field text; editable keys only (the caller
     *        rejects the rest)
     * @param array<string, bool> $reset keys whose reset box was ticked
     * @param array<string, bool> $empty string keys whose "empty value" box was ticked
     * @param array<string, mixed> $inherited every schema key => its typed value from the code defaults + config.php
     * @param array<string, mixed> $current the panel values stored now ('dotted.path' => typed)
     * @param array<string, mixed> $fileOverrides config.php's raw (nested) overrides
     * @return array{
     *     values: array<string, mixed>,
     *     errors: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>,
     *     info: list<array<string, mixed>>,
     *     changes: list<array{key: string, from: string, to: string, reset: bool}>,
     *     form: array<string, string>
     * } `values`: the panel values to store (only keys that differ from `inherited`); `form`: every editable key's
     *   text for the form (the normalized value when valid, the typed text when not)
     */
    public static function validate(
        array $posted,
        array $reset,
        array $empty,
        array $inherited,
        array $current,
        array $fileOverrides
    ): array {
        $schema = Config::schema();
        $errors = [];
        $warnings = [];
        $info = [];
        $proposal = $current;
        $form = [];

        foreach (SettingRules::table() as $path => $rule) {
            $type = $schema[$path];
            $form[$path] = self::text($type, $current[$path] ?? $inherited[$path]);
            if (isset($reset[$path])) {
                unset($proposal[$path]);
                $form[$path] = self::text($type, $inherited[$path]);
                continue;
            }
            if (!array_key_exists($path, $posted) && !isset($empty[$path])) {
                continue;
            }
            $raw = $posted[$path] ?? '';
            $form[$path] = $raw;
            if (isset($empty[$path]) && $type === 'string') {
                // An explicit empty value (not "inherit"): the only way to blank a key config.php fills.
                $form[$path] = '';
                $parsed = ($rule['required'] ?? false)
                    ? ['value' => null, 'errors' => [['code' => 'required', 'args' => []]], 'warnings' => []]
                    : ['value' => '', 'errors' => [], 'warnings' => []];
            } elseif (trim($raw) === '') {
                unset($proposal[$path]);
                $form[$path] = self::text($type, $inherited[$path]);
                continue;
            } else {
                $parsed = self::parse($path, $type, $rule, $raw);
            }
            foreach ($parsed['errors'] as $error) {
                $errors[] = ['key' => $path] + $error;
            }
            foreach ($parsed['warnings'] as $warning) {
                $warnings[] = ['key' => $path] + $warning;
            }
            if ($parsed['errors'] !== []) {
                continue;
            }
            $form[$path] = self::text($type, $parsed['value']);
            if (self::same($parsed['value'], $inherited[$path])) {
                unset($proposal[$path]);
            } else {
                $proposal[$path] = $parsed['value'];
            }
        }

        $effective = array_replace($inherited, $proposal);
        $before = array_replace($inherited, $current);
        $changes = self::changes($schema, $before, $effective, $inherited);
        $changed = array_column($changes, 'key');

        foreach (self::conflicts($effective, $changed) as $conflict) {
            $warnings[] = $conflict;
        }
        foreach (self::doctorWarnings($fileOverrides, $current, $proposal) as $warning) {
            $warnings[] = $warning;
        }
        $given = Config::flatten($fileOverrides);
        foreach (array_keys($proposal) as $path) {
            if (array_key_exists($path, $given)) {
                $info[] = ['key' => $path, 'code' => 'shadows_file'];
            }
        }

        ksort($proposal);

        return [
            'values' => $proposal,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'changes' => $changes,
            'form' => $form,
        ];
    }

    /**
     * One field's text to a typed value, with the problems of that field alone.
     *
     * @param array<string, mixed> $rule
     * @return array{value: mixed, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public static function parse(string $path, string $type, array $rule, string $raw): array
    {
        $out = ['value' => null, 'errors' => [], 'warnings' => []];
        $fail = static function (string $code, array $args = []) use (&$out): array {
            $out['errors'][] = ['code' => $code, 'args' => $args];

            return $out;
        };

        if ($type === 'bool') {
            $bool = match (trim($raw)) {
                '1' => true,
                '0' => false,
                default => null,
            };
            if ($bool === null) {
                return $fail('bool');
            }
            $out['value'] = $bool;

            return $out;
        }

        if ($type === 'int' || $type === 'float') {
            $number = self::latinDigits(trim($raw));
            $value = Config::cast($type, $number);
            if ($value === null || (is_float($value) && !is_finite($value))) {
                return $fail($type);
            }
            if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                return $fail('enum', [implode(', ', array_map('strval', $rule['enum']))]);
            }
            if (isset($rule['min']) && $value < $rule['min'] || isset($rule['max']) && $value > $rule['max']) {
                return $fail('range', [(string) ($rule['min'] ?? ''), (string) ($rule['max'] ?? '')]);
            }
            $out['value'] = $value;

            return $out;
        }

        // string
        $format = (string) ($rule['format'] ?? '');
        if (mb_strlen($raw, 'UTF-8') > SettingRules::MAX_TEXT) {
            return $fail('too_long', [(string) SettingRules::MAX_TEXT]);
        }
        $multiline = $format === 'csv';
        if (preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $raw) === 1) {
            return $fail('control');
        }
        if (preg_match('//u', $raw) !== 1) {
            return $fail('utf8');
        }
        $value = $multiline ? $raw : trim($raw);
        if ($format === 'csv') {
            $csv = self::normalizeList($raw);
            $out['value'] = $csv['text'];
            if ($csv['dropped'] > 0) {
                $out['warnings'][] = ['code' => 'csv_dropped', 'args' => [(string) $csv['dropped']]];
            }

            return $out;
        }
        $problem = match ($format) {
            'url' => $value === '' || self::isHttpUrl($value) ? null : 'url',
            'origin' => $value === '' || preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$#', $value) === 1
                ? null : 'origin',
            'host' => $value === '' || preg_match(
                '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?)$/',
                $value
            ) === 1 ? null : 'host',
            'prefix' => preg_match('/^[A-Za-z0-9:_.\-]{1,64}$/', $value) === 1 ? null : 'prefix',
            default => null,
        };
        if ($problem !== null) {
            return $fail($problem);
        }
        $out['value'] = $value;

        return $out;
    }

    /**
     * A comma / line separated list of words, normalized as the runtime normalizes each item, without empty
     * or repeated items, joined with ",". `dropped` counts the items that disappeared (blank, empty after
     * normalization, or a repeat).
     *
     * @return array{text: string, items: list<string>, dropped: int}
     */
    public static function normalizeList(string $text): array
    {
        $parts = preg_split('/[,\r\n\x{060C}]/u', $text) ?: [];
        $items = [];
        $dropped = 0;
        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue; // a trailing comma or a blank line is formatting, not an item
            }
            $item = Normalizer::normalize(trim($part));
            if ($item === '' || in_array($item, $items, true)) {
                $dropped++;
                continue;
            }
            $items[] = $item;
        }

        return ['text' => implode(',', $items), 'items' => $items, 'dropped' => $dropped];
    }

    /** A value as the form shows it ('1' / '0' for a switch, no trailing zeros for a number). */
    public static function text(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'float' => rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0',
            default => (string) $value,
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_float($a) || is_float($b) ? abs((float) $a - (float) $b) < 1e-9 : $a === $b;
    }

    private static function isHttpUrl(string $value): bool
    {
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);

        return $parts !== false
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user']);
    }

    /** Persian and Arabic-Indic digits and the Persian decimal point, so a number typed on a Persian keyboard works. */
    private static function latinDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', '−' => '-',
        ]);
    }

    /**
     * @param array<string, string> $schema
     * @param array<string, mixed> $before effective values now
     * @param array<string, mixed> $after effective values the proposal gives
     * @param array<string, mixed> $inherited
     * @return list<array{key: string, from: string, to: string, reset: bool}>
     */
    private static function changes(array $schema, array $before, array $after, array $inherited): array
    {
        $changes = [];
        foreach (array_keys(SettingRules::table()) as $path) {
            if (self::same($before[$path], $after[$path])) {
                continue;
            }
            $changes[] = [
                'key' => $path,
                'from' => self::text($schema[$path], $before[$path]),
                'to' => self::text($schema[$path], $after[$path]),
                'reset' => self::same($after[$path], $inherited[$path]),
            ];
        }

        return $changes;
    }

    /**
     * Conflicts between keys, on the effective values. Each is reported only when the proposal changes one
     * of the keys it involves, so a setup that was already odd is not nagged on every unrelated save.
     *
     * @param array<string, mixed> $v effective values
     * @param list<string> $changed keys whose effective value changes
     * @return list<array<string, mixed>>
     */
    private static function conflicts(array $v, array $changed): array
    {
        $found = [];
        $rule = static function (string $code, array $keys, array $args = []) use (&$found, $changed): void {
            if (array_intersect($keys, $changed) !== []) {
                $found[] = ['key' => $keys[0], 'code' => $code, 'keys' => $keys, 'args' => $args];
            }
        };

        // The documented target (800 ms) is the whole request; the VPS wait is a part of it.
        if ((int) $v['vps.timeout_ms'] >= (int) $v['search.latency_budget_ms']) {
            $rule('timeout_over_budget', ['vps.timeout_ms', 'search.latency_budget_ms'], [
                (string) $v['vps.timeout_ms'], (string) $v['search.latency_budget_ms'],
            ]);
        }
        // Rows past the solid keyword hits come from the semantic list, which is at most semantic_top_k long.
        if ((int) $v['search.tail_cap'] > 0 && (int) $v['search.tail_cap'] > (int) $v['search.semantic_top_k']) {
            $rule('tail_over_top_k', ['search.tail_cap', 'search.semantic_top_k'], [
                (string) $v['search.tail_cap'], (string) $v['search.semantic_top_k'],
            ]);
        }
        // result_cap only flags a list as capped in the log; below the semantic list's length every hybrid
        // answer would count as capped.
        if ((int) $v['search.result_cap'] > 0 && (int) $v['search.result_cap'] < (int) $v['search.semantic_top_k']) {
            $rule('cap_under_top_k', ['search.result_cap', 'search.semantic_top_k'], [
                (string) $v['search.result_cap'], (string) $v['search.semantic_top_k'],
            ]);
        }
        if (
            $v['search.entities_enabled'] && (float) $v['search.entity_boost'] <= 0.0
            && (float) $v['search.entity_demote'] <= 0.0
        ) {
            $rule('entities_both_zero', ['search.entity_boost', 'search.entity_demote', 'search.entities_enabled']);
        }
        if ((float) $v['search.keyword_weight'] <= 0.0 && (float) $v['search.semantic_weight'] <= 0.0) {
            $rule('weights_zero', ['search.keyword_weight', 'search.semantic_weight']);
        }
        if ((int) $v['redis.ttl_zero'] > (int) $v['redis.ttl']) {
            $rule('ttl_zero_over_ttl', ['redis.ttl_zero', 'redis.ttl'], [
                (string) $v['redis.ttl_zero'], (string) $v['redis.ttl'],
            ]);
        }
        if ((int) $v['redis.ttl_hot'] < (int) $v['redis.ttl']) {
            $rule('ttl_hot_under_ttl', ['redis.ttl_hot', 'redis.ttl'], [
                (string) $v['redis.ttl_hot'], (string) $v['redis.ttl'],
            ]);
        }

        return $found;
    }

    /**
     * What the config doctor says about the proposal and did not say about the stored settings: it owns the
     * rules (Redis on without a host, a VPS URL without its token), this only words them without naming a
     * credential.
     *
     * @param array<string, mixed> $fileOverrides
     * @param array<string, mixed> $current
     * @param array<string, mixed> $proposal
     * @return list<array<string, mixed>>
     */
    private static function doctorWarnings(array $fileOverrides, array $current, array $proposal): array
    {
        $base = Config::flatten($fileOverrides);
        $now = ConfigDoctor::check(Config::expand(array_replace($base, $current)));
        $next = ConfigDoctor::check(Config::expand(array_replace($base, $proposal)));
        $known = array_flip(array_merge($now['errors'], $now['warnings']));
        $found = [];
        foreach (array_merge($next['errors'], $next['warnings']) as $line) {
            if (isset($known[$line])) {
                continue;
            }
            $key = explode(': ', $line, 2)[0];
            $code = match ($key) {
                'redis.host' => ['redis_host_empty', 'redis.host'],
                'vps.token' => ['vps_token_missing', 'vps.url'],
                default => null,
            };
            if ($code !== null) {
                $found[] = ['key' => $code[1], 'code' => $code[0]];
            }
        }

        return $found;
    }
}
