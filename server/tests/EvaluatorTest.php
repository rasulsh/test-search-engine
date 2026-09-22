<?php

declare(strict_types=1);

namespace App\Tests;

use App\Evaluator;
use PHPUnit\Framework\TestCase;

/**
 * precision@k / recall@k math, with a synthetic searcher (no database).
 */
final class EvaluatorTest extends TestCase
{
    /** @param array<string, list<int>> $byQuery */
    private function evaluator(array $byQuery): Evaluator
    {
        return new Evaluator(static fn (array $case): array => $byQuery[$case['q']] ?? []);
    }

    public function testPrecisionAndRecallAtK(): void
    {
        $cases = [
            ['q' => 'a', 'expected_ids' => [1, 2]],
            ['q' => 'b', 'expected_ids' => [3]],
        ];
        // 'a' top-2 = [1, 9] -> 1 hit; 'b' top-2 = [3] -> 1 hit.
        $report = $this->evaluator(['a' => [1, 9, 2], 'b' => [3]])->run($cases, 2);

        self::assertSame(2, $report['query_count']);
        self::assertSame(2, $report['k']);
        // precision: (1/2 + 1/2) / 2 = 0.5 ; recall: (1/2 + 1/1) / 2 = 0.75
        self::assertEqualsWithDelta(0.5, $report['precision_at_k'], 1e-9);
        self::assertEqualsWithDelta(0.75, $report['recall_at_k'], 1e-9);
    }

    public function testLargerKCapturesMoreRelevant(): void
    {
        $cases = [['q' => 'a', 'expected_ids' => [1, 2]]];
        // top-3 = [1, 9, 2] -> both relevant found.
        $report = $this->evaluator(['a' => [1, 9, 2]])->run($cases, 3);

        self::assertSame(2, $report['per_query'][0]['hits']);
        self::assertEqualsWithDelta(1.0, $report['recall_at_k'], 1e-9);
    }

    public function testEmptyExpectedSetHasZeroRecall(): void
    {
        $report = $this->evaluator(['a' => [1, 2, 3]])->run([['q' => 'a', 'expected_ids' => []]], 5);

        self::assertEqualsWithDelta(0.0, $report['recall_at_k'], 1e-9);
    }
}
