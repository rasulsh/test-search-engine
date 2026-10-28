<?php

declare(strict_types=1);

namespace App\Tests;

use App\Analytics;
use App\AnalyticsInsights;

/**
 * The log-v2 metrics of analytics.php over seeded search_logs / search_events rows:
 * each figure, the real zero-result -> reformulation pairs, probes and repeat submits
 * left out, old rows left out of the v2-only figures, and the previous-window compare.
 */
final class AnalyticsInsightsTest extends DatabaseTestCase
{
    private int $seq = 0;

    /** @var array<string, string> search_uuid by label */
    private array $uuids = [];

    private function log(string $label, string $query, int $results, array $extra = []): void
    {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', ++$this->seq);
        $this->uuids[$label] = $uuid;
        $row = $extra + [
            'raw_q' => $query, 'normalized_q' => $query, 'result_count' => $results, 'search_uuid' => $uuid,
            'log_version' => 2, 'session_id' => 's1', 'latency_ms' => 10, 'tier' => 'hybrid', 'minutes_ago' => 5,
        ];
        $minutes = $row['minutes_ago'];
        unset($row['minutes_ago']);
        $columns = array_keys($row);
        $this->pdo->prepare(
            'INSERT INTO search_logs (' . implode(',', $columns) . ', ts) VALUES (:' . implode(', :', $columns)
            . ', NOW() - INTERVAL ' . (int) ($minutes * 60) . ' SECOND)'
        )->execute($row);
    }

    private function event(string $label, string $action = 'click', int $position = 1, int $product = 7): void
    {
        $this->pdo->prepare(
            'INSERT INTO search_events (search_uuid, product_id, position, action) VALUES (?, ?, ?, ?)'
        )->execute([$this->uuids[$label], $product, $position, $action]);
    }

    private function report(string $window = '24h', bool $compare = false): array
    {
        return (new Analytics($this->pdo, 'search_logs', 'products', 'search_events'))->report($window, 20, $compare);
    }

    public function testAnOldTableReportsTheLegacyKeysAndFlagsV2AsUnavailable(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec(
            "CREATE TABLE search_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, raw_q VARCHAR(512) NOT NULL,
                normalized_q VARCHAR(512) NOT NULL DEFAULT '', had_vector TINYINT(1) NOT NULL DEFAULT 0,
                result_count INT UNSIGNED NOT NULL DEFAULT 0, top_ids VARCHAR(1024) NOT NULL DEFAULT '',
                customer_id VARCHAR(64) DEFAULT NULL, latency_ms INT UNSIGNED NOT NULL DEFAULT 0)"
        );
        $this->pdo->exec("INSERT INTO search_logs (raw_q, normalized_q, result_count) VALUES ('a', 'a', 1)");

        $report = $this->report();

        self::assertSame(1, $report['summary']['total']);
        self::assertFalse($report['log_v2']['available']);
        self::assertArrayNotHasKey('engagement', $report);
    }

    public function testTheLegacyKeysSurviveWithTheirShape(): void
    {
        $this->log('a', 'mafia', 3);
        $report = $this->report();

        foreach (
            ['window', 'since', 'summary', 'cache', 'tier', 'latency', 'did_you_mean', 'top_queries',
            'zero_result_queries', 'volume'] as $key
        ) {
            self::assertArrayHasKey($key, $report);
        }
        foreach (['total', 'suggested', 'share'] as $key) {
            self::assertArrayHasKey($key, $report['did_you_mean']);
        }
        foreach (
            ['unique', 'zero_result', 'engagement', 'latency_cold', 'tier_timeline', 'capped_share',
            'alias_candidates', 'silent_failures', 'demand_gap', 'trending', 'suspicious'] as $key
        ) {
            self::assertArrayHasKey($key, $report);
        }
        self::assertArrayNotHasKey('compare', $report);
    }

    public function testProbesAndRepeatsAreLeftOutWhereTheyShouldBe(): void
    {
        $this->log('a', 'mafia', 3, ['session_id' => 's1']);
        $this->log('b', 'mafia', 3, ['session_id' => 's1', 'is_duplicate' => 1]);
        $this->log('c', 'x union select 1', 0, ['is_suspicious' => 1]);
        $this->log('d', 'wolverine', 0, ['session_id' => 's2']);
        $this->log('old', 'legacy', 1, ['log_version' => 1, 'session_id' => null]);

        $report = $this->report();

        self::assertSame(4, $report['summary']['total'], 'probes count nowhere, repeats in plain counts');
        self::assertSame(['searches' => 2, 'sessions' => 2, 'searches_per_session' => 1.0], $report['unique']);
        self::assertSame(50.0, $report['zero_result']['rate']);
        self::assertSame('wolverine', $report['zero_result']['queries'][0]['query']);
        self::assertSame(1, $report['suspicious']['count']);
        self::assertSame('x union select 1', $report['suspicious']['samples'][0]['query']);
        self::assertSame(3, $report['log_v2']['rows'], 'v2 rows exclude the legacy one and the probe');
    }

    public function testEngagementFromClicksIgnoringOldCartAndOrderRows(): void
    {
        $this->log('s1', 'mafia', 5);
        $this->log('s2', 'ps5', 5, ['session_id' => 's2']);
        $this->log('s3', 'zelda', 5, ['session_id' => 's3']);
        $this->log('s4', 'nothing', 0, ['session_id' => 's4']);
        $this->event('s1', 'click', 1);
        $this->event('s1', 'click', 4, 8);
        $this->event('s1', 'cart');   // rows from before M43 stay in the table and are ignored
        $this->event('s2', 'click', 2);
        $this->event('s2', 'order');
        $this->event('s3', 'order');

        $e = $this->report()['engagement'];

        self::assertTrue($e['tracking']);
        self::assertSame(3, $e['searches'], 'only searches with results');
        self::assertSame(66.7, $e['ctr']);
        self::assertSame(33.3, $e['no_click_rate']);
        self::assertSame(2.33, $e['mean_click_position']);   // (1 + 4 + 2) / 3
        self::assertSame(0.5, $e['mrr']);                      // (1/1 + 1/2 + 0) / 3
        self::assertArrayNotHasKey('cart_rate', $e);
        self::assertArrayNotHasKey('order_rate', $e);
    }

    public function testColdLatencyIgnoresCacheHitsAndNamesTheSlowQueries(): void
    {
        foreach ([100, 200, 300, 400] as $i => $ms) {
            $this->log("m{$i}", "q{$i}", 1, ['latency_ms' => $ms, 'cache_hit' => 0, 'lat_keyword_ms' => $ms / 2,
                'lat_vector_ms' => 10, 'lat_spell_ms' => null]);
        }
        $this->log('hit', 'cached', 1, ['latency_ms' => 2, 'cache_hit' => 1]);

        $cold = $this->report()['latency_cold'];

        self::assertSame(4, $cold['count']);
        self::assertSame(200, $cold['p50']);
        self::assertSame(400, $cold['p95']);
        self::assertSame(400, $cold['max']);
        self::assertSame(['keyword' => 200, 'vector' => 10, 'spell' => 0], $cold['stages_p95']);
        self::assertSame('q3', $cold['slowest'][0]['query']);
        self::assertSame(400, $cold['slowest'][0]['max_ms']);
    }

    public function testTheTierTimelineFindsAVectorOutage(): void
    {
        // Hybrid answers, then one full hour of keyword-only ones, then hybrid again (clock-hour aligned).
        foreach ([4 => 'hybrid', 3 => 'keyword_only', 2 => 'hybrid'] as $hoursAgo => $tier) {
            for ($i = 0; $i < 4; $i++) {
                $label = "{$tier}{$hoursAgo}{$i}";
                $this->log($label, 'ok', 1, ['tier' => $tier, 'session_id' => $label]);
                $this->pdo->prepare(
                    "UPDATE search_logs SET ts = DATE_FORMAT(NOW() - INTERVAL {$hoursAgo} HOUR, '%Y-%m-%d %H:20:00')"
                    . ' WHERE search_uuid = ?'
                )->execute([$this->uuids[$label]]);
            }
        }

        $timeline = $this->report()['tier_timeline'];

        self::assertSame([100.0, 0.0, 100.0], array_column($timeline['hours'], 'hybrid_share'));
        self::assertSame([4, 4, 4], array_column($timeline['hours'], 'searches'));
        self::assertCount(1, $timeline['outages']);
        self::assertSame(1, $timeline['outages'][0]['hours']);
        self::assertSame(4, $timeline['outages'][0]['searches']);
        self::assertSame($timeline['hours'][1]['bucket'], $timeline['outages'][0]['from']);
    }

    public function testCappedShare(): void
    {
        $this->log('a', 'cable', 300, ['is_capped' => 1]);
        $this->log('b', 'mouse', 12, ['session_id' => 's2']);

        self::assertSame(['searches' => 2, 'capped' => 1, 'share' => 50.0], $this->report()['capped_share']);
    }

    public function testSuggestionQuality(): void
    {
        $this->log('a', 'gtavi', 34, ['did_you_mean' => 'atari', 'dym_shown' => 1]);
        $this->log('b', 'gtavi', 34, ['did_you_mean' => 'atari', 'dym_shown' => 1, 'session_id' => 's2']);
        $this->log('c', 'sonny', 3, ['did_you_mean' => 'sony', 'dym_shown' => 1, 'session_id' => 's3']);
        $this->log('d', 'plain', 3, ['session_id' => 's4']);
        $this->log('z', 'atari', 0, ['session_id' => 's5']);        // the suggestion itself finds nothing
        $this->event('c', 'dym_click', 0, 0);

        $dym = $this->report()['did_you_mean'];

        self::assertSame(3, $dym['shown']);
        self::assertSame(60.0, $dym['shown_rate']);
        self::assertSame(1, $dym['clicked']);
        self::assertSame(33.3, $dym['ctr']);
        self::assertSame(1, $dym['zero_result_suggestions']);
        self::assertSame(['query' => 'gtavi', 'suggestion' => 'atari', 'shown' => 2, 'clicked' => 0], $dym['top'][0]);
    }

    public function testAliasCandidatesArePairsOfAZeroResultAndALaterSuccess(): void
    {
        // The real ones: a zero-result query, then within a minute another query that worked.
        $this->log('z1', 'ولورینگ', 0, ['session_id' => 'a', 'minutes_ago' => 10]);
        $this->log('f1', 'wolvering', 4, ['session_id' => 'a', 'minutes_ago' => 9.5]);
        $this->event('f1', 'click', 1);
        $this->log('z2', 'رزیدنت اویل', 0, ['session_id' => 'b', 'minutes_ago' => 8]);
        $this->log('f2', 'requim', 2, ['session_id' => 'b', 'minutes_ago' => 7.5]);
        // Too late (over a minute), a different session, a zero follow-up and a repeat do not count.
        $this->log('z3', 'late', 0, ['session_id' => 'c', 'minutes_ago' => 6]);
        $this->log('f3', 'later', 2, ['session_id' => 'c', 'minutes_ago' => 3]);
        $this->log('z4', 'lonely', 0, ['session_id' => 'd', 'minutes_ago' => 6]);
        $this->log('f4', 'other', 2, ['session_id' => 'e', 'minutes_ago' => 5.9]);
        $this->log('z5', 'again', 0, ['session_id' => 'f', 'minutes_ago' => 6]);
        $this->log('f5', 'again', 3, ['session_id' => 'f', 'minutes_ago' => 5.8]);

        $pairs = $this->report()['alias_candidates'];

        self::assertSame(
            [
                ['query' => 'ولورینگ', 'then' => 'wolvering', 'pairs' => 1, 'clicks' => 1],
                ['query' => 'رزیدنت اویل', 'then' => 'requim', 'pairs' => 1, 'clicks' => 0],
            ],
            $pairs
        );
    }

    public function testDemandGapIsTheZeroResultsNothingResolved(): void
    {
        $this->log('z1', 'beamng.drive', 0, ['session_id' => 'a']);
        $this->log('z2', 'beamng.drive', 0, ['session_id' => 'b']);
        $this->log('z3', 'ولورینگ', 0, ['session_id' => 'c', 'minutes_ago' => 4]);
        $this->log('f3', 'wolvering', 4, ['session_id' => 'c', 'minutes_ago' => 3.8]);

        $gap = $this->report()['demand_gap'];

        self::assertSame(['beamng.drive'], array_column($gap, 'query'));
        self::assertSame(2, $gap[0]['searches']);
    }

    public function testSilentFailuresNeedTrackedClicks(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->log("g{$i}", 'gtavi', 34, ['session_id' => "g{$i}"]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->log("p{$i}", 'ps5', 20, ['session_id' => "p{$i}"]);
        }
        self::assertSame([], $this->report()['silent_failures'], 'no click was ever tracked');

        foreach ([0, 1, 2, 3] as $i) {
            $this->event("p{$i}", 'click', 1);
        }
        $silent = $this->report()['silent_failures'];

        self::assertSame(['gtavi'], array_column($silent, 'query'));
        self::assertSame(
            ['query' => 'gtavi', 'searches' => 6, 'clicks' => 0, 'ctr' => 0.0, 'avg_results' => 34.0],
            $silent[0]
        );
    }

    public function testTrendingAndCompareAgainstThePreviousWindow(): void
    {
        // Previous 24h: 2 searches "ps5". This 24h: 5 "ps5", 4 "zelda" (new).
        $this->log('p1', 'ps5', 3, ['minutes_ago' => 25 * 60, 'session_id' => 'p1']);
        $this->log('p2', 'ps5', 3, ['minutes_ago' => 26 * 60, 'session_id' => 'p2']);
        for ($i = 0; $i < 5; $i++) {
            $this->log("c{$i}", 'ps5', 3, ['session_id' => "c{$i}"]);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->log("z{$i}", 'zelda', 3, ['session_id' => "z{$i}"]);
        }

        $report = $this->report('24h', true);

        self::assertSame('zelda', $report['trending'][0]['query']);
        self::assertSame(4, $report['trending'][0]['growth']);
        self::assertNull($report['trending'][0]['growth_pct']);
        self::assertSame('ps5', $report['trending'][1]['query']);
        self::assertSame(3, $report['trending'][1]['growth']);
        self::assertSame(150.0, $report['trending'][1]['growth_pct']);

        $searches = $report['compare']['metrics']['searches'];
        self::assertSame(9, $searches['current']);
        self::assertSame(2, $searches['previous']);
        self::assertSame(350.0, $searches['change_pct']);
        self::assertArrayHasKey('previous_since', $report['compare']);
        self::assertArrayNotHasKey('compare', $this->report('all', true));
    }

    public function testCompareHelperHandlesAZeroBaseline(): void
    {
        $out = AnalyticsInsights::compare(['a' => 5, 'b' => 2.5], ['a' => 0, 'b' => 5.0]);

        self::assertNull($out['a']['change_pct']);
        self::assertSame(-2.5, $out['b']['change']);
        self::assertSame(-50.0, $out['b']['change_pct']);
    }
}
