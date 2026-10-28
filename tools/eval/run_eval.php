<?php

/**
 * Golden-set evaluation (M44, docs/EVALUATION.md).
 *
 *   php tools/eval/run_eval.php --golden=tools/eval/golden.csv [--k=10] [--out-dir=tools/eval/runs] [--no-save]
 *   php tools/eval/run_eval.php --compare runs/before.json runs/after.json
 *
 * Runs every query of the golden CSV (query, relevant_ids, note) through the same SearchController /search uses
 * (SearchController::fromConfig), with limit = k, WITHOUT logging and without the result cache, so an evaluation never
 * touches search_logs. DB and VPS settings come from server/config.php or SEARCH_CONFIG_FILE; without a VPS the run
 * measures the keyword tier only. Prints success@k, MRR, nDCG@k, the zero-result rate and the rank of the first
 * relevant hit per query, and saves the run as JSON with a timestamp so two runs can be compared.
 */

declare(strict_types=1);

use App\Db;
use App\GoldenEval;
use App\SearchController;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    return;
}

$root = dirname(__DIR__, 2);
require $root . '/server/tools/_php-guard.php'; // PHP 8.1+ check, before anything else runs

$config = require $root . '/server/bootstrap.php';

$argv = $_SERVER['argv'];
array_shift($argv);

if (($argv[0] ?? '') === '--compare') {
    if (count($argv) !== 3) {
        fwrite(STDERR, "usage: php tools/eval/run_eval.php --compare before.json after.json\n");
        exit(2);
    }
    $runs = array_map(static fn (string $path): ?array => json_decode((string) @file_get_contents($path), true), array_slice($argv, 1));
    if (!is_array($runs[0]) || !is_array($runs[1])) {
        fwrite(STDERR, "cannot read one of the run files\n");
        exit(2);
    }
    $diff = GoldenEval::compare($runs[0], $runs[1]);
    printf("%-18s %9s %9s %9s\n", 'metric', 'before', 'after', 'delta');
    foreach ($diff['metrics'] as $name => $m) {
        printf("%-18s %9.3f %9.3f %+9.3f\n", $name, $m['before'], $m['after'], $m['delta']);
    }
    $rank = static fn (?int $r): string => $r === null ? '-' : (string) $r;
    foreach (['regressions' => 'REGRESSED', 'improvements' => 'improved'] as $key => $label) {
        printf("\n%s: %d\n", $label, count($diff[$key]));
        foreach ($diff[$key] as $row) {
            printf("  %-40s rank %s -> %s\n", $row['query'], $rank($row['before']), $rank($row['after']));
        }
    }
    printf("\nadded queries: %d, removed queries: %d\n", count($diff['added']), count($diff['removed']));
    exit(0);
}

$options = getopt('', ['golden:', 'k:', 'out-dir:', 'no-save']);
$goldenPath = (string) ($options['golden'] ?? __DIR__ . '/golden.csv');
$k = max(1, (int) ($options['k'] ?? 10));
$outDir = (string) ($options['out-dir'] ?? __DIR__ . '/runs');
if (!is_file($goldenPath)) {
    fwrite(STDERR, "golden file not found: {$goldenPath} (copy golden.sample.csv and fill it)\n");
    exit(2);
}

try {
    $cases = GoldenEval::parseCsv((string) file_get_contents($goldenPath));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
if ($cases === []) {
    fwrite(STDERR, "no queries in {$goldenPath}\n");
    exit(2);
}

$controller = SearchController::fromConfig((new Db($config['db']))->pdo(), $config, null, null, false);
$semantic = 0;
$report = GoldenEval::run($cases, static function (string $query) use ($controller, $k, &$semantic): array {
    $response = $controller->search(['q' => $query, 'limit' => $k]);
    $semantic += isset($response['cosine_scores']) ? 1 : 0;

    return $response['product_ids'];
}, $k);
$report['generated_at'] = gmdate('c');
$report['golden'] = basename($goldenPath);
$report['tier'] = $config['vps']['url'] === '' ? 'keyword_only' : "hybrid ({$semantic} answered by the VPS)";

printf("Golden set: %s, %d queries, k=%d, %s\n\n", $report['golden'], $report['query_count'], $k, $report['tier']);
printf("%-44s %6s %6s %7s\n", 'query', 'hits', 'rank', 'nDCG');
foreach ($report['per_query'] as $row) {
    printf(
        "%-44s %6d %6s %7.3f\n",
        mb_strimwidth($row['query'], 0, 44, '…'),
        $row['results'],
        $row['first_rank'] ?? ($row['relevant'] === 0 ? ($row['success'] ? 'ok' : 'FP') : '-'),
        $row['ndcg']
    );
}
printf(
    "\nsuccess@%d %.3f   MRR %.3f   nDCG@%d %.3f   zero-result rate %.3f\n",
    $k,
    $report['success_at_k'],
    $report['mrr'],
    $k,
    $report['ndcg_at_k'],
    $report['zero_result_rate']
);

if (!isset($options['no-save'])) {
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        fwrite(STDERR, "cannot create {$outDir}\n");
        exit(1);
    }
    $path = $outDir . '/eval-' . gmdate('Ymd-His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "saved {$path}\n";
}
