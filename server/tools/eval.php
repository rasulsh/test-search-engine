<?php

/**
 * Eval harness CLI (CLAUDE.md sec. 8).
 *
 * Runs the labeled queries in fixtures/eval_queries.json through the real search
 * pipeline and prints precision@k / recall@k, so search changes are measurable.
 *
 *   php server/tools/eval.php [--queries=<path>] [--k=<int>] [--min-recall=<float>]
 *
 * DB connection, search and VPS settings come from the environment (see
 * config). Without SEARCH_VPS_URL the report reflects Tier 1 only; with it, each
 * query goes through the VPS exactly as /search does (the hybrid tier), and the
 * header counts the queries the VPS actually answered.
 * Exits non-zero when --min-recall is given and recall@k falls below it.
 */

declare(strict_types=1);

use App\Db;
use App\Evaluator;
use App\SearchController;

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
/** @var list<array{q: string, expected_ids: list<int>}> $cases */
$cases = $decoded['queries'];

$db = new Db($config['db']);
$pdo = $db->pdo();
$controller = SearchController::fromConfig($pdo, $config);
$semanticCount = 0;

$search = static function (array $case) use ($controller, &$semanticCount): array {
    $response = $controller->search(['q' => (string) ($case['q'] ?? ''), 'limit' => 20]);
    $semanticCount += isset($response['cosine_scores']) ? 1 : 0;

    return $response['product_ids'];
};

$report = (new Evaluator($search))->run($cases, $k);

$tier = ($config['vps']['url'] ?? '') === '' ? 'VPS not configured' : "VPS answered {$semanticCount}";
printf("Eval: %d queries, k=%d  (%s)\n", $report['query_count'], $report['k'], $tier);
printf("%-26s %8s %8s %8s\n", 'query', 'hits', 'P@k', 'R@k');
foreach ($report['per_query'] as $row) {
    printf("%-26s %8d %8.3f %8.3f\n", $row['q'], $row['hits'], $row['precision'], $row['recall']);
}
printf("%-26s %8s %8.3f %8.3f\n", 'MEAN', '', $report['precision_at_k'], $report['recall_at_k']);

if ($minRecall !== null && $report['recall_at_k'] < $minRecall) {
    fwrite(STDERR, sprintf("recall@%d %.3f below --min-recall %.3f\n", $k, $report['recall_at_k'], $minRecall));
    exit(1);
}
