<?php

declare(strict_types=1);

namespace App;

/**
 * The dashboard of the admin panel's analytics page (M35, inside the panel since M39). It embeds the report
 * (Analytics::report(), the only source of numbers) as JSON and ships
 * AnalyticsPage.js, which draws the tiles, hand-rolled SVG charts and sortable
 * tables and re-fetches `?format=json` every `logs.refresh_seconds`. No
 * third-party code and no request leaves the origin. The script reuses the page's own query
 * string, so the panel session cookie is the only credential.
 */
final class AnalyticsPage
{
    /** The widget styles; the colour tokens and base rules come from AdminPage.css. */
    public static function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/AnalyticsPage.css');
    }

    /**
     * The dashboard as panel content: the mount point, the three inert JSON blocks and the
     * script (the only code that runs, under the response nonce).
     *
     * @param array<string, mixed> $report Analytics::report() plus `refresh_seconds`
     * @param string $nonce the per-response CSP nonce the script tag carries
     * @param array<string, mixed> $meta page-only facts that never enter the report JSON: `db_clock`
     *        {zone, offset_minutes} of the database session
     */
    public static function body(array $report, string $nonce, array $meta = []): string
    {
        // JSON_HEX_* keep "</script>" or "<!--" in a user's query from ending the data block.
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
        $data = json_encode($report, $flags);
        $strings = json_encode(AnalyticsStrings::fa(), $flags);
        // The panel supplies the title and the theme switch.
        $metaJson = json_encode((object) ($meta + ['embedded' => true]), $flags);
        $script = (string) file_get_contents(__DIR__ . '/AnalyticsPage.js');
        $n = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

        return '<div id="app"><noscript><p>' . AnalyticsStrings::fa()['noscript']
            . ' <a href="?p=analytics&amp;format=json">?format=json</a></p></noscript></div>'
            . '<script type="application/json" id="report">' . $data . '</script>'
            . '<script type="application/json" id="strings">' . $strings . '</script>'
            . '<script type="application/json" id="meta">' . $metaJson . '</script>'
            . '<script nonce="' . $n . '">' . $script . '</script>';
    }
}
