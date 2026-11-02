<?php

declare(strict_types=1);

namespace App;

/**
 * What the settings page accepts for each editable key (M46), next to the schema in {@see Config}:
 * the type and default come from there, this table adds the range, the allowed values, the format and the
 * unit label. The Persian help line of every key is in {@see AdminStrings} (`settings.help`); a PHPUnit test
 * fails when an editable key lacks a rule or a help line, and when its own default breaks its rule.
 *
 * Where a bound is the code's own (a clamp in the class that uses the value, a check in the VPS, the
 * installer's old limits) the comment says so; every other bound is a technical sanity limit, not a
 * statement about what is a good value.
 *
 * Rule keys: min / max (inclusive), step (the input's step), enum (allowed values), format (url, origin,
 * csv, host, prefix; default plain text), required (a string may not be blank), unit (a label key in
 * AdminStrings `settings.units`).
 */
final class SettingRules
{
    /** The longest text value accepted (a comma separated list of words fits easily). */
    public const MAX_TEXT = 2048;

    /**
     * @return array<string, array{min?: int|float, max?: int|float, step?: int|float, enum?: list<string|int>,
     *         format?: string, required?: bool, unit?: string}>
     */
    public static function table(): array
    {
        $weight = ['min' => 0, 'max' => 1000, 'step' => 0.1, 'unit' => 'weight'];   // installer's limits
        // Ranker clamps at 0; the upper 10 is a technical bound
        $boost = ['min' => 0, 'max' => 10, 'step' => 0.01, 'unit' => 'ratio'];
        $count = static fn (int $min, int $max, string $unit = 'count'): array => [
            'min' => $min, 'max' => $max, 'step' => 1, 'unit' => $unit,
        ];

        return [
            // Keyword tier. Clamps in Keyword's constructor: limit/size/length >= 1, results/cap/min products >= 0.
            'search.default_limit'            => $count(1, 1000),
            'search.min_token_size'           => $count(1, 10, 'chars'),
            'search.title_weight'             => $weight,
            'search.desc_weight'              => $weight,
            'search.phrase_bonus'             => $weight,
            'search.spec_weight'              => $weight,
            'search.tag_weight'               => $weight,
            'search.brand_weight'             => $weight,
            'search.category_weight'          => $weight,
            'search.sku_prefix_min_length'    => $count(1, 64, 'chars'),
            'search.alias_max_variants'       => $count(1, 50),
            'search.synonyms_max_group_size'  => $count(1, 100),
            'search.require_all_terms'        => [],
            'search.soft_and_min_results'     => $count(0, 1000),
            // Keyword clamps the coverage to 0..1
            'search.soft_and_min_coverage'    => ['min' => 0, 'max' => 1, 'step' => 0.05, 'unit' => 'ratio'],
            'search.soft_and_partial_penalty' => ['min' => 0, 'max' => 10, 'step' => 0.05, 'unit' => 'ratio'],
            'search.soft_and_candidate_cap'   => $count(0, 10000),
            'search.facet_min_products'       => $count(0, 10000),
            'search.collapse_weight'          => $weight,
            'search.collapse_min_length'      => $count(1, 64, 'chars'),
            // Semantic tier and the blend. The VPS accepts min_score only in -1..1 (cosine).
            'search.semantic_top_k'           => $count(1, 1000),
            'search.semantic_min_score'       => ['min' => -1, 'max' => 1, 'step' => 0.01, 'unit' => 'score'],
            'search.keyword_weight'           => ['min' => 0, 'max' => 10, 'step' => 0.05, 'unit' => 'ratio'],
            'search.semantic_weight'          => ['min' => 0, 'max' => 10, 'step' => 0.05, 'unit' => 'ratio'],
            'search.min_relevance'            => ['min' => 0, 'max' => 10, 'step' => 0.01, 'unit' => 'ratio'],
            'search.stock_boost'              => $boost,
            'search.popularity_boost'         => $boost,
            'search.brand_match_boost'        => $boost,
            'search.category_match_boost'     => $boost,
            'search.tag_match_boost'          => $boost,
            'search.tag_match_min_tokens'     => $count(1, 10),
            'search.tail_cap'                 => $count(0, 10000),
            'search.intent_max_terms'         => $count(0, 20),
            // "Did you mean". Speller: distance and frequency >= 1; its index is built for distance 1 or 2.
            'search.suggest_min_results'      => $count(1, 1000),
            'search.suggest_min_frequency'    => $count(1, 100000),
            'search.suggest_max_distance'     => ['enum' => [1, 2], 'step' => 1, 'unit' => 'chars'],
            'search.suggest_blocklist'        => ['format' => 'csv'],
            'search.synonym_blocklist'        => ['format' => 'csv'],
            'search.brand_blocklist'          => ['format' => 'csv'],
            // TokenStats: a ratio above 0 enables the frequency test; 0 turns it off, 1 can never be exceeded.
            'search.generic_df_ratio'         => ['min' => 0, 'max' => 1, 'step' => 0.01, 'unit' => 'ratio'],
            'search.stopwords'                => ['format' => 'csv'],
            'search.entities_enabled'         => [],
            'search.entity_boost'             => $boost,
            // Ranker clamps the demotion to 0..1
            'search.entity_demote'            => ['min' => 0, 'max' => 1, 'step' => 0.01, 'unit' => 'ratio'],
            'search.condition_enabled'        => [],
            'search.query_max_chars'          => $count(1, 1000, 'chars'),
            'search.query_max_tokens'         => $count(1, 100),
            'search.result_cap'               => $count(0, 100000),
            'search.latency_budget_ms'        => $count(50, 60000, 'ms'),

            'storefront.store_base'           => ['format' => 'url'],
            'storefront.image_base'           => ['format' => 'url'],

            'vps.url'                         => ['format' => 'url'],
            'vps.timeout_ms'                  => $count(50, 10000, 'ms'),           // installer's limits
            'vps.reload_timeout_ms'           => $count(1000, 600000, 'ms'),

            'redis.enabled'                   => [],
            'redis.host'                      => ['format' => 'host'],
            'redis.port'                      => $count(1, 65535, 'port'),
            'redis.db'                        => $count(0, 255, 'index'),
            'redis.ttl'                       => $count(1, 31536000, 'seconds'),
            'redis.ttl_zero'                  => $count(1, 31536000, 'seconds'),
            'redis.ttl_hot'                   => $count(1, 31536000, 'seconds'),
            'redis.ttl_hot_threshold'         => $count(0, 1000000),
            'redis.freq_window'               => $count(1, 31536000, 'seconds'),
            'redis.timeout_ms'                => $count(1, 10000, 'ms'),
            // A blank prefix would let the cache flush match every key of the Redis database.
            'redis.prefix'                    => ['format' => 'prefix', 'required' => true],

            'events.allowed_origin'           => ['format' => 'origin'],

            'log.duplicate_seconds'           => $count(0, 86400, 'seconds'),
            'logs.page_size'                  => $count(1, 500),                    // LogsPage clamps 1..500
            'logs.refresh_seconds'            => $count(0, 3600, 'seconds'),
            'logs.scan_limit'                 => $count(0, 100000000),
        ];
    }

    /**
     * @return array<string, mixed>|null the rule, or null for a key that is not editable
     */
    public static function for(string $path): ?array
    {
        return self::table()[$path] ?? null;
    }

    /**
     * Keys whose change can alter which products a query returns: the result cache is not keyed by
     * them, so the page points the owner at the cache flush after saving one.
     */
    public static function affectsResults(string $path): bool
    {
        return str_starts_with($path, 'search.') || in_array($path, ['vps.url', 'vps.timeout_ms'], true);
    }

    /**
     * Every editable key in schema order, grouped by section ('search' => [...]).
     *
     * @return array<string, list<string>>
     */
    public static function sections(): array
    {
        $sections = [];
        foreach (array_keys(Config::schema()) as $path) {
            if (Config::editability($path) === Config::EDITABLE) {
                $sections[explode('.', $path, 2)[0]][] = $path;
            }
        }

        return $sections;
    }
}
