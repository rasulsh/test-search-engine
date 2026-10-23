<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;

/**
 * Read-only rows of search_logs for tools/logs-export.php (M36): either the raw
 * rows (newest first, paged by id so memory stays flat on a big log) or one row
 * per distinct normalized query, the shape that feeds pipeline/gen_aliases.py.
 * `customer_id` and `top_ids` are never exported: the file leaves the server and
 * the alias loop needs neither. Every query is a prepared statement.
 */
final class LogsExport
{
    /** Raw-row columns in file order; a table from before M21 / M26 lacks the last three. */
    public const RAW_COLUMNS = [
        'ts', 'raw_q', 'normalized_q', 'had_vector', 'result_count', 'latency_ms',
        'did_you_mean', 'tier', 'cache_hit',
    ];
    public const GROUPED_COLUMNS = ['normalized_q', 'searches', 'last_seen'];

    private const PAGE = 2000;
    private const QUERY = "COALESCE(NULLIF(normalized_q, ''), raw_q)";

    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $searchLogsTable)
    {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
    }

    /** "2026-03-01" or "2026-03-01 14:30:00" as a DATETIME string; null when it is neither. */
    public static function parseTime(string $text): ?string
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($time !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0)) {
                return $time->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /** @return list<string> */
    public function header(bool $grouped): array
    {
        return $grouped ? self::GROUPED_COLUMNS : $this->rawColumns();
    }

    /**
     * @param string|null $since inclusive lower bound (DATETIME), null = no bound
     * @param string|null $until exclusive upper bound
     * @param int $limit 0 = every row
     * @return iterable<list<string|int|null>> rows in header() order
     */
    public function rows(?string $since, ?string $until, bool $zeroOnly, int $limit, bool $grouped): iterable
    {
        $conditions = $zeroOnly ? ['result_count = 0'] : [];
        $params = [];
        if ($since !== null) {
            $conditions[] = 'ts >= :since';
            $params['since'] = $since;
        }
        if ($until !== null) {
            $conditions[] = 'ts < :until';
            $params['until'] = $until;
        }

        return $grouped
            ? $this->grouped($conditions, $params, $limit)
            : $this->raw($conditions, $params, $limit);
    }

    /**
     * @param list<string> $conditions
     * @param array<string, string> $params
     * @return iterable<list<string|int|null>>
     */
    private function grouped(array $conditions, array $params, int $limit): iterable
    {
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $query = self::QUERY;
        $cap = $limit > 0 ? ' LIMIT ' . $limit : '';
        $stmt = $this->pdo->prepare(
            "SELECT {$query} AS q, COUNT(*) AS searches, MAX(ts) AS last_seen FROM {$this->table}{$where}"
            . " GROUP BY q HAVING q <> '' ORDER BY searches DESC, last_seen DESC, q{$cap}"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            yield [(string) $row[0], (int) $row[1], (string) $row[2]];
        }
    }

    /**
     * @param list<string> $conditions
     * @param array<string, string> $params
     * @return iterable<list<string|int|null>>
     */
    private function raw(array $conditions, array $params, int $limit): iterable
    {
        $columns = $this->rawColumns();
        $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $conditions[] = 'id < :last';
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare(
            "SELECT id, {$select} FROM {$this->table}{$where} ORDER BY id DESC LIMIT :page"
        );

        $last = PHP_INT_MAX;
        $left = $limit > 0 ? $limit : PHP_INT_MAX;
        while ($left > 0) {
            $page = min(self::PAGE, $left);
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value);
            }
            $stmt->bindValue('last', $last, PDO::PARAM_INT);
            $stmt->bindValue('page', $page, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $last = (int) $row[0];
                yield array_slice($row, 1);
            }
            if (count($rows) < $page) {
                return;
            }
            $left -= $page;
        }
    }

    /** @return list<string> */
    private function rawColumns(): array
    {
        $present = [];
        foreach ($this->pdo->query("SHOW COLUMNS FROM {$this->table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $present[(string) $row['Field']] = true;
        }

        return array_values(array_filter(self::RAW_COLUMNS, static fn (string $c): bool => isset($present[$c])));
    }
}
