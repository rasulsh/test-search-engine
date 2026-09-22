<?php

/**
 * POST /search. Reachable directly or included by the front controller.
 * Keyword-only (Tier 1) in M2; the Tier 2 vector path arrives in M4.
 */

declare(strict_types=1);

use App\Db;
use App\Identifier;
use App\Keyword;
use App\Logger;
use App\SearchController;
use App\Speller;

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

$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $db = new Db($config['db']);
    $pdo = $db->pdo();
    $productsTable = $config['db']['products_table'];

    $keyword = new Keyword(
        $pdo,
        $productsTable,
        $config['search']['min_token_size'],
        $config['search']['default_limit']
    );
    $logger = new Logger($pdo, $config['db']['search_logs_table']);
    $spellerFactory = static function () use ($pdo, $productsTable): Speller {
        $texts = [];
        $sql = 'SELECT normalized_title, normalized_desc FROM ' . Identifier::quote($productsTable);
        foreach ($pdo->query($sql) as $row) {
            $texts[] = $row['normalized_title'];
            $texts[] = $row['normalized_desc'];
        }

        return new Speller(Speller::buildVocabulary($texts));
    };

    $result = (new SearchController($keyword, $logger, $spellerFactory))->search($request);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
