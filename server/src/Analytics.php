<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDOException;
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
 * tier or cache_hit; those figures then read as zero / keyword-only. Flagged probes
 * (is_suspicious) count nowhere. The log-v2 metrics live in AnalyticsInsights and
 * are merged in by report().
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

    /** Trend charts read at most this many daily buckets, so "all" never scans years of logs. */
    private const TREND_DAYS = 366;
    // Fewer unique searches than this in a bucket and its rate is mostly noise.
    private const LOW_CONFIDENCE_N = 30;
    private const MOVING_AVERAGE_BUCKETS = 7;

    private PDO $pdo;
    private string $table;
    private ?string $productsTable;
    private AnalyticsInsights $insights;
    /** @var array<string, true>|null */
    private ?array $columns = null;

    /**
     * @param string|null $productsTable the catalog table topBrandsCategories() reads; null = no brand figures
     * @param string $searchEventsTable the click / dym_click events the engagement figures read
     */
    public function __construct(
        PDO $pdo,
        string $searchLogsTable,
        ?string $productsTable = null,
        string $searchEventsTable = 'search_events'
    ) {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
        $this->productsTable = $productsTable === null ? null : Identifier::quote($productsTable);
        $this->insights = new AnalyticsInsights($pdo, $searchLogsTable, $searchEventsTable, $productsTable);
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
        $format = self::bucketFormat($bucket);
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
     * Zero-result rate per bucket, oldest first (the trend line). Only unique searches count
     * (not repeated submits, not paging requests): duplicates and bursts of the same gibberish
     * query made the line jump. `n` is the bucket's sample size, `low_confidence` marks fewer
     * than LOW_CONFIDENCE_N of them, `partial` the still-running last bucket, and `zero_rate_ma`
     * the rate pooled over the last MOVING_AVERAGE_BUCKETS buckets (zero results / searches, so
     * a busy bucket weighs more than a quiet one).
     *
     * @return list<array{bucket: string, n: int, zero_results: int, zero_rate: float, zero_rate_ma: float,
     *     low_confidence: bool, partial: bool}>
     */
    public function zeroRateTrend(?string $since, string $bucket = 'day'): array
    {
        $format = self::bucketFormat($bucket);
        [$where, $params] = $this->bound(
            $since,
            $bucket === 'day' ? 'ts >= NOW() - INTERVAL ' . self::TREND_DAYS . ' DAY' : ''
        );
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(ts, '{$format}') AS bucket, COUNT(*) AS n,"
            . " COALESCE(SUM(result_count = 0), 0) AS zero FROM {$this->table}{$where}"
            . ' GROUP BY bucket ORDER BY bucket DESC LIMIT ' . self::TREND_DAYS
        );
        $stmt->execute($params);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($rows === []) {
            return [];
        }
        $current = (string) $this->pdo->query("SELECT DATE_FORMAT(NOW(), '{$format}')")->fetchColumn();

        $trend = [];
        foreach ($rows as $i => $r) {
            $n = (int) $r['n'];
            $zero = (int) $r['zero'];
            $first = max(0, $i - self::MOVING_AVERAGE_BUCKETS + 1);
            $window = array_slice($rows, $first, $i - $first + 1);
            $windowZero = (int) array_sum(array_column($window, 'zero'));
            $windowN = (int) array_sum(array_column($window, 'n'));
            $trend[] = [
                'bucket' => (string) $r['bucket'], 'n' => $n, 'zero_results' => $zero,
                'zero_rate' => self::pct($zero, $n),
                'zero_rate_ma' => self::pct($windowZero, $windowN),
                'low_confidence' => $n < self::LOW_CONFIDENCE_N,
                'partial' => $i === count($rows) - 1 && (string) $r['bucket'] === $current,
            ];
        }

        return $trend;
    }

    /**
     * Average and nearest-rank p95 latency per bucket, oldest first. The p95
     * uses window functions (MariaDB 10.2+ / MySQL 8) over the window's rows. A
     * database without them (or any error here) yields an empty series, logged
     * once, so this one chart can never take the whole dashboard down.
     *
     * @return list<array{bucket: string, searches: int, avg: float, p95: int}>
     */
    public function latencyTrend(?string $since, string $bucket = 'day'): array
    {
        $format = self::bucketFormat($bucket);
        [$where, $params] = $this->trendBound($since, $bucket);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT bucket, COUNT(*) AS searches, AVG(latency_ms) AS avg,'
                . ' MIN(CASE WHEN rn >= CEIL(0.95 * cnt) THEN latency_ms END) AS p95 FROM ('
                . 'SELECT bucket, latency_ms, ROW_NUMBER() OVER (PARTITION BY bucket ORDER BY latency_ms) AS rn,'
                . ' COUNT(*) OVER (PARTITION BY bucket) AS cnt FROM ('
                . "SELECT DATE_FORMAT(ts, '{$format}') AS bucket, latency_ms FROM {$this->table}{$where}"
                . ') AS b) AS ranked GROUP BY bucket ORDER BY bucket DESC LIMIT ' . self::TREND_DAYS
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('analytics: latency trend unavailable (window functions needed): ' . $e->getMessage());

            return [];
        }

        return array_reverse(array_map(
            static fn (array $r): array => [
                'bucket' => (string) $r['bucket'], 'searches' => (int) $r['searches'],
                'avg' => round((float) $r['avg'], 1), 'p95' => (int) $r['p95'],
            ],
            $rows
        ));
    }

    /**
     * Searches by weekday and hour of day (database clock); only cells with
     * searches are returned. weekday: 0 = Monday .. 6 = Sunday.
     *
     * @return list<array{weekday: int, hour: int, searches: int}>
     */
    public function hourlyHeatmap(?string $since): array
    {
        [$where, $params] = $this->bound($since, '');
        $stmt = $this->pdo->prepare(
            "SELECT WEEKDAY(ts) AS d, HOUR(ts) AS h, COUNT(*) AS searches FROM {$this->table}{$where}"
            . ' GROUP BY d, h ORDER BY d, h'
        );
        $stmt->execute($params);

        return array_map(
            static fn (array $r): array => [
                'weekday' => (int) $r['d'], 'hour' => (int) $r['h'], 'searches' => (int) $r['searches'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Histogram of query length in words; always the five buckets 1..5, where 5 means "5 or more".
     *
     * @return list<array{tokens: int, searches: int}>
     */
    public function queryLengthDistribution(?string $since): array
    {
        $query = 'TRIM(' . self::QUERY . ')';
        [$where, $params] = $this->bound($since, "{$query} <> ''");
        $stmt = $this->pdo->prepare(
            "SELECT LEAST(5, 1 + CHAR_LENGTH({$query}) - CHAR_LENGTH(REPLACE({$query}, ' ', ''))) AS n,"
            . " COUNT(*) AS searches FROM {$this->table}{$where} GROUP BY n"
        );
        $stmt->execute($params);
        $counts = array_fill(1, 5, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['n']] = (int) $row['searches'];
        }

        $histogram = [];
        foreach ($counts as $tokens => $searches) {
            $histogram[] = ['tokens' => $tokens, 'searches' => $searches];
        }

        return $histogram;
    }

    /**
     * The most searched catalog brands and categories: a search counts for a
     * brand / category when every word of its normalized name is a word of the
     * (normalized) query. Matching is per distinct query (the 1000 most
     * frequent), in PHP, against the catalog's distinct names. Names in a
     * script the shoppers do not type (Latin brand, Persian query) are only
     * counted where the query is written like the catalog.
     *
     * @return array{brands: list<array{name: string, searches: int}>,
     *     categories: list<array{name: string, searches: int}>}
     */
    public function topBrandsCategories(?string $since, int $limit = 10): array
    {
        $empty = ['brands' => [], 'categories' => []];
        if ($this->productsTable === null) {
            return $empty;
        }
        $queries = [];
        foreach ($this->grouped($since, 1000, '') as $row) {
            $queries[] = [array_flip(Tokenizer::split($row['query'])), $row['searches']];
        }
        if ($queries === []) {
            return $empty;
        }

        return [
            'brands' => $this->rank('normalized_brand', $queries, $limit),
            'categories' => $this->rank('normalized_category', $queries, $limit),
        ];
    }

    /**
     * Share of queries searched more than once in the window: `rate` over the
     * distinct queries, `search_share` over all searches.
     *
     * @return array{distinct: int, repeated: int, rate: float, searches: int, repeated_searches: int,
     *     search_share: float}
     */
    public function repeatQueryRate(?string $since): array
    {
        [$where, $params] = $this->bound($since, '');
        $query = self::QUERY;
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS d, COALESCE(SUM(c > 1), 0) AS r, COALESCE(SUM(c), 0) AS s,'
            . ' COALESCE(SUM(CASE WHEN c > 1 THEN c ELSE 0 END), 0) AS rs FROM ('
            . "SELECT {$query} AS q, COUNT(*) AS c FROM {$this->table}{$where} GROUP BY q HAVING q <> ''"
            . ') AS grouped'
        );
        $stmt->execute($params);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'distinct' => (int) $row['d'], 'repeated' => (int) $row['r'],
            'rate' => self::pct((int) $row['r'], (int) $row['d']),
            'searches' => (int) $row['s'], 'repeated_searches' => (int) $row['rs'],
            'search_share' => self::pct((int) $row['rs'], (int) $row['s']),
        ];
    }

    /**
     * Everything the dashboard and `?format=json` show, for one window. The log-v2 keys
     * (see AnalyticsInsights) are added beside the older ones; `$compare` adds `compare`, the
     * headline figures against the previous window of the same length (not for "all").
     *
     * @return array<string, mixed>
     */
    public function report(string $window, int $limit = 20, bool $compare = false): array
    {
        $window = self::window($window);
        $since = $this->since($window);
        $bucket = $window === '24h' ? 'hour' : 'day';
        $previous = $this->previousSince($since, $window);

        $report = [
            'window' => $window,
            'since' => $since,
            'summary' => $this->resultHealth($since),
            'cache' => $this->cacheStats($since),
            'tier' => $this->tierStats($since),
            'latency' => $this->latencyStats($since),
            'did_you_mean' => $this->didYouMeanStats($since),
            'top_queries' => $this->topQueries($since, $limit),
            'zero_result_queries' => $this->zeroResultQueries($since, $limit),
            'volume' => $this->volume($since, $bucket),
            'zero_rate_trend' => $this->zeroRateTrend($since, $bucket),
            'latency_trend' => $this->latencyTrend($since, $bucket),
            'hourly_heatmap' => $this->hourlyHeatmap($since),
            'query_length' => $this->queryLengthDistribution($since),
            'top_brands_categories' => $this->topBrandsCategories($since, 10),
            'repeat_queries' => $this->repeatQueryRate($since),
            'requests' => $this->requestsVsSearches($since),
        ];
        $insights = $this->insights->report($since, null, $previous, $limit);
        $report['did_you_mean'] += $insights['did_you_mean'] ?? [];
        unset($insights['did_you_mean']);
        $report += $insights;

        if ($compare && $since !== null && $previous !== null && $this->insights->available()) {
            $report['compare'] = [
                'previous_since' => $previous,
                'previous_until' => $since,
                'metrics' => AnalyticsInsights::compare(
                    $this->insights->headline($since, null),
                    $this->insights->headline($previous, $since)
                ),
            ];
        }

        return $report;
    }

    /** The start of the window before $since that has the same length, or null for "all". */
    private function previousSince(?string $since, string $window): ?string
    {
        $interval = self::WINDOWS[$window] ?? null;
        if ($since === null || $interval === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT CAST(:since AS DATETIME) - INTERVAL {$interval}");
        $stmt->execute(['since' => $since]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<array{0: array<string, int>, 1: int}> $queries [word set, searches] per distinct query
     * @return list<array{name: string, searches: int}>
     */
    private function rank(string $column, array $queries, int $limit): array
    {
        try {
            $names = $this->pdo->query(
                "SELECT DISTINCT {$column} FROM {$this->productsTable} WHERE {$column} <> '' LIMIT 5000"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            return []; // a catalog without the column: no figures rather than a failed page
        }
        $counts = [];
        foreach ($names as $name) {
            $words = Tokenizer::split((string) $name);
            if ($words === []) {
                continue;
            }
            foreach ($queries as [$set, $searches]) {
                foreach ($words as $word) {
                    if (!isset($set[$word])) {
                        continue 2;
                    }
                }
                $counts[(string) $name] = ($counts[(string) $name] ?? 0) + $searches;
            }
        }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, max(1, $limit), true) as $name => $searches) {
            $top[] = ['name' => (string) $name, 'searches' => $searches];
        }

        return $top;
    }

    private static function bucketFormat(string $bucket): string
    {
        return match ($bucket) {
            'day' => '%Y-%m-%d',
            'hour' => '%Y-%m-%d %H:00',
            default => throw new InvalidArgumentException("unknown bucket: {$bucket}"),
        };
    }

    /**
     * The window bound plus, for daily trends, the TREND_DAYS cap.
     *
     * @return array{string, array<string, string>}
     */
    private function trendBound(?string $since, string $bucket): array
    {
        return $this->bound(
            $since,
            $bucket === 'day' ? 'ts >= NOW() - INTERVAL ' . self::TREND_DAYS . ' DAY' : ''
        );
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
                'query' => (string) $r['q'],
                'searches' => (int) $r['searches'],
                'last_seen' => (string) $r['last_seen'],
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
        if ($this->hasColumn('is_suspicious')) {
            $conditions[] = 'is_suspicious = 0';
        }
        array_push($conditions, ...SearchRows::conditions($this->hasColumn(...)));
        $params = [];
        if ($since !== null) {
            $conditions[] = 'ts >= :since';
            $params['since'] = $since;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Log rows of the window against the rows counted as searches (see SearchRows).
     *
     * @return array{requests: int, searches: int, filtered: int}
     */
    public function requestsVsSearches(?string $since): array
    {
        $params = $since === null ? [] : ['since' => $since];
        $time = $since === null ? '' : ' WHERE ts >= :since';
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table}{$time}");
        $stmt->execute($params);
        $requests = (int) $stmt->fetchColumn();
        [$where, $bound] = $this->bound($since, '');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table}{$where}");
        $stmt->execute($bound);
        $searches = (int) $stmt->fetchColumn();

        return ['requests' => $requests, 'searches' => $searches, 'filtered' => max(0, $requests - $searches)];
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
