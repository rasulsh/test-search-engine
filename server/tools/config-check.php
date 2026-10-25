<?php

/**
 * Config doctor CLI (M29): checks config.php against the code's schema.
 *
 *   php server/tools/config-check.php [--verbose] [--file=<path>]
 *
 * ERROR (a required key missing or empty, a value of the wrong type) makes the
 * exit status 1, so deploy scripts and CI can gate on it. WARN flags unknown keys
 * and half-configured features. Without --verbose it prints key paths only. --verbose
 * lists every known key as `key = value [config.php|code default]`; credentials show as
 * `set` / `empty` and keys the schema does not know are not listed (they are WARNed above).
 */

declare(strict_types=1);

use App\Config;
use App\ConfigDoctor;
use App\ConfigReport;

require __DIR__ . '/_php-guard.php'; // PHP 8.1+ check, before anything else runs

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

// The autoloader only; the doctor reads the raw overrides itself.
require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['verbose', 'file:']);
$file = is_string($options['file'] ?? null) ? $options['file'] : Config::file();
if (!is_file($file)) {
    echo "No config file at {$file}: every tunable uses its code default.\n";
}

$overrides = Config::overrides($file);
$report = ConfigDoctor::check($overrides);
foreach ($report['errors'] as $line) {
    echo "ERROR  {$line}\n";
}
foreach ($report['warnings'] as $line) {
    echo "WARN   {$line}\n";
}
if (isset($options['verbose'])) {
    foreach (ConfigReport::rows($overrides) as $row) {
        if ($row['state'] === 'hidden') {
            continue;
        }
        $value = $row['state'] === 'value' ? ($row['value'] === '' ? "''" : $row['value']) : $row['state'];
        $source = $row['source'] === ConfigReport::SOURCE_FILE ? 'config.php' : 'code default';
        echo "INFO   {$row['key']} = {$value} [{$source}]\n";
    }
}
printf(
    "%d error(s), %d warning(s)%s\n",
    count($report['errors']),
    count($report['warnings']),
    $report['errors'] === [] && $report['warnings'] === [] ? ': config is clean' : ''
);
exit($report['errors'] === [] ? 0 : 1);
