<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Read-only query behind the panel's logs page (M21, in the admin panel since M39): recent searches
 * newest first, only the zero-result ones (the gaps worth fixing) or the slowest, paginated. Since
 * M42 the page also sorts by any shown column, searches the raw and the normalized query, and takes
 * its rows per page from the request, all over the whole filtered result.
 *
 * Nothing from the request reaches the SQL text: the sort column comes from {@see SORTS} (an unknown
 * name falls back to the view's default), the direction is asc or desc, the page size one of
 * {@see Paging::sizes()}, the search term a bound LIKE pattern with its wildcards escaped. Every query
 * has a LIMIT. A search or a sort on a column without an index must read rows to find the page, so
 * on a big table it looks at the newest `logs.scan_limit` rows only (0 = all) and says so; the index-ordered
 * views (newest first, by time, the zero-result count) always cover everything. A table from before M21
 * lacks did_you_mean and tier (before M26, cache_hit): those columns render empty and cannot be sorted by.
 */
final class LogsPage
{
    public const VIEWS = ['recent', 'zero', 'slowest'];
    /** Sortable columns: the shown ones, a closed list so a column name never comes from the request. */
    public const SORTS = [
        'ts', 'raw_q', 'normalized_q', 'tier', 'had_vector', 'result_count', 'top_ids', 'latency_ms',
        'did_you_mean', 'customer_id', 'cache_hit', 'id',
    ];
    /** Columns whose natural first order is A to Z; the others (time, counts) start with the biggest. */
    public const TEXT = ['raw_q', 'normalized_q', 'tier', 'top_ids', 'did_you_mean', 'customer_id'];
    /** The longest search term used; the column is 512 wide, a longer pattern could not match more. */
    public const MAX_TERM = 100;
    /** LIKE escape character: not a backslash, whose meaning depends on the server's sql_mode. */
    private const ESCAPE = '|';

    private PDO $pdo;
    private string $table;
    private int $pageSize;
    private int $scanLimit;
    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(PDO $pdo, string $searchLogsTable, int $pageSize = 50, int $scanLimit = 0)
    {
        $this->scanLimit = max(0, $scanLimit);
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
        $this->pageSize = min(500, max(1, $pageSize));
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @param string $sort a column of {@see SORTS}; anything else (or '') is the view's default
     * @param string $dir 'asc' or 'desc'; anything else is the default direction of the sort
     * @param string $term search across raw_q and normalized_q
     * @param int|null $perPage a {@see Paging::sizes()} choice; anything else is the configured size
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, view: string,
     *         sort: string, dir: string, q: string, per: int, from: int, to: int, scan_limit: int|null}
     *         scan_limit is set when older rows were left out of a search or an unindexed sort
     */
    public function fetch(
        string $view,
        int $page,
        string $sort = '',
        string $dir = '',
        string $term = '',
        ?int $perPage = null
    ): array {
        $view = in_array($view, self::VIEWS, true) ? $view : 'recent';
        $per = Paging::size($perPage, $this->pageSize);
        $term = trim(mb_substr($term, 0, self::MAX_TERM));

        if (!in_array($sort, $this->sortable(), true)) {
            // An unusable sort takes its direction with it: the view's own order, newest or slowest first.
            $sort = $view === 'slowest' ? 'latency_ms' : '';
            $dir = '';
        }
        $dir = $dir === 'asc' || $dir === 'desc' ? $dir : (in_array($sort, self::TEXT, true) ? 'asc' : 'desc');
        // The unsorted view is the primary key backwards: the newest rows, no sort at all.
        $order = $sort === '' || $sort === 'id'
            ? 'id ' . strtoupper($dir)
            : Identifier::quote($sort) . ' ' . strtoupper($dir) . ', id ' . strtoupper($dir);

        [$where, $params] = $this->filter($view, $term);
        $scanLimit = null;
        if ($this->scanLimit > 0 && ($term !== '' || !in_array($sort, ['', 'id', 'ts'], true))) {
            $floor = (int) $this->pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$this->table}")->fetchColumn()
                - $this->scanLimit;
            if ($floor > 0) {
                $where .= ($where === '' ? ' WHERE' : ' AND') . ' id > ' . $floor;
                $left = $this->pdo->query("SELECT 1 FROM {$this->table} WHERE id <= {$floor} LIMIT 1")->fetchColumn();
                $scanLimit = $left === false ? null : $this->scanLimit;
            }
        }

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table}{$where}");
        $count->execute($params);
        $paging = Paging::of((int) $count->fetchColumn(), $page, $per);

        $select = $this->pdo->prepare(
            "SELECT * FROM {$this->table}{$where} ORDER BY {$order} LIMIT {$paging['limit']} OFFSET {$paging['offset']}"
        );
        $select->execute($params);

        return [
            'rows' => $select->fetchAll(PDO::FETCH_ASSOC),
            'total' => $paging['total'],
            'page' => $paging['page'],
            'pages' => $paging['pages'],
            'view' => $view,
            'sort' => $sort,
            'dir' => $dir,
            'q' => $term,
            'per' => $per,
            'from' => $paging['from'],
            'to' => $paging['to'],
            'scan_limit' => $scanLimit,
        ];
    }

    /** A LIKE pattern matching $term anywhere, with %, _ and the escape character taken literally. */
    public static function likePattern(string $term): string
    {
        return '%' . strtr($term, [self::ESCAPE => self::ESCAPE . self::ESCAPE, '%' => self::ESCAPE . '%',
            '_' => self::ESCAPE . '_']) . '%';
    }

    /**
     * @return array{string, array<string, string>} WHERE clause (with a leading space, or '') and its
     *         bound parameters
     */
    private function filter(string $view, string $term): array
    {
        $conditions = [];
        $params = [];
        if ($view === 'zero') {
            $conditions[] = 'result_count = 0';
        }
        if ($term !== '') {
            // The stored normalized form is what the search saw, so the term is normalized too: a
            // Persian query typed with Arabic letters still finds its row. Both columns, either may match.
            $normalized = Normalizer::normalize($term);
            $params['raw'] = self::likePattern($term);
            $params['norm'] = self::likePattern($normalized !== '' ? $normalized : $term);
            $escape = " ESCAPE '" . self::ESCAPE . "'";
            $conditions[] = "(raw_q LIKE :raw{$escape} OR normalized_q LIKE :norm{$escape})";
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return list<string> the SORTS the table has (an older table lacks some columns) */
    private function sortable(): array
    {
        if ($this->columns === null) {
            try {
                $this->columns = array_map(
                    'strval',
                    $this->pdo->query("SHOW COLUMNS FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN)
                );
            } catch (Throwable) {
                $this->columns = ['id', 'ts', 'raw_q', 'normalized_q', 'result_count', 'latency_ms'];
            }
        }

        return array_values(array_intersect(self::SORTS, $this->columns));
    }
}
