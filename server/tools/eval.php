<?php

/**
 * Eval harness CLI (CLAUDE.md sec. 8).
 *
 * Runs the labeled queries in fixtures/eval_queries.json through the real search
 * pipeline and prints precision@k / recall@k, so search changes are measurable.
 *
 *   php server/tools/eval.php [--queries=<path>] [--k=<int>] [--min-recall=<float>]
 *
 * DB connection and model settings come from the environment (see config). With
 * only keyword labels the report reflects the Tier 1 tier; add a per-query
 * "q_vector" to the fixture to measure the hybrid tier with real query vectors.
 * Exits non-zero when --min-recall is given and recall@k falls below it.
 */

declare(strict_types=1);

use App\Db;
use App\Evaluator;
use App\SearchController;
use App\Vectors;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$repoRoot = dirname(__DIR__, 2);

$options = getopt('', ['queries:', 'k:', 'min-recall:']);
$queriesPath = $options['queries'] ?? $repoRoot . '/fixtures/eval_queries.json';
$k = isset($options['k']) ? max(1, (int) $options['k']) : 5;
$minRecall = isset($options['min-recall']) ? (float) $options['min-recall'] : null;

$decoded = json_decode((string) file_get_contents($queriesPath), true);
if (!is_array($decoded) || !isset($decoded['queries']) || !is_array($decoded['queries'])) {
    fwrite(STDERR, "Invalid eval file: {$queriesPath}\n");
    exit(2);
}
/** @var list<array{q: string, expected_ids: list<int>, q_vector?: list<float>}> $cases */
$cases = $decoded['queries'];

$db = new Db($config['db']);
$pdo = $db->pdo();
$controller = SearchController::fromConfig($pdo, $config);
$vectors = new Vectors($config['paths']['data'], (int) $config['model']['dim']);

$search = static function (array $case) use ($controller): array {
    $request = ['q' => (string) ($case['q'] ?? ''), 'limit' => 20];
    if (isset($case['q_vector']) && is_array($case['q_vector'])) {
        $request['q_vector'] = $case['q_vector'];
    }

    return $controller->search($request)['product_ids'];
};

$report = (new Evaluator($search))->run($cases, $k);

$tier = $vectors->isLoaded() ? 'loaded' : 'absent';
printf("Eval: %d queries, k=%d  (vectors %s)\n", $report['query_count'], $report['k'], $tier);
printf("%-26s %8s %8s %8s\n", 'query', 'hits', 'P@k', 'R@k');
foreach ($report['per_query'] as $row) {
    printf("%-26s %8d %8.3f %8.3f\n", $row['q'], $row['hits'], $row['precision'], $row['recall']);
}
printf("%-26s %8s %8.3f %8.3f\n", 'MEAN', '', $report['precision_at_k'], $report['recall_at_k']);

if ($minRecall !== null && $report['recall_at_k'] < $minRecall) {
    fwrite(STDERR, sprintf("recall@%d %.3f below --min-recall %.3f\n", $k, $report['recall_at_k'], $minRecall));
    exit(1);
}
