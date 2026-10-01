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

    public function testSolidHitIsExemptFromTheFloorButWeakHitIsNot(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45);

        // 1 solid, 2 weak, both keyword-only (0.4 / 0.2 < 0.45); 3 has cosine.
        $out = $ranker->blend([1 => 10.0, 2 => 5.0], [3 => 0.8], [], 10, [1]);

        self::assertSame([3, 1], self::ids($out));
    }

    public function testSolidHitWithoutAnyCosineSurvivesAnEmptySemanticList(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45);

        $out = $ranker->blend([1 => 10.0, 2 => 5.0], [], [], 10, [1, 2], 0.4);

        self::assertSame([1, 2], self::ids($out)); // keyword order, nothing blanked
    }

    public function testUnreturnedSolidHitIsAssumedAtTheFloorAndLeadsPureNeighbours(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45);

        // 1: solid, not returned: 0.4 + 0.6 * (0.4 / 0.8) = 0.7; 2: pure semantic: 0.6.
        $out = $ranker->blend([1 => 10.0], [2 => 0.8], [], 10, [1], 0.4);

        self::assertSame([1, 2], self::ids($out));
        // Not exempt: a weak hit gets no assumed cosine and no pass.
        self::assertSame([2], self::ids($ranker->blend([1 => 10.0], [2 => 0.8], [], 10, [], 0.4)));
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

    /**
     * Ranker for the M21 match boosts alone: no stock / popularity, no floor.
     *
     * @return Ranker
     */
    private static function matchRanker(float $brand = 0.15, float $category = 0.1, float $tag = 0.1): Ranker
    {
        return new Ranker(0.0, 0.0, 0.4, 0.6, 0.0, $brand, $category, $tag, 2);
    }

    /** @param array<int, array<string, mixed>> $texts */
    private static function signals(array $texts): array
    {
        return array_map(static fn (array $t): array => ['stock' => 0, 'popularity' => 0] + $t, $texts);
    }

    public function testBrandMatchBoostFixesBrandDrift(): void
    {
        // Equal cosines for three SSD brands: ties go to the lowest id, so the
        // queried brand (the highest id) only leads because of the boost.
        $cosines = [1 => 0.70, 2 => 0.70, 3 => 0.69];
        $signals = self::signals([1 => ['brand' => 'wd'], 2 => ['brand' => 'kingston'], 3 => ['brand' => 'samsung']]);
        $variants = [['اس', 'اس', 'دی', 'samsung']];

        $plain = self::matchRanker(0.0)->blend([], $cosines, $signals, 10, [], 0.0, $variants);
        $boosted = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, $variants);

        self::assertSame([1, 2, 3], self::ids($plain));
        self::assertSame([3, 1, 2], self::ids($boosted));
    }

    public function testBrandMatchesThroughAnAliasVariant(): void
    {
        // A Persian query meets a Latin brand name through its alias variant.
        $signals = self::signals([1 => ['brand' => 'lg'], 2 => ['brand' => 'samsung']]);
        $cosines = [1 => 0.7, 2 => 0.7];

        $literal = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, [['سامسونگ']]);
        $aliased = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, [['سامسونگ'], ['samsung']]);

        self::assertSame([1, 2], self::ids($literal));
        self::assertSame([2, 1], self::ids($aliased));
    }

    public function testBrandAndCategoryNeedEveryWordOfTheirName(): void
    {
        $signals = self::signals([
            1 => ['brand' => 'sandisk', 'category' => 'flash'],
            2 => ['brand' => 'western digital', 'category' => 'solid state drive'],
        ]);
        $cosines = [1 => 0.7, 2 => 0.7];

        $partial = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, [['western', 'drive']]);
        $full = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, [['western', 'digital', 'ssd']]);

        self::assertEqualsWithDelta($partial[0]['score'], $partial[1]['score'], 1e-9); // no boost for half a name
        self::assertSame(2, $full[0]['product_id']);
        self::assertEqualsWithDelta(0.6 * 1.15, $full[0]['score'], 1e-9);
    }

    public function testCategoryMatchBoostLiftsThatCategory(): void
    {
        $signals = self::signals([1 => ['category' => 'cable'], 2 => ['category' => 'headphone']]);
        $cosines = [1 => 0.7, 2 => 0.7];

        $out = self::matchRanker()->blend([], $cosines, $signals, 10, [], 0.0, [['wireless', 'headphone']]);

        self::assertSame([2, 1], self::ids($out));
        self::assertEqualsWithDelta(0.6 * 1.1, $out[0]['score'], 1e-9);
    }

    public function testTagPhraseBoostNeedsAContiguousMultiWordPhrase(): void
    {
        $tags = 'assassins creed origins no mans sky';
        $signals = self::signals([1 => ['tags' => 'other'], 2 => ['tags' => $tags]]);
        $cosines = [1 => 0.7, 2 => 0.7];
        $boost = static fn (array $variants): array => self::matchRanker(0.0, 0.0)
            ->blend([], $cosines, $signals, 10, [], 0.0, $variants);

        self::assertSame([2, 1], self::ids($boost([['assassins', 'creed', 'origins']])));
        self::assertSame([2, 1], self::ids($boost([['creed', 'origins']]))); // phrase inside the run
        self::assertSame([1, 2], self::ids($boost([['assassins', 'origins']]))); // not adjacent
        self::assertSame([1, 2], self::ids($boost([['origins', 'assassins']]))); // wrong order
        self::assertSame([1, 2], self::ids($boost([['assassins']]))); // one word: keyword tier owns it
    }

    public function testTagBoostWordCountIsConfigDriven(): void
    {
        $signals = self::signals([1 => ['tags' => 'other'], 2 => ['tags' => 'pubg']]);
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0, 0.0, 0.0, 0.1, 1);

        $out = $ranker->blend([], [1 => 0.7, 2 => 0.7], $signals, 10, [], 0.0, [['pubg']]);

        self::assertSame([2, 1], self::ids($out));
    }

    public function testMatchBoostsNeverRescueAnItemTheFloorDropped(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45, 1.0, 1.0, 1.0, 1);
        $signals = self::signals([1 => ['brand' => 'samsung', 'category' => 'ssd', 'tags' => 'samsung ssd']]);

        // Keyword-only hit: 0.4 < 0.45, dropped however well brand / category / tags match.
        $out = $ranker->blend([1 => 10.0], [], $signals, 10, [], 0.0, [['samsung', 'ssd']]);

        self::assertSame([], $out);
    }

    public function testMatchBoostsReorderCloseCandidatesButDoNotDominate(): void
    {
        // Stacked brand + category + tag boosts (+0.35) lift 0.9 over 0.7, but not over 0.5 of 1.0.
        $ranker = self::matchRanker();
        $signals = self::signals([
            1 => [],
            2 => ['brand' => 'samsung', 'category' => 'ssd', 'tags' => 'samsung ssd'],
            3 => ['brand' => 'samsung', 'category' => 'ssd', 'tags' => 'samsung ssd'],
        ]);
        $variants = [['samsung', 'ssd']];

        $close = $ranker->blend([], [1 => 0.8, 2 => 0.7], $signals, 10, [], 0.0, $variants);
        $far = $ranker->blend([], [1 => 1.0, 3 => 0.5], $signals, 10, [], 0.0, $variants);

        self::assertSame([2, 1], self::ids($close)); // 0.6 * 0.875 * 1.35 = 0.709 > 0.6
        self::assertSame([1, 3], self::ids($far)); // 0.3 * 1.35 = 0.405 < 0.6
    }

    public function testNoMatchBoostWithoutVariantsOrTexts(): void
    {
        $signals = self::signals([1 => ['brand' => 'samsung'], 2 => []]);

        $out = self::matchRanker(1.0, 1.0, 1.0)->blend([], [1 => 0.7, 2 => 0.7], $signals, 10);

        self::assertSame([1, 2], self::ids($out));
        self::assertEqualsWithDelta($out[0]['score'], $out[1]['score'], 1e-12);
    }

    public function testExplainReportsEveryComponentOfTheScore(): void
    {
        $ranker = new Ranker(0.1, 0.2, 0.4, 0.6, 0.0, 0.15, 0.1, 0.1, 2);
        $signals = [
            1 => ['stock' => 3, 'popularity' => 10, 'brand' => 'samsung', 'category' => '', 'tags' => 'a b'],
            2 => ['stock' => 0, 'popularity' => 5],
        ];

        $out = $ranker->blend([1 => 8.0, 2 => 4.0], [1 => 0.5], $signals, 10, [2], 0.25, [['samsung']], true);

        self::assertSame([1, 2], self::ids($out));
        $detail = $out[0]['detail'];
        self::assertSame(['keyword', 'semantic', 'blended', 'boosts', 'score'], array_keys($detail));
        self::assertSame(['score' => 8.0, 'normalized' => 1.0], $detail['keyword']);
        self::assertSame(['cosine' => 0.5, 'normalized' => 1.0, 'assumed' => false], $detail['semantic']);
        self::assertSame(1.0, $detail['blended']);
        self::assertSame(
            ['stock' => 0.1, 'popularity' => 0.2, 'brand' => 0.15, 'category' => 0.0, 'tag' => 0.0],
            $detail['boosts']
        );
        self::assertSame(1.45, $detail['score']);
        // Solid hit the VPS did not return: its cosine is assumed at the floor (capped at the best).
        self::assertTrue($out[1]['detail']['semantic']['assumed']);
        self::assertNull($out[1]['detail']['semantic']['cosine']);
        // Without $explain the rows stay as before.
        self::assertArrayNotHasKey('detail', $ranker->blend([1 => 8.0], [], $signals, 10)[0] ?? ['product_id' => 1]);
    }

    public function testSettingsExposeTheKnobsForTheDebugBreakdown(): void
    {
        $settings = (new Ranker(0.1, 0.2, 0.4, 0.6, 0.45, 0.15, 0.1, 0.05, 3))->settings();

        self::assertSame(
            [
                'keyword_weight' => 0.4, 'semantic_weight' => 0.6, 'min_relevance' => 0.45, 'stock_boost' => 0.1,
                'popularity_boost' => 0.2, 'brand_match_boost' => 0.15, 'category_match_boost' => 0.1,
                'tag_match_boost' => 0.05, 'tag_match_min_tokens' => 3,
            ],
            $settings
        );
    }
}
