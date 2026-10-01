<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ranker;
use PHPUnit\Framework\TestCase;

/**
 * Blended hybrid ranking (M20): weighted keyword + semantic relevance, a
 * combined floor, light business boosts after it. No database — Ranker works on
 * score maps and a signals map.
 */
final class RankerTest extends TestCase
{
    /** @param list<array{product_id: int, score: float}> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['product_id'], $rows);
    }

    public function testBlendedScoreOrdersByWeightedNormalizedSignals(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        // 1: keyword 1.0 / cosine 0.5 -> 0.4 + 0.6*(0.5/0.8)=0.775
        // 2: keyword 0.5 / cosine 0.8 -> 0.2 + 0.6          =0.8
        $out = $ranker->blend([1 => 10.0, 2 => 5.0], [1 => 0.5, 2 => 0.8], [], 10);

        self::assertSame([2, 1], self::ids($out));
        self::assertEqualsWithDelta(0.8, $out[0]['score'], 1e-9);
        self::assertEqualsWithDelta(0.775, $out[1]['score'], 1e-9);
    }

    public function testStrongSemanticWeakKeywordOutranksStrongKeywordWeakSemantic(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        $out = $ranker->blend([1 => 10.0, 2 => 1.0], [1 => 0.2, 2 => 0.9], [], 10);

        self::assertSame([2, 1], self::ids($out)); // semantic drives, no keyword tier
    }

    public function testSemanticOnlyNeighbourCanOutrankAKeywordHit(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        $out = $ranker->blend([1 => 10.0], [1 => 0.1, 2 => 0.9], [], 10);

        self::assertSame([2, 1], self::ids($out));
        self::assertFalse($out[0]['keyword']);
        self::assertTrue($out[1]['keyword']);
    }

    public function testKeywordOnlyHitFallsBelowTheCombinedFloor(): void
    {
        // The "بلبرینگ" case: top keyword score (specs mention) but ~zero
        // cosine -> blend 0.4 < 0.45; the semantically supported hit stays.
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45);

        $out = $ranker->blend([1 => 10.0, 2 => 4.0], [2 => 0.7], [], 10);

        self::assertSame([2], self::ids($out));
    }

    public function testEverythingBelowTheFloorYieldsNothing(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45);

        self::assertSame([], $ranker->blend([1 => 10.0], [], [], 10));
        self::assertSame([], $ranker->blend([], [], [], 10));
    }

    public function testFloorAppliesBeforeBoostsSoTheyNeverRescue(): void
    {
        $ranker = new Ranker(1.0, 1.0, 0.4, 0.6, 0.45);
        $signals = [1 => ['stock' => 5, 'popularity' => 100]];

        self::assertSame([], $ranker->blend([1 => 10.0], [], $signals, 10));
    }

    public function testStockBoostBreaksATie(): void
    {
        $ranker = new Ranker(0.2, 0.0, 0.4, 0.6, 0.0);
        $signals = [
            1 => ['stock' => 0, 'popularity' => 0],
            2 => ['stock' => 3, 'popularity' => 0],
        ];

        $out = $ranker->blend([1 => 5.0, 2 => 5.0], [1 => 0.5, 2 => 0.5], $signals, 10);

        self::assertSame([2, 1], self::ids($out));
    }

    public function testPopularityBoostBreaksATieAndIsBounded(): void
    {
        $ranker = new Ranker(0.0, 0.2, 0.4, 0.6, 0.0);
        $signals = [
            1 => ['stock' => 1, 'popularity' => 10],
            2 => ['stock' => 1, 'popularity' => 100000],
        ];

        $out = $ranker->blend([1 => 5.0, 2 => 5.0], [1 => 0.5, 2 => 0.5], $signals, 10);

        self::assertSame([2, 1], self::ids($out));
        self::assertEqualsWithDelta(1.0 * 1.2, $out[0]['score'], 1e-9); // boost capped at +20%
    }

    public function testBoostsDoNotOverrideClearRelevanceLead(): void
    {
        $ranker = new Ranker(0.1, 0.1, 0.4, 0.6, 0.0);
        $signals = [
            1 => ['stock' => 0, 'popularity' => 0],
            2 => ['stock' => 9, 'popularity' => 1000],
        ];

        $out = $ranker->blend([1 => 10.0, 2 => 8.0], [1 => 0.9, 2 => 0.5], $signals, 10);

        self::assertSame(1, self::ids($out)[0]);
    }

    public function testKeywordOnlyAndSemanticOnlyInputs(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        self::assertSame([2, 1], self::ids($ranker->blend([1 => 1.0, 2 => 3.0], [], [], 10)));
        self::assertSame([3, 4], self::ids($ranker->blend([], [3 => 0.9, 4 => 0.5], [], 10)));
    }

    public function testWeightsAreConfigDriven(): void
    {
        $keywordHeavy = new Ranker(0.0, 0.0, 1.0, 0.0, 0.0);
        $semanticHeavy = new Ranker(0.0, 0.0, 0.0, 1.0, 0.0);
        $keyword = [1 => 10.0, 2 => 1.0];
        $cosine = [1 => 0.2, 2 => 0.9];

        self::assertSame([1, 2], self::ids($keywordHeavy->blend($keyword, $cosine, [], 10)));
        self::assertSame([2, 1], self::ids($semanticHeavy->blend($keyword, $cosine, [], 10)));
    }

    public function testLimitIsApplied(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        $out = $ranker->blend([], array_fill_keys(range(1, 30), 0.5), [], 5);

        self::assertCount(5, $out);
    }

    public function testTiesBreakByProductId(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        self::assertSame([3, 7], self::ids($ranker->blend([], [7 => 0.5, 3 => 0.5], [], 10)));
    }
}
