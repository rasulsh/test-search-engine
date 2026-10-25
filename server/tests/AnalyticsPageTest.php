<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminPage;
use App\AnalyticsPage;
use App\AnalyticsStrings;
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

        $html = AnalyticsPage::body(self::report($query), 'abc123');

        preg_match('#<script type="application/json" id="report">(.*?)</script>#s', $html, $m);
        self::assertSame(self::report($query), json_decode($m[1], true));
        self::assertSame(
            4,
            substr_count($html, '</script>'),
            'three data blocks and the dashboard code, nothing a query adds'
        );
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testOnlyTheNoncedBlockRunsAndNothingLoadsFromElsewhere(): void
    {
        $html = AnalyticsPage::body(self::report('x'), 'N0nce+/=');

        self::assertSame(1, substr_count($html, '<script nonce="N0nce+/=">'));
        self::assertSame(1, substr_count($html, '<script nonce='), 'the only code block carries the response nonce');
        foreach (['<link', ' src=', '@import', 'https://', 'cdn', 'url(http'] as $external) {
            self::assertStringNotContainsString($external, $html, $external);
        }
        self::assertSame(1, preg_match_all('#http://#', $html), 'only the SVG namespace URI');
        self::assertStringContainsString('http://www.w3.org/2000/svg', $html);
    }

    public function testTheBodyIsEmbeddedWithoutItsOwnTitleAndThemeSwitch(): void
    {
        $html = AnalyticsPage::body(self::report('x'), 'n');

        self::assertStringContainsString('"embedded":true', $html);
        self::assertStringContainsString('id="strings"', $html);
        self::assertStringContainsString('format=json', $html);
        self::assertStringContainsString("var THEME_KEY = 'admin-theme'", $html, 'one stored theme choice');
        self::assertStringContainsString('prefers-color-scheme', AnalyticsPage::css() . $html);
        self::assertStringNotContainsString('<html', $html);
        foreach (['پرترددترین ساعت‌ها', 'نرخ جستجوی بی‌نتیجه'] as $text) {
            self::assertStringContainsString($text, $html, $text);
        }
    }

    public function testThePanelPageCarriesTheDashboardInPersianRtlWithSelfHostedFonts(): void
    {
        $page = AdminPage::shell('analytics', 'x', [
            'nonce' => 'n', 'fonts' => 'fonts/vazirmatn/', 'csrf' => 'c', 'css' => AnalyticsPage::css(),
            'tail' => AnalyticsPage::body(self::report('x'), 'n'),
        ]);

        self::assertStringContainsString('<html lang="fa" dir="rtl">', $page);
        self::assertSame(2, preg_match_all('#url\(fonts/vazirmatn/Vazirmatn-\w+\.woff2\)#', $page));
        self::assertStringContainsString('data-theme="dark"', $page);
        self::assertStringContainsString('prefers-color-scheme:dark', $page);
        self::assertStringNotContainsString('url(http', $page);
    }

    public function testEveryPersianUiStringLivesInTheStringsFile(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.js');

        // The help mark and the percent sign are the only Persian characters the script itself holds.
        self::assertSame(0, preg_match('/[\x{0600}-\x{06FF}]/u', str_replace(['؟', '٪'], '', $js)));
    }

    public function testEveryCardHelpEntryIsComplete(): void
    {
        $fa = AnalyticsStrings::fa();

        self::assertGreaterThanOrEqual(25, count($fa['help']));
        foreach ($fa['help'] as $key => $entry) {
            foreach (['what', 'calc', 'read', 'act'] as $part) {
                self::assertNotSame('', trim($entry[$part] ?? ''), "help {$key}.{$part}");
            }
        }
        foreach (['alias', 'tile_unique', 'tile_per_session'] as $key) {
            self::assertStringContainsString('session_id', $fa['help'][$key]['needs']);
        }
        foreach (['silent', 'tile_ctr', 'tile_mrr'] as $key) {
            self::assertStringContainsString('/events', $fa['help'][$key]['needs']);
        }
    }

    public function testTheFontsAndTheirLicenceShipUnderPublic(): void
    {
        $dir = dirname(__DIR__) . '/public/fonts/vazirmatn';

        foreach (['Regular', 'Bold'] as $weight) {
            $file = "{$dir}/Vazirmatn-{$weight}.woff2";
            self::assertFileExists($file);
            self::assertSame('wOF2', substr((string) file_get_contents($file, false, null, 0, 4), 0, 4), $weight);
        }
        $licence = strtoupper((string) file_get_contents($dir . '/OFL.txt'));
        self::assertStringContainsString('SIL OPEN FONT LICENSE', $licence);
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
