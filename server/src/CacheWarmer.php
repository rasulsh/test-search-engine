<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Throwable;

/**
 * Pre-fills the result cache for a list of queries (tools/warm-cache.php). Sequential and gentle: a
 * pause after each query that reached the VPS, a stop after too many consecutive VPS failures, one
 * retry of the degraded queries at the end. The search itself is SearchController::warm(), so a
 * cached answer is exactly the one a shopper asking the same thing would be served.
 */
final class CacheWarmer
{
    private SearchController $controller;
    private int $limit;
    private int $sleepMs;
    private int $maxFailures;
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param int $limit the storefront's depth (GoldenEval::STOREFRONT_DEPTH): part of the cache key
     * @param int $maxFailures consecutive VPS failures that stop the run; 0 never stops
     * @param null|callable(int): void $sleep pauses for that many milliseconds (tests replace it)
     */
    public function __construct(
        SearchController $controller,
        int $limit,
        int $sleepMs,
        int $maxFailures,
        ?callable $sleep = null
    ) {
        $this->controller = $controller;
        $this->limit = $limit;
        $this->sleepMs = max(0, $sleepMs);
        $this->maxFailures = max(0, $maxFailures);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * The `normalized_q` column of a grouped logs-export CSV, in file order.
     *
     * @return list<string>
     * @throws InvalidArgumentException when the file cannot be read or has no such column
     */
    public static function readQueryFile(string $path): array
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new InvalidArgumentException("cannot read {$path}");
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (is_array($header) && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            $column = is_array($header) ? array_search('normalized_q', $header, true) : false;
            if ($column === false) {
                throw new InvalidArgumentException("{$path} has no normalized_q column (a logs-export --grouped file does)");
            }
            $queries = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $query = trim((string) ($row[$column] ?? ''));
                if ($query !== '') {
                    $queries[] = $query;
                }
            }

            return $queries;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The given lists joined in order, each query once (by its normalized form).
     *
     * @param list<string> ...$lists
     * @return list<string>
     */
    public static function unique(array ...$lists): array
    {
        $seen = [];
        $queries = [];
        foreach ($lists as $list) {
            foreach ($list as $query) {
                $key = Normalizer::normalize($query);
                if ($key !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $queries[] = $query;
                }
            }
        }

        return $queries;
    }

    /**
     * What a run would do, without searching or writing anything.
     *
     * @param list<string> $queries
     * @return list<array{query: string, cached: bool}>
     */
    public function plan(array $queries, bool $force): array
    {
        return array_map(fn (string $query): array => [
            'query' => $query,
            'cached' => !$force && $this->controller->isCached($query, $this->limit),
        ], $queries);
    }

    /**
     * @param list<string> $queries
     * @param bool $force recompute queries that are already cached
     * @param null|callable(string): void $report one progress / problem line at a time
     * @return array{warmed: int, cached: int, degraded: int, failed: int, not_attempted: int, stopped: bool,
     *     seconds: float, degraded_queries: list<string>}
     */
    public function run(array $queries, bool $force, ?callable $report = null): array
    {
        $report ??= static function (string $line): void {
        };
        $started = microtime(true);
        $summary = ['warmed' => 0, 'cached' => 0, 'degraded' => 0, 'failed' => 0, 'not_attempted' => 0,
            'stopped' => false, 'seconds' => 0.0, 'degraded_queries' => []];
        $degraded = [];
        $streak = 0;
        $total = count($queries);

        foreach ($queries as $i => $query) {
            $outcome = $this->warmOne($query, $force, $report);
            if ($outcome['outcome'] === SearchController::WARM_STORED) {
                $summary['warmed']++;
            } elseif ($outcome['outcome'] === SearchController::WARM_CACHED) {
                $summary['cached']++;
            } elseif ($outcome['outcome'] === SearchController::WARM_DEGRADED) {
                $degraded[] = $query;
                $report("degraded (VPS did not answer, not cached): {$query}");
            } else {
                $summary['failed']++;
            }

            if ($outcome['vps']) {
                $streak = $outcome['outcome'] === SearchController::WARM_DEGRADED ? $streak + 1 : 0;
                if ($this->sleepMs > 0) {
                    ($this->sleep)($this->sleepMs);
                }
            }
            if (($i + 1) % 50 === 0) {
                $report(sprintf(
                    'progress %d/%d: warmed %d, already cached %d, degraded %d, failed %d',
                    $i + 1,
                    $total,
                    $summary['warmed'],
                    $summary['cached'],
                    count($degraded),
                    $summary['failed']
                ));
            }
            if ($this->maxFailures > 0 && $streak >= $this->maxFailures) {
                $summary['stopped'] = true;
                $summary['not_attempted'] = $total - $i - 1;
                $report("stopping after {$streak} consecutive VPS failures; check the VPS (admin panel VPS page) and run again");
                break;
            }
        }

        if (!$summary['stopped'] && $degraded !== []) {
            $report('retrying ' . count($degraded) . ' degraded queries once');
            foreach ($degraded as $query) {
                $outcome = $this->warmOne($query, true, $report);
                if ($outcome['outcome'] === SearchController::WARM_STORED) {
                    $summary['warmed']++;
                } else {
                    $summary['degraded']++;
                    $summary['degraded_queries'][] = $query;
                }
                if ($outcome['vps'] && $this->sleepMs > 0) {
                    ($this->sleep)($this->sleepMs);
                }
            }
        } else {
            $summary['degraded'] = count($degraded);
            $summary['degraded_queries'] = $degraded;
        }
        $summary['seconds'] = round(microtime(true) - $started, 1);

        return $summary;
    }

    /**
     * @param callable(string): void $report
     * @return array{outcome: string, vps: bool}
     */
    private function warmOne(string $query, bool $force, callable $report): array
    {
        try {
            return $this->controller->warm($query, $this->limit, $force);
        } catch (Throwable $e) {
            $report("failed: {$query}: " . $e->getMessage());

            return ['outcome' => SearchController::WARM_UNCACHED, 'vps' => false];
        }
    }
}
