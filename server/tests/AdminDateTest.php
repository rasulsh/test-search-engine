<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminDate;
use App\AdminViews;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminDateTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function knownDates(): iterable
    {
        yield 'Nowruz 1403' => ['2024-03-20', '1403/01/01'];
        yield 'last day of 1402' => ['2024-03-19', '1402/12/29'];
        yield 'Nowruz 1404' => ['2025-03-21', '1404/01/01'];
        yield 'a leap-year Esfand 30' => ['2025-03-20', '1403/12/30'];
        yield 'Nowruz 1405' => ['2026-03-21', '1405/01/01'];
        yield 'the day this was written' => ['2026-10-25', '1405/08/03'];
        yield 'year end' => ['2026-12-31', '1405/10/10'];
        yield 'turn of the century' => ['2000-01-01', '1378/10/11'];
        yield 'with a time' => ['2026-10-25 14:03:07', '1405/08/03'];
        yield 'ISO with zone' => ['2026-10-01T10:00:00Z', '1405/07/09'];
    }

    #[DataProvider('knownDates')]
    public function testJalaliDateOfAStoredTimestamp(string $stored, string $expected): void
    {
        self::assertSame($expected, AdminDate::jalaliText($stored));
    }

    /** @return iterable<string, array{string}> */
    public static function notDates(): iterable
    {
        yield 'text' => ['soon'];
        yield 'impossible day' => ['2026-02-30 00:00:00'];
        yield 'month 13' => ['2026-13-01'];
        yield 'zero date' => ['0000-00-00 00:00:00'];
        yield 'short' => ['2026-10'];
    }

    #[DataProvider('notDates')]
    public function testAnythingThatIsNotADateHasNoJalaliForm(string $stored): void
    {
        self::assertNull(AdminDate::jalaliText($stored));
    }

    public function testHtmlKeepsTheStoredTextAndAddsTheJalaliDateBesideIt(): void
    {
        $html = AdminDate::html('2026-10-25 14:03:07');
        self::assertStringContainsString('<bdi class="ltr">2026-10-25 14:03:07</bdi>', $html);
        self::assertStringContainsString('<bdi class="ltr">1405/08/03</bdi>', $html);
        self::assertSame($html, AdminViews::ts('2026-10-25 14:03:07'), 'every page goes through this helper');
    }

    public function testHtmlEscapesTheValueAndSurvivesNonDates(): void
    {
        self::assertSame('<bdi class="ltr">&lt;b&gt;</bdi>', AdminDate::html('<b>'));
        self::assertSame('نامشخص', AdminDate::html(null));
        self::assertSame('نامشخص', AdminDate::html('  '));
        self::assertSame('نامشخص', AdminDate::html(['x']));
    }

    public function testFromUnixIsTheDatabaseFormatInUtc(): void
    {
        self::assertSame('2026-10-25 14:03:07', AdminDate::fromUnix(gmmktime(14, 3, 7, 10, 25, 2026)));
    }

    /** @param array<string, string> $row */
    private static function detail(string $action, string $detail): string
    {
        return AdminViews::auditDetail(['action' => $action, 'detail' => $detail, 'ts' => '', 'ip' => '']);
    }

    public function testAuditDetailTurnsOperationJsonIntoASentence(): void
    {
        $ok = self::detail('reload_apply', '{"ok":true,"count":1500,"s":2.34}');
        self::assertStringContainsString('1,500', $ok);
        self::assertStringNotContainsString('{', $ok);
        self::assertStringNotContainsString('"ok"', $ok);

        $failed = self::detail('rollback', '{"ok":false,"reason":"nothing_to_roll_back"}');
        self::assertStringContainsString('badge bad', $failed);
        self::assertStringNotContainsString('nothing_to_roll_back', $failed, 'the reason is worded, not echoed');
    }

    public function testAuditDetailReadsTheReloadApiCountAndEmptyDetails(): void
    {
        self::assertStringContainsString('1,234', self::detail('reload_api', 'count=1234'));
        self::assertSame('-', self::detail('login_ok', ''));
    }

    public function testAuditDetailListsWhatAnAliasSaveChanged(): void
    {
        $html = self::detail('alias_save', (string) json_encode([
            'mode' => 'group', 'groups' => 12, 'terms' => 30, 'added' => ['ps5', 'xbox'], 'removed' => [],
            'added_total' => 6, 'removed_total' => 0,
        ], JSON_UNESCAPED_UNICODE));

        self::assertStringContainsString('ps5، xbox (+4)', $html);
        self::assertStringContainsString('حذف‌شده: -', $html);
        self::assertStringNotContainsString('added_total', $html);
    }

    public function testAuditDetailNeverRunsMarkupOrBrokenJsonThroughRaw(): void
    {
        $broken = self::detail('alias_save', '{"groups":"<script>alert(1)</script>"');
        self::assertStringNotContainsString('<script>', $broken);
        self::assertStringContainsString('&lt;script&gt;', self::detail('something_else', '<script>x</script>'));
        $row = (string) json_encode(['added' => ['<img src=x>'], 'added_total' => 1]);
        self::assertStringNotContainsString('<img', self::detail('alias_save', $row));
    }
}
