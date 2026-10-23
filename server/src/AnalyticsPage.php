<?php

declare(strict_types=1);

namespace App;

/**
 * The dashboard shell of public/analytics.php (M35). It embeds the report
 * (Analytics::report(), the only source of numbers) as JSON and ships
 * AnalyticsPage.js, which draws the tiles, hand-rolled SVG charts and sortable
 * tables and re-fetches `?format=json` every `logs.refresh_seconds`. No
 * third-party code and no request leaves the origin. The token is never written
 * into the page: the script reuses the page's own query string.
 */
final class AnalyticsPage
{
    /**
     * @param array<string, mixed> $report Analytics::report() plus `refresh_seconds`
     * @param string $nonce the per-response CSP nonce the script tag carries
     */
    public static function render(array $report, string $nonce): string
    {
        // JSON_HEX_* keep "</script>" or "<!--" in a user's query from ending the data block.
        $data = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $script = (string) file_get_contents(__DIR__ . '/AnalyticsPage.js');
        $n = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Search analytics</title>'
            . '<style>' . (string) file_get_contents(__DIR__ . '/AnalyticsPage.css') . '</style></head><body>'
            . '<div id="app"><h1>Search analytics</h1>'
            . '<noscript><p>This dashboard needs JavaScript. The same data is at '
            . '<a href="?format=json">?format=json</a>.</p></noscript></div>'
            . '<script type="application/json" id="report">' . $data . '</script>'
            . '<script nonce="' . $n . '">' . $script . '</script>'
            . '</body></html>';
    }
}
