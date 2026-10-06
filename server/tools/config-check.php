<?php

/**
 * Config doctor CLI (M29): checks config.php against the code's schema.
 *
 *   php server/tools/config-check.php [--verbose] [--file=<path>]
 *
 * ERROR (a required key missing or empty, a value of the wrong type) makes the
 * exit status 1, so deploy scripts and CI can gate on it. WARN flags unknown keys
 * and half-configured features; --verbose adds the tunables still at their code
 * default. Prints key paths only, never values.
 */

declare(strict_types=1);

use App\Config;
use App\ConfigDoctor;

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

$report = ConfigDoctor::check(Config::overrides($file));
foreach ($report['errors'] as $line) {
    echo "ERROR  {$line}\n";
}
foreach ($report['warnings'] as $line) {
    echo "WARN   {$line}\n";
}
if (isset($options['verbose'])) {
    foreach ($report['info'] as $line) {
        echo "INFO   {$line}\n";
    }
}
printf(
    "%d error(s), %d warning(s)%s\n",
    count($report['errors']),
    count($report['warnings']),
    $report['errors'] === [] && $report['warnings'] === [] ? ': config is clean' : ''
);
exit($report['errors'] === [] ? 0 : 1);
