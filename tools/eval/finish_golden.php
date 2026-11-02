<?php

/**
 * Golden-set labeling, step 2 (docs/EVALUATION.md): the marked candidate sheet -> the golden CSV run_eval.php reads.
 *
 *   php tools/eval/finish_golden.php --in=candidates.csv --out=tools/eval/golden.csv [--force]
 *
 * relevant = 1 rows become the query's relevant_ids (";" separated). A query whose rows are all 0 and whose note
 * column has the word "off" becomes an off-topic row (empty relevant_ids). Any other query is skipped and listed on
 * screen. Refuses to overwrite an existing --out without --force. Needs no database; runs on the repo checkout.
 * Exit 0 on success, 2 on a usage error or a bad mark.
 */

declare(strict_types=1);

use App\GoldenLabeling;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    return;
}

$root = dirname(__DIR__, 2);
require $root . '/server/tools/_php-guard.php';
require $root . '/server/bootstrap.php'; // the autoloader; no database is opened

$options = getopt('', ['in:', 'out:', 'force']);
$fail = static function (string $message): never {
    fwrite(STDERR, "finish_golden: {$message}\n");
    exit(2);
};
$in = (string) ($options['in'] ?? '');
$out = (string) ($options['out'] ?? '');
if ($in === '' || $out === '') {
    $fail('--in=<marked candidates.csv> and --out=<golden.csv> are required');
}
if (!is_file($in)) {
    $fail("candidate sheet not found: {$in}");
}
if (is_file($out) && !isset($options['force'])) {
    $fail("{$out} exists; pass --force to overwrite it");
}

try {
    $result = GoldenLabeling::finish((string) file_get_contents($in));
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage());
}
if (file_put_contents($out, GoldenLabeling::goldenCsv($result['golden'])) === false) {
    fwrite(STDERR, "finish_golden: cannot write {$out}\n");
    exit(1);
}

$off = count(array_filter($result['golden'], static fn (array $g): bool => $g['relevant_ids'] === ''));
printf(
    "wrote %d queries to %s (%d with right products, %d off-topic); skipped %d\n",
    count($result['golden']),
    $out,
    count($result['golden']) - $off,
    $off,
    count($result['skipped'])
);
foreach ($result['skipped'] as $row) {
    printf("  skipped: %s (%s)\n", $row['query'], $row['reason']);
}
