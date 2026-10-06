<?php

/**
 * Example server configuration: OVERRIDES ONLY.
 *
 * Anything not listed here uses the code default in `App\Config::defaults()`;
 * to change a tunable, add its key here (same nested path, e.g.
 * 'search' => ['title_weight' => 12.0]). `php tools/config-check.php` lists the
 * keys you can set and flags typos and half-configured features.
 *
 * public/install.php writes `server/config.php` (gitignored) from this file on
 * the first deploy; it can also be copied by hand. This file contains NO secrets:
 * provide credentials and tokens through the environment (see `.env.example`) or
 * type them into config.php. A secret left empty switches its feature off.
 */

declare(strict_types=1);

// getenv() ?: default would turn an explicit "0" into the default.
$setting = static fn (string $name, string $default): string =>
    (($value = getenv($name)) === false || $value === '') ? $default : $value;

return [
    // Required: the database the service reads (PDO DSN and credentials).
    'db' => [
        'dsn'      => $setting('SEARCH_DB_DSN', ''),
        'user'     => $setting('SEARCH_DB_USER', ''),
        'password' => $setting('SEARCH_DB_PASSWORD', ''),
    ],

    // Tier 2: the VPS vector service (vps/README.md). Empty url = keyword-only.
    'vps' => [
        'url'   => $setting('SEARCH_VPS_URL', ''),
        // The VPS's VPS_TOKEN; required by the VPS, so set it with the url.
        'token' => $setting('SEARCH_VPS_TOKEN', ''),
        // @installer:vps (tune the call budget here: 'timeout_ms' => 300)
    ],

    // Result cache on the VPS (docs/CONFIGURATION.md). Off until enabled.
    'redis' => [
        'enabled' => $setting('SEARCH_REDIS_ENABLED', ''),
        'host'    => $setting('SEARCH_REDIS_HOST', '127.0.0.1'),
        'port'    => $setting('SEARCH_REDIS_PORT', ''),
        'auth'    => $setting('SEARCH_REDIS_AUTH', ''),
    ],

    // Absolute bases for the product url / image the export holds as relative
    // paths. Empty keeps the exported values.
    'storefront' => [
        'store_base' => $setting('SEARCH_STORE_BASE', ''),
        'image_base' => $setting('SEARCH_IMAGE_BASE', ''),
    ],

    // Tokens: each feature is off until its token is set. Use long random,
    // different values.
    'reload' => ['token' => $setting('SEARCH_RELOAD_TOKEN', '')], // POST /reload
    'logs'   => ['token' => $setting('SEARCH_LOGS_TOKEN', '')],   // logs.php
    'debug'  => ['token' => $setting('SEARCH_DEBUG_TOKEN', '')],  // "debug": 1 in /search

    // Only when pinning, add (see App\Config for the keys and defaults):
    //   'model' => ['name' => ..., 'dim' => ..., 'normalization_version' => ...],
    //   'paths' => ['data' => ..., 'data_incoming' => ...],
    // @installer:sections
];
