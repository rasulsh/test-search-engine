<?php

/**
 * Router for `php -S`: a mock VPS vector service answering from the scenario
 * JSON file named by MOCK_VPS_SCENARIO (see FakeVps). Each request is recorded
 * to "<scenario>.last" so tests can assert what cPanel sent.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Tests\FakeVps;

$scenarioPath = (string) getenv('MOCK_VPS_SCENARIO');
$scenario = json_decode((string) @file_get_contents($scenarioPath), true) ?: [];
$body = (string) file_get_contents('php://input');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($scenarioPath . '.last', (string) json_encode([
    'path' => parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $authorization,
    'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    'body' => json_decode($body, true),
]));

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/search-vectors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = FakeVps::respond($scenario, $authorization, $body);
} elseif (
    ($path === '/health' && $_SERVER['REQUEST_METHOD'] === 'GET')
    || ($path === '/reload' && $_SERVER['REQUEST_METHOD'] === 'POST')
) {
    $response = FakeVps::admin($scenario, (string) $path, $authorization);
} else {
    $response = ['status' => 404, 'body' => '{"detail":"Not Found"}'];
}

http_response_code($response['status']);
header('Content-Type: application/json');
// What the real VPS adds (vps/search_vectors/app.py): the echoed request id, Server-Timing, inflight.
if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
    header('X-Request-Id: ' . $_SERVER['HTTP_X_REQUEST_ID']);
}
if (isset($scenario['server_timing'])) {
    header('Server-Timing: ' . $scenario['server_timing']);
}
if (isset($scenario['inflight'])) {
    header('X-Vps-Inflight: ' . $scenario['inflight']);
}
echo $response['body'];
