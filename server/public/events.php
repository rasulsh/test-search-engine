<?php

/**
 * POST /events. The storefront reports what a shopper did with a search's results:
 * `{"search_uuid": "...", "product_id": 123, "position": 4, "action": "click"}`
 * (action: click | cart | order | dym_click, default click; a dym_click names no
 * product, send product_id 0). Sent with navigator.sendBeacon as text/plain, so
 * the cross-origin request needs no preflight; the body is JSON either way.
 * Answers 204 on success (and on a repeat), 400 / 404 otherwise; never details.
 */

declare(strict_types=1);

use App\Db;
use App\Events;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $appBase = require __DIR__ . '/app_base.php';
    $config = require $appBase . '/bootstrap.php';
}

header('Cache-Control: no-store');
$origin = (string) $config['events']['allowed_origin'];
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    return;
}
if ($method !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    return;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    return;
}

try {
    $db = new Db($config['db']);
    $outcome = (new Events(
        $db->pdo(),
        (string) $config['db']['search_events_table'],
        (string) $config['db']['search_logs_table']
    ))->record($input);
} catch (Throwable $e) {
    error_log('events: write failed: ' . $e->getMessage());
    http_response_code(500);
    return;
}

http_response_code(match ($outcome) {
    'ok', 'duplicate' => 204,
    'unknown_search' => 404,
    default => 400,
});
