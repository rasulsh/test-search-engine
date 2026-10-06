<?php

/**
 * GET /health. Reachable directly or included by the front controller.
 * Returns 200 when the database is reachable, 503 otherwise.
 */

declare(strict_types=1);

use App\Config;
use App\Db;
use App\Health;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $appBase = require __DIR__ . '/app_base.php';
    $config = require $appBase . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $configSection = Health::configSection(Config::overrides(Config::file()));
} catch (Throwable) {
    $configSection = ['ok' => false, 'errors' => ['config: could not be evaluated'], 'warnings' => []];
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

$result['config'] = $configSection;

http_response_code($result['status'] === 'ok' ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
