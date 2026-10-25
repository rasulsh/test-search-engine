<?php

declare(strict_types=1);

namespace App;

/**
 * Every date the panel prints (M41): the stored Gregorian text untouched, left to right inside the RTL
 * line, with the Jalali date beside it in Latin digits. One helper, so a page cannot forget either.
 * The conversion is plain arithmetic: it does not depend on ext-intl, which shared hosting often lacks.
 */
final class AdminDate
{
    /** @return array{0: int, 1: int, 2: int} Jalali year, month, day */
    public static function jalali(int $year, int $month, int $day): array
    {
        $before = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $leapYear = $month > 2 ? $year + 1 : $year;
        $days = 355666 + 365 * $year + intdiv($leapYear + 3, 4) - intdiv($leapYear + 99, 100)
            + intdiv($leapYear + 399, 400) + $day + $before[$month - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
        }

        return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    /** "1405/08/03" for a text starting with a valid YYYY-MM-DD; null for anything else. */
    public static function jalaliText(string $stored): ?string
    {
        $isDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $stored, $m) === 1;
        if (!$isDate || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        [$y, $mo, $d] = self::jalali((int) $m[1], (int) $m[2], (int) $m[3]);

        return sprintf('%04d/%02d/%02d', $y, $mo, $d);
    }

    /** HTML for a stored timestamp: Gregorian as stored, then the Jalali date. "Unknown" for an empty value. */
    public static function html(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return AdminPage::esc(AdminStrings::fa()['ui']['unknown']);
        }
        $out = '<bdi class="ltr">' . AdminPage::esc($text) . '</bdi>';
        $jalali = self::jalaliText($text);

        return $jalali === null ? $out : $out . ' <small class="jal"><bdi class="ltr">' . $jalali . '</bdi></small>';
    }

    /** A Unix time as the database writes it (UTC), for values the code itself stamps. */
    public static function fromUnix(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }
}
