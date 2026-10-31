<?php

declare(strict_types=1);

namespace App\Tests;

use App\Analytics;
use App\AnalyticsInsights;
use App\Config;
use App\Logger;
use App\SearchRows;

/** "A search" in the analytics: one shared condition, the 120 s duplicate window and the burst flag. */
final class SearchRowsTest extends DatabaseTestCase
{
    private function logger(int $duplicateSeconds = Logger::DUPLICATE_SECONDS): Logger
    {
        return new Logger($this->pdo, 'search_logs', $duplicateSeconds);
    }

    /** @param array<string, mixed> $entry */
    private function write(array $entry = []): array
    {
        $this->logger()->log($entry + ['raw_q' => 'mafia', 'normalized_q' => 'mafia', 'session_id' => 's1']);

        return (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    /** @param array<string, mixed> $row */
    private function insert(string $query, array $row = []): void
    {
        $row += ['is_duplicate' => 0, 'page' => 1, 'source' => 'page', 'is_burst' => 0, 'result_count' => 1];
        $this->pdo->prepare(
            'INSERT INTO search_logs (raw_q, normalized_q, result_count, log_version, is_duplicate, page, source,'
            . ' is_burst) VALUES (:q, :n, :r, 2, :d, :p, :s, :b)'
        )->execute([
            'q' => $query, 'n' => $query, 'r' => $row['result_count'], 'd' => $row['is_duplicate'], 'p' => $row['page'],
            's' => $row['source'], 'b' => $row['is_burst'],
        ]);
    }

    private function seedMixed(): void
    {
        $this->insert('counted a');
        $this->insert('counted b', ['result_count' => 0]);
        $this->insert('repeat', ['is_duplicate' => 1]);
        $this->insert('paging', ['page' => 2, 'source' => 'paging']);
        $this->insert('sorted', ['source' => 'filter']);
        $this->insert('burst', ['is_burst' => 1]);
        $this->insert('typeahead', ['source' => 'autocomplete']);
    }

    public function testTheConditionListsExactlyTheColumnsTheTableHas(): void
    {
        $all = SearchRows::conditions(static fn (string $c): bool => true, 'l.');
        self::assertSame(
            ['l.is_duplicate = 0', 'l.page = 1', "l.source <> 'filter'", 'l.is_burst = 0'],
            $all
        );
        self::assertSame(
            ['is_duplicate = 0', 'page = 1'],
            SearchRows::conditions(static fn (string $c): bool => in_array($c, ['is_duplicate', 'page'], true))
        );
        self::assertSame([], SearchRows::conditions(static fn (string $c): bool => false));
    }

    public function testAnalyticsAndInsightsCountTheSameSearches(): void
    {
        $this->seedMixed();
        $analytics = new Analytics($this->pdo, 'search_logs');

        // counted a, counted b, typeahead.
        self::assertSame(3, $analytics->resultHealth(null)['total']);
        self::assertSame(1, $analytics->resultHealth(null)['zero_results']);
        self::assertSame(3, array_sum(array_column($analytics->volume(null), 'searches')));
        self::assertSame(3, array_sum(array_column($analytics->zeroRateTrend(null), 'n')));
        $queries = array_column($analytics->topQueries(null), 'query');
        sort($queries);
        self::assertSame(['counted a', 'counted b', 'typeahead'], $queries);

        $insights = new AnalyticsInsights($this->pdo, 'search_logs', 'search_events', null);
        self::assertSame(3, $insights->unique(null, null)['searches']);
        self::assertSame(['counted b'], array_column($insights->zeroResultQueries(null, null, 10), 'query'));
    }

    public function testTheRequestCardCountsEveryRowAgainstTheSearches(): void
    {
        $this->seedMixed();

        self::assertSame(
            ['requests' => 7, 'searches' => 3, 'filtered' => 4],
            (new Analytics($this->pdo, 'search_logs'))->requestsVsSearches(null)
        );
        $report = (new Analytics($this->pdo, 'search_logs'))->report('all');
        self::assertSame(7, $report['requests']['requests']);
    }

    public function testAnOldTableWithoutTheNewerColumnsKeepsTheOldBehaviour(): void
    {
        $this->seedMixed();
        $this->pdo->exec('ALTER TABLE search_logs DROP COLUMN is_burst, DROP COLUMN source, DROP COLUMN page');

        // Only is_duplicate is left: 'repeat' is out, the rest (paging, filter, burst rows) counts.
        $analytics = new Analytics($this->pdo, 'search_logs');
        self::assertSame(6, $analytics->resultHealth(null)['total']);
        self::assertSame(
            ['requests' => 7, 'searches' => 6, 'filtered' => 1],
            $analytics->requestsVsSearches(null)
        );

        $this->pdo->exec('ALTER TABLE search_logs DROP COLUMN is_duplicate');
        self::assertSame(7, (new Analytics($this->pdo, 'search_logs'))->resultHealth(null)['total']);
    }

    public function testATableWithoutOnlyIsBurstStillAppliesTheOtherConditions(): void
    {
        $this->seedMixed();
        $this->pdo->exec('ALTER TABLE search_logs DROP COLUMN is_burst');

        // 'burst' is now a plain row: counted a, counted b, burst, typeahead.
        self::assertSame(4, (new Analytics($this->pdo, 'search_logs'))->resultHealth(null)['total']);
        $insights = new AnalyticsInsights($this->pdo, 'search_logs', 'search_events', null);
        self::assertSame(4, $insights->unique(null, null)['searches']);
    }

    public function testTheDuplicateWindowIs120SecondsByDefaultAndConfigurable(): void
    {
        self::assertSame(120, Logger::DUPLICATE_SECONDS);
        self::assertSame(120, Config::load(null)['log']['duplicate_seconds']);

        $this->write();
        $this->pdo->exec('UPDATE search_logs SET ts = NOW() - INTERVAL 100 SECOND');
        self::assertSame(1, (int) $this->write()['is_duplicate'], '100 s ago is inside 120 s');

        $this->pdo->exec('UPDATE search_logs SET ts = NOW() - INTERVAL 130 SECOND');
        self::assertSame(0, (int) $this->write(['session_id' => 's1'])['is_duplicate'], '130 s ago is outside');

        $this->pdo->exec('DELETE FROM search_logs');
        $this->write();
        $this->pdo->exec('UPDATE search_logs SET ts = NOW() - INTERVAL 20 SECOND');
        $this->logger(10)->log(['raw_q' => 'mafia', 'normalized_q' => 'mafia', 'session_id' => 's1']);
        $row = (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame(0, (int) $row['is_duplicate'], 'a 10 s window leaves 20 s ago out');

        $this->logger(0)->log(['raw_q' => 'mafia', 'normalized_q' => 'mafia', 'session_id' => 's1']);
        $row = (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame(0, (int) $row['is_duplicate'], '0 turns the flag off');
    }

    public function testTheFourthIdenticalPageOneRowOfASessionIsABurst(): void
    {
        $flags = [];
        for ($i = 0; $i < 6; $i++) {
            $flags[] = (int) $this->write()['is_burst'];
        }
        self::assertSame([0, 0, 0, 1, 1, 1], $flags);

        $row = $this->write(['session_id' => 's2']);
        self::assertSame(0, (int) $row['is_burst'], 'another session starts over');
        self::assertSame(0, (int) $this->write(['normalized_q' => 'other', 'raw_q' => 'other'])['is_burst']);
        self::assertSame(0, (int) $this->write(['page' => 2])['is_burst'], 'page 2 is never a burst');
        self::assertSame(
            0,
            (int) $this->write(['is_suspicious' => false])['is_suspicious'],
            'burst is not the probe flag'
        );
    }

    public function testBurstRowsOutsideTheMinuteDoNotCount(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->write();
        }
        $this->pdo->exec('UPDATE search_logs SET ts = NOW() - INTERVAL 61 SECOND');

        self::assertSame(0, (int) $this->write()['is_burst']);
    }

    public function testWithoutASessionTheBurstIsPerNormalizedQuery(): void
    {
        $flags = [];
        for ($i = 0; $i < 5; $i++) {
            $flags[] = (int) $this->write(['session_id' => null])['is_burst'];
        }
        self::assertSame([0, 0, 0, 1, 1], $flags);
        $other = $this->write(['session_id' => null, 'normalized_q' => 'x', 'raw_q' => 'x']);
        self::assertSame(0, (int) $other['is_burst']);
    }

    public function testABurstRowIsKeptInTheRawLogButNotCounted(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->write(['session_id' => null]);
        }

        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
        self::assertSame(3, (new Analytics($this->pdo, 'search_logs'))->requestsVsSearches(null)['searches']);
    }

    public function testTheLoggerAddsTheBurstColumnToAnOlderTable(): void
    {
        $this->pdo->exec('ALTER TABLE search_logs DROP COLUMN is_burst');

        $row = $this->write();

        self::assertSame(0, (int) $row['is_burst']);
    }
}
