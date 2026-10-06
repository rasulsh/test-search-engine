<?php

declare(strict_types=1);

namespace App;

/**
 * The HTML dashboard of public/analytics.php (M31): numeric tiles and plain
 * tables over App\Analytics::report(), in LogsPage's minimal style (no JS, no
 * charts; `?format=json` serves the same data to anyone who wants to plot it).
 */
final class AnalyticsPage
{
    private const LABELS = [
        '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days',
        '90d' => 'Last 90 days', 'all' => 'All time',
    ];

    /**
     * @param array<string, mixed> $report Analytics::report()
     * @param string $urlToken when the token came in the URL, carried on every link
     */
    public static function render(array $report, string $urlToken = ''): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (array $query): string => '?' . http_build_query(
            array_filter($query + ['token' => $urlToken], static fn ($v) => $v !== '')
        );

        $nav = '';
        foreach (self::LABELS as $window => $label) {
            $nav .= '<a href="' . $e($link(['window' => $window])) . '"'
                . ($window === $report['window'] ? ' class="on"' : '') . '>' . $e($label) . '</a> ';
        }
        $nav .= '<a href="' . $e($link(['window' => $report['window'], 'format' => 'json'])) . '">JSON</a>';

        $s = $report['summary'];
        $latency = $report['latency'];
        $tiles = [
            ['Searches', $s['total']],
            ['Zero-result rate', $s['zero_rate'] . '%'],
            ['Cache hit rate', $report['cache']['hit_rate'] . '%'],
            ['Hybrid share', $report['tier']['hybrid_share'] . '%'],
            ['Avg latency', $latency['avg'] . ' ms'],
            ['p95 latency', $latency['p95'] . ' ms'],
        ];
        $tileHtml = '';
        foreach ($tiles as [$label, $value]) {
            $tileHtml .= '<div class="tile"><b>' . $e($value) . '</b><span>' . $e($label) . '</span></div>';
        }

        $cache = $report['cache'];
        $tier = $report['tier'];
        $details = self::table(
            ['Measure', 'Count', 'Share'],
            [
                ['Served from cache', $cache['hits'], $cache['hit_rate'] . '%'],
                ['Computed (cache miss)', $cache['misses'], self::share($cache['misses'], $cache['total'])],
                ['Keyword-only tier', $tier['keyword_only'], self::share($tier['keyword_only'], $tier['total'])],
                ['Hybrid tier', $tier['hybrid'], $tier['hybrid_share'] . '%'],
                ['Semantic tier took part (vector)', $tier['with_vector'], $tier['vector_share'] . '%'],
                ['Zero results', $s['zero_results'], $s['zero_rate'] . '%'],
                ['Did-you-mean offered', $report['did_you_mean']['suggested'], $report['did_you_mean']['share'] . '%'],
            ],
            $e,
            [1, 2]
        );
        $latencyTable = self::table(
            ['Latency (ms)', 'Value'],
            [['Average', $latency['avg']], ['p50', $latency['p50']], ['p95', $latency['p95']], ['Max', $latency['max']],
                ['Avg results per search', $s['avg_results']]],
            $e,
            [1]
        );

        $queryRows = static fn (array $rows): array => array_map(
            static fn (array $r): array => [$r['query'], $r['searches'], $r['last_seen']],
            $rows
        );
        $top = self::table(['Query', 'Searches', 'Last seen'], $queryRows($report['top_queries']), $e, [1], true);
        $zero = self::table(
            ['Query', 'Searches', 'Last seen'],
            $queryRows($report['zero_result_queries']),
            $e,
            [1],
            true
        );
        $volume = self::table(
            ['Period', 'Searches'],
            array_map(static fn (array $r): array => [$r['bucket'], $r['searches']], $report['volume']),
            $e,
            [1]
        );

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Search analytics</title><style>'
            . 'body{font:14px system-ui,sans-serif;margin:16px;color:#222}'
            . 'nav a{margin-right:12px}nav a.on{font-weight:700;text-decoration:none;color:#000}'
            . '.tiles{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0}'
            . '.tile{border:1px solid #ddd;padding:8px 16px;min-width:120px}'
            . '.tile b{display:block;font-size:22px}.tile span{color:#666}'
            . 'table{border-collapse:collapse;margin:8px 0 16px}'
            . 'th,td{border:1px solid #ddd;padding:4px 8px;text-align:start;vertical-align:top}'
            . 'th{background:#f4f4f4}td.n{text-align:end}'
            . '</style></head><body><h1>Search analytics</h1><nav>' . $nav . '</nav>'
            . '<p>' . $e(self::LABELS[$report['window']])
            . ($report['since'] !== null ? ', since ' . $e($report['since']) : '')
            . '</p><div class="tiles">' . $tileHtml . '</div>'
            . '<h2>Top queries</h2>' . $top
            . '<h2>Zero-result queries</h2>' . $zero
            . '<h2>Tier and cache</h2>' . $details
            . '<h2>Latency</h2>' . $latencyTable
            . '<h2>Volume</h2>' . $volume
            . '</body></html>';
    }

    private static function share(int $part, int $whole): string
    {
        return ($whole === 0 ? 0.0 : round(100 * $part / $whole, 1)) . '%';
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param callable(mixed): string $e escaper
     * @param list<int> $numeric column indexes right-aligned
     * @param bool $text first column holds user text (dir=auto)
     */
    private static function table(array $headers, array $rows, callable $e, array $numeric, bool $text = false): string
    {
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . $e($header) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $i => $value) {
                $class = in_array($i, $numeric, true) ? ' class="n"' : '';
                $dir = $text && $i === 0 ? ' dir="auto"' : '';
                $body .= '<td' . $class . $dir . '>' . $e($value) . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . count($headers) . '">No searches in this window.</td></tr>';
        }

        return '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }
}
