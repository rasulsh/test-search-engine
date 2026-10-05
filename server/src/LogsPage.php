<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Read-only view of search_logs for public/logs.php (M21): recent searches
 * newest first, only the zero-result ones (the gaps worth fixing) or the
 * slowest, paginated. A table from before M21 lacks did_you_mean and tier
 * (before M26, cache_hit); those columns then render empty.
 */
final class LogsPage
{
    public const VIEWS = [
        'recent'  => 'Recent',
        'zero'    => 'Zero results',
        'slowest' => 'Slowest',
    ];

    private const COLUMNS = [
        'id' => 'ID', 'ts' => 'Time', 'raw_q' => 'Query', 'normalized_q' => 'Normalized',
        'tier' => 'Tier', 'had_vector' => 'Vector', 'result_count' => 'Results',
        'top_ids' => 'Top ids', 'latency_ms' => 'ms', 'did_you_mean' => 'Did you mean',
        'customer_id' => 'Customer', 'cache_hit' => 'Cache',
    ];

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
        $view = isset(self::VIEWS[$view]) ? $view : 'recent';
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

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, view: string} $data
     * @param string $urlToken when the token came in the URL, carried on every link
     */
    public static function render(array $data, string $urlToken = ''): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (string $view, int $page): string => '?' . http_build_query(
            array_filter(['view' => $view, 'page' => $page, 'token' => $urlToken], static fn ($v) => $v !== '')
        );

        $nav = '';
        foreach (self::VIEWS as $view => $label) {
            $nav .= '<a href="' . $e($link($view, 1)) . '"' . ($view === $data['view'] ? ' class="on"' : '')
                . '>' . $e($label) . '</a> ';
        }
        $head = '';
        foreach (self::COLUMNS as $label) {
            $head .= '<th>' . $e($label) . '</th>';
        }
        $body = '';
        foreach ($data['rows'] as $row) {
            $body .= '<tr>';
            foreach (array_keys(self::COLUMNS) as $column) {
                $value = $row[$column] ?? '';
                if ($column === 'had_vector') {
                    $value = ($row[$column] ?? 0) ? 'yes' : 'no';
                }
                $body .= '<td class="' . $e($column) . '" dir="auto">' . $e($value) . '</td>';
            }
            $body .= "</tr>\n";
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . count(self::COLUMNS) . '">No searches logged.</td></tr>';
        }

        $pager = 'Page ' . $data['page'] . ' of ' . $data['pages'] . ' (' . $data['total'] . ' rows) ';
        if ($data['page'] > 1) {
            $pager .= '<a href="' . $e($link($data['view'], $data['page'] - 1)) . '">&larr; Newer</a> ';
        }
        if ($data['page'] < $data['pages']) {
            $pager .= '<a href="' . $e($link($data['view'], $data['page'] + 1)) . '">Older &rarr;</a>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Search logs</title><style>'
            . 'body{font:14px system-ui,sans-serif;margin:16px;color:#222}'
            . 'nav a,.pager a{margin-right:12px}nav a.on{font-weight:700;text-decoration:none;color:#000}'
            . 'table{border-collapse:collapse;width:100%;margin:12px 0}'
            . 'th,td{border:1px solid #ddd;padding:4px 8px;text-align:start;vertical-align:top}'
            . 'th{background:#f4f4f4}td.result_count,td.latency_ms,td.id{text-align:end}'
            . '</style></head><body><h1>Search logs</h1><nav>' . $nav . '</nav>'
            . '<div class="pager">' . $pager . '</div>'
            . '<div style="overflow-x:auto"><table><thead><tr>' . $head . '</tr></thead><tbody>'
            . $body . '</tbody></table></div><div class="pager">' . $pager . '</div></body></html>';
    }
}
