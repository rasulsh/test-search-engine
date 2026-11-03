<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- fixtures

namespace App\Tests;

use App\VpsCallReport;
use PDO;

/** The VPS page's queries over seeded vps_calls rows: summary, hourly counts, incidents, filters, export. */
final class VpsCallReportTest extends DatabaseTestCase
{
    private VpsCallReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new VpsCallReport($this->pdo);
    }

    /** @param array<string, mixed> $extra */
    private function call(string $secondsAgo, string $outcome = 'ok', string $kind = 'search', int $ms = 100, array $extra = []): void
    {
        $row = ['kind' => $kind, 'outcome' => $outcome, 'duration_ms' => $ms, 'timeout_ms' => 600] + $extra;
        $this->pdo->prepare(
            'INSERT INTO vps_calls (ts, kind, outcome, duration_ms, timeout_ms, error, normalized_q, http_status)'
            . ' VALUES (UTC_TIMESTAMP() - INTERVAL :s SECOND, :kind, :outcome, :ms, :timeout, :error, :q, :status)'
        )->execute([
            's' => $secondsAgo, 'kind' => $row['kind'], 'outcome' => $row['outcome'], 'ms' => $row['duration_ms'],
            'timeout' => $row['timeout_ms'], 'error' => $row['error'] ?? null, 'q' => $row['normalized_q'] ?? null,
            'status' => $row['http_status'] ?? null,
        ]);
    }

    public function testSummaryCountsOutcomesPercentilesAndNearTimeouts(): void
    {
        foreach ([100, 200, 300, 400, 500] as $i => $ms) {
            $this->call((string) (60 + $i), 'ok', 'search', $ms);   // 500 > 80% of 600
        }
        $this->call('70', 'timeout', 'search', 600);
        $this->call('80', 'unreachable', 'search', 3);
        $this->call((string) (3 * 3600 * 24), 'ok', 'search', 9000);  // 3 days old: only in the 7-day window
        $this->call('90', 'ok', 'probe', 5);

        $day = $this->report->summary(24, 'search');
        $week = $this->report->summary(168, 'search');

        self::assertSame(7, $day['calls']);
        self::assertSame(5, $day['ok']);
        self::assertEqualsWithDelta(71.4, $day['ok_pct'], 0.05);
        self::assertSame(1, $day['outcomes']['timeout']);
        self::assertSame(1, $day['outcomes']['unreachable']);
        self::assertSame(0, $day['outcomes']['http_error']);
        self::assertSame([300, 600, 600], [$day['p50'], $day['p95'], $day['max']], 'durations 3,100..500,600');
        self::assertSame(1, $day['near'], 'the ok 500 ms call; a timeout is not a near-timeout');
        self::assertEqualsWithDelta(14.3, $day['near_pct'], 0.05);
        self::assertSame(8, $week['calls']);
        self::assertSame(9000, $week['max']);
        self::assertSame(1, $this->report->summary(24, 'probe')['calls']);
        self::assertSame(0, $this->report->summary(24, 'reload')['calls']);
        self::assertNull($this->report->summary(24, 'reload')['ok_pct']);
        self::assertNull($this->report->summary(24, 'reload')['p50']);
    }

    public function testSkippedSearchesAreCountedApartAndAreNotFailures(): void
    {
        $this->call('10', 'ok', 'search', 100);
        $this->call('20', 'skipped', 'search', 0, ['error' => 'not_configured']);
        $this->call('30', 'skipped', 'search', 0, ['error' => 'empty_query']);
        $this->pdo->exec("UPDATE vps_calls SET duration_ms = NULL, source = 'none' WHERE outcome = 'skipped'");

        $day = $this->report->summary(24, 'search');

        self::assertSame([1, 2, 1, 100.0], [$day['calls'], $day['skipped'], $day['ok'], $day['ok_pct']]);
        self::assertSame([100, 100], [$day['p50'], $day['max']], 'skipped rows have no duration');
        self::assertSame([], $this->report->incidents(7), 'skipped is not an incident');
        $f = static fn (array $q): array => VpsCallReport::filters($q);
        self::assertSame(0, $this->report->rows($f([]), 1, 50)['total'], 'not in the failures view');
        self::assertSame(2, $this->report->rows($f(['view' => 'all', 'outcome' => 'skipped']), 1, 50)['total']);
        self::assertSame(0, $this->report->rows($f(['outcome' => 'failed']), 1, 50)['total']);
        $series = $this->report->hourly(1, 'search');
        self::assertSame(2, array_sum(array_column(array_column($series, 'counts'), 'skipped')));
    }

    public function testPercentilesUseTheNearestRank(): void
    {
        self::assertNull(VpsCallReport::percentile([], 50));
        self::assertSame(5, VpsCallReport::percentile([5], 95));
        self::assertSame(2, VpsCallReport::percentile([1, 2, 3, 4], 50));
        self::assertSame(19, VpsCallReport::percentile(range(1, 20), 95));
        self::assertSame(18, VpsCallReport::percentile(range(1, 20), 90));
        self::assertSame(20, VpsCallReport::percentile(range(1, 20), 100));
        self::assertSame(3, VpsCallReport::median([5, 1, 3]));
        self::assertSame(3, VpsCallReport::median([2, 4]));
        self::assertNull(VpsCallReport::median([]));
    }

    public function testHourlyCoversEveryHourOfTheWindowAndCountsPerOutcome(): void
    {
        $this->call('30', 'ok');
        $this->call('40', 'ok');
        $this->call('50', 'timeout');
        $this->call('5', 'ok', 'probe');
        $this->call((string) (8 * 86400), 'ok');  // outside the 7 days

        $series = $this->report->hourly(7, 'search');

        self::assertCount(168, $series);
        self::assertSame(gmdate('Y-m-d H:00'), $series[167]['hour']);
        self::assertSame(gmdate('Y-m-d H:00', time() - 167 * 3600), $series[0]['hour']);
        $total = ['ok' => 0, 'timeout' => 0];
        foreach ($series as $hour) {
            foreach ($hour['counts'] as $outcome => $count) {
                $total[$outcome] += $count;
            }
        }
        self::assertSame(['ok' => 2, 'timeout' => 1], $total, 'search only, inside the window');
    }

    public function testFailuresWithinAMinuteOfEachOtherAreOneIncident(): void
    {
        $failures = [
            ['ts' => '2026-11-03 10:00:00', 'kind' => 'search', 'outcome' => 'timeout'],
            ['ts' => '2026-11-03 10:00:40', 'kind' => 'search', 'outcome' => 'timeout'],
            ['ts' => '2026-11-03 10:01:40', 'kind' => 'probe', 'outcome' => 'unreachable'],   // exactly 60 s after: still joined
            ['ts' => '2026-11-03 10:02:41', 'kind' => 'search', 'outcome' => 'timeout'],      // 61 s: a new incident
            ['ts' => '2026-11-03 12:00:00', 'kind' => 'search', 'outcome' => 'http_error'],
        ];

        $incidents = VpsCallReport::groupIncidents($failures);

        self::assertCount(3, $incidents);
        self::assertSame(['2026-11-03 10:00:00', '2026-11-03 10:01:40', 3], [$incidents[0]['start'], $incidents[0]['end'], $incidents[0]['count']]);
        self::assertSame(['timeout' => 2, 'unreachable' => 1], $incidents[0]['outcomes']);
        self::assertSame(['search', 'probe'], $incidents[0]['kinds']);
        self::assertSame([1, 1], [$incidents[1]['count'], $incidents[2]['count']]);
        self::assertSame([], VpsCallReport::groupIncidents([]));
    }

    public function testIncidentsCarryTheMedianOfTenOkCallsBeforeAndAfter(): void
    {
        // Oldest first: 12 fast calls (50 ms), the burst, then 12 slower calls (300 ms).
        for ($i = 0; $i < 12; $i++) {
            $this->call((string) (4000 - $i * 10), 'ok', 'search', $i < 2 ? 9999 : 50);   // the two oldest are outside "10 before"
        }
        foreach ([1000, 990, 975] as $at) {
            $this->call((string) $at, 'timeout', 'search', 600);
        }
        $this->call('980', 'unreachable', 'probe', 4);
        for ($i = 0; $i < 12; $i++) {
            $this->call((string) (900 - $i * 10), 'ok', 'search', $i >= 10 ? 9999 : 300);   // the two newest are outside "10 after"
        }
        $this->call('500', 'ok', 'probe', 1);  // ok probes never count as a search baseline
        $this->call('10', 'not_configured', 'probe', 0);  // not a failure of the VPS

        $incidents = $this->report->incidents(7);

        self::assertCount(1, $incidents);
        self::assertSame(4, $incidents[0]['count']);
        self::assertSame([50, 300], [$incidents[0]['before_ms'], $incidents[0]['after_ms']]);
    }

    public function testNoBaselineMeansNullNotZero(): void
    {
        $this->call('100', 'unreachable', 'probe', 5);

        $incidents = $this->report->incidents(7);

        self::assertCount(1, $incidents);
        self::assertNull($incidents[0]['before_ms']);
        self::assertNull($incidents[0]['after_ms']);
    }

    public function testFiltersAcceptOnlyKnownValues(): void
    {
        self::assertSame(
            ['view' => 'failures', 'outcome' => '', 'kind' => '', 'from' => '', 'to' => ''],
            VpsCallReport::filters(['view' => "x'; DROP", 'outcome' => 'nope', 'kind' => ['a'], 'from' => 'yesterday', 'to' => '2026-02-30'])
        );
        self::assertSame(
            ['view' => 'slowest', 'outcome' => 'failed', 'kind' => 'probe', 'from' => '2026-11-01', 'to' => '2026-11-03 10:00:00'],
            VpsCallReport::filters(['view' => 'slowest', 'outcome' => 'failed', 'kind' => 'probe', 'from' => '2026-11-01', 'to' => '2026-11-03 10:00:00'])
        );
    }

    public function testTheListFiltersByViewOutcomeKindAndDates(): void
    {
        $this->call('100', 'ok', 'search', 120);
        $this->call('200', 'ok', 'search', 480);
        $this->call('300', 'timeout', 'search', 600);
        $this->call('400', 'http_error', 'probe', 20, ['http_status' => 503]);
        $this->call('500', 'not_configured', 'probe', 0);
        $ids = static fn (array $page): array => array_column($page['rows'], 'duration_ms');
        $f = static fn (array $q): array => VpsCallReport::filters($q);

        self::assertSame([20, 600], $ids($this->report->rows($f([]), 1, 50)), 'failures: newest first, not_configured is not a failure');
        self::assertSame([480, 120], $ids($this->report->rows($f(['view' => 'slowest']), 1, 50)), 'slowest ok calls first');
        self::assertSame([0, 20, 600, 480, 120], $ids($this->report->rows($f(['view' => 'all']), 1, 50)), 'newest first');
        self::assertSame([600], $ids($this->report->rows($f(['outcome' => 'timeout']), 1, 50)));
        self::assertSame([20], $ids($this->report->rows($f(['kind' => 'probe']), 1, 50)));
        self::assertSame([0], $ids($this->report->rows($f(['view' => 'all', 'outcome' => 'not_configured']), 1, 50)));
        self::assertSame([20, 600], $ids($this->report->rows($f(['view' => 'all', 'outcome' => 'failed']), 1, 50)));

        $today = gmdate('Y-m-d');
        self::assertSame(5, $this->report->rows($f(['view' => 'all', 'from' => gmdate('Y-m-d', time() - 86400)]), 1, 50)['total']);
        self::assertSame(0, $this->report->rows($f(['view' => 'all', 'from' => gmdate('Y-m-d', time() + 86400)]), 1, 50)['total']);
        self::assertSame(0, $this->report->rows($f(['view' => 'all', 'to' => gmdate('Y-m-d', time() - 2 * 86400)]), 1, 50)['total']);
        self::assertSame(5, $this->report->rows($f(['view' => 'all', 'to' => $today]), 1, 50)['total'], 'a date-only "to" includes that whole day');
    }

    public function testPagingIsCappedAndConsistent(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->call((string) $i, 'timeout', 'search', $i);
        }

        $second = $this->report->rows(VpsCallReport::filters([]), 2, 3);

        self::assertSame([7, 3, 2, 4, 6], [$second['total'], $second['pages'], $second['page'], $second['from'], $second['to']]);
        self::assertSame([4, 3, 2], array_column($second['rows'], 'duration_ms'), 'newest first, 3 per page');
        self::assertSame(3, $this->report->rows(VpsCallReport::filters([]), 99, 3)['page'], 'page is clamped');
    }

    public function testTheExportHasEveryColumnNoSecretAndNeutralisedFormulas(): void
    {
        $this->call('10', 'timeout', 'search', 600, ['error' => 'timeout [curl 28]', 'normalized_q' => '=SUM(A1)']);
        $this->call('20', 'ok', 'search', 100, ['normalized_q' => 'ps5']);

        $rows = iterator_to_array($this->report->export(VpsCallReport::filters(['view' => 'all'])), false);

        self::assertCount(2, $rows);
        self::assertSame(count(VpsCallReport::COLUMNS), count($rows[0]));
        $queryAt = array_search('normalized_q', VpsCallReport::COLUMNS, true);
        self::assertSame('ps5', $rows[0][$queryAt], 'newest first');
        self::assertSame('=SUM(A1)', $rows[1][$queryAt], 'the raw value; csvCell guards the file');
        self::assertSame("'=SUM(A1)", VpsCallReport::csvCell('=SUM(A1)'));
        self::assertSame("'@x", VpsCallReport::csvCell('@x'));
        self::assertSame(100, VpsCallReport::csvCell(100));
        self::assertSame('ps5', VpsCallReport::csvCell('ps5'));
        self::assertNull(VpsCallReport::csvCell(null));
        foreach (['customer_id', 'session_id', 'token', 'url'] as $forbidden) {
            self::assertNotContains($forbidden, VpsCallReport::COLUMNS);
        }
    }

    public function testAvailableIsFalseWithoutTheTable(): void
    {
        self::assertTrue($this->report->available());
        $this->pdo->exec('DROP TABLE vps_calls');

        self::assertFalse($this->report->available());
    }
}
