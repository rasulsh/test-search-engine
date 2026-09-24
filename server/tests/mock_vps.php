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
    'body' => json_decode($body, true),
]));

$response = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/search-vectors'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    ? FakeVps::respond($scenario, $authorization, $body)
    : ['status' => 404, 'body' => '{"detail":"Not Found"}'];

http_response_code($response['status']);
header('Content-Type: application/json');
echo $response['body'];
