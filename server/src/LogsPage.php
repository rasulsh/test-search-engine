<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Read-only query behind the panel's logs page (M21, in the admin panel since M39): recent searches
 * newest first, only the zero-result ones (the gaps worth fixing) or the
 * slowest, paginated. A table from before M21 lacks did_you_mean and tier
 * (before M26, cache_hit); those columns then render empty.
 */
final class LogsPage
{
    public const VIEWS = ['recent', 'zero', 'slowest'];

    private PDO $pdo;
    private string $table;
    private int $pageSize;

    public function __construct(PDO $pdo, string $searchLogsTable, int $pageSize = 50)
    {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
        $this->pageSize = min(500, max(1, $pageSize));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, view: string}
     */
    public function fetch(string $view, int $page): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'recent';
        $where = $view === 'zero' ? ' WHERE result_count = 0' : '';
        $order = $view === 'slowest' ? 'latency_ms DESC, id DESC' : 'id DESC';

        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}{$where}")->fetchColumn();
        $pages = max(1, (int) ceil($total / $this->pageSize));
        $page = min(max(1, $page), $pages);
        $rows = $this->pdo->query(
            "SELECT * FROM {$this->table}{$where} ORDER BY {$order} LIMIT {$this->pageSize} OFFSET "
            . (($page - 1) * $this->pageSize)
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'view' => $view];
    }
}
