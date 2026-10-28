<?php

declare(strict_types=1);

namespace App\Tests;

use App\GoldenEval;
use PHPUnit\Framework\TestCase;

/** M44: the metric math of tools/eval/run_eval.php on a tiny fixture computed by hand. */
final class GoldenEvalTest extends TestCase
{
    public function testFirstRankIsOneBasedOrNull(): void
    {
        self::assertSame(1, GoldenEval::firstRank([5, 6], [5]));
        self::assertSame(3, GoldenEval::firstRank([1, 2, 7, 5], [7, 5]));
        self::assertNull(GoldenEval::firstRank([1, 2], [9]));
        self::assertNull(GoldenEval::firstRank([], [9]));
    }

    public function testNdcgWithBinaryRelevance(): void
    {
        // One relevant id at rank 3: DCG = 1/log2(4) = 0.5, ideal = 1/log2(2) = 1.
        self::assertEqualsWithDelta(0.5, GoldenEval::ndcg([1, 2, 9], [9], 10), 1e-9);
        // Two relevant ids at ranks 1 and 3: DCG = 1 + 0.5; ideal = 1 + 1/log2(3).
        $ideal = 1.0 + 1.0 / log(3, 2);
        self::assertEqualsWithDelta(1.5 / $ideal, GoldenEval::ndcg([8, 1, 9], [8, 9], 10), 1e-9);
        self::assertEqualsWithDelta(1.0, GoldenEval::ndcg([8, 9], [8, 9], 10), 1e-9);
        // Beyond k does not count; the ideal list is cut to k too.
        self::assertSame(0.0, GoldenEval::ndcg([1, 2, 3, 9], [9], 3));
        self::assertSame(0.0, GoldenEval::ndcg([1], [], 10));
    }

    public function testRunAveragesTheMetricsOverAllQueries(): void
    {
        $results = [
            'a' => [10, 11, 12],   // relevant 10: rank 1
            'b' => [20, 21, 22],   // relevant 22: rank 3
            'c' => [30, 31],       // relevant 99: missed
            'd' => [],             // relevant 40: zero result
            'e' => [],             // nothing expected, nothing returned: a hit
            'f' => [50],           // nothing expected, but something returned: a miss
        ];
        $cases = [
            ['query' => 'a', 'relevant' => [10], 'note' => ''],
            ['query' => 'b', 'relevant' => [22], 'note' => ''],
            ['query' => 'c', 'relevant' => [99], 'note' => ''],
            ['query' => 'd', 'relevant' => [40], 'note' => ''],
            ['query' => 'e', 'relevant' => [], 'note' => ''],
            ['query' => 'f', 'relevant' => [], 'note' => ''],
        ];

        $report = GoldenEval::run($cases, static fn (string $q): array => $results[$q], 10);

        self::assertSame(6, $report['query_count']);
        self::assertEqualsWithDelta(3 / 6, $report['success_at_k'], 1e-9); // a, b, e
        self::assertEqualsWithDelta((1 + 1 / 3 + 0 + 0 + 1 + 0) / 6, $report['mrr'], 1e-9);
        self::assertEqualsWithDelta((1 + 0.5 + 0 + 0 + 1 + 0) / 6, $report['ndcg_at_k'], 1e-9);
        self::assertEqualsWithDelta(2 / 6, $report['zero_result_rate'], 1e-9); // d, e
        self::assertSame([1, 3, null, null, null, null], array_column($report['per_query'], 'first_rank'));
    }

    public function testKCutsTheResultList(): void
    {
        $report = GoldenEval::run(
            [['query' => 'q', 'relevant' => [9], 'note' => '']],
            static fn (): array => [1, 2, 3, 9],
            3
        );

        self::assertSame(0.0, $report['success_at_k']);
        self::assertSame(3, $report['per_query'][0]['results']);
    }

    public function testCompareListsRegressionsAndImprovementsPerQuery(): void
    {
        $row = static fn (string $q, ?int $rank, int $relevant = 1): array => [
            'query' => $q, 'note' => '', 'relevant' => $relevant, 'results' => 5, 'first_rank' => $rank,
            'success' => $relevant === 0 ? $rank === null : $rank !== null, 'rr' => 0.0, 'ndcg' => 0.0, 'zero' => false,
        ];
        $before = ['success_at_k' => 0.5, 'mrr' => 0.4, 'ndcg_at_k' => 0.3, 'zero_result_rate' => 0.1, 'per_query' => [
            $row('same', 2), $row('worse', 1), $row('lost', 1), $row('better', null), $row('found', 4), $row('gone', 1),
        ]];
        $after = ['success_at_k' => 0.6, 'mrr' => 0.5, 'ndcg_at_k' => 0.35, 'zero_result_rate' => 0.05, 'per_query' => [
            $row('same', 2), $row('worse', 3), $row('lost', null), $row('better', 2), $row('found', 1), $row('new', 1),
        ]];

        $diff = GoldenEval::compare($before, $after);

        self::assertSame(['worse', 'lost'], array_column($diff['regressions'], 'query'));
        self::assertSame(['better', 'found'], array_column($diff['improvements'], 'query'));
        self::assertSame(['new'], $diff['added']);
        self::assertSame(['gone'], $diff['removed']);
        self::assertEqualsWithDelta(0.1, $diff['metrics']['success_at_k']['delta'], 1e-9);
        self::assertEqualsWithDelta(-0.05, $diff['metrics']['zero_result_rate']['delta'], 1e-9);
    }

    public function testCompareTreatsANothingExpectedCaseByItsSuccessFlag(): void
    {
        $none = static fn (bool $clean): array => [
            'success_at_k' => 0.0, 'mrr' => 0.0, 'ndcg_at_k' => 0.0, 'zero_result_rate' => 0.0,
            'per_query' => [['query' => 'shirt', 'note' => '', 'relevant' => 0, 'results' => $clean ? 0 : 3,
                'first_rank' => null, 'success' => $clean, 'rr' => 0.0, 'ndcg' => 0.0, 'zero' => $clean]],
        ];

        self::assertSame(['shirt'], array_column(GoldenEval::compare($none(true), $none(false))['regressions'], 'query'));
        self::assertSame(['shirt'], array_column(GoldenEval::compare($none(false), $none(true))['improvements'], 'query'));
    }

    public function testParseCsvSkipsCommentsAndReadsIdsAndNotes(): void
    {
        $csv = "# comment line\n# another\nquery,relevant_ids,note\n"
            . "ps5 slim,\"101; 102;101\",two ids\n"
            . "\"quoted, query\",7,\n"
            . "off topic,,nothing expected\n"
            . ",5,no query: skipped\n";

        $cases = GoldenEval::parseCsv($csv);

        self::assertSame([
            ['query' => 'ps5 slim', 'relevant' => [101, 102], 'note' => 'two ids'],
            ['query' => 'quoted, query', 'relevant' => [7], 'note' => ''],
            ['query' => 'off topic', 'relevant' => [], 'note' => 'nothing expected'],
        ], $cases);
    }

    public function testParseCsvRequiresTheColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoldenEval::parseCsv("q,ids\na,1\n");
    }

    public function testTheSampleFileParses(): void
    {
        $cases = GoldenEval::parseCsv((string) file_get_contents(dirname(__DIR__, 2) . '/tools/eval/golden.sample.csv'));

        self::assertCount(4, $cases);
        self::assertSame([990001, 990002], $cases[0]['relevant']);
        self::assertSame([], $cases[3]['relevant']);
    }
}
