<?php

/**
 * VPS probe CLI: one GET /health to the VPS, recorded in vps_calls as kind=probe, for a once-a-minute
 * cPanel cron (docs/DEPLOY.md). Separates "the VPS or the network path is down" (probes fail too) from
 * "the VPS is slow under search load" (probes stay fast while searches time out).
 *
 *   php server/tools/vps-probe.php [--quiet]
 *
 * Prints one line (outcome, HTTP status, milliseconds) unless --quiet. Exit 0 when the VPS answered
 * healthy, 1 when it did not (timeout, unreachable, bad answer) or vps.url is empty, 2 on a usage error.
 * A recording problem (for instance the vps_calls migration is missing) never changes the exit code.
 */

declare(strict_types=1);

use App\AdminVps;
use App\Db;
use App\VpsClient;

require __DIR__ . '/_php-guard.php'; // PHP 8.1+ check, before anything else runs

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['quiet', 'help']);
if (isset($options['help']) || count($argv) > 1 + count($options)) {
    fwrite(STDERR, "usage: php vps-probe.php [--quiet]\n");
    exit(2);
}

try {
    $pdo = (new Db($config['db']))->pdo();
    $result = AdminVps::probeWith(VpsClient::fromConfig($config['vps']), $pdo, $config);
} catch (Throwable $e) {
    fwrite(STDERR, 'vps-probe: failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!isset($options['quiet'])) {
    echo $result['outcome'] . ' http=' . $result['status'] . ' ms=' . ($result['duration_ms'] ?? '-')
        . ($result['error'] === '' ? '' : ' error=' . $result['error']) . "\n";
}
exit($result['ok'] ? 0 : 1);
