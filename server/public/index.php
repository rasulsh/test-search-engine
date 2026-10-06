<?php

/**
 * Front controller. Tiny by design: parse the path, dispatch to a handler.
 * Serves GET /health, POST /search, POST /reload and GET /logs (token-protected).
 */

declare(strict_types=1);

$appBase = require __DIR__ . '/app_base.php';

$config = require $appBase . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// By file name, so the same routes work under a subfolder (/search-api/search).
$route = basename(rtrim($path, '/'));

switch ($route) {
    case 'health':
    case 'health.php':
        require __DIR__ . '/health.php';
        break;
    case 'search':
    case 'search.php':
        require __DIR__ . '/search.php';
        break;
    case 'reload':
    case 'reload.php':
        require __DIR__ . '/reload.php';
        break;
    case 'logs':
    case 'logs.php':
        require __DIR__ . '/logs.php';
        break;
    default:
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
}
