<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ranker;
use PHPUnit\Framework\TestCase;

/**
 * Hybrid ranking: Reciprocal Rank Fusion + light business boosts (CLAUDE.md
 * sec. 5.5). No database — Ranker operates on rank order and a signals map.
 */
final class RankerTest extends TestCase
{
    /** @param list<array{product_id: int, score: float}> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['product_id'], $rows);
    }

    public function testPureRrfOrderingWithoutBoosts(): void
    {
        $ranker = new Ranker(60, 0.0, 0.0);

        $out = $ranker->fuse([1, 2, 3], [3, 4, 5], [], 10);

        // id 3 appears in both lists (highest fused score); ties (2 and 4, both
        // rank 2 in one list) break by product_id ascending.
        self::assertSame([3, 1, 2, 4, 5], self::ids($out));
    }

    public function testStockBoostBreaksAnRrfTie(): void
    {
        $ranker = new Ranker(60, 0.2, 0.0);
        // Both ids sit at rank 1 of one list -> identical RRF score.
        $signals = [
            1 => ['stock' => 0, 'popularity' => 0],
            2 => ['stock' => 3, 'popularity' => 0],
        ];

        $out = $ranker->fuse([1], [2], $signals, 10);

        self::assertSame([2, 1], self::ids($out)); // in-stock nudged ahead
    }

    public function testPopularityBoostBreaksAnRrfTie(): void
    {
        $ranker = new Ranker(60, 0.0, 0.2);
        $signals = [
            1 => ['stock' => 1, 'popularity' => 10],
            2 => ['stock' => 1, 'popularity' => 100],
        ];

        $out = $ranker->fuse([1], [2], $signals, 10);

        self::assertSame([2, 1], self::ids($out)); // more popular nudged ahead
    }

    public function testBoostsDoNotOverrideAnItemRankedInBothLists(): void
    {
        // Even a maxed-out in-stock + popular item that appears only once, low in
        // the semantic list, must not beat an item strong in BOTH lists.
        $ranker = new Ranker(60, 0.1, 0.1);
        $semantic = array_merge([99], range(200, 228)); // id 99 at semantic rank 1; filler
        $signals = [
            1  => ['stock' => 0, 'popularity' => 0],   // strong: keyword #1 + semantic #1
            99 => ['stock' => 9, 'popularity' => 1000], // weak rank but max signals
        ];

        $out = $ranker->fuse([1], array_merge([1], $semantic), $signals, 5);

        self::assertSame(1, self::ids($out)[0]);
    }

    public function testKeywordOnlyFusion(): void
    {
        $ranker = new Ranker(60, 0.1, 0.1);

        $out = $ranker->fuse([5, 6, 7], [], [], 10);

        self::assertSame([5, 6, 7], self::ids($out));
    }

    public function testSemanticOnlyFusion(): void
    {
        $ranker = new Ranker(60, 0.1, 0.1);

        $out = $ranker->fuse([], [8, 9], [], 10);

        self::assertSame([8, 9], self::ids($out));
    }

    public function testLimitIsApplied(): void
    {
        $ranker = new Ranker(60, 0.0, 0.0);

        $out = $ranker->fuse([1, 2, 3, 4, 5], [], [], 2);

        self::assertCount(2, $out);
        self::assertSame([1, 2], self::ids($out));
    }
}
