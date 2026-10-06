<?php

declare(strict_types=1);

namespace App\Tests;

use App\Analytics;
use InvalidArgumentException;

final class AnalyticsTest extends DatabaseTestCase
{
    private Analytics $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new Analytics($this->pdo, 'search_logs');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function log(string $rawQuery, int $hoursAgo, array $row = []): void
    {
        $row += [
            'normalized_q' => strtolower($rawQuery), 'result_count' => 3, 'latency_ms' => 10,
            'tier' => 'keyword_only', 'had_vector' => 0, 'cache_hit' => 0, 'did_you_mean' => null,
        ];
        $this->pdo->prepare(
            'INSERT INTO search_logs (ts, raw_q, normalized_q, had_vector, result_count, latency_ms, tier,'
            . ' cache_hit, did_you_mean) VALUES (DATE_SUB(NOW(), INTERVAL :h HOUR), :raw, :norm, :vec, :res,'
            . ' :lat, :tier, :cache, :dym)'
        )->execute([
            'h' => $hoursAgo, 'raw' => $rawQuery, 'norm' => $row['normalized_q'], 'vec' => $row['had_vector'],
            'res' => $row['result_count'], 'lat' => $row['latency_ms'], 'tier' => $row['tier'],
            'cache' => $row['cache_hit'], 'dym' => $row['did_you_mean'],
        ]);
    }

    /** A known catalog of searches across 5 days and one far outside every window. */
    private function seed(): void
    {
        $hybrid = ['tier' => 'hybrid', 'had_vector' => 1, 'result_count' => 5, 'latency_ms' => 40];
        $this->log('Far Cry', 1, $hybrid);
        $this->log('far cry', 2, $hybrid + ['cache_hit' => 1]);
        $this->log('FAR  CRY', 3, $hybrid + ['cache_hit' => 1, 'normalized_q' => 'far cry']);
        $this->log('Mouse', 30, ['latency_ms' => 20]);
        $this->log('mouse', 40, ['latency_ms' => 20, 'did_you_mean' => 'mouse pad']);
        $this->log('zzzz', 50, ['result_count' => 0]);
        $this->log('zzzz', 60, ['result_count' => 0, 'did_you_mean' => '']);
        $this->log('qqq', 240, ['result_count' => 0]);          // 10 days ago
        $this->log('RAW ONLY', 1, ['result_count' => 0, 'normalized_q' => '']);
        $this->log('oldie', 100 * 24, ['result_count' => 1]);   // 100 days ago
    }

    public function testWindowsMapToDateBounds(): void
    {
        $now = strtotime((string) $this->pdo->query('SELECT NOW()')->fetchColumn());

        foreach (['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400, '90d' => 90 * 86400] as $label => $seconds) {
            $since = strtotime((string) $this->analytics->since($label));
            self::assertEqualsWithDelta($now - $seconds, $since, 5, $label);
        }
        self::assertNull($this->analytics->since('all'));
        self::assertSame(Analytics::window('7d'), Analytics::window('bogus'), 'unknown windows fall back to 7d');
        self::assertSame(['24h', '7d', '30d', '90d', 'all'], array_keys(Analytics::WINDOWS));
    }

    public function testTopQueriesGroupByNormalizedFormWithinTheWindow(): void
    {
        $this->seed();
        $week = $this->analytics->since('7d');

        $top = $this->analytics->topQueries($week, 10);

        self::assertSame(
            [['far cry', 3], ['mouse', 2], ['zzzz', 2], ['raw only', 1]], // ties: most recently seen first
            array_map(static fn (array $r): array => [strtolower($r['query']), $r['searches']], $top)
        );
        self::assertSame('far cry', $top[0]['query'], 'spelling variants of one normalized form merge');
        self::assertContains('RAW ONLY', array_column($top, 'query'), 'an empty normalized form falls back to raw_q');
        self::assertNotContains('qqq', array_column($top, 'query'), '10 days ago is outside 7d');
        self::assertNotContains('oldie', array_column($top, 'query'));
        self::assertCount(2, $this->analytics->topQueries($week, 2), 'the limit applies');
    }

    public function testWiderWindowsAndAllTimeIncludeOlderSearches(): void
    {
        $this->seed();

        $month = array_column($this->analytics->topQueries($this->analytics->since('30d'), 20), 'query');
        $quarter = array_column($this->analytics->topQueries($this->analytics->since('90d'), 20), 'query');
        $all = array_column($this->analytics->topQueries(null, 20), 'query');

        self::assertContains('qqq', $month);
        self::assertNotContains('oldie', $quarter);
        self::assertContains('oldie', $all);
        self::assertSame(
            ['far cry', 'raw only'],
            array_map(
                'strtolower',
                array_column($this->analytics->topQueries($this->analytics->since('24h'), 20), 'query')
            )
        );
    }

    public function testZeroResultQueriesListOnlyTheGaps(): void
    {
        $this->seed();

        $zero = $this->analytics->zeroResultQueries($this->analytics->since('7d'), 10);

        self::assertSame(
            [['zzzz', 2], ['RAW ONLY', 1]],
            array_map(static fn (array $r): array => [$r['query'], $r['searches']], $zero)
        );
        self::assertContains('qqq', array_column($this->analytics->zeroResultQueries(null, 10), 'query'));
    }

    public function testCacheStatsAndHitRate(): void
    {
        $this->seed();

        self::assertSame(
            ['total' => 9, 'hits' => 2, 'misses' => 7, 'hit_rate' => 22.2],
            $this->analytics->cacheStats($this->analytics->since('30d'))
        );
        self::assertSame(
            ['total' => 0, 'hits' => 0, 'misses' => 0, 'hit_rate' => 0.0],
            (new Analytics($this->pdo, 'search_logs'))->cacheStats('2100-01-01 00:00:00'),
            'an empty window is 0%, not a division error'
        );
    }

    public function testTierAndVectorShares(): void
    {
        $this->seed();

        $tier = $this->analytics->tierStats($this->analytics->since('7d'));

        self::assertSame(8, $tier['total']);
        self::assertSame(3, $tier['hybrid']);
        self::assertSame(5, $tier['keyword_only']);
        self::assertSame(37.5, $tier['hybrid_share']);
        self::assertSame(3, $tier['with_vector']);
        self::assertSame(37.5, $tier['vector_share']);
    }

    public function testResultHealth(): void
    {
        $this->seed();

        self::assertSame(
            ['total' => 8, 'zero_results' => 3, 'zero_rate' => 37.5, 'avg_results' => 2.6],
            $this->analytics->resultHealth($this->analytics->since('7d'))
        );
        self::assertSame(4, $this->analytics->resultHealth(null)['zero_results'], 'all time adds the 10-day-old gap');
    }

    public function testLatencyPercentilesAreNearestRank(): void
    {
        foreach (range(1, 20) as $i) {
            $this->log("q{$i}", 1, ['latency_ms' => $i * 10]);
        }

        self::assertSame(
            ['count' => 20, 'avg' => 105.0, 'p50' => 100, 'p95' => 190, 'max' => 200],
            $this->analytics->latencyStats(null)
        );
        self::assertSame(
            ['count' => 0, 'avg' => 0.0, 'p50' => 0, 'p95' => 0, 'max' => 0],
            $this->analytics->latencyStats('2100-01-01 00:00:00')
        );

        $this->pdo->exec('DELETE FROM search_logs');
        $this->log('one', 1, ['latency_ms' => 77]);
        self::assertSame(77, $this->analytics->latencyStats(null)['p95']);
    }

    public function testDidYouMeanStatsIgnoreEmptySuggestions(): void
    {
        $this->seed();

        self::assertSame(
            ['total' => 8, 'suggested' => 1, 'share' => 12.5],
            $this->analytics->didYouMeanStats($this->analytics->since('7d'))
        );
    }

    public function testVolumeBucketsByDayAndHourOldestFirst(): void
    {
        $this->seed();

        $days = $this->analytics->volume($this->analytics->since('30d'), 'day');
        $buckets = array_column($days, 'bucket');
        $sorted = $buckets;
        sort($sorted);

        self::assertSame($sorted, $buckets);
        self::assertSame(9, array_sum(array_column($days, 'searches')));
        self::assertSame(
            4,
            array_sum(array_column($this->analytics->volume($this->analytics->since('24h'), 'hour'), 'searches')),
            'last 24h: far cry x3 and the raw-only row'
        );
        $this->expectException(InvalidArgumentException::class);
        $this->analytics->volume(null, 'week');
    }

    public function testReportBundlesEverythingForOneWindow(): void
    {
        $this->seed();

        $report = $this->analytics->report('7d', 5);

        self::assertSame('7d', $report['window']);
        $sections = [
            'summary', 'cache', 'tier', 'latency', 'did_you_mean', 'top_queries', 'zero_result_queries', 'volume',
        ];
        foreach ($sections as $section) {
            self::assertArrayHasKey($section, $report);
        }
        self::assertSame(8, $report['summary']['total']);
        self::assertSame('7d', $this->analytics->report('nonsense')['window']);
    }

    public function testATableWithoutTheLaterColumnsStillReports(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec(
            'CREATE TABLE search_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts DATETIME NOT NULL'
            . ' DEFAULT CURRENT_TIMESTAMP, raw_q VARCHAR(512) NOT NULL, normalized_q VARCHAR(512) NOT NULL'
            . " DEFAULT '', had_vector TINYINT(1) NOT NULL DEFAULT 0, result_count INT UNSIGNED NOT NULL"
            . " DEFAULT 0, top_ids VARCHAR(1024) NOT NULL DEFAULT '', customer_id VARCHAR(64) DEFAULT NULL,"
            . ' latency_ms INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id))'
        );
        $this->pdo->exec("INSERT INTO search_logs (raw_q, normalized_q, result_count) VALUES ('a', 'a', 0)");

        $report = (new Analytics($this->pdo, 'search_logs'))->report('all');

        self::assertSame(1, $report['summary']['total']);
        self::assertSame(0, $report['cache']['hits']);
        self::assertSame(1, $report['tier']['keyword_only']);
        self::assertSame(0, $report['did_you_mean']['suggested']);
    }

    public function testTheCompositeIndexExists(): void
    {
        $indexes = array_column(
            $this->pdo->query("SHOW INDEX FROM search_logs WHERE Key_name = 'idx_ts_result'")->fetchAll(),
            'Column_name'
        );

        self::assertSame(['ts', 'result_count'], $indexes);
    }
}
