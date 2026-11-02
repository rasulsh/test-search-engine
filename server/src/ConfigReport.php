<?php

declare(strict_types=1);

namespace App;

/**
 * The status page's "effective configuration" table (M40): every known key with the value the app
 * uses, where it comes from (config.php or the code default) and what the config doctor said about
 * it. Credentials are never returned, only whether they are set; so are the values of keys the schema
 * does not know (a secret typed under a wrong name must not leak here).
 */
final class ConfigReport
{
    public const SOURCE_FILE = 'file';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_PANEL = 'panel';

    /**
     * @param array<string, mixed> $overrides config.php's raw array with the panel's values merged over it
     *        ({@see Config::layers()} 'merged')
     * @param array<string, array<string, string>>|null $deprecated replaces {@see Config::deprecated()} (tests)
     * @param array<string, mixed>|null $panel {@see Config::panelLayer()}: its keys get the source `panel`
     * @return list<array{key: string, value: ?string, state: string, source: string,
     *         issues: list<array{level: string, message: string}>}>
     *         state: 'value' (shown), 'set' / 'empty' (a secret), 'hidden' (an unknown key)
     */
    public static function rows(array $overrides, ?array $deprecated = null, ?array $panel = null): array
    {
        $config = Config::merge($overrides, null, $deprecated);
        $given = Config::flatten($overrides);
        $schema = Config::schema();
        $issues = self::issuesByKey(ConfigDoctor::check($overrides, $deprecated, $panel));
        $fromPanel = $panel['values'] ?? [];
        // A renamed key's value was moved to its new name: that row's source is config.php too.
        $fromFile = $given;
        foreach ($deprecated ?? Config::deprecated() as $old => $rule) {
            if (isset($rule['to']) && array_key_exists($old, $given)) {
                $fromFile[$rule['to']] = true;
            }
        }

        $rows = [];
        foreach ($schema as $path => $type) {
            $value = self::get($config, $path);
            if (Config::isSecret($path)) {
                $state = trim((string) $value) === '' ? 'empty' : 'set';
                $shown = null;
            } else {
                $state = 'value';
                $shown = self::display($type, $value);
            }
            $rows[] = [
                'key' => $path,
                'value' => $shown,
                'state' => $state,
                'source' => array_key_exists($path, $fromPanel)
                    ? self::SOURCE_PANEL
                    : (array_key_exists($path, $fromFile) ? self::SOURCE_FILE : self::SOURCE_DEFAULT),
                'issues' => $issues[$path] ?? [],
            ];
        }
        // Keys config.php sets that the schema does not know (typos, removed or renamed keys).
        foreach ($given as $path => $_) {
            if (!isset($schema[$path])) {
                $rows[] = [
                    'key' => $path,
                    'value' => null,
                    'state' => 'hidden',
                    'source' => self::SOURCE_FILE,
                    'issues' => $issues[$path] ?? [],
                ];
            }
        }

        return $rows;
    }

    private static function display(string $type, mixed $value): string
    {
        if ($type === 'bool') {
            return $value ? 'true' : 'false';
        }
        if ($type === 'float') {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
        }

        return (string) $value;
    }

    /**
     * Doctor lines are "key.path: message" (a few name two keys, "a / b: message"); each key gets
     * its own notes.
     *
     * @param array{errors: list<string>, warnings: list<string>, info: list<string>} $report
     * @return array<string, list<array{level: string, message: string}>>
     */
    private static function issuesByKey(array $report): array
    {
        $byKey = [];
        foreach (['errors' => 'error', 'warnings' => 'warning'] as $list => $level) {
            foreach ($report[$list] as $line) {
                $parts = explode(': ', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                foreach (explode(' / ', $parts[0]) as $key) {
                    $byKey[trim($key)][] = ['level' => $level, 'message' => $parts[1]];
                }
            }
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function get(array $tree, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $tree = $tree[$key] ?? null;
        }

        return $tree;
    }
}
