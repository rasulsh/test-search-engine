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
    /** Vazirmatn weights shipped under public/fonts/vazirmatn (OFL, self-hosted: font-src 'self'). */
    private const FONTS = ['Regular' => 400, 'Bold' => 700];

    /**
     * @param array<string, mixed> $report Analytics::report() plus `refresh_seconds`
     * @param string $nonce the per-response CSP nonce the script tags carry
     * @param array<string, mixed> $meta page-only facts that never enter the report JSON: `db_clock`
     *        {zone, offset_minutes} of the database session, `fonts` URL prefix of the font folder (not sent to the script)
     */
    public static function render(array $report, string $nonce, array $meta = []): string
    {
        // JSON_HEX_* keep "</script>" or "<!--" in a user's query from ending the data block.
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
        $data = json_encode($report, $flags);
        $strings = json_encode(AnalyticsStrings::fa(), $flags);
        $fontBase = (string) ($meta['fonts'] ?? 'fonts/vazirmatn/');
        $fontBase = preg_replace('#[^A-Za-z0-9/_.-]#', '', $fontBase);
        unset($meta['fonts']);
        $metaJson = json_encode((object) $meta, $flags);
        $script = (string) file_get_contents(__DIR__ . '/AnalyticsPage.js');
        $n = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
        $title = AnalyticsStrings::fa()['title'];

        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . $title . '</title>'
            // Runs before first paint: the stored choice wins, otherwise the CSS media query decides.
            . '<script nonce="' . $n . '">try{var t=localStorage.getItem("analytics-theme");'
            . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t)}}catch(e){}</script>'
            . '<style>' . self::fontFaces($fontBase) . (string) file_get_contents(__DIR__ . '/AnalyticsPage.css')
            . '</style></head><body>'
            . '<div id="app"><h1>' . $title . '</h1>'
            . '<noscript><p>' . AnalyticsStrings::fa()['noscript']
            . ' <a href="?format=json">?format=json</a></p></noscript></div>'
            . '<script type="application/json" id="report">' . $data . '</script>'
            . '<script type="application/json" id="strings">' . $strings . '</script>'
            . '<script type="application/json" id="meta">' . $metaJson . '</script>'
            . '<script nonce="' . $n . '">' . $script . '</script>'
            . '</body></html>';
    }

    private static function fontFaces(string $base): string
    {
        $css = '';
        foreach (self::FONTS as $name => $weight) {
            $css .= "@font-face{font-family:Vazirmatn;font-style:normal;font-weight:{$weight};font-display:swap;"
                . "src:url({$base}Vazirmatn-{$name}.woff2) format('woff2')}";
        }

        return $css;
    }
}
