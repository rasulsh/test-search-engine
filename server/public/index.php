<?php

/**
 * Front controller. Tiny by design: parse the path, dispatch to a handler.
 * Serves GET /health, POST /search, and POST /reload.
 */

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

switch ($path) {
    case '/health':
    case '/health.php':
        require __DIR__ . '/health.php';
        break;
    case '/search':
    case '/search.php':
        require __DIR__ . '/search.php';
        break;
    case '/reload':
    case '/reload.php':
        require __DIR__ . '/reload.php';
        break;
    default:
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
}
