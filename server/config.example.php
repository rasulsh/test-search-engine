<?php

/**
 * Example server configuration.
 *
 * public/install.php writes `server/config.php` (gitignored) from this file on
 * the first deploy, replacing the defaults below; it can also be copied by hand.
 * Values
 * are read from the environment with safe defaults so the same file works in dev
 * and on cPanel. Nothing here — model, dimension, thresholds, table names — may
 * be hardcoded elsewhere in the server; read it from this config.
 *
 * This file contains NO secrets. Provide real credentials and the reload token
 * through the environment (see `.env.example`).
 */

declare(strict_types=1);

// getenv() ?: default would turn an explicit "0" into the default, so tuning
// knobs that may legitimately be 0 read through this instead.
$setting = static fn (string $name, string $default): string =>
    (($value = getenv($name)) === false || $value === '') ? $default : $value;

return [
    'db' => [
        'dsn'      => getenv('SEARCH_DB_DSN') ?: 'mysql:host=localhost;dbname=search;charset=utf8mb4',
        'user'     => getenv('SEARCH_DB_USER') ?: '',
        'password' => getenv('SEARCH_DB_PASSWORD') ?: '',
        // Table names are configurable (never hardcode them in code).
        'products_table'    => getenv('SEARCH_PRODUCTS_TABLE') ?: 'products',
        'search_logs_table' => getenv('SEARCH_SEARCH_LOGS_TABLE') ?: 'search_logs',
    ],

    // Embedding model — must match the pipeline and the browser embedder.
    // Bundle compatibility is checked against these on POST /reload.
    'model' => [
        'name'                  => getenv('SEARCH_MODEL') ?: 'intfloat/multilingual-e5-small',
        'revision'              => getenv('SEARCH_MODEL_REVISION') ?: 'main',
        'dim'                   => (int) (getenv('SEARCH_MODEL_DIM') ?: 384),
        // Defaults to the deployed code's rules, as the pipeline stamps its own
        // into meta.json, so a rules change needs no edit here. Set the variable
        // only to pin a version deliberately.
        'normalization_version' => (int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: \App\Normalizer::VERSION),
    ],

    'search' => [
        'default_limit'     => (int) (getenv('SEARCH_DEFAULT_LIMIT') ?: 20),
        // Queries with any token shorter than this fall back from FULLTEXT to a
        // LIKE scan. Mirror the host's innodb_ft_min_token_size (default 3),
        // which cannot be changed on shared cPanel.
        'min_token_size'    => (int) (getenv('SEARCH_MIN_TOKEN_SIZE') ?: 3),
        // Keyword field weighting (M10). Each query token counts in the best
        // field it matched: (title_weight * tokens in title + desc_weight *
        // tokens only in description) / query tokens, plus phrase_bonus when
        // the tokens appear adjacent and in order in the title. Any title match ranks above any
        // description-only match regardless of these; they order within the
        // two bands. Starting points — tune on the real catalog.
        'title_weight'      => (float) $setting('SEARCH_TITLE_WEIGHT', '10.0'),
        'desc_weight'       => (float) $setting('SEARCH_DESC_WEIGHT', '1.0'),
        'phrase_bonus'      => (float) $setting('SEARCH_PHRASE_BONUS', '5.0'),
        // Specs (M13): a token found in the attributes / feature titles
        // (normalized_specs) but not the title earns spec_weight. Any spec match
        // ranks above any description-only match, below any title match; the
        // weight orders rows within the bands. Needs real-catalog tuning.
        'spec_weight'       => (float) $setting('SEARCH_SPEC_WEIGHT', '6.0'),
        // SKU search (M12): a product whose normalized SKU equals the query
        // ranks first, then SKU prefix matches, above all text matches. Prefix
        // matching needs at least this many characters and a digit in the query.
        'sku_prefix_min_length' => (int) $setting('SEARCH_SKU_PREFIX_MIN_LENGTH', '4'),
        // Synonym / alias expansion (M15): a query term found whole in the
        // bundle's synonyms.json or aliases.json is also searched with each
        // other term of its group, in the title only. alias_max_variants caps
        // the variants per query (the literal one included; 1 disables
        // expansion); they share one title-index scan. Generated
        // synonym groups larger than synonyms_max_group_size are ignored as
        // likely catalog noise; owner aliases are never capped.
        'alias_max_variants'      => (int) $setting('SEARCH_ALIAS_MAX_VARIANTS', '6'),
        'synonyms_max_group_size' => (int) $setting('SEARCH_SYNONYMS_MAX_GROUP_SIZE', '4'),
        // All terms (M16): a multi-word query matches only products holding
        // every word across title, specs and description ("red keyboard" is
        // keyboards that are red, not keyboards plus red things). Alias
        // variants apply it per variant. When nothing holds every word, the
        // products matching the most words are served instead. While there
        // are all-words hits, semantic neighbours only reorder them and are
        // not appended below. 0 always serves partial matches (most words
        // first) with the additive neighbours.
        'require_all_terms' => filter_var(
            $setting('SEARCH_REQUIRE_ALL_TERMS', '1'),
            FILTER_VALIDATE_BOOLEAN
        ),
        // Global cosine top-K width for Tier 2 (candidates fused with keyword).
        'semantic_top_k'    => (int) (getenv('SEARCH_SEMANTIC_TOP_K') ?: 100),
        // Relevance floor (cosine) for Tier 2 neighbours. The nearest vectors of
        // a query with no relevant product are still unrelated items; below this
        // they are dropped, and a query with neither keyword hits nor neighbours
        // above it returns nothing. Tune against a real eval set.
        'semantic_min_score' => (float) $setting('SEARCH_SEMANTIC_MIN_SCORE', '0.82'),
        // With a query vector, keyword hits that matched only in the description
        // (not the title) must also reach semantic_min_score, or they are
        // dropped: spec text mentioning the query ("ball bearing" in a case
        // fan) is not a product for it. Title matches are always kept;
        // keyword-only requests are unaffected. Set to 0 to disable.
        'desc_only_needs_semantic' => filter_var(
            $setting('SEARCH_DESC_ONLY_NEEDS_SEMANTIC', '1'),
            FILTER_VALIDATE_BOOLEAN
        ),
        // Reciprocal Rank Fusion constant. Keyword and cosine scores are on
        // different scales, so they are merged by rank, not added raw. Keyword
        // hits always rank above semantic-only neighbours; the weights set how
        // much each list reorders items within those bands.
        'rrf_k'             => (int) $setting('SEARCH_RRF_K', '60'),
        'keyword_weight'    => (float) $setting('SEARCH_KEYWORD_WEIGHT', '1.0'),
        'semantic_weight'   => (float) $setting('SEARCH_SEMANTIC_WEIGHT', '1.0'),
        // Light business boosts applied AFTER fusion (kept small on purpose).
        'stock_boost'       => (float) $setting('SEARCH_STOCK_BOOST', '0.1'),
        'popularity_boost'  => (float) $setting('SEARCH_POPULARITY_BOOST', '0.1'),
        // "Did you mean": tried only when the literal query has fewer than
        // suggest_min_results keyword hits, and offered only if the suggestion
        // returns more. Candidates must occur in at least suggest_min_frequency
        // products and lie within suggest_max_distance edits (tokens of 4
        // characters or fewer allow 1).
        'suggest_min_results'   => (int) $setting('SEARCH_SUGGEST_MIN_RESULTS', '3'),
        'suggest_min_frequency' => (int) $setting('SEARCH_SUGGEST_MIN_FREQUENCY', '2'),
        'suggest_max_distance'  => (int) $setting('SEARCH_SUGGEST_MAX_DISTANCE', '2'),
        'latency_budget_ms' => (int) (getenv('SEARCH_LATENCY_BUDGET_MS') ?: 200),
    ],

    // Absolute bases for the product `url` / `image` values exported from
    // OpenCart (relative, e.g. "index.php?route=..." and "catalog/x.jpg"). When
    // set, `with_details` responses carry absolute links; empty keeps the values
    // as exported (the test page then resolves them against its parent directory).
    'storefront' => [
        'store_base' => $setting('SEARCH_STORE_BASE', ''),
        'image_base' => $setting('SEARCH_IMAGE_BASE', ''),
    ],

    // Active bundle plus staging directory used for the atomic reload swap.
    'paths' => [
        'data'          => getenv('SEARCH_DATA_DIR') ?: __DIR__ . '/data',
        'data_incoming' => getenv('SEARCH_DATA_INCOMING_DIR') ?: __DIR__ . '/data_incoming',
    ],

    'reload' => [
        // Empty by default; must be set in the environment to enable POST /reload.
        'token' => getenv('SEARCH_RELOAD_TOKEN') ?: '',
    ],
];
