<?php

declare(strict_types=1);

namespace App;

/**
 * Checks config.php's raw overrides against the schema in App\Config. Messages
 * carry key paths and statuses only, never values: the /health section built
 * from them is unauthenticated.
 */
final class ConfigDoctor
{
    /**
     * @param array<string, mixed> $overrides the raw array config.php returns
     * @return array{errors: list<string>, warnings: list<string>, info: list<string>}
     */
    public static function check(array $overrides): array
    {
        $schema = Config::schema();
        $config = Config::merge($overrides);
        $given = Config::flatten($overrides);
        $errors = [];
        $warnings = [];
        $info = [];

        foreach (Config::required() as $path) {
            if (trim((string) self::get($config, $path)) === '') {
                $errors[] = "{$path}: required, missing or empty";
            }
        }
        foreach ($given as $path => $value) {
            if (!isset($schema[$path])) {
                $warnings[] = "{$path}: unknown key (typo or removed option)";
                continue;
            }
            $blank = is_string($value) && trim($value) === '' && $schema[$path] !== 'string';
            if (!$blank && Config::cast($schema[$path], $value) === null) {
                $errors[] = "{$path}: not a valid {$schema[$path]}";
            }
        }

        if ($config['redis']['enabled'] && trim($config['redis']['host']) === '') {
            $warnings[] = 'redis.host: empty while redis.enabled is on (cache stays off)';
        }
        if (trim($config['vps']['url']) !== '' && $config['vps']['token'] === '') {
            $warnings[] = 'vps.token: empty while vps.url is set (the VPS will refuse the calls)';
        }
        if ($config['reload']['token'] === '') {
            $warnings[] = 'reload.token: empty, POST /reload is disabled';
        }

        foreach (array_keys($schema) as $path) {
            if (!array_key_exists($path, $given)) {
                $info[] = "{$path}: code default";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'info' => $info];
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function get(array $tree, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($tree) || !array_key_exists($key, $tree)) {
                return null;
            }
            $tree = $tree[$key];
        }

        return $tree;
    }
}
