<?php

/**
 * Example server configuration.
 *
 * Copy this file to `server/config.php` (gitignored) for the target host. Values
 * are read from the environment with safe defaults so the same file works in dev
 * and on cPanel. Nothing here — model, dimension, thresholds, table names — may
 * be hardcoded elsewhere in the server; read it from this config.
 *
 * This file contains NO secrets. Provide real credentials and the reload token
 * through the environment (see `.env.example`).
 */

declare(strict_types=1);

return [
    'db' => [
        'dsn'      => getenv('SEARCH_DB_DSN') ?: 'mysql:host=localhost;dbname=search;charset=utf8mb4',
        'user'     => getenv('SEARCH_DB_USER') ?: '',
        'password' => getenv('SEARCH_DB_PASSWORD') ?: '',
    ],

    // Embedding model — must match the pipeline and the browser embedder.
    // Bundle compatibility is checked against these on POST /reload.
    'model' => [
        'name'                  => getenv('SEARCH_MODEL') ?: 'intfloat/multilingual-e5-small',
        'revision'              => getenv('SEARCH_MODEL_REVISION') ?: 'main',
        'dim'                   => (int) (getenv('SEARCH_MODEL_DIM') ?: 384),
        'normalization_version' => (int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: 1),
    ],

    'search' => [
        'default_limit'     => (int) (getenv('SEARCH_DEFAULT_LIMIT') ?: 20),
        'semantic_top_k'    => (int) (getenv('SEARCH_SEMANTIC_TOP_K') ?: 100),
        'keyword_weight'    => (float) (getenv('SEARCH_KEYWORD_WEIGHT') ?: 0.5),
        'semantic_weight'   => (float) (getenv('SEARCH_SEMANTIC_WEIGHT') ?: 0.5),
        'latency_budget_ms' => (int) (getenv('SEARCH_LATENCY_BUDGET_MS') ?: 200),
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
