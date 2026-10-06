<?php

/**
 * Resolves the application base directory (the folder holding bootstrap.php,
 * src/ and config.php) and returns it: SEARCH_APP_BASE when set (a deploy whose
 * web root holds only the public files), else the parent of public/. A base
 * without bootstrap.php is answered with a clear 500 instead of a raw include
 * error. Endpoints use it as: $appBase = require __DIR__ . '/app_base.php';
 * $config = require $appBase . '/bootstrap.php';
 */

declare(strict_types=1);

$base = getenv('SEARCH_APP_BASE');
if (($base === false || $base === '') && isset($_SERVER['SEARCH_APP_BASE'])) {
    $base = (string) $_SERVER['SEARCH_APP_BASE']; // SetEnv in .htaccess
}
$base = rtrim($base === false || $base === '' ? dirname(__DIR__) : $base, '/');

if (!is_file($base . '/bootstrap.php')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(
        ['error' => 'app_base_not_found', 'message' => 'app base not found; set SEARCH_APP_BASE'],
        JSON_UNESCAPED_UNICODE
    );
    exit(1);
}

return $base;
