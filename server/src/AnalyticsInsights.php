<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * The log-v2 metrics of public/analytics.php, beside the older ones in Analytics:
 * unique searches and sessions, zero-result rate, engagement (clicks
 * from search_events), cold-path latency per stage, the hybrid-share timeline,
 * suggestion quality, alias candidates, silent failures, demand gaps, trending
 * queries and flagged probes.
 *
 * Rules: a metric that needs a v2 column reads only log_version = 2 rows (older rows
 * stay in the plain counts); flagged probes (is_suspicious) are left out everywhere
 * and repeated submits (is_duplicate) out of every "unique" metric. Every query is a
 * prepared statement over a [from, to) range. A table or events table that has not
 * been migrated yields `available: false`, never an error.
 */
final class AnalyticsInsights
{
    /** A query needs this many unique searches before its click-through says anything. */
    private const MIN_SILENT_SEARCHES = 5;
    /** A reformulation within this many seconds of a zero-result search is its "follow-up". */
    private const FOLLOW_UP_SECONDS = 60;
    /** Click-through at or below this share (percent) of a frequent query's searches is "silent". */
    private const SILENT_CTR_MAX = 2.0;

    private const QUERY = "COALESCE(NULLIF(normalized_q, ''), raw_q)";

    private PDO $pdo;
    private string $logs;
    private string $events;
    private ?string $products;
    /** @var array<string, true>|null */
    private ?array $columns = null;

    /** @param string|null $productsTable the catalog table the silent-failure rows read their first result's title from */
    public function __construct(PDO $pdo, string $searchLogsTable, string $searchEventsTable, ?string $productsTable = null)
    {
        $this->pdo = $pdo;
        $this->logs = Identifier::quote($searchLogsTable);
        $this->events = Identifier::quote($searchEventsTable);
        $this->products = $productsTable === null ? null : Identifier::quote($productsTable);
    }

    /** True when the log table has the v2 columns (the migration, or the Logger, added them). */
    public function available(): bool
    {
        return $this->hasColumn('log_version') && $this->hasColumn('is_duplicate');
    }

    /**
     * @return array<string, mixed> the new report keys for the range [$from, $to) ($to null = now)
     */
    public function report(?string $from, ?string $to, ?string $previousFrom, int $limit = 20): array
    {
        if (!$this->available()) {
            return ['log_v2' => ['available' => false, 'reason' => 'run db/migrations/2026-10-24_search_logs_v2.sql']];
        }
        $zero = $this->zeroResultQueries($from, $to, $limit);
        $pairs = $this->aliasPairs($from, $to, 200);
        $resolved = array_flip(array_column($pairs, 'query'));

        return [
            'log_v2' => ['available' => true, 'rows' => $this->v2Rows($from, $to)],
            'unique' => $this->unique($from, $to),
            'zero_result' => ['rate' => $this->zeroRate($from, $to)] + ['queries' => $zero],
            'engagement' => $this->engagement($from, $to),
            'latency_cold' => $this->latencyCold($from, $to, $limit),
            'tier_timeline' => $this->tierTimeline($from, $to),
            'capped_share' => $this->cappedShare($from, $to),
            'did_you_mean' => $this->suggestions($from, $to, $limit),
            'alias_candidates' => array_slice($pairs, 0, $limit),
            'silent_failures' => $this->silentFailures($from, $to, $limit),
            'brand_unmatched' => $this->brandUnmatched($from, $to, $limit),
            'demand_gap' => $this->demandGap($from, $to, $limit, $resolved),
            'trending' => $this->trending($from, $to, $previousFrom, $limit),
            'suspicious' => $this->suspicious($from, $to),
        ];
    }

    /**
     * Headline figures of one range, compared across two ranges by compare().
     *
     * @return array<string, int|float>
     */
    public function headline(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range($from, $to, ['is_suspicious = 0']);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(cache_hit = 1), 0) AS hits FROM {$this->logs}{$where}"
        );
        $stmt->execute($params);
        $all = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        $engagement = $this->engagement($from, $to);
        $tier = $this->tierTimeline($from, $to);
        $hybrid = array_sum(array_column($tier['hours'], 'hybrid'));
        $searches = array_sum(array_column($tier['hours'], 'searches'));

        return [
            'searches' => (int) $all['n'],
            'unique_searches' => $this->unique($from, $to)['searches'],
            'zero_rate' => $this->zeroRate($from, $to),
            'ctr' => $engagement['ctr'],
            'cold_p95_ms' => $this->latencyCold($from, $to, 1)['p95'],
            'hybrid_share' => $searches === 0 ? 0.0 : round(100 * $hybrid / $searches, 1),
            'cache_hit_rate' => self::pct((int) $all['hits'], (int) $all['n']),
        ];
    }

    /**
     * @param array<string, int|float> $current
     * @param array<string, int|float> $previous
     * @return array<string, array{current: int|float, previous: int|float, change: int|float, change_pct: ?float}>
     */
    public static function compare(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $before = $previous[$key] ?? 0;
            $out[$key] = [
                'current' => $value,
                'previous' => $before,
                'change' => round($value - $before, 1),
                'change_pct' => $before == 0 ? null : round(100 * ($value - $before) / $before, 1),
            ];
        }

        return $out;
    }

    /** Rows written by the v2 logger in the range (the base of every v2-only figure). */
    public function v2Rows(?string $from, ?string $to): int
    {
        return $this->count($this->v2($from, $to));
    }

    /**
     * Unique searches (not a repeat submit), the sessions behind them, searches per session.
     *
     * @return array{searches: int, sessions: int, searches_per_session: float}
     */
    public function unique(?string $from, ?string $to): array
    {
        [$where, $params] = $this->v2($from, $to, ['is_duplicate = 0']);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS n, COUNT(session_id) AS with_session, COUNT(DISTINCT session_id) AS sessions"
            . " FROM {$this->logs}{$where}"
        );
        $stmt->execute($params);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        $sessions = (int) $row['sessions'];

        return [
            'searches' => (int) $row['n'],
            'sessions' => $sessions,
            'searches_per_session' => $sessions === 0 ? 0.0 : round((int) $row['with_session'] / $sessions, 2),
        ];
    }

    /** Percent of unique searches that returned nothing. */
    public function zeroRate(?string $from, ?string $to): float
    {
        return self::pct(
            $this->count($this->v2($from, $to, ['is_duplicate = 0', 'result_count = 0'])),
            $this->count($this->v2($from, $to, ['is_duplicate = 0']))
        );
    }

    /** @return list<array{query: string, searches: int, last_seen: string}> */
    public function zeroResultQueries(?string $from, ?string $to, int $limit): array
    {
        return $this->grouped($this->v2($from, $to, ['is_duplicate = 0', 'result_count = 0']), $limit);
    }

    /**
     * What shoppers did with the answers, over unique searches that had results:
     * click-through, no-click rate, mean click position, mean reciprocal rank of the
     * first click.
     *
     * @return array{tracking: bool, searches: int, ctr: float, no_click_rate: float, mean_click_position: float,
     *     mrr: float}
     */
    public function engagement(?string $from, ?string $to): array
    {
        $empty = [
            'tracking' => false, 'searches' => 0, 'ctr' => 0.0, 'no_click_rate' => 0.0,
            'mean_click_position' => 0.0, 'mrr' => 0.0,
        ];
        try {
            [$where, $params] = $this->v2($from, $to, ['l.is_duplicate = 0', 'l.result_count > 0'], 'l.');
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(e.clicks > 0), 0) AS clicked,'
                . ' COALESCE(SUM(CASE WHEN e.clicks > 0 THEN 1 / e.first_pos END), 0) AS rr'
                . " FROM {$this->logs} l LEFT JOIN (" . $this->eventSummary($from) . ')'
                . ' e ON e.search_uuid = l.search_uuid'
                . $where
            );
            $stmt->execute($params + $this->eventParams($from));
            $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
            $events = $this->pdo->prepare(
                "SELECT COUNT(*) AS n, COALESCE(AVG(position), 0) AS pos FROM {$this->events}"
                . " WHERE action = 'click'" . ($from === null ? '' : ' AND ts >= :from')
            );
            $events->execute($from === null ? [] : ['from' => $from]);
            $clicks = (array) $events->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('analytics: engagement unavailable: ' . $e->getMessage());

            return $empty;
        }
        $n = (int) $row['n'];
        $clicked = (int) $row['clicked'];

        return [
            'tracking' => (int) $clicks['n'] > 0,
            'searches' => $n,
            'ctr' => self::pct($clicked, $n),
            'no_click_rate' => $n === 0 ? 0.0 : round(100 - self::pct($clicked, $n), 1),
            'mean_click_position' => round((float) $clicks['pos'], 2),
            'mrr' => $n === 0 ? 0.0 : round((float) $row['rr'] / $n, 4),
        ];
    }

    /**
     * Cache misses only (the work the service really did): latency percentiles, the p95 of
     * each stage and the slowest queries.
     *
     * @return array{count: int, p50: int, p95: int, max: int, stages_p95: array<string, int>,
     *     slowest: list<array{query: string, max_ms: int, searches: int}>}
     */
    public function latencyCold(?string $from, ?string $to, int $limit): array
    {
        $cold = $this->v2($from, $to, ['cache_hit = 0']);
        $count = $this->count($cold);
        $stages = [];
        $columns = ['keyword' => 'lat_keyword_ms', 'vector' => 'lat_vector_ms', 'spell' => 'lat_spell_ms'];
        foreach ($columns as $name => $column) {
            $measured = $this->v2($from, $to, ['cache_hit = 0', "{$column} IS NOT NULL"]);
            $stages[$name] = $this->percentile($column, $measured, 0.95);
        }
        [$where, $params] = $cold;
        $limit = min(100, max(1, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::QUERY . " AS q, MAX(latency_ms) AS slowest, COUNT(*) AS n FROM {$this->logs}{$where}"
            . " GROUP BY q HAVING q <> '' ORDER BY slowest DESC, n DESC LIMIT {$limit}"
        );
        $stmt->execute($params);

        return [
            'count' => $count,
            'p50' => $this->percentile('latency_ms', $cold, 0.50),
            'p95' => $this->percentile('latency_ms', $cold, 0.95),
            'max' => $count === 0 ? 0 : $this->percentile('latency_ms', $cold, 1.0),
            'stages_p95' => $stages,
            'slowest' => array_map(
                static fn (array $r): array => [
                    'query' => (string) $r['q'], 'max_ms' => (int) $r['slowest'], 'searches' => (int) $r['n'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            ),
        ];
    }

    /**
     * Hybrid share per hour, and the runs of hours where only the keyword tier answered
     * while it normally does not (a vector outage).
     *
     * @return array{hours: list<array{bucket: string, searches: int, hybrid: int, hybrid_share: float}>,
     *     outages: list<array{from: string, to: string, hours: int, searches: int}>}
     */
    public function tierTimeline(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range($from, $to, ['is_suspicious = 0']);
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00') AS bucket, COUNT(*) AS n,"
            . " COALESCE(SUM(tier = 'hybrid'), 0) AS hybrid FROM {$this->logs}{$where}"
            . ' GROUP BY bucket ORDER BY bucket DESC LIMIT 744'
        );
        $stmt->execute($params);
        $hours = array_reverse(array_map(
            static fn (array $r): array => [
                'bucket' => (string) $r['bucket'], 'searches' => (int) $r['n'], 'hybrid' => (int) $r['hybrid'],
                'hybrid_share' => self::pct((int) $r['hybrid'], (int) $r['n']),
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));

        // An outage is a run of busy hours with no hybrid answer at all, in a window that otherwise has some.
        $outages = [];
        $anyHybrid = array_sum(array_column($hours, 'hybrid')) > 0;
        $run = null;
        foreach ($hours as $hour) {
            if ($anyHybrid && $hour['searches'] >= 3 && $hour['hybrid'] === 0) {
                $run ??= ['from' => $hour['bucket'], 'to' => $hour['bucket'], 'hours' => 0, 'searches' => 0];
                $run['to'] = $hour['bucket'];
                $run['hours']++;
                $run['searches'] += $hour['searches'];
                continue;
            }
            if ($run !== null) {
                $outages[] = $run;
                $run = null;
            }
        }
        if ($run !== null) {
            $outages[] = $run;
        }

        return ['hours' => $hours, 'outages' => $outages];
    }

    /** @return array{searches: int, capped: int, share: float} */
    public function cappedShare(?string $from, ?string $to): array
    {
        $total = $this->count($this->v2($from, $to));
        $capped = $this->count($this->v2($from, $to, ['is_capped = 1']));

        return ['searches' => $total, 'capped' => $capped, 'share' => self::pct($capped, $total)];
    }

    /**
     * Suggestion quality: how often one is shown, how often it is clicked, the suggestions whose own
     * search finds nothing, and the most shown query -> suggestion pairs with their clicks.
     *
     * @return array{shown: int, shown_rate: float, clicked: int, ctr: float, zero_result_suggestions: int,
     *     top: list<array{query: string, suggestion: string, shown: int, clicked: int}>}
     */
    public function suggestions(?string $from, ?string $to, int $limit): array
    {
        $unique = ['is_duplicate = 0'];
        $total = $this->count($this->v2($from, $to, $unique));
        $shown = $this->count($this->v2($from, $to, array_merge($unique, ['dym_shown = 1'])));
        $top = [];
        $clicked = 0;
        $zeroSuggestions = 0;
        try {
            [$where, $params] = $this->v2($from, $to, ['l.is_duplicate = 0', 'l.dym_shown = 1'], 'l.');
            $limit = min(100, max(1, $limit));
            $stmt = $this->pdo->prepare(
                'SELECT l.normalized_q AS q, l.did_you_mean AS s, COUNT(*) AS shown,'
                . ' COALESCE(SUM(e.dym > 0), 0) AS clicked'
                . " FROM {$this->logs} l LEFT JOIN (SELECT search_uuid, COUNT(*) AS dym FROM {$this->events}"
                . " WHERE action = 'dym_click'" . ($from === null ? '' : ' AND ts >= :efrom')
                . ' GROUP BY search_uuid) e ON e.search_uuid = l.search_uuid'
                . "{$where} GROUP BY q, s ORDER BY shown DESC, q LIMIT {$limit}"
            );
            $stmt->execute($params + ($from === null ? [] : ['efrom' => $from]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $top[] = ['query' => (string) $r['q'], 'suggestion' => (string) $r['s'],
                    'shown' => (int) $r['shown'], 'clicked' => (int) $r['clicked']];
            }
            $clicked = array_sum(array_column($top, 'clicked'));
            [$where, $params] = $this->v2($from, $to, ['d.dym_shown = 1', "d.did_you_mean <> ''"], 'd.');
            $zero = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT d.did_you_mean) FROM {$this->logs} d JOIN {$this->logs} z"
                . " ON z.normalized_q = d.did_you_mean AND z.result_count = 0 AND z.is_suspicious = 0{$where}"
            );
            $zero->execute($params);
            $zeroSuggestions = (int) $zero->fetchColumn();
        } catch (PDOException $e) {
            error_log('analytics: suggestion figures unavailable: ' . $e->getMessage());
        }

        return [
            'shown' => $shown, 'shown_rate' => self::pct($shown, $total),
            'clicked' => $clicked, 'ctr' => self::pct($clicked, $shown),
            'zero_result_suggestions' => $zeroSuggestions, 'top' => $top,
        ];
    }

    /**
     * Zero-result queries a shopper then replaced, within a minute and in the same session, by a
     * different query that did return results: the raw material of new aliases. Ranked by clicks on
     * the follow-up, then by how often the pair occurs.
     *
     * @return list<array{query: string, then: string, pairs: int, clicks: int}>
     */
    public function aliasPairs(?string $from, ?string $to, int $limit): array
    {
        [$where, $params] = $this->v2($from, $to, [
            'a.result_count = 0', 'a.is_duplicate = 0', 'a.session_id IS NOT NULL', "a.normalized_q <> ''",
        ], 'a.');
        $limit = min(500, max(1, $limit));
        $seconds = self::FOLLOW_UP_SECONDS;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.normalized_q AS q, f.normalized_q AS t, COUNT(*) AS pairs,'
                . ' COALESCE(SUM(c.clicks > 0), 0) AS clicks FROM ('
                . "SELECT a.id AS aid, MIN(b.id) AS bid FROM {$this->logs} a JOIN {$this->logs} b"
                . ' ON b.session_id = a.session_id AND b.id > a.id'
                . " AND b.ts <= a.ts + INTERVAL {$seconds} SECOND AND b.result_count > 0 AND b.is_suspicious = 0"
                . " AND b.is_duplicate = 0 AND b.normalized_q <> a.normalized_q{$where} GROUP BY a.id) m"
                . " JOIN {$this->logs} a ON a.id = m.aid JOIN {$this->logs} f ON f.id = m.bid"
                . " LEFT JOIN (SELECT search_uuid, COUNT(*) AS clicks FROM {$this->events} WHERE action = 'click'"
                . ($from === null ? '' : ' AND ts >= :efrom')
                . ' GROUP BY search_uuid) c ON c.search_uuid = f.search_uuid'
                . " GROUP BY q, t ORDER BY clicks DESC, pairs DESC, q LIMIT {$limit}"
            );
            $stmt->execute($params + ($from === null ? [] : ['efrom' => $from]));
        } catch (PDOException $e) {
            error_log('analytics: alias candidates unavailable: ' . $e->getMessage());

            return [];
        }

        return array_map(
            static fn (array $r): array => [
                'query' => (string) $r['q'], 'then' => (string) $r['t'],
                'pairs' => (int) $r['pairs'], 'clicks' => (int) $r['clicks'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Frequent queries that return results nobody clicks: the answer looks like a hit and is not
     * ("gtavi" showing Atari products). Empty while no click was ever tracked in the range.
     *
     * Each row names the first product the query's latest search returned (`top_id`, `top_title`), so the
     * owner can judge at a glance whether the answer was right.
     *
     * @return list<array{query: string, searches: int, clicks: int, ctr: float, avg_results: float, top_id: ?int, top_title: string}>
     */
    public function silentFailures(?string $from, ?string $to, int $limit): array
    {
        if (!$this->engagement($from, $to)['tracking']) {
            return [];
        }
        [$where, $params] = $this->v2(
            $from,
            $to,
            ['l.is_duplicate = 0', 'l.result_count > 0', "l.normalized_q <> ''"],
            'l.'
        );
        $min = self::MIN_SILENT_SEARCHES;
        $max = self::SILENT_CTR_MAX;
        $limit = min(100, max(1, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT l.normalized_q AS q, COUNT(*) AS n, COALESCE(SUM(e.clicks > 0), 0) AS clicked,'
                . " AVG(l.result_count) AS avg FROM {$this->logs} l LEFT JOIN (" . $this->eventSummary($from) . ') e'
                . " ON e.search_uuid = l.search_uuid{$where} GROUP BY q HAVING n >= {$min}"
                . " AND 100 * clicked / n <= {$max} ORDER BY n DESC, q LIMIT {$limit}"
            );
            $stmt->execute($params + $this->eventParams($from));
        } catch (PDOException $e) {
            error_log('analytics: silent failures unavailable: ' . $e->getMessage());

            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $top = $this->topResults(array_map(static fn (array $r): string => (string) $r['q'], $rows));

        return array_map(
            static fn (array $r): array => [
                'query' => (string) $r['q'], 'searches' => (int) $r['n'], 'clicks' => (int) $r['clicked'],
                'ctr' => self::pct((int) $r['clicked'], (int) $r['n']), 'avg_results' => round((float) $r['avg'], 1),
                'top_id' => $top[(string) $r['q']]['id'] ?? null,
                'top_title' => $top[(string) $r['q']]['title'] ?? '',
            ],
            $rows
        );
    }

    /**
     * The first result of each query's latest search that had results, with its title (cut to 120 characters).
     *
     * @param list<string> $queries normalized queries
     * @return array<string, array{id: int, title: string}> by query; a query whose product is gone is left out
     */
    private function topResults(array $queries): array
    {
        if ($queries === [] || $this->products === null) {
            return [];
        }
        try {
            $latest = $this->pdo->prepare(
                "SELECT top_ids FROM {$this->logs} WHERE normalized_q = ? AND result_count > 0 AND is_suspicious = 0"
                . ' ORDER BY ts DESC, id DESC LIMIT 1'
            );
            $ids = [];
            foreach ($queries as $query) {
                $latest->execute([$query]);
                $first = (int) explode(',', (string) $latest->fetchColumn())[0];
                if ($first > 0) {
                    $ids[$query] = $first;
                }
            }
            if ($ids === []) {
                return [];
            }
            $unique = array_values(array_unique($ids));
            $stmt = $this->pdo->prepare(
                "SELECT product_id, title FROM {$this->products} WHERE product_id IN ("
                . implode(',', array_fill(0, count($unique), '?')) . ')'
            );
            $stmt->execute($unique);
            $titles = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            error_log('analytics: silent failure titles unavailable: ' . $e->getMessage());

            return [];
        }
        $top = [];
        foreach ($ids as $query => $id) {
            if (isset($titles[$id])) {
                $top[$query] = ['id' => $id, 'title' => mb_substr((string) $titles[$id], 0, 120)];
            }
        }

        return $top;
    }

    /**
     * Brands a query named that none of the candidates carried ("asked for, not stocked", M44), most searched
     * first: the brand as the query named it, its unique searches, one example query and the last search.
     * Empty while the log table has no `brand_unmatched` column.
     *
     * @return list<array{brand: string, searches: int, example: string, last_seen: string}>
     */
    public function brandUnmatched(?string $from, ?string $to, int $limit): array
    {
        if (!$this->hasColumn('brand_unmatched')) {
            return [];
        }
        [$where, $params] = $this->v2($from, $to, ['is_duplicate = 0', 'brand_unmatched IS NOT NULL']);
        $limit = min(100, max(1, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT brand_unmatched AS b, COUNT(*) AS n, MAX(COALESCE(NULLIF(normalized_q, \'\'), raw_q)) AS example,'
                . " MAX(ts) AS last FROM {$this->logs}{$where} GROUP BY b ORDER BY n DESC, b LIMIT {$limit}"
            );
            $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('analytics: unmatched brands unavailable: ' . $e->getMessage());

            return [];
        }

        return array_map(
            static fn (array $r): array => [
                'brand' => (string) $r['b'], 'searches' => (int) $r['n'],
                'example' => (string) $r['example'], 'last_seen' => (string) $r['last'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Zero-result queries no reformulation resolved: most likely products the catalog does not have.
     *
     * @param array<string, int> $resolved queries that have an alias candidate, as keys
     * @return list<array{query: string, searches: int, last_seen: string}>
     */
    public function demandGap(?string $from, ?string $to, int $limit, array $resolved): array
    {
        $gaps = [];
        foreach ($this->zeroResultQueries($from, $to, 500) as $row) {
            if (!isset($resolved[$row['query']])) {
                $gaps[] = $row;
            }
        }

        return array_slice($gaps, 0, $limit);
    }

    /**
     * The queries whose searches grew most against the previous range of the same length.
     *
     * @return list<array{query: string, searches: int, previous: int, growth: int, growth_pct: ?float}>
     */
    public function trending(?string $from, ?string $to, ?string $previousFrom, int $limit): array
    {
        if ($from === null || $previousFrom === null) {
            return [];
        }
        $now = $this->grouped($this->plain($from, $to), 500);
        if ($now === []) {
            return [];
        }
        $before = [];
        foreach ($this->grouped($this->plain($previousFrom, $from), 5000) as $row) {
            $before[$row['query']] = $row['searches'];
        }
        $rows = [];
        foreach ($now as $row) {
            $was = $before[$row['query']] ?? 0;
            if ($row['searches'] > $was && $row['searches'] >= 3) {
                $rows[] = [
                    'query' => $row['query'], 'searches' => $row['searches'], 'previous' => $was,
                    'growth' => $row['searches'] - $was,
                    'growth_pct' => $was === 0 ? null : round(100 * ($row['searches'] - $was) / $was, 1),
                ];
            }
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => [$b['growth'], $a['query']] <=> [$a['growth'], $b['query']]
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * Probes the service refused to search: how many, and the latest few (cut short).
     *
     * @return array{count: int, samples: list<array{ts: string, query: string}>}
     */
    public function suspicious(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range($from, $to, ['is_suspicious = 1']);
        $stmt = $this->pdo->prepare(
            "SELECT ts, raw_q FROM {$this->logs}{$where} ORDER BY id DESC LIMIT 10"
        );
        $stmt->execute($params);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->logs}{$where}");
        $count->execute($params);

        return [
            'count' => (int) $count->fetchColumn(),
            'samples' => array_map(
                static fn (array $r): array => [
                    'ts' => (string) $r['ts'], 'query' => mb_substr((string) $r['raw_q'], 0, 120),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            ),
        ];
    }

    /**
     * Per search: how many clicks it got and the position of its first click. Only click and
     * dym_click rows count: older cart / order rows (no longer written) are ignored. The events
     * table is scanned from the range start on only.
     */
    private function eventSummary(?string $from): string
    {
        return "SELECT search_uuid, SUM(action = 'click') AS clicks,"
            . " MIN(CASE WHEN action = 'click' THEN GREATEST(position, 1) END) AS first_pos FROM {$this->events}"
            . " WHERE action IN ('click', 'dym_click')" . ($from === null ? '' : ' AND ts >= :efrom')
            . ' GROUP BY search_uuid';
    }

    /** @return array<string, string> */
    private function eventParams(?string $from): array
    {
        return $from === null ? [] : ['efrom' => $from];
    }

    /**
     * v2 rows of the range without flagged probes, plus $extra conditions.
     *
     * @param list<string> $extra
     * @return array{string, array<string, string>}
     */
    private function v2(?string $from, ?string $to, array $extra = [], string $alias = ''): array
    {
        return $this->range(
            $from,
            $to,
            array_merge(["{$alias}log_version = 2", "{$alias}is_suspicious = 0"], $extra),
            $alias
        );
    }

    /**
     * Every log row of the range without flagged probes or repeat submits (those columns, where present).
     *
     * @return array{string, array<string, string>}
     */
    private function plain(?string $from, ?string $to): array
    {
        return $this->range($from, $to, ['is_suspicious = 0', 'is_duplicate = 0']);
    }

    /**
     * @param list<string> $conditions
     * @return array{string, array<string, string>} WHERE clause and its parameters
     */
    private function range(?string $from, ?string $to, array $conditions = [], string $alias = ''): array
    {
        $params = [];
        if ($from !== null) {
            $conditions[] = "{$alias}ts >= :from";
            $params['from'] = $from;
        }
        if ($to !== null) {
            $conditions[] = "{$alias}ts < :to";
            $params['to'] = $to;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array{string, array<string, string>} $range
     * @return list<array{query: string, searches: int, last_seen: string}>
     */
    private function grouped(array $range, int $limit): array
    {
        [$where, $params] = $range;
        $limit = min(5000, max(1, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::QUERY . " AS q, COUNT(*) AS n, MAX(ts) AS last_seen FROM {$this->logs}{$where}"
            . " GROUP BY q HAVING q <> '' ORDER BY n DESC, last_seen DESC, q LIMIT {$limit}"
        );
        $stmt->execute($params);

        return array_map(
            static fn (array $r): array => [
                'query' => (string) $r['q'], 'searches' => (int) $r['n'], 'last_seen' => (string) $r['last_seen'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @param array{string, array<string, string>} $range */
    private function count(array $range): int
    {
        [$where, $params] = $range;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->logs}{$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Nearest-rank percentile of a column over the rows of $range.
     *
     * @param array{string, array<string, string>} $range
     */
    private function percentile(string $column, array $range, float $p): int
    {
        $n = $this->count($range);
        if ($n === 0) {
            return 0;
        }
        [$where, $params] = $range;
        $offset = max(0, (int) ceil($p * $n) - 1);
        $stmt = $this->pdo->prepare(
            "SELECT {$column} FROM {$this->logs}{$where} ORDER BY {$column} LIMIT 1 OFFSET {$offset}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function hasColumn(string $name): bool
    {
        if ($this->columns === null) {
            $this->columns = [];
            foreach ($this->pdo->query("SHOW COLUMNS FROM {$this->logs}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
