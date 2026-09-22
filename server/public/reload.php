<?php

/**
 * POST /reload. Token-protected. Validates the staged bundle and atomically
 * swaps it in (see App\Reload). Reachable directly or via the front controller.
 */

declare(strict_types=1);

use App\Db;
use App\Reload;
use App\ReloadException;

/** @var array<string, mixed> $config */
if (!isset($config)) {
    $config = require dirname(__DIR__) . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    return;
}

$configuredToken = (string) ($config['reload']['token'] ?? '');
if ($configuredToken === '') {
    http_response_code(503);
    echo json_encode(['error' => 'reload_disabled'], JSON_UNESCAPED_UNICODE);
    return;
}

$provided = (string) ($_SERVER['HTTP_X_RELOAD_TOKEN'] ?? '');
if ($provided === '') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($body) && isset($body['token'])) {
        $provided = (string) $body['token'];
    }
}
if (!hash_equals($configuredToken, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $db = new Db($config['db']);
    $result = (new Reload($db->pdo(), $config))->run();
} catch (ReloadException $e) {
    http_response_code(422);
    echo json_encode(
        ['error' => 'invalid_bundle', 'reason' => $e->reason(), 'details' => $e->details()],
        JSON_UNESCAPED_UNICODE
    );
    return;
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    return;
}

http_response_code(200);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
