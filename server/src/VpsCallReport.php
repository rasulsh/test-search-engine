<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Read-only reports over vps_calls for the admin panel's VPS page: a summary per window, hourly counts per
 * outcome, incidents (failures close together), the list of calls with filters and paging, and a CSV
 * export. All times are UTC. Nothing from the request reaches the SQL text: kind and outcome are checked
 * against closed lists, dates parsed by {@see LogsExport::parseTime()}, everything else is bound.
 * The table holds no secret (VpsCallLog never receives one), so neither does a report or an export.
 */
final class VpsCallReport
{
    public const VIEWS = ['failures', 'slowest', 'all'];
    /** A "failure" filter value meaning every outcome but ok, not_configured and skipped. */
    public const FAILED = 'failed';
    /** Failures this close together (seconds) are one incident. */
    public const INCIDENT_GAP = 60;
    /** An ok call slower than this share of its timeout is a near-timeout. */
    public const NEAR_TIMEOUT = 0.8;
    public const INCIDENT_OK_CALLS = 10;
    public const INCIDENT_LIMIT = 50;
    public const EXPORT_LIMIT = 50000;

    public const COLUMNS = [
        'id', 'ts', 'kind', 'outcome', 'source', 'http_status', 'duration_ms', 'namelookup_ms', 'connect_ms',
        'appconnect_ms', 'ttfb_ms', 'timeout_ms', 'error', 'response_bytes', 'neighbours', 'request_id',
        'vps_queue_ms', 'vps_embed_ms', 'vps_search_ms', 'vps_took_ms', 'vps_inflight', 'normalized_q',
        'sent_differs', 'sent_hash',
    ];

    private const T = VpsCallLog::TABLE;
    private const FAILURE_SQL = "outcome NOT IN ('ok', 'not_configured', 'skipped')";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** False when the migration was not applied: the page then says so instead of failing. */
    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::T . ' LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Calls of one kind in the last $hours hours (`calls` leaves out searches that skipped the tier).
     *
     * @return array{calls: int, skipped: int, ok: int, ok_pct: ?float, outcomes: array<string, int>, p50: ?int,
     *         p95: ?int,
     *         max: ?int, near: int, near_pct: ?float}
     */
    public function summary(int $hours, string $kind): array
    {
        $where = 'kind = :kind AND ts >= UTC_TIMESTAMP() - INTERVAL ' . max(1, $hours) . ' HOUR';
        $counts = $this->pdo->prepare('SELECT outcome, COUNT(*) FROM ' . self::T . " WHERE {$where} GROUP BY outcome");
        $counts->execute(['kind' => $kind]);
        $outcomes = array_fill_keys(VpsCallLog::OUTCOMES, 0);
        foreach ($counts->fetchAll(PDO::FETCH_NUM) as [$outcome, $count]) {
            $outcomes[(string) $outcome] = (int) $count;
        }
        // A skipped search made no call: it is counted apart and never lowers ok %.
        $calls = array_sum($outcomes) - $outcomes['skipped'];

        $durations = $this->pdo->prepare(
            'SELECT duration_ms FROM ' . self::T . " WHERE {$where} AND duration_ms IS NOT NULL ORDER BY duration_ms"
        );
        $durations->execute(['kind' => $kind]);
        $sorted = array_map('intval', $durations->fetchAll(PDO::FETCH_COLUMN));

        $near = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::T . " WHERE {$where} AND outcome = 'ok' AND timeout_ms > 0"
            . ' AND duration_ms > timeout_ms * ' . self::NEAR_TIMEOUT
        );
        $near->execute(['kind' => $kind]);
        $nearCount = (int) $near->fetchColumn();

        return [
            'calls' => $calls,
            'skipped' => $outcomes['skipped'],
            'ok' => $outcomes['ok'],
            'ok_pct' => $calls === 0 ? null : round(100 * $outcomes['ok'] / $calls, 1),
            'outcomes' => $outcomes,
            'p50' => self::percentile($sorted, 50),
            'p95' => self::percentile($sorted, 95),
            'max' => $sorted === [] ? null : $sorted[count($sorted) - 1],
            'near' => $nearCount,
            'near_pct' => $calls === 0 ? null : round(100 * $nearCount / $calls, 1),
        ];
    }

    /**
     * Nearest-rank percentile of an ascending list.
     *
     * @param list<int> $sorted
     */
    public static function percentile(array $sorted, int $percent): ?int
    {
        if ($sorted === []) {
            return null;
        }
        $rank = (int) ceil($percent / 100 * count($sorted));

        return $sorted[max(0, min(count($sorted) - 1, $rank - 1))];
    }

    /**
     * Calls per hour and outcome over the last $days days (oldest hour first, hours without calls included).
     *
     * @return list<array{hour: string, counts: array<string, int>}> hour is "YYYY-MM-DD HH:00" UTC
     */
    public function hourly(int $days, string $kind): array
    {
        $hours = max(1, $days) * 24;
        $now = (string) $this->pdo->query('SELECT DATE_FORMAT(UTC_TIMESTAMP(), \'%Y-%m-%d %H:00:00\')')->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00') AS h, outcome, COUNT(*) FROM " . self::T
            . ' WHERE kind = :kind AND ts >= UTC_TIMESTAMP() - INTERVAL ' . $hours . ' HOUR GROUP BY h, outcome'
        );
        $stmt->execute(['kind' => $kind]);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$hour, $outcome, $count]) {
            $found[(string) $hour][(string) $outcome] = (int) $count;
        }
        $end = (int) strtotime($now . ' UTC');
        $series = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = gmdate('Y-m-d H:00', $end - $i * 3600);
            $series[] = ['hour' => $hour, 'counts' => $found[$hour] ?? []];
        }

        return $series;
    }

    /**
     * Failures within INCIDENT_GAP seconds of each other as one incident, newest first, with the median
     * duration of the ok search calls just before and just after it. A probe-only incident has no medians
     * when no search ran around it.
     *
     * @return list<array{start: string, end: string, count: int, outcomes: array<string, int>,
     *         kinds: list<string>, before_ms: ?int, after_ms: ?int}>
     */
    public function incidents(int $days): array
    {
        $stmt = $this->pdo->query(
            'SELECT ts, kind, outcome FROM ' . self::T . ' WHERE ' . self::FAILURE_SQL
            . ' AND ts >= UTC_TIMESTAMP() - INTERVAL ' . max(1, $days) . ' DAY ORDER BY ts, id LIMIT 50000'
        );
        $incidents = array_slice(self::groupIncidents($stmt->fetchAll(PDO::FETCH_ASSOC)), -self::INCIDENT_LIMIT);

        $before = $this->pdo->prepare(
            'SELECT duration_ms FROM ' . self::T . " WHERE kind = 'search' AND outcome = 'ok' AND ts < :at"
            . ' AND duration_ms IS NOT NULL ORDER BY ts DESC LIMIT ' . self::INCIDENT_OK_CALLS
        );
        $after = $this->pdo->prepare(
            'SELECT duration_ms FROM ' . self::T . " WHERE kind = 'search' AND outcome = 'ok' AND ts > :at"
            . ' AND duration_ms IS NOT NULL ORDER BY ts ASC LIMIT ' . self::INCIDENT_OK_CALLS
        );
        foreach ($incidents as &$incident) {
            $before->execute(['at' => $incident['start']]);
            $after->execute(['at' => $incident['end']]);
            $incident['before_ms'] = self::median(array_map('intval', $before->fetchAll(PDO::FETCH_COLUMN)));
            $incident['after_ms'] = self::median(array_map('intval', $after->fetchAll(PDO::FETCH_COLUMN)));
        }
        unset($incident);

        return array_reverse($incidents);
    }

    /**
     * @param list<array{ts: string, kind: string, outcome: string}> $failures oldest first
     * @return list<array{start: string, end: string, count: int, outcomes: array<string, int>,
     *         kinds: list<string>, before_ms: ?int, after_ms: ?int}> oldest first
     */
    public static function groupIncidents(array $failures): array
    {
        $incidents = [];
        $lastTime = null;
        foreach ($failures as $row) {
            $time = (int) strtotime($row['ts'] . ' UTC');
            if ($lastTime === null || $time - $lastTime > self::INCIDENT_GAP) {
                $incidents[] = [
                    'start' => $row['ts'], 'end' => $row['ts'], 'count' => 0, 'outcomes' => [], 'kinds' => [],
                    'before_ms' => null, 'after_ms' => null,
                ];
            }
            $last = count($incidents) - 1;
            $incidents[$last]['end'] = $row['ts'];
            $incidents[$last]['count']++;
            $incidents[$last]['outcomes'][$row['outcome']] = ($incidents[$last]['outcomes'][$row['outcome']] ?? 0) + 1;
            if (!in_array($row['kind'], $incidents[$last]['kinds'], true)) {
                $incidents[$last]['kinds'][] = $row['kind'];
            }
            $lastTime = $time;
        }

        return $incidents;
    }

    /** @param list<int> $values */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Checked filter values: an unknown kind / outcome / view or an unparsable date is dropped, never used.
     *
     * @param array<string, mixed> $query the request's query parameters
     * @return array{view: string, outcome: string, kind: string, from: string, to: string}
     */
    public static function filters(array $query): array
    {
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $outcome = $text('outcome');
        $date = static fn (string $key): string => LogsExport::parseTime($text($key)) !== null ? $text($key) : '';

        return [
            'view' => in_array($text('view'), self::VIEWS, true) ? $text('view') : 'failures',
            'outcome' => in_array($outcome, [...VpsCallLog::OUTCOMES, self::FAILED], true) ? $outcome : '',
            'kind' => in_array($text('kind'), VpsCallLog::KINDS, true) ? $text('kind') : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    /**
     * @param array{view: string, outcome: string, kind: string, from: string, to: string} $filters
     * @return array{string, array<string, string>} the WHERE clause (leading space) and its bound values
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        if ($filters['view'] === 'failures' && $filters['outcome'] === '') {
            $conditions[] = self::FAILURE_SQL;
        } elseif ($filters['view'] === 'slowest' && $filters['outcome'] === '') {
            $conditions[] = "outcome = 'ok'";
        }
        if ($filters['outcome'] === self::FAILED) {
            $conditions[] = self::FAILURE_SQL;
        } elseif ($filters['outcome'] !== '') {
            $conditions[] = 'outcome = :outcome';
            $params['outcome'] = $filters['outcome'];
        }
        if ($filters['kind'] !== '') {
            $conditions[] = 'kind = :kind';
            $params['kind'] = $filters['kind'];
        }
        if ($filters['from'] !== '') {
            $conditions[] = 'ts >= :from';
            $params['from'] = (string) LogsExport::parseTime($filters['from']);
        }
        if ($filters['to'] !== '') {
            // A date alone means that whole day: the bound is exclusive, so it moves to the next midnight.
            $to = (string) LogsExport::parseTime($filters['to']);
            $conditions[] = 'ts < :to';
            $params['to'] = strlen($filters['to']) === 10
                ? gmdate('Y-m-d H:i:s', (int) strtotime($to . ' UTC') + 86400)
                : $to;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * One page of calls. The "failures" view lists the newest first, "slowest" the ok calls by duration,
     * "all" every call newest first.
     *
     * @param array{view: string, outcome: string, kind: string, from: string, to: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, from: int, to: int,
     *         per: int}
     */
    public function rows(array $filters, int $page, int $per): array
    {
        [$where, $params] = $this->where($filters);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::T . $where);
        $count->execute($params);
        $paging = Paging::of((int) $count->fetchColumn(), $page, $per);
        $order = $filters['view'] === 'slowest' ? 'duration_ms DESC, id DESC' : 'id DESC';
        $select = $this->pdo->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM ' . self::T . $where
            . " ORDER BY {$order} LIMIT {$paging['limit']} OFFSET {$paging['offset']}"
        );
        $select->execute($params);

        return [
            'rows' => $select->fetchAll(PDO::FETCH_ASSOC), 'total' => $paging['total'], 'page' => $paging['page'],
            'pages' => $paging['pages'], 'from' => $paging['from'], 'to' => $paging['to'], 'per' => $per,
        ];
    }

    /**
     * Every row of the filtered list, newest first, in COLUMNS order, paged by id so memory stays flat.
     * Capped at EXPORT_LIMIT rows.
     *
     * @param array{view: string, outcome: string, kind: string, from: string, to: string} $filters
     * @return iterable<list<string|int|null>>
     */
    public function export(array $filters): iterable
    {
        [$where, $params] = $this->where($filters);
        $where .= ($where === '' ? ' WHERE' : ' AND') . ' id < :last';
        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM ' . self::T . $where . ' ORDER BY id DESC LIMIT 2000'
        );
        $last = PHP_INT_MAX;
        $left = self::EXPORT_LIMIT;
        while ($left > 0) {
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value);
            }
            $stmt->bindValue('last', $last, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $last = (int) $row[0];
                if ($left-- <= 0) {
                    return;
                }
                yield $row;
            }
            if (count($rows) < 2000) {
                return;
            }
        }
    }

    /**
     * A cell safe to open in a spreadsheet: text starting with = + - @ would run as a formula.
     */
    public static function csvCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
