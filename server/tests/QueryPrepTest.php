<?php

declare(strict_types=1);

namespace App\Tests;

use App\QueryPrep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryPrepTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function entityCases(): array
    {
        return [
            'apostrophe entity' => ['Assassin&#039;s Creed', "Assassin's Creed"],
            'double encoded' => ['Assassin&amp;#039;s Creed', "Assassin's Creed"],
            'ampersand' => ['Ratchet &amp; Clank', 'Ratchet & Clank'],
            'double encoded ampersand' => ['Ratchet &amp;amp; Clank', 'Ratchet & Clank'],
            'quotes' => ['&quot;them&quot;', '"them"'],
            'stripped apostrophe' => ['Assassin#039s Creed', "Assassin's Creed"],
            'stripped ampersand' => ['ampamp', '&'],
            'stripped quotes' => ['ampquotthemampquot', '"them"'],
            'plain text is untouched' => ['amp 100w #5 rock&roll', 'amp 100w #5 rock&roll'],
            'persian is untouched' => ['استیل‌بوک', 'استیل‌بوک'],
        ];
    }

    #[DataProvider('entityCases')]
    public function testCleanDecodesEntities(string $raw, string $expected): void
    {
        self::assertSame($expected, QueryPrep::clean($raw));
    }

    /** @return array<string, array{string}> */
    public static function probes(): array
    {
        return [
            ['1 AND extractvalue(1,concat(0x7e,(select 1)))'],
            ['x UNION  SELECT password'],
            ['x union all select 1'],
            ['mafia; select sleep(5)'],
            ['SLEEP (3)'],
            ["mafia' -- "],
            ['admin" --'],
            ['x` -- y'],
            ['concat( 0x41'],
        ];
    }

    #[DataProvider('probes')]
    public function testSuspiciousQueriesAreFlagged(string $probe): void
    {
        self::assertTrue(QueryPrep::isSuspicious($probe));
    }

    public function testOrdinaryQueriesAreNotFlagged(): void
    {
        foreach (
            ['gta v', 'PS5 slim - white', 'selection of unions', 'sleeping bag', 'مافیا', "o'reilly",
            'ps5 -- slim', 'a--b', '-- 5', 'ps4 --'] as $query
        ) {
            self::assertFalse(QueryPrep::isSuspicious($query), $query);
        }
    }

    public function testCapKeepsAtMostTheTokenAndCharacterLimits(): void
    {
        self::assertSame(['a b c', false], QueryPrep::cap('  a   b c ', 100, 8));
        self::assertSame(['a b c', true], QueryPrep::cap('a b c d e', 100, 3));
        self::assertSame(['abcde', true], QueryPrep::cap('abcdefgh', 5, 8));
        self::assertSame('abc', QueryPrep::cap('abc def', 4, 8)[0]); // a cut word does not leave a trailing space
        self::assertSame(['مافیا دو', false], QueryPrep::cap('مافیا دو', 100, 8));
    }
}
