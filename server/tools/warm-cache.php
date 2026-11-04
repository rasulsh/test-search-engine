<?php

/**
 * Result-cache warm-up CLI: pre-fills the Redis cache for the most searched queries after a deploy,
 * a bundle reload, a cache flush or a VPS vector reload, so shoppers do not pay the miss
 * (docs/DEPLOY.md, "Warming the result cache").
 *
 *   php server/tools/warm-cache.php [--n 500] [--window 30d] [--queries file.csv] [--force]
 *       [--sleep-ms 250] [--max-failures 5] [--dry-run] [--if-pending [--quiet]]
 *
 * Queries: the top --n distinct normalized queries of the last --window (24h|7d|30d|90d|all; same
 * rows the analytics count: no suspicious, duplicate, burst or paging rows; at most 1000), then the
 * `normalized_q` column of --queries (a `logs-export.php --grouped` file), each query once.
 * Each goes through the normal search at the storefront depth, so the cache key is the one a shopper
 * hits. No search_logs row is written. A query already cached is skipped unless --force
 * (--only-missing is the default). Sequential: --sleep-ms after each query that reached the VPS;
 * the run stops after --max-failures consecutive VPS failures (0 = never). A degraded result (the
 * VPS timed out) is never cached; those queries are retried once at the end. --dry-run lists what
 * would be done and changes nothing. A lock file in storage/ keeps two runs from overlapping.
 *
 * --if-pending is the every-minute cron mode: with no storage/warm-cache.pending flag it exits 0 at once
 * and silently (no database, no Redis). With a flag and a free lock it removes the flag as the run starts (a
 * request made during the run schedules the next one), runs with --n defaulting to `warm.n`, and writes
 * storage/warm-cache.status.json. Redis down or the lock held leaves the flag for the next minute; after 30
 * minutes the flag is dropped and the status records the failure. --quiet prints nothing.
 *
 * Exit 0 when everything is cached, 1 when queries stayed degraded or failed, the run stopped, Redis
 * is off or unreachable, or another run holds the lock, 2 on a usage error.
 */

declare(strict_types=1);

use App\Analytics;
use App\Cache;
use App\CacheWarmer;
use App\Db;
use App\GoldenEval;
use App\SearchController;
use App\WarmQueue;

require __DIR__ . '/_php-guard.php'; // PHP 8.1+ and extension check, before anything else runs

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$fail = static function (string $message, int $status = 2) use (&$quiet): never {
    if (!$quiet) {
        fwrite(STDERR, "warm-cache: {$message}\n");
    }
    exit($status);
};

$options = getopt('', [
    'n:', 'window:', 'queries:', 'force', 'only-missing', 'sleep-ms:', 'max-failures:', 'dry-run',
    'if-pending', 'quiet',
]);
$quiet = isset($options['quiet']);
$ifPending = isset($options['if-pending']);
$queue = WarmQueue::fromConfig($config);
$pending = null;
if ($ifPending) {
    // The only work a flagless minute does: one is_file().
    if (!$queue->hasFlag()) {
        exit(0);
    }
    $pending = $queue->pending() ?? ['requested_at' => gmdate('c'), 'reasons' => []];
}
$opt = static function ($name) use ($options) {
    return is_string($options[$name] ?? null) ? $options[$name] : null;
};
$number = static function ($name, $default, $min) use ($opt, $fail) {
    $raw = $opt($name);
    if ($raw === null) {
        return $default;
    }
    if (!ctype_digit($raw) || (int) $raw < $min) {
        $fail("--{$name} must be an integer of at least {$min}");
    }

    return (int) $raw;
};

$n = $number('n', (int) $config['warm']['n'], 1);
if ($n > 1000) {
    $fail('--n is at most 1000 (the analytics cap)');
}
$sleepMs = $number('sleep-ms', 250, 0);
$maxFailures = $number('max-failures', 5, 0);
$window = $opt('window') ?? '30d';
if (!array_key_exists($window, Analytics::WINDOWS)) {
    $fail('--window must be one of ' . implode(', ', array_keys(Analytics::WINDOWS)));
}
if (isset($options['force']) && isset($options['only-missing'])) {
    $fail('give --force or --only-missing, not both');
}
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);
$file = $opt('queries');
if (isset($options['queries']) && ($file === null || $file === '')) {
    $fail('--queries needs a file path');
}

$say = static function ($line) use (&$quiet) {
    if (!$quiet) {
        echo $line . "\n";
    }
};

// A pending run that cannot start is retried every minute; past 30 minutes the flag is dropped and the
// failure recorded, so a dead Redis cannot make the cron job retry for ever.
$cannotStart = static function (string $why, string $message) use ($queue, $pending, $fail): never {
    if ($pending !== null) {
        if ($queue->age($pending) > WarmQueue::GIVE_UP_SECONDS) {
            $queue->clear();
            $now = gmdate('c');
            $queue->writeStatus([
                'started_at' => $now, 'finished_at' => $now, 'queries' => 0, 'warmed' => 0, 'already_cached' => 0,
                'degraded' => 0, 'failed' => 0, 'stopped_early' => false, 'exit_code' => 1,
                'reasons' => $pending['reasons'], 'error' => $why,
            ]);
            $fail("gave up after 30 minutes: {$message}", 1);
        }
        $fail($message . '; the request stays pending', 1);
    }
    $fail($message, 1);
};

$startedAt = null;
$reasons = [];
try {
    $fromFile = [];
    if ($file !== null) {
        try {
            $fromFile = CacheWarmer::readQueryFile($file);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }

    if (!$dryRun) {
        // Held until the process ends; the OS drops it if the process dies.
        $lockPath = rtrim((string) $config['paths']['storage'], '/') . '/' . WarmQueue::LOCK_FILE;
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            $cannotStart('error', "cannot create the lock file {$lockPath} (is storage/ writable?)");
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $cannotStart('lock', 'another warm-up is running (lock ' . $lockPath . '); not starting a second one');
        }
    }
    if (!(bool) $config['redis']['enabled']) {
        $cannotStart('redis', 'the result cache is off (redis.enabled); there is nothing to warm');
    }
    $cache = Cache::fromConfig($config);
    if ($cache === null || !$cache->ping()) {
        $cannotStart('redis', 'Redis does not answer (redis.host / port / auth); nothing was warmed');
    }
    $pdo = (new Db($config['db']))->pdo();
    $controller = SearchController::fromConfig($pdo, $config, null, null, true, false);

    $analytics = new Analytics($pdo, (string) $config['db']['search_logs_table']);
    $logged = array_column($analytics->topQueries($analytics->since($window), $n), 'query');
    $queries = CacheWarmer::unique($logged, $fromFile);
    $warmer = new CacheWarmer($controller, GoldenEval::STOREFRONT_DEPTH, $sleepMs, $maxFailures);

    if ($dryRun) {
        $plan = $warmer->plan($queries, $force);
        $todo = 0;
        foreach ($plan as $item) {
            $say(($item['cached'] ? 'cached   ' : 'would warm') . ' ' . $item['query']);
            $todo += $item['cached'] ? 0 : 1;
        }
        $say(sprintf(
            'dry run: %d queries (%d from the log, %d from the file), %d to warm, %d already cached',
            count($queries),
            count($logged),
            count($fromFile),
            $todo,
            count($queries) - $todo
        ));
        exit(0);
    }

    // The run has started: drop the flag now, so a request made meanwhile schedules another run.
    $startedAt = gmdate('c');
    if ($pending !== null) {
        $queue->clear();
    }
    $reasons = $pending['reasons'] ?? [];
    $queue->writeStatus([
        'started_at' => $startedAt, 'finished_at' => null, 'queries' => count($queries), 'reasons' => $reasons,
    ]);

    $say(sprintf(
        'warming %d queries (%d from the log, %d from the file), depth %d%s',
        count($queries),
        count($logged),
        count($fromFile),
        GoldenEval::STOREFRONT_DEPTH,
        $force ? ', forced' : ''
    ));
    $summary = $warmer->run($queries, $force, $say);
} catch (Throwable $e) {
    if ($startedAt === null) {
        $cannotStart('error', 'failed before starting: ' . $e->getMessage());
    }
    $queue->writeStatus([
        'started_at' => $startedAt, 'finished_at' => gmdate('c'), 'queries' => 0, 'warmed' => 0, 'already_cached' => 0,
        'degraded' => 0, 'failed' => 0, 'stopped_early' => true, 'exit_code' => 1, 'reasons' => $reasons,
        'error' => 'exception',
    ]);
    $fail('failed: ' . $e->getMessage(), 1);
}

$exitCode = $summary['stopped'] || $summary['degraded'] > 0 || $summary['failed'] > 0 ? 1 : 0;
$queue->writeStatus([
    'started_at' => $startedAt, 'finished_at' => gmdate('c'), 'queries' => count($queries),
    'warmed' => $summary['warmed'], 'already_cached' => $summary['cached'], 'degraded' => $summary['degraded'],
    'failed' => $summary['failed'], 'stopped_early' => $summary['stopped'], 'exit_code' => $exitCode,
    'reasons' => $reasons,
]);

$say(sprintf(
    'done: warmed %d, already cached %d, degraded %d, failed %d%s in %s s',
    $summary['warmed'],
    $summary['cached'],
    $summary['degraded'],
    $summary['failed'],
    $summary['stopped'] ? ', not attempted ' . $summary['not_attempted'] : '',
    $summary['seconds']
));
foreach ($summary['degraded_queries'] as $query) {
    $say('still degraded: ' . $query);
}
exit($exitCode);
