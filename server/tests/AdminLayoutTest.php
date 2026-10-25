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
}
