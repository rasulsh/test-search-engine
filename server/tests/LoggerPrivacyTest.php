<?php

declare(strict_types=1);

namespace App\Tests;

use App\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * M43: queries that look like personal data (a phone / ID number, a pasted paragraph) are
 * stored masked, never as typed. The caller still gets its own text back from /search.
 */
final class LoggerPrivacyTest extends DatabaseTestCase
{
    /** @return array<string, array{string, string}> */
    public static function digitRuns(): iterable
    {
        yield 'latin phone' => ['call 09123456789 now', 'call # now'];
        yield 'persian digits' => ['۰۹۱۲۳۴۵۶۷۸۹', '#'];
        yield 'arabic-indic digits' => ['٠٩١٢٣٤٥٦٧٨٩', '#'];
        yield 'exactly seven' => ['1234567', '#'];
        yield 'six is a model number' => ['rtx 123456', 'rtx 123456'];
        yield 'separated groups stay' => ['0912 345 6789', '0912 345 6789'];
        yield 'product query' => ['ps5 slim 1tb', 'ps5 slim 1tb'];
    }

    #[DataProvider('digitRuns')]
    public function testDigitRunsOfSevenOrMoreAreMasked(string $raw, string $expected): void
    {
        self::assertSame($expected, Logger::maskDigits($raw));
    }

    public function testThePhoneNumberNeverReachesTheTable(): void
    {
        (new Logger($this->pdo, 'search_logs'))->log([
            'raw_q' => 'خرید 09123456789', 'normalized_q' => 'خرید 09123456789',
            'did_you_mean' => 'خرید 09123456780', 'expanded_q' => 'خرید 09123456789 | x', 'search_uuid' => 'u1',
        ]);

        $row = (array) $this->pdo->query('SELECT * FROM search_logs')->fetch();
        self::assertSame('خرید #', $row['raw_q']);
        self::assertSame('خرید #', $row['normalized_q']);
        self::assertSame('خرید #', $row['did_you_mean']);
        self::assertSame('خرید # | x', $row['expanded_q']);
    }

    public function testALongQueryIsStoredAsAPlaceholderWhateverItSays(): void
    {
        $logger = new Logger($this->pdo, 'search_logs');
        $logger->log(['raw_q' => str_repeat('a', 81), 'normalized_q' => str_repeat('a', 81)]);
        $logger->log(['raw_q' => str_repeat('b', 80), 'normalized_q' => str_repeat('b', 80)]);

        $rows = $this->pdo->query('SELECT raw_q, normalized_q FROM search_logs ORDER BY id')->fetchAll();
        self::assertSame(['raw_q' => Logger::LONG_PLACEHOLDER, 'normalized_q' => Logger::LONG_PLACEHOLDER], $rows[0]);
        self::assertSame(str_repeat('b', 80), $rows[1]['raw_q'], 'eighty characters is still a query');
    }

    public function testFlaggedProbesAreKeptVerbatimForTheReview(): void
    {
        $probe = "1' union select 1234567890,2 -- " . str_repeat('x', 60);
        (new Logger($this->pdo, 'search_logs'))->log(['raw_q' => $probe, 'is_suspicious' => true]);

        self::assertSame($probe, $this->pdo->query('SELECT raw_q FROM search_logs')->fetchColumn());
    }
}
