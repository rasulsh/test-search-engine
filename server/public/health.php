<?php

/**
 * GET /health. Reachable directly or included by the front controller.
 * Returns 200 when the database is reachable, 503 otherwise.
 */

declare(strict_types=1);

use App\Db;
use App\Health;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $config = require dirname(__DIR__) . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $db = new Db($config['db']);
    $result = (new Health($db, $config['db']['products_table']))->check();
} catch (Throwable) {
    $result = [
        'status' => 'degraded',
        'checks' => ['database' => false, 'product_count' => null],
    ];
}

http_response_code($result['status'] === 'ok' ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
