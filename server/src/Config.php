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
     * Keys that existed once and are gone, so an old config.php is told what to do instead of getting
     * a bare "unknown key". Two shapes:
     *  - renamed: ['to' => 'new.key', 'since' => 'M40', 'until' => 'M42']: the old value is used for the
     *    new key (unless the new key is set too) up to the `until` release, the doctor warns;
     *  - removed: ['removed' => 'why', 'since' => 'M39']: ignored, the doctor says why it can be deleted.
     * Add every renamed or removed key here and to docs/CHANGELOG.md (CLAUDE.md, Definition of Done).
     *
     * @return array<string, array{since: string, to?: string, until?: string, removed?: string}>
     */
    public static function deprecated(): array
    {
        return [
            'logs.token' => [
                'since' => 'M39',
                'removed' => 'the logs and analytics pages sit behind the admin panel login (admin.username / '
                    . 'admin.password_hash), so this token is not used',
            ],
            'model.name' => [
                'since' => 'M42',
                'removed' => 'cPanel holds no vectors any more (the VPS serves the semantic tier), so the bundle '
                    . 'carries no model and /reload checks only the normalization version',
            ],
            'model.revision' => [
                'since' => 'M42',
                'removed' => 'cPanel holds no vectors any more, so there is no model revision to check',
            ],
            'model.dim' => [
                'since' => 'M42',
                'removed' => 'cPanel holds no vectors any more, so there is no vector dimension to check',
            ],
            'admin.ip_header' => [
                'since' => 'M39',
                'removed' => 'X-Forwarded-For is no longer trusted by default; the client address is REMOTE_ADDR, '
                    . 'and reverse proxies are listed in admin.trusted_proxies',
            ],
        ];
    }

    /** Dotted paths whose values are credentials: never shown, only "set" or "empty". */
    public static function isSecret(string $path): bool
    {
        return in_array($path, ['db.dsn', 'db.user'], true)
            || preg_match('/(^|\.)(password|password_hash|token|auth)$/', $path) === 1;
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
     * @param array<string, array<string, string>>|null $deprecated replaces {@see deprecated()} (tests)
     * @return array<string, mixed>
     */
    public static function merge(array $overrides, ?string $root = null, ?array $deprecated = null): array
    {
        $config = self::defaults($root);
        $schema = self::schema();
        $given = self::flatten($overrides);
        // A renamed key keeps working for its grace period: its value moves to the new name.
        foreach ($deprecated ?? self::deprecated() as $old => $rule) {
            if (isset($rule['to']) && array_key_exists($old, $given) && !array_key_exists($rule['to'], $given)) {
                $given[$rule['to']] = $given[$old];
            }
        }
        foreach ($given as $path => $value) {
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
            'db.search_events_table' => ['string', 'search_events'],
            'db.admin_audit_table' => ['string', 'admin_audit'],

            // The normalization version the bundle's meta.json is stamped with, checked on /reload;
            // it follows the deployed code. cPanel has no model since M42: the semantic model runs on the VPS.
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
            'search.semantic_top_k'           => ['int', 100],
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
            // Rows past the solid keyword hits kept in a result list (0 = no cap); see Ranker.
            'search.tail_cap'                 => ['int', 40],
            'search.intent_max_terms'         => ['int', 4],
            'search.suggest_min_results'      => ['int', 3],
            'search.suggest_min_frequency'    => ['int', 2],
            // 2 only for a long Latin token with the same first letter (Speller); 2 here lifts that restriction.
            'search.suggest_max_distance'     => ['int', 1],
            // Generic words never offered as a "did you mean" (comma separated, normalized).
            'search.suggest_blocklist'        => ['string', 'کالا,عدد,باب,محصول,مدل,نوع,سری,اصل,جدید,قیمت,خرید,فروش'],
            // Terms (comma separated, normalized): a build-derived synonym group holding one is dropped.
            // Owner aliases are never filtered; add a term here to remove a bad generated group.
            'search.synonym_blocklist'        => ['string', ''],
            // M44: a token held by more than this share of the catalog's titles and tags is GENERIC
            // ("بازی", "کارت"): it never qualifies a product by itself and only adds a little score.
            'search.generic_df_ratio'         => ['float', 0.15],
            // Words that are generic whatever the catalog says (comma separated, normalized). Keep it to
            // words no product is named by; "play", "one", "pro" are product words and stay out.
            'search.stopwords'                => ['string', 'خرید,قیمت,مدل,نوع,یک,با,برای,و,از,buy,price,for,with,and,the'],
            // M44: a brand / platform the query names lifts its products by entity_boost and demotes the
            // other brands' / platforms' by entity_demote, after the relevance floor. Both 0 turns it off.
            'search.entities_enabled'         => ['bool', true],
            'search.entity_boost'             => ['float', 0.15],
            'search.entity_demote'            => ['float', 0.10],
            // A longer query is cut before the spell correction and the VPS call.
            'search.query_max_chars'          => ['int', 100],
            'search.query_max_tokens'         => ['int', 8],
            // A result list this long counts as capped in the log (search_logs.is_capped).
            'search.result_cap'               => ['int', 300],
            // A documented target and a CI reference only: the runtime never enforces it.
            'search.latency_budget_ms'        => ['int', 800],

            'storefront.store_base' => ['string', ''],
            'storefront.image_base' => ['string', ''],

            'vps.url'        => ['string', ''],
            'vps.token'      => ['string', ''],
            'vps.timeout_ms' => ['int', 600],
            // POST {vps.url}/reload (the admin panel's "reload vectors"): loading a catalog takes seconds.
            'vps.reload_timeout_ms' => ['int', 60000],

            'redis.enabled'    => ['bool', false],
            'redis.host'       => ['string', '127.0.0.1'],
            'redis.port'       => ['int', 6379],
            'redis.auth'       => ['string', ''],
            'redis.db'         => ['int', 0],
            'redis.ttl'        => ['int', 86400],
            // A zero-result answer is cached this long only: it may be a gap an alias or a
            // bundle reload is about to close.
            'redis.ttl_zero'   => ['int', 300],
            'redis.ttl_hot'    => ['int', 604800],
            'redis.ttl_hot_threshold' => ['int', 20],
            'redis.freq_window' => ['int', 604800],
            'redis.timeout_ms' => ['int', 100],
            'redis.prefix'     => ['string', 'search:cache:'],

            // Origin the storefront runs on, for a fetch()-based events call; sendBeacon needs none.
            'events.allowed_origin' => ['string', ''],

            'debug.token'     => ['string', ''],
            'logs.page_size'  => ['int', 50],
            'logs.refresh_seconds' => ['int', 30],
            // The logs page's search and unindexed sorts read this many of the newest rows (a full
            // scan of a huge table would load the shared host); 0 = no bound. Index-ordered views cover all rows.
            'logs.scan_limit' => ['int', 200000],
            'reload.token'    => ['string', ''],

            // Admin panel (M39). Without username + password_hash admin.php answers 503.
            // password_hash comes from tools/admin-password.php; plaintext is not accepted.
            'admin.username'            => ['string', ''],
            'admin.password_hash'       => ['string', ''],
            // Comma separated IPs / CIDR ranges; empty = any address may reach the login form.
            'admin.allowed_ips'         => ['string', ''],
            'admin.idle_timeout_minutes' => ['int', 30],
            'admin.session_hours'       => ['int', 8],
            'admin.login_max_failures'  => ['int', 5],
            'admin.login_max_failures_global' => ['int', 50],
            'admin.login_window_minutes' => ['int', 15],
            'admin.audit_retention_days' => ['int', 180],
            // Reverse proxies (comma separated IPs / CIDR) whose X-Forwarded-For is believed. Empty
            // (the default) = the client address is REMOTE_ADDR and the header is ignored.
            'admin.trusted_proxies'     => ['string', ''],
            // Accept X-Forwarded-Proto: https as HTTPS (TLS ended at a proxy).
            'admin.trust_forwarded_proto' => ['bool', false],
            // Budget of each outbound probe (VPS /health, Redis) on the status page.
            'admin.probe_timeout_ms'    => ['int', 1000],
            // Alias manager (M41): longest term, products one term may match before a warning, backups kept.
            'admin.alias_max_term_chars' => ['int', 64],
            'admin.alias_warn_matches'  => ['int', 500],
            'admin.alias_backups'       => ['int', 20],

            'paths.data'          => ['string', $root . '/data'],
            'paths.data_incoming' => ['string', $root . '/data_incoming'],
            // The owner's editable files (aliases.json, its backups); survives every reload (M41).
            'paths.storage'       => ['string', $root . '/storage'],
        ];
    }
}
