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

    // Model the offline bundle's meta.json is stamped with (pipeline/config.py
    // `model`); bundle compatibility is checked against these on POST /reload.
    // Since M18 /search does not read the bundle's vectors: the semantic tier's
    // model (bge-m3) runs on the VPS, configured there (vps/README.md).
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
        // Tags, brand, category (M21): a token not in the title but in one of
        // these fields is credited that field's weight, like spec_weight above,
        // and the fields rank tags > brand > category > specs. tag_weight sits
        // just below title_weight: tags are franchise / alternate product names.
        // A match there (and none in the title) still ranks above any
        // description-only match. Starting points; tune on the real catalog.
        'tag_weight'        => (float) $setting('SEARCH_TAG_WEIGHT', '8.0'),
        'brand_weight'      => (float) $setting('SEARCH_BRAND_WEIGHT', '7.0'),
        'category_weight'   => (float) $setting('SEARCH_CATEGORY_WEIGHT', '5.0'),
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
        // Soft AND (M23): strict all-words matching empties a query when one word
        // matches nothing (a transliteration the catalog does not hold). When fewer
        // than soft_and_min_results products hold every word (the literal query and
        // its alias variants together), a multi-word query is topped up with
        // partial matches: products holding at least soft_and_min_coverage of a
        // variant's words (0.5 = half; 1 = none). They always rank below every
        // full-coverage hit (most words first); soft_and_partial_penalty scales their
        // keyword score for the hybrid blend (1 = no penalty, 0 = ranked by the
        // semantic score alone), where they stay subject to the relevance floor.
        // 0 results = strict all-words only. One-word queries are never affected.
        // soft_and_candidate_cap bounds the work per alias variant: only rows holding a
        // word the variant swapped in are looked at, and at most this many are scored
        // (the engine's best matches first), so a swapped-in word that is nearly
        // everywhere cannot cost a catalog scan per variant (0 = no cap).
        'soft_and_min_results'     => (int) $setting('SEARCH_SOFT_AND_MIN_RESULTS', '3'),
        'soft_and_min_coverage'    => (float) $setting('SEARCH_SOFT_AND_MIN_COVERAGE', '0.5'),
        'soft_and_partial_penalty' => (float) $setting('SEARCH_SOFT_AND_PARTIAL_PENALTY', '0.5'),
        'soft_and_candidate_cap'   => (int) $setting('SEARCH_SOFT_AND_CANDIDATE_CAP', '100'),
        // Collapsed names (M23): "farcry" = "far cry", "dualsense" = "dual sense".
        // The query with its spaces removed is also matched, as a substring, against
        // the collapsed title, brand and tags (products.normalized_collapsed: needs
        // the M23 rebuild + reload) and verified to start at a word. A hit is a name
        // match scored collapse_weight: below title_weight, so a regular title
        // match outranks it. Collapsed queries shorter than collapse_min_length
        // characters are not tried (a short run of letters matches inside many
        // names); 0 weight turns the feature off.
        'collapse_weight'      => (float) $setting('SEARCH_COLLAPSE_WEIGHT', '9.0'),
        'collapse_min_length'  => (int) $setting('SEARCH_COLLAPSE_MIN_LENGTH', '5'),
        // Global cosine top-K width for Tier 2: the `limit` asked of the VPS
        // (candidates fused with keyword). Keep it at or below the VPS's
        // VPS_MAX_LIMIT (500): a full list tells /search that more neighbours
        // may clear the floor further down.
        'semantic_top_k'    => (int) (getenv('SEARCH_SEMANTIC_TOP_K') ?: 300),
        // Relevance floor (cosine) for Tier 2 neighbours, sent to the VPS as
        // min_score and re-applied here. The nearest vectors of a query with no
        // relevant product are still unrelated items; below this they are
        // dropped, and a query with neither keyword hits nor neighbours above it
        // returns nothing. 0.4 is a starting point for bge-m3, whose scores sit
        // far lower than e5's (the old 0.82 was e5's and would drop nearly every
        // bge-m3 neighbour). It NEEDS TUNING on the real catalog: on the fixture,
        // right one-word hits scored 0.42-0.49 and wrong ones up to 0.43, so no
        // floor separates short queries; the keyword tier (all terms, aliases)
        // blended with it carries one-word precision, and the floor mainly keeps
        // far neighbours out.
        'semantic_min_score' => (float) $setting('SEARCH_SEMANTIC_MIN_SCORE', '0.4'),
        // Blended hybrid ranking (M20). Each candidate gets keyword_norm (its
        // field-weighted keyword score / the best in the result set) and
        // semantic_norm (its VPS cosine / the best cosine; 0 when the VPS did
        // not return it), and relevance = keyword_weight * keyword_norm +
        // semantic_weight * semantic_norm. WEAK keyword hits (the query matched
        // only in specs / description) below min_relevance are dropped; solid
        // ones (every term in the title / name, exact SKU) are never dropped, only ranked,
        // and semantic-only neighbours keep the floor. A keyword-only hit tops out at keyword_weight, so a floor
        // above it (0.45 > 0.4) drops hits the model sees no link to ("ball
        // bearing" in a power supply's specs) with no per-tier gate; a strong
        // semantic match with a weak keyword match survives. Exact-SKU hits are
        // pinned first regardless. STARTING POINTS: tune on real queries.
        'keyword_weight'    => (float) $setting('SEARCH_KEYWORD_WEIGHT', '0.4'),
        'semantic_weight'   => (float) $setting('SEARCH_SEMANTIC_WEIGHT', '0.6'),
        'min_relevance'     => (float) $setting('SEARCH_MIN_RELEVANCE', '0.45'),
        // Light business boosts applied AFTER fusion (kept small on purpose).
        'stock_boost'       => (float) $setting('SEARCH_STOCK_BOOST', '0.1'),
        'popularity_boost'  => (float) $setting('SEARCH_POPULARITY_BOOST', '0.1'),
        // Match boosts (M21), the same kind as stock / popularity: applied after
        // the floor, multiplying the blended relevance by (1 + boosts). They
        // reorder close candidates and never admit a dropped one, so a brand
        // cannot flood unrelated results. brand_match_boost: all words of the
        // product's brand are in the query (or in one of its alias / synonym
        // variants, so a Persian query meets a Latin brand: list the pair in
        // aliases.json). category_match_boost: likewise for the whole category
        // name. tag_match_boost: a query of at least tag_match_min_tokens words
        // is a phrase inside the product's tags; one word is excluded because the
        // keyword tier already credits it through tag_weight (no double count).
        // 0 disables a boost. Hybrid mode only (keyword-only is ordered by Keyword).
        'brand_match_boost'    => (float) $setting('SEARCH_BRAND_MATCH_BOOST', '0.15'),
        'category_match_boost' => (float) $setting('SEARCH_CATEGORY_MATCH_BOOST', '0.1'),
        'tag_match_boost'      => (float) $setting('SEARCH_TAG_MATCH_BOOST', '0.1'),
        'tag_match_min_tokens' => (int) $setting('SEARCH_TAG_MATCH_MIN_TOKENS', '2'),
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

    // VPS vector service (M18, vps/README.md): the query text is POSTed to
    // {url}/search-vectors server-to-server and the returned neighbours feed
    // Tier 2. Empty url = keyword-only. Unreachable, slow (timeout_ms bounds
    // connect + response) or failing VPS = keyword-only results for that
    // request, logged via error_log; the request never fails because of it.
    // The token is the VPS's VPS_TOKEN (a secret: keep it in the environment
    // or in config.php, never in the repository).
    'vps' => [
        'url'        => $setting('SEARCH_VPS_URL', ''),
        'token'      => $setting('SEARCH_VPS_TOKEN', ''),
        'timeout_ms' => (int) $setting('SEARCH_VPS_TIMEOUT_MS', '300'),
    ],

    // Read-only tooling (M21), each off until its token is set. `debug`: a
    // /search request with "debug": 1 and this token in the X-Debug-Token header
    // gets a per-result score breakdown (never logged). `logs`: public/logs.php,
    // a read-only page of recent searches, opened with this token (header
    // X-Logs-Token, or ?token= in the URL). Use long random values and different
    // ones: they are secrets, keep them in the environment or in config.php.
    'debug' => [
        'token' => $setting('SEARCH_DEBUG_TOKEN', ''),
    ],
    'logs' => [
        'token'     => $setting('SEARCH_LOGS_TOKEN', ''),
        'page_size' => (int) $setting('SEARCH_LOGS_PAGE_SIZE', '50'),
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
