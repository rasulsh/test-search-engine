<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminPage;
use App\AdminStrings;
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

    public function testNoUiStringOrScriptUsesPersianDigits(): void
    {
        $digits = '/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}\x{066A}\x{066B}\x{066C}]/u';
        $json = (string) json_encode([AnalyticsStrings::fa(), AdminStrings::fa()], JSON_UNESCAPED_UNICODE);

        self::assertSame(0, preg_match($digits, $json), 'strings use Latin digits and %');
        foreach (['AnalyticsPage.js', 'AdminPage.js', 'AdminViews.php'] as $file) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/src/' . $file);
            self::assertSame(0, preg_match($digits, $code), $file);
        }
    }

    public function testAllNumberAndDateFormattingLivesInOneHelper(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.js');
        $start = strpos($js, 'var fmt = (function');
        $end = strpos($js, '/* ---- DOM helpers');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $outside = substr($js, 0, $start) . substr($js, $end);
        // (toFixed(2) stays: it only sets a heatmap cell's fill-opacity, never text.)
        foreach (['Intl.', 'toLocale', 'toFixed(1)'] as $needle) {
            self::assertStringNotContainsString($needle, $outside, $needle . ' only inside fmt');
        }
        self::assertStringContainsString('fa-IR-u-ca-persian-nu-latn', $js);
        self::assertStringNotContainsString("fa-IR'", $js);
    }

    public function testTheFormattingHelperProducesLatinDigitsCommasAndJalaliDates(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }
        $js = (string) file_get_contents(dirname(__DIR__) . '/src/AnalyticsPage.js');
        $start = (int) strpos($js, 'var fmt = (function');
        $end = (int) strpos($js, '/* ---- DOM helpers');
        $helper = substr($js, $start, $end - $start);
        $script = "var T={ui:{ms:'{0} ms'}};function t(x,a){return x.replace('{0}',a[0]);}\n" . $helper
            . "console.log(JSON.stringify([fmt.n(1118),fmt.n(1234567.6),fmt.d1(11.66),fmt.d2(0.5),fmt.pct(11.7),"
            . "fmt.ms(315.6),fmt.jalali(new Date(Date.UTC(2026,9,25,9,56)),true),"
            . "fmt.jalali(new Date(Date.UTC(2026,9,25,9,56)),false),/^\\d\\d:\\d\\d:\\d\\d$/.test(fmt.now())]));";
        $file = tempnam(sys_get_temp_dir(), 'fmt_') . '.js';
        file_put_contents($file, $script);
        exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        @unlink($file);

        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame(
            ['1,118', '1,234,568', '11.7', '0.5', '11.7%', '316 ms', '1405/08/03 09:56', '1405/08/03', true],
            json_decode($out[0], true)
        );
    }
}
