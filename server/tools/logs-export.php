<?php

/**
 * Search-log export CLI (M36): search_logs -> CSV (default) or JSON, for the
 * zero-result loop into pipeline/gen_aliases.py (docs/ALIASES.md).
 *
 *   php server/tools/logs-export.php --out zero.csv [--window 24h|7d|30d|90d|all]
 *       [--since "YYYY-MM-DD[ HH:MM:SS]"] [--until "..."] [--zero-only]
 *       [--grouped] [--limit N] [--format csv|json]
 *
 * Raw rows (newest first): ts, raw_q, normalized_q, had_vector, result_count,
 * latency_ms, did_you_mean, tier, cache_hit. --grouped: one row per distinct
 * normalized query: normalized_q, searches, last_seen. customer_id is never
 * exported. --since / --until are alternatives to --window (until is exclusive).
 * The file is UTF-8 without a BOM and is never written under server/public.
 * Exit 0 on success, 2 on a usage error, 1 on any other failure.
 */

declare(strict_types=1);

use App\Analytics;
use App\Db;
use App\LogsExport;

require __DIR__ . '/_php-guard.php'; // PHP 8.1+ check, before anything else runs

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$fail = static function (string $message, int $status = 2): never {
    fwrite(STDERR, "logs-export: {$message}\n");
    exit($status);
};

$options = getopt('', ['out:', 'window:', 'since:', 'until:', 'zero-only', 'grouped', 'limit:', 'format:']);
$opt = static function ($name) use ($options) {
    return is_string($options[$name] ?? null) ? $options[$name] : null;
};

$out = $opt('out');
if ($out === null || $out === '') {
    $fail('--out <path> is required (see the header of this file for the options)');
}
$format = $opt('format') ?? 'csv';
if (!in_array($format, ['csv', 'json'], true)) {
    $fail('--format must be csv or json');
}
$limit = $opt('limit');
if ($limit !== null && (!ctype_digit($limit))) {
    $fail('--limit must be a non-negative integer');
}
$window = $opt('window');
if ($window !== null && !array_key_exists($window, Analytics::WINDOWS)) {
    $fail('--window must be one of ' . implode(', ', array_keys(Analytics::WINDOWS)));
}
if ($window !== null && $opt('since') !== null) {
    $fail('give --window or --since, not both');
}
$times = [];
foreach (['since', 'until'] as $name) {
    $raw = $opt($name);
    $times[$name] = $raw === null ? null : LogsExport::parseTime($raw);
    if ($raw !== null && $times[$name] === null) {
        $fail("--{$name} must be YYYY-MM-DD or \"YYYY-MM-DD HH:MM:SS\"");
    }
}

// The file may hold shoppers' queries: keep it out of the document root.
$directory = realpath(dirname($out));
$public = realpath(dirname(__DIR__) . '/public');
if ($directory === false || !is_dir($directory)) {
    $fail('the directory of --out does not exist: ' . dirname($out));
}
if ($public !== false && ($directory === $public || str_starts_with($directory . '/', $public . '/'))) {
    $fail('refusing to write under server/public (the web root); choose another --out');
}
$target = $directory . '/' . basename($out);

try {
    $pdo = (new Db($config['db']))->pdo();
    $table = (string) $config['db']['search_logs_table'];
    $since = $times['since'] ?? ($window === null ? null : (new Analytics($pdo, $table))->since($window));
    $export = new LogsExport($pdo, $table);
    $grouped = isset($options['grouped']);
    $rows = $export->rows(
        $since,
        $times['until'],
        isset($options['zero-only']),
        (int) ($limit ?? 0),
        $grouped
    );
    $header = $export->header($grouped);

    $handle = fopen($target, 'wb');
    if ($handle === false) {
        $fail("cannot write {$target}", 1);
    }
    $count = 0;
    if ($format === 'csv') {
        fputcsv($handle, $header, ',', '"', '', "\n");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '', "\n");
            $count++;
        }
    } else {
        fwrite($handle, "[\n");
        foreach ($rows as $row) {
            $item = json_encode(array_combine($header, $row), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            fwrite($handle, ($count === 0 ? '  ' : ",\n  ") . $item);
            $count++;
        }
        fwrite($handle, ($count === 0 ? '' : "\n") . "]\n");
    }
    fclose($handle);
} catch (Throwable $e) {
    $fail('export failed: ' . $e->getMessage(), 1);
}

echo "wrote {$count} " . ($grouped ? 'distinct queries' : 'rows') . " to {$target}\n";
