<?php

/**
 * POST /search. Reachable directly or included by the front controller.
 * Tier 1 (keyword) always; Tier 2 (semantic) is added when the request carries a
 * query vector and a bundle is loaded (see App\SearchController). With
 * `"with_details": true` the response also carries display fields per result;
 * `"debug": 1` plus the X-Debug-Token header adds a per-result score breakdown.
 */

declare(strict_types=1);

use App\Db;
use App\ProductDetails;
use App\SearchController;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $appBase = require __DIR__ . '/app_base.php';
    $config = require $appBase . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    return;
}

$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    return;
}

// Opt-in score breakdown (M21): needs the configured debug token; never logged.
$debug = false;
if (in_array($request['debug'] ?? null, [1, true, '1'], true)) {
    $debugToken = (string) $config['debug']['token'];
    if ($debugToken === '' || !hash_equals($debugToken, (string) ($_SERVER['HTTP_X_DEBUG_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'debug_forbidden'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $debug = true;
}

try {
    $db = new Db($config['db']);
    $pdo = $db->pdo();

    $controller = SearchController::fromConfig($pdo, $config);
    $result = $controller->search($request, $debug);

    // Opt-in display fields (e.g. the test page); the default response is unchanged.
    if (($request['with_details'] ?? false) === true) {
        $details = new ProductDetails(
            $pdo,
            $config['db']['products_table'],
            (string) $config['storefront']['store_base'],
            (string) $config['storefront']['image_base']
        );
        $result['products'] = $details->fetch($result['product_ids']);
    }
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
