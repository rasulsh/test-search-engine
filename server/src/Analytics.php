<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * Read-only aggregates over search_logs for public/analytics.php (M31). Every
 * query is a prepared statement bounded by a time window ($since, a DATETIME
 * string or null for all time), and none writes.
 *
 * Queries are grouped by their NORMALIZED form (the raw text when that is
 * empty): spelling variants that normalize alike merge, but cross-script aliases
 * ("far cry" and "فارکرای") stay separate. That is expected, and next to the alias
 * file it is itself a signal. A table from before M21 / M26 lacks did_you_mean,
 * tier or cache_hit; those figures then read as zero / keyword-only.
 */
final class Analytics
{
    /** Window label => SQL interval; null = all time. */
    public const WINDOWS = [
        '24h' => '24 HOUR',
        '7d'  => '7 DAY',
        '30d' => '30 DAY',
        '90d' => '90 DAY',
        'all' => null,
    ];

    private const QUERY = "COALESCE(NULLIF(normalized_q, ''), raw_q)";

    private PDO $pdo;
    private string $table;
    /** @var array<string, true>|null */
    private ?array $columns = null;

    public function __construct(PDO $pdo, string $searchLogsTable)
    {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
    }

    /** The window a request names, or the default (7d) for anything unknown. */
    public static function window(string $label): string
    {
        return array_key_exists($label, self::WINDOWS) ? $label : '7d';
    }

    /**
     * The lower bound of a window as a DATETIME string, from the database's own
     * clock (the one `ts` defaults to); null for "all".
     */
    public function since(string $window): ?string
    {
        $interval = self::WINDOWS[self::window($window)];
        if ($interval === null) {
            return null;
        }

        return (string) $this->pdo->query("SELECT NOW() - INTERVAL {$interval}")->fetchColumn();
    }

    /**
     * @return list<array{query: string, searches: int, last_seen: string}>
     */
    public function topQueries(?string $since, int $limit = 20): array
    {
        return $this->grouped($since, $limit, '');
    }

    /**
     * The gap list: queries that returned nothing, most frequent first.
     *
     * @return list<array{query: string, searches: int, last_seen: string}>
     */
    public function zeroResultQueries(?string $since, int $limit = 20): array
    {
        return $this->grouped($since, $limit, 'result_count = 0');
    }

    /**
     * @return array{total: int, hits: int, misses: int, hit_rate: float}
     */
    public function cacheStats(?string $since): array
    {
        $hits = $this->hasColumn('cache_hit') ? 'COALESCE(SUM(cache_hit = 1), 0)' : '0';
        $row = $this->one("SELECT COUNT(*) AS total, {$hits} AS hits FROM {$this->table}", $since);
        $total = (int) $row['total'];
        $hitCount = (int) $row['hits'];

        return [
            'total' => $total, 'hits' => $hitCount, 'misses' => $total - $hitCount,
            'hit_rate' => self::pct($hitCount, $total),
        ];
    }

    /**
     * @return array{total: int, keyword_only: int, hybrid: int, hybrid_share: float,
     *     with_vector: int, vector_share: float}
     */
    public function tierStats(?string $since): array
    {
        $hybrid = $this->hasColumn('tier') ? "COALESCE(SUM(tier = 'hybrid'), 0)" : '0';
        $row = $this->one(
            "SELECT COUNT(*) AS total, {$hybrid} AS hybrid, COALESCE(SUM(had_vector = 1), 0) AS vec"
            . " FROM {$this->table}",
            $since
        );
        $total = (int) $row['total'];

        return [
            'total' => $total, 'keyword_only' => $total - (int) $row['hybrid'], 'hybrid' => (int) $row['hybrid'],
            'hybrid_share' => self::pct((int) $row['hybrid'], $total),
            'with_vector' => (int) $row['vec'], 'vector_share' => self::pct((int) $row['vec'], $total),
        ];
    }

    /**
     * @return array{total: int, zero_results: int, zero_rate: float, avg_results: float}
     */
    public function resultHealth(?string $since): array
    {
        $row = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(result_count = 0), 0) AS zero,"
            . " COALESCE(AVG(result_count), 0) AS avg FROM {$this->table}",
            $since
        );
        $total = (int) $row['total'];

        return [
            'total' => $total, 'zero_results' => (int) $row['zero'],
            'zero_rate' => self::pct((int) $row['zero'], $total), 'avg_results' => round((float) $row['avg'], 1),
        ];
    }

    /**
     * Percentiles are nearest-rank over the ordered latencies (two cheap
     * LIMIT/OFFSET reads), cache hits included.
     *
     * @return array{count: int, avg: float, p50: int, p95: int, max: int}
     */
    public function latencyStats(?string $since): array
    {
        $row = $this->one(
            "SELECT COUNT(*) AS n, COALESCE(AVG(latency_ms), 0) AS avg, COALESCE(MAX(latency_ms), 0) AS max"
            . " FROM {$this->table}",
            $since
        );
        $n = (int) $row['n'];

        return [
            'count' => $n, 'avg' => round((float) $row['avg'], 1),
            'p50' => $this->percentile($since, $n, 0.50), 'p95' => $this->percentile($since, $n, 0.95),
            'max' => (int) $row['max'],
        ];
    }

    /**
     * @return array{total: int, suggested: int, share: float}
     */
    public function didYouMeanStats(?string $since): array
    {
        $suggested = $this->hasColumn('did_you_mean')
            ? "COALESCE(SUM(did_you_mean IS NOT NULL AND did_you_mean <> ''), 0)"
            : '0';
        $row = $this->one("SELECT COUNT(*) AS total, {$suggested} AS s FROM {$this->table}", $since);

        return [
            'total' => (int) $row['total'], 'suggested' => (int) $row['s'],
            'share' => self::pct((int) $row['s'], (int) $row['total']),
        ];
    }

    /**
     * Searches per day or hour, oldest first (the newest 366 buckets at most).
     *
     * @return list<array{bucket: string, searches: int}>
     */
    public function volume(?string $since, string $bucket = 'day'): array
    {
        $format = match ($bucket) {
            'day' => '%Y-%m-%d',
            'hour' => '%Y-%m-%d %H:00',
            default => throw new InvalidArgumentException("unknown bucket: {$bucket}"),
        };
        [$where, $params] = $this->bound($since, '');
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(ts, '{$format}') AS bucket, COUNT(*) AS searches FROM {$this->table}{$where}"
            . ' GROUP BY bucket ORDER BY bucket DESC LIMIT 366'
        );
        $stmt->execute($params);

        return array_reverse(array_map(
            static fn (array $r): array => ['bucket' => (string) $r['bucket'], 'searches' => (int) $r['searches']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    /**
     * Everything the dashboard and `?format=json` show, for one window.
     *
     * @return array<string, mixed>
     */
    public function report(string $window, int $limit = 20): array
    {
        $window = self::window($window);
        $since = $this->since($window);

        return [
            'window' => $window,
            'since' => $since,
            'summary' => $this->resultHealth($since),
            'cache' => $this->cacheStats($since),
            'tier' => $this->tierStats($since),
            'latency' => $this->latencyStats($since),
            'did_you_mean' => $this->didYouMeanStats($since),
            'top_queries' => $this->topQueries($since, $limit),
            'zero_result_queries' => $this->zeroResultQueries($since, $limit),
            'volume' => $this->volume($since, $window === '24h' ? 'hour' : 'day'),
        ];
    }

    /** @return list<array{query: string, searches: int, last_seen: string}> */
    private function grouped(?string $since, int $limit, string $condition): array
    {
        $limit = min(1000, max(1, $limit));
        [$where, $params] = $this->bound($since, $condition);
        $query = self::QUERY;
        $stmt = $this->pdo->prepare(
            "SELECT {$query} AS q, COUNT(*) AS searches, MAX(ts) AS last_seen FROM {$this->table}{$where}"
            . " GROUP BY q HAVING q <> '' ORDER BY searches DESC, last_seen DESC, q LIMIT {$limit}"
        );
        $stmt->execute($params);

        return array_map(
            static fn (array $r): array => [
                'query' => (string) $r['q'], 'searches' => (int) $r['searches'], 'last_seen' => (string) $r['last_seen'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function percentile(?string $since, int $n, float $p): int
    {
        if ($n === 0) {
            return 0;
        }
        $offset = max(0, (int) ceil($p * $n) - 1);
        [$where, $params] = $this->bound($since, '');
        $stmt = $this->pdo->prepare(
            "SELECT latency_ms FROM {$this->table}{$where} ORDER BY latency_ms LIMIT 1 OFFSET {$offset}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function one(string $selectFrom, ?string $since): array
    {
        [$where, $params] = $this->bound($since, '');
        $stmt = $this->pdo->prepare($selectFrom . $where);
        $stmt->execute($params);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{string, array<string, string>} WHERE clause and its parameters
     */
    private function bound(?string $since, string $condition): array
    {
        $conditions = $condition === '' ? [] : [$condition];
        $params = [];
        if ($since !== null) {
            $conditions[] = 'ts >= :since';
            $params['since'] = $since;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function hasColumn(string $name): bool
    {
        if ($this->columns === null) {
            $this->columns = [];
            foreach ($this->pdo->query("SHOW COLUMNS FROM {$this->table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->columns[(string) $row['Field']] = true;
            }
        }

        return isset($this->columns[$name]);
    }

    private static function pct(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 1);
    }
}
