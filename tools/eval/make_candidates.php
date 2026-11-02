<?php

/**
 * Golden-set labeling, step 1 (docs/EVALUATION.md): the most searched queries -> a candidate sheet to mark in Excel.
 *
 *   php tools/eval/make_candidates.php --queries=top.csv [--n=150] [--k=10] [--depth=300] --out=candidates.csv
 *
 * top.csv is the grouped log export (php server/tools/logs-export.php --grouped, column normalized_q). Each query is
 * searched like run_eval.php does (storefront depth, no logging, no result cache) and its first k results are written,
 * one row per result: query, rank, product_id, title, relevant (empty, to fill with 1 or 0), note. A query that
 * returns nothing gets one placeholder row so it can be marked off-topic. UTF-8 with a BOM, for Excel.
 * Runs on the repo checkout, not on cPanel. Exit 0 on success, 2 on a usage error.
 */

declare(strict_types=1);

use App\Db;
use App\GoldenEval;
use App\GoldenLabeling;
use App\ProductDetails;
use App\SearchController;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    return;
}

$root = dirname(__DIR__, 2);
require $root . '/server/tools/_php-guard.php';

$config = require $root . '/server/bootstrap.php';

$options = getopt('', ['queries:', 'n:', 'k:', 'depth:', 'out:']);
$fail = static function (string $message): never {
    fwrite(STDERR, "make_candidates: {$message}\n");
    exit(2);
};
$queriesPath = (string) ($options['queries'] ?? '');
$out = (string) ($options['out'] ?? '');
if ($queriesPath === '' || $out === '') {
    $fail('--queries=<grouped log export> and --out=<candidates.csv> are required');
}
if (!is_file($queriesPath)) {
    $fail("queries file not found: {$queriesPath}");
}
$n = max(1, (int) ($options['n'] ?? 150));
$k = max(1, (int) ($options['k'] ?? 10));
$depth = (int) ($options['depth'] ?? GoldenEval::STOREFRONT_DEPTH);
if ($depth < $k) {
    $fail("--depth ({$depth}) must be at least --k ({$k})");
}

try {
    $queries = GoldenLabeling::readQueries((string) file_get_contents($queriesPath), $n);
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage());
}

$pdo = (new Db($config['db']))->pdo();
$controller = SearchController::fromConfig($pdo, $config, null, null, false);
$products = new ProductDetails($pdo, (string) $config['db']['products_table']);
$rows = [];
foreach ($queries as $query) {
    $ids = array_slice($controller->search(['q' => $query, 'limit' => $depth])['product_ids'], 0, $k);
    $rows = array_merge($rows, GoldenLabeling::candidateRows($query, $ids, array_column($products->fetch($ids), 'title', 'id')));
}
if (file_put_contents($out, GoldenLabeling::candidatesCsv($rows)) === false) {
    fwrite(STDERR, "make_candidates: cannot write {$out}\n");
    exit(1);
}
printf("wrote %d rows for %d queries (k=%d, depth=%d) to %s\n", count($rows), count($queries), $k, $depth, $out);
