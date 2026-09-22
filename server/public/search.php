<?php

/**
 * POST /search. Reachable directly or included by the front controller.
 * Tier 1 (keyword) always; Tier 2 (semantic) is added when the request carries a
 * query vector and a bundle is loaded (see App\SearchController).
 */

declare(strict_types=1);

use App\Db;
use App\Identifier;
use App\Keyword;
use App\Logger;
use App\Ranker;
use App\SearchController;
use App\Speller;
use App\Vectors;

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
    // The bundle dictionary is cached per worker (and in APCu); the table scan
    // is only a fallback for a data directory without spellcheck.txt.
    $dictionaryPath = $config['paths']['data'] . '/' . Speller::DICTIONARY_FILE;
    $spellerFactory = static fn (): Speller => Speller::fromDictionary($dictionaryPath)
        ?? Speller::fromProducts($pdo, $productsTable);

    // Tier 2 wiring. Vectors reads the active bundle; the signals provider both
    // fetches business signals and acts as the existence filter (ids it omits are
    // treated as absent from the catalog).
    $vectors = new Vectors($config['paths']['data'], (int) $config['model']['dim']);
    $ranker = new Ranker(
        (int) $config['search']['rrf_k'],
        (float) $config['search']['stock_boost'],
        (float) $config['search']['popularity_boost']
    );
    $signalsProvider = static function (array $ids) use ($pdo, $productsTable): array {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT product_id, stock, popularity FROM ' . Identifier::quote($productsTable)
             . ' WHERE product_id IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($ids));
        $signals = [];
        foreach ($stmt as $row) {
            $signals[(int) $row['product_id']] = [
                'stock'      => (int) $row['stock'],
                'popularity' => (int) $row['popularity'],
            ];
        }

        return $signals;
    };

    $controller = new SearchController(
        $keyword,
        $logger,
        $spellerFactory,
        null,
        10,
        $vectors,
        $ranker,
        $signalsProvider,
        (int) $config['search']['semantic_top_k'],
        (int) $config['search']['default_limit']
    );
    $result = $controller->search($request);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
