<?php

declare(strict_types=1);

namespace App;

/**
 * The admin panel's document shell (M39): RTL Persian, self-hosted Vazirmatn, light / dark theme
 * (stored choice, else the system's, applied before first paint), side navigation, a help box per
 * page. Page bodies come from {@see AdminViews}. No request leaves the origin; the only scripts
 * are the nonce'd theme bootstrap and AdminPage.js (plus the analytics dashboard on its page).
 */
final class AdminPage
{
    /** Vazirmatn weights shipped under public/fonts/vazirmatn (OFL, self-hosted: font-src 'self'). */
    private const FONTS = ['Regular' => 400, 'Bold' => 700];

    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A full panel page.
     *
     * @param string $current nav key of the page
     * @param string $body trusted HTML of the page (built by AdminViews, which escapes its data)
     * @param array{nonce: string, fonts: string, csrf: string, tail?: string, css?: string} $ctx
     *        `tail` is extra markup after the body (the analytics dashboard), `css` extra styles
     */
    public static function shell(string $current, string $body, array $ctx): string
    {
        $fa = AdminStrings::fa();
        $nav = '';
        foreach ($fa['nav'] as $key => $label) {
            $nav .= '<a href="?p=' . self::esc($key) . '"' . ($key === $current ? ' aria-current="page"' : '')
                . '>' . self::esc($label) . '</a>';
        }
        $title = $fa['nav'][$current] ?? $fa['title'];
        $bar = '<header class="bar"><h1>' . self::esc($title) . '</h1><div>'
            . self::themeButton() . ' '
            . '<form method="post" action="?p=logout"><input type="hidden" name="csrf" value="'
            . self::esc($ctx['csrf']) . '"><button class="btn">' . self::esc($fa['ui']['logout'])
            . '</button></form></div></header>';
        $page = '<div class="shell"><aside class="side"><p class="brand">' . self::esc($fa['title'])
            . '</p><nav aria-label="' . self::esc($fa['nav_label']) . '">' . $nav . '</nav></aside>'
            . '<main>' . $bar . '<div class="content"><noscript><p class="note">' . self::esc($fa['ui']['noscript'])
            . '</p></noscript>' . $body . '</div>' . ($ctx['tail'] ?? '') . '</main></div>';

        return self::document($title, $page, $ctx);
    }

    /**
     * A page without navigation (login, refusals).
     *
     * @param array{nonce: string, fonts: string} $ctx
     */
    public static function bare(string $title, string $body, array $ctx): string
    {
        return self::document(
            $title,
            '<div class="float">' . self::themeButton() . '</div><main class="center"><div class="content">' . $body
                . '</div></main>',
            $ctx
        );
    }

    /** The light / dark switch, on every page including the ones without the side navigation. */
    private static function themeButton(): string
    {
        $ui = AdminStrings::fa()['ui'];

        return '<button type="button" class="btn" id="theme" aria-label="' . self::esc($ui['theme_label'])
            . '" data-to-dark="' . self::esc($ui['theme_to_dark'])
            . '" data-to-light="' . self::esc($ui['theme_to_light']) . '">'
            . self::esc($ui['theme_to_dark']) . '</button>';
    }

    /** @param array{nonce: string, fonts: string, css?: string} $ctx */
    private static function document(string $title, string $inner, array $ctx): string
    {
        $n = self::esc($ctx['nonce']);
        $fonts = (string) preg_replace('#[^A-Za-z0-9/_.-]#', '', $ctx['fonts']);
        $script = (string) file_get_contents(__DIR__ . '/AdminPage.js');

        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><link rel="icon" href="data:,">'
            . '<title>' . self::esc($title) . ' | '
            . self::esc(AdminStrings::fa()['title']) . '</title>'
            // Runs before first paint: the stored choice wins, otherwise the CSS media query decides.
            . '<script nonce="' . $n . '">try{var t=localStorage.getItem("admin-theme");'
            . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t)}}catch(e){}</script>'
            . '<style>' . self::fontFaces($fonts) . (string) file_get_contents(__DIR__ . '/AdminPage.css')
            . ($ctx['css'] ?? '') . '</style></head><body>' . $inner
            . '<script nonce="' . $n . '">' . $script . '</script></body></html>';
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
