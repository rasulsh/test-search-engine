<?php

declare(strict_types=1);

namespace App;

/**
 * The single source of truth for configuration: every non-secret tunable, its
 * type and its default live in table(). config.php holds only the deployer's
 * overrides (credentials, tokens, URLs, any pinned tunable); load() deep-merges
 * them over the defaults, so code reads every key without a fallback and an old
 * config.php never lacks a key a newer release added.
 */
final class Config
{
    /** Keys the app cannot invent: environment-specific, so they must be set. */
    private const REQUIRED = ['db.dsn', 'db.user'];

    /**
     * Where the overrides live: server/config.php, or SEARCH_CONFIG_FILE when set
     * (a config kept outside the code directory; the test suite uses it too).
     */
    public static function file(): string
    {
        $path = getenv('SEARCH_CONFIG_FILE');

        return $path === false || $path === '' ? dirname(__DIR__) . '/config.php' : $path;
    }

    /**
     * @param string|null $root the server directory the default paths hang off
     *        (the code's own by default; the installer passes the one it writes to)
     * @return array<string, mixed> the full nested default tree
     */
    public static function defaults(?string $root = null): array
    {
        $tree = [];
        foreach (self::table($root) as $path => [, $default]) {
            self::set($tree, $path, $default);
        }

        return $tree;
    }

    /**
     * @return array<string, string> 'search.title_weight' => 'float'
     */
    public static function schema(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], self::table());
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return self::REQUIRED;
    }

    /**
     * The overrides array of config.php; empty when the file is absent.
     *
     * @return array<string, mixed>
     */
    public static function overrides(?string $overridesFile): array
    {
        if ($overridesFile === null || !is_file($overridesFile)) {
            return [];
        }
        $overrides = (static fn (string $file): mixed => require $file)($overridesFile);

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * The effective config: config.php's overrides over the defaults, every
     * known key cast to its schema type.
     *
     * @return array<string, mixed>
     */
    public static function load(?string $overridesFile, ?string $root = null): array
    {
        return self::merge(self::overrides($overridesFile), $root);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function merge(array $overrides, ?string $root = null): array
    {
        $config = self::defaults($root);
        $schema = self::schema();
        foreach (self::flatten($overrides) as $path => $value) {
            if (!isset($schema[$path])) {
                // Unknown to the schema: kept as given (the doctor warns about it).
                self::set($config, $path, $value);
                continue;
            }
            $cast = self::cast($schema[$path], $value);
            if ($cast !== null) {
                self::set($config, $path, $cast);
            }
            // A blank or invalid value keeps the default; the doctor reports it.
        }

        return $config;
    }

    /**
     * Cast an override to a schema type. Null: blank (non-string types) or not
     * castable. An explicit "0" / "0.0" / "false" survives as 0 / 0.0 / false.
     */
    public static function cast(string $type, mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            $value = $type === 'string' ? $value : trim($value);
            if ($value === '' && $type !== 'string') {
                return null;
            }
        }

        return match ($type) {
            'string' => is_scalar($value) && !is_bool($value) ? (string) $value : null,
            'int' => is_int($value) || (is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1)
                ? (int) $value
                : null,
            'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? (float) $value
                : null,
            'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => null,
        };
    }

    /**
     * Leaf overrides as 'dotted.path' => value (arrays recursed into).
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && $value !== []) {
                $flat += self::flatten($value, $path . '.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function set(array &$tree, string $path, mixed $value): void
    {
        $node = &$tree;
        foreach (explode('.', $path) as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node = $value;
    }

    /**
     * Every tunable: 'dotted.path' => [type, default]. What each search knob
     * does is explained in docs/SEARCH-BEHAVIOR.md; starting points for the
     * relevance numbers need tuning on the real catalog.
     *
     * @return array<string, array{string, string|int|float|bool}>
     */
    private static function table(?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__), '/');

        return [
            'db.dsn'               => ['string', ''],
            'db.user'              => ['string', ''],
            'db.password'          => ['string', ''],
            'db.products_table'    => ['string', 'products'],
            'db.search_logs_table' => ['string', 'search_logs'],

            // The model the bundle's meta.json is stamped with, checked on /reload.
            // /search does not read the bundle's vectors (M18): the semantic model
            // runs on the VPS. normalization_version follows the deployed code.
            'model.name'                  => ['string', 'intfloat/multilingual-e5-small'],
            'model.revision'              => ['string', 'main'],
            'model.dim'                   => ['int', 384],
            'model.normalization_version' => ['int', Normalizer::VERSION],

            'search.default_limit'            => ['int', 20],
            // Mirror the host's innodb_ft_min_token_size (3 on shared cPanel).
            'search.min_token_size'           => ['int', 3],
            'search.title_weight'             => ['float', 10.0],
            'search.desc_weight'              => ['float', 1.0],
            'search.phrase_bonus'             => ['float', 5.0],
            'search.spec_weight'              => ['float', 6.0],
            'search.tag_weight'               => ['float', 8.0],
            'search.brand_weight'             => ['float', 7.0],
            'search.category_weight'          => ['float', 5.0],
            'search.sku_prefix_min_length'    => ['int', 4],
            'search.alias_max_variants'       => ['int', 6],
            'search.synonyms_max_group_size'  => ['int', 4],
            'search.require_all_terms'        => ['bool', true],
            'search.soft_and_min_results'     => ['int', 3],
            'search.soft_and_min_coverage'    => ['float', 0.5],
            'search.soft_and_partial_penalty' => ['float', 0.5],
            'search.soft_and_candidate_cap'   => ['int', 100],
            'search.facet_min_products'       => ['int', 20],
            'search.collapse_weight'          => ['float', 9.0],
            'search.collapse_min_length'      => ['int', 5],
            'search.semantic_top_k'           => ['int', 300],
            // bge-m3 scale; the old e5 floor (0.82) would drop nearly every neighbour.
            'search.semantic_min_score'       => ['float', 0.4],
            'search.keyword_weight'           => ['float', 0.4],
            'search.semantic_weight'          => ['float', 0.6],
            'search.min_relevance'            => ['float', 0.45],
            'search.stock_boost'              => ['float', 0.1],
            'search.popularity_boost'         => ['float', 0.1],
            'search.brand_match_boost'        => ['float', 0.15],
            'search.category_match_boost'     => ['float', 0.1],
            'search.tag_match_boost'          => ['float', 0.1],
            'search.tag_match_min_tokens'     => ['int', 2],
            'search.suggest_min_results'      => ['int', 3],
            'search.suggest_min_frequency'    => ['int', 2],
            'search.suggest_max_distance'     => ['int', 2],
            'search.latency_budget_ms'        => ['int', 200],

            'storefront.store_base' => ['string', ''],
            'storefront.image_base' => ['string', ''],

            'vps.url'        => ['string', ''],
            'vps.token'      => ['string', ''],
            'vps.timeout_ms' => ['int', 300],

            'redis.enabled'    => ['bool', false],
            'redis.host'       => ['string', '127.0.0.1'],
            'redis.port'       => ['int', 6379],
            'redis.auth'       => ['string', ''],
            'redis.db'         => ['int', 0],
            'redis.ttl'        => ['int', 300],
            'redis.timeout_ms' => ['int', 100],
            'redis.prefix'     => ['string', 'search:cache:'],

            'debug.token'     => ['string', ''],
            'logs.token'      => ['string', ''],
            'logs.page_size'  => ['int', 50],
            'reload.token'    => ['string', ''],

            'paths.data'          => ['string', $root . '/data'],
            'paths.data_incoming' => ['string', $root . '/data_incoming'],
        ];
    }
}
