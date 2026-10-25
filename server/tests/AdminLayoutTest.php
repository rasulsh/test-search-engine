<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** The side navigation stays open on every desktop width: only a phone-sized viewport may collapse it. */
final class AdminLayoutTest extends TestCase
{
    private const COLLAPSE_AT = 760;

    public function testNoMediaQueryCollapsesTheShellAboveAPhoneWidth(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/src/AdminPage.css')
            . (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.css');
        preg_match_all('/@media\s*([^{]+)\{/', $css, $queries);
        self::assertNotSame([], $queries[1]);
        foreach ($queries[1] as $query) {
            if (str_contains($query, 'prefers-color-scheme')) {
                continue;
            }
            self::assertStringNotContainsString('min-width', $query, 'no desktop-only query may hide the nav');
            self::assertSame(1, preg_match('/max-width:\s*(\d+)px/', $query, $m), $query);
            self::assertLessThanOrEqual(self::COLLAPSE_AT, (int) $m[1], $query);
        }
    }

    public function testTheSidebarIsAFixedWidthColumnAndTheAnalyticsStylesDoNotTouchIt(): void
    {
        $panel = (string) file_get_contents(dirname(__DIR__) . '/src/AdminPage.css');
        self::assertStringContainsString('grid-template-columns:220px minmax(0,1fr)', $panel);
        $analytics = (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.css');
        foreach (['.side', '.shell', 'aside', ' nav', 'main'] as $selector) {
            self::assertStringNotContainsString($selector . '{', $analytics, $selector);
            self::assertStringNotContainsString($selector . ' ', $analytics, $selector);
        }
    }

    public function testTheContentUsesTheWholeColumnUpToAboutSixteenHundredPixels(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/src/AdminPage.css');
        self::assertMatchesRegularExpression('/\.content\{[^}]*width:100%[^}]*max-width:1600px/', $css);
        self::assertStringNotContainsString('max-width:1280px', $css);
        $analytics = (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.css');
        self::assertDoesNotMatchRegularExpression('/#app\{[^}]*max-width/', $analytics, 'the dashboard fills it too');
    }

    public function testTablesFillTheCardAndOnlyTheCardScrollsSideways(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/src/AdminPage.css');
        self::assertStringContainsString('table{border-collapse:collapse;width:100%}', $css);
        self::assertStringContainsString('.scroll{overflow-x:auto}', $css);
        self::assertStringContainsString('overflow-x:hidden', $css, 'the page itself never scrolls sideways');
        self::assertStringNotContainsString('.cmp{width:auto', $css);
        self::assertMatchesRegularExpression('/td\.c,th\.c\{text-align:center/', $css, 'headers align like cells');
    }

    public function testTheLoginBoxIsCenteredInBothDirectionsWhateverTheViewport(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/src/AdminPage.css');
        self::assertMatchesRegularExpression(
            '/\.center\{[^}]*min-height:100vh[^}]*display:grid[^}]*place-items:center/',
            $css
        );
        self::assertStringNotContainsString('12vh', $css);
        $html = \App\AdminPage::bare('t', '<form class="login"></form>', ['nonce' => 'n', 'fonts' => 'f/']);
        self::assertStringContainsString('<main class="center"><div class="content"><form class="login">', $html);
        self::assertStringContainsString('id="theme"', $html, 'the theme switch stays on the page');
    }

    public function testEveryNumericDateAndCodeColumnOfTheBigTablesIsCentered(): void
    {
        $logs = \App\AdminViews::logs([
            'rows' => [['id' => 1, 'ts' => '2026-01-01 10:00:00', 'raw_q' => 'x', 'normalized_q' => 'x',
                'tier' => 'hybrid', 'had_vector' => 1, 'result_count' => 3, 'top_ids' => '1,2', 'latency_ms' => 9,
                'did_you_mean' => '', 'customer_id' => '', 'cache_hit' => 0]],
            'total' => 1, 'page' => 1, 'pages' => 1, 'view' => 'recent', 'sort' => '', 'dir' => 'desc', 'q' => '',
            'per' => 50, 'from' => 1, 'to' => 1, 'scan_limit' => null, 'default_per' => 50,
            'sizes' => [25, 50, 100, 200],
        ]);
        preg_match('#<thead>(.*?)</thead>#su', $logs, $head);
        preg_match_all('#<th([^>]*)>#u', $head[1], $headers);
        preg_match('#<tbody>(.*?)</tbody>#su', $logs, $body);
        preg_match_all('#<td([^>]*)>#u', $body[1], $cells);
        $kind = static fn (string $attributes): string => str_contains($attributes, 'class="c') ? 'c' : '';

        // id, ts | raw_q, normalized_q | tier .. latency_ms | did_you_mean | customer_id, cache_hit
        $expected = ['c', 'c', '', '', 'c', 'c', 'c', 'c', 'c', '', 'c', 'c'];
        self::assertSame($expected, array_map($kind, $headers[1]), 'header cells');
        self::assertSame($expected, array_map($kind, $cells[1]), 'body cells: headers align like their cells');
    }
}
