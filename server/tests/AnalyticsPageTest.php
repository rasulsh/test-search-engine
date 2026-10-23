<?php

declare(strict_types=1);

namespace App\Tests;

use App\AnalyticsPage;
use PHPUnit\Framework\TestCase;

final class AnalyticsPageTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function report(string $query): array
    {
        return ['window' => '7d', 'refresh_seconds' => 30, 'top_queries' => [['query' => $query, 'searches' => 2]]];
    }

    public function testTheReportIsEmbeddedAsInertJsonThatRoundTrips(): void
    {
        $query = '</script><script>alert(1)</script> <!-- "q" & \'x\' ' . "\u{0641}\u{0631}\u{0645}\u{0648}\u{0646}";

        $html = AnalyticsPage::render(self::report($query), 'abc123');

        preg_match('#<script type="application/json" id="report">(.*?)</script>#s', $html, $m);
        self::assertSame(self::report($query), json_decode($m[1], true));
        self::assertSame(
            2,
            substr_count($html, '</script>'),
            'the data block and the code block, nothing a query adds'
        );
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testOnlyTheNoncedBlockRunsAndNothingLoadsFromElsewhere(): void
    {
        $html = AnalyticsPage::render(self::report('x'), 'N0nce+/=');

        self::assertStringContainsString('<script nonce="N0nce+/=">', $html);
        self::assertSame(1, substr_count($html, '<script nonce='));
        foreach (['<link', ' src=', '@import', 'https://', 'cdn'] as $external) {
            self::assertStringNotContainsString($external, $html, $external);
        }
        self::assertSame(1, preg_match_all('#http://#', $html), 'only the SVG namespace URI');
        self::assertStringContainsString('http://www.w3.org/2000/svg', $html);
    }

    public function testTheShellNamesTheDashboardSectionsAndDarkMode(): void
    {
        $html = AnalyticsPage::render(self::report('x'), 'n');

        foreach (
            ['Search analytics', 'Zero-result rate', 'Cache hit rate', 'Hybrid share', 'p95 latency', 'Busiest hours',
            'Query length', 'Top brands', 'Top categories', 'Zero-result queries', 'Top queries',
            'prefers-color-scheme:dark',
            'Filter queries', 'format=json'] as $text
        ) {
            self::assertStringContainsString($text, $html, $text);
        }
    }

    public function testTheDashboardScriptIsValidJavaScript(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }

        $script = dirname(__DIR__) . '/src/AnalyticsPage.js';
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($script) . ' 2>&1', $out, $code);

        self::assertSame(0, $code, implode("\n", $out));
    }
}
