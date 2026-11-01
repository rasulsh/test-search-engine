<?php

declare(strict_types=1);

namespace App\Tests;

use App\Normalizer;
use App\QueryEntities;
use App\Synonyms;
use PHPUnit\Framework\TestCase;

/** M44: brands and platforms a query names, found through the owner's alias groups. */
final class QueryEntitiesTest extends TestCase
{
    private function entities(): QueryEntities
    {
        $synonyms = new Synonyms([
            ['شیامی', 'شیائومی', 'xiaomi'],
            ['ps4', 'playstation 4', 'پلی استیشن 4'],
            ['western digital', 'وسترن دیجیتال', 'wd'],
        ]);

        return new QueryEntities(
            ['xiaomi', 'Western Digital', 'asus', 'lg'],
            [
                'ps4' => ['ps4', 'playstation 4'],
                'ps5' => ['ps5', 'playstation 5'],
                'xbox' => ['xbox one', 'xbox series'],
            ],
            $synonyms
        );
    }

    /** @return list<list<string>> */
    private static function variants(string ...$queries): array
    {
        return array_map(static fn (string $q): array => explode(' ', Normalizer::normalize($q)), $queries);
    }

    public function testAMisspelledBrandResolvesThroughTheOwnersAliasGroup(): void
    {
        $found = $this->entities()->recognize(self::variants('مانیتور شیامی'));

        self::assertSame(['xiaomi'], $found['brands']);
        self::assertSame([], $found['platforms']);
        self::assertSame(['مانیتور'], $found['rest']);
    }

    public function testAPhraseBrandIsFoundLongestFirst(): void
    {
        $found = $this->entities()->recognize(self::variants('هارد وسترن دیجیتال 1tb'));
        self::assertSame(['western digital'], $found['brands']);
        self::assertSame(['western digital'], $this->entities()->recognize(self::variants('wd'))['brands']);
    }

    public function testAPlatformIsFoundThroughItsAliasesToo(): void
    {
        $entities = $this->entities();

        self::assertSame(['ps4'], $entities->recognize(self::variants('بازی ps4'))['platforms']);
        self::assertSame(['ps4'], $entities->recognize(self::variants('بازی پلی استیشن 4'))['platforms']);
        self::assertSame(['xbox'], $entities->recognize(self::variants('xbox series x'))['platforms']);
    }

    public function testAQueryWithNoEntityRecognizesNone(): void
    {
        self::assertSame(
            ['brands' => [], 'typed' => [], 'ambiguous' => [], 'platforms' => [], 'rest' => [],
                'condition' => null],
            $this->entities()->recognize(self::variants('کیبورد مکانیکال'))
        );
    }

    public function testStatusesCarryTheEntityAnotherOneOrNone(): void
    {
        $entities = $this->entities();
        $recognized = ['brands' => ['xiaomi'], 'platforms' => ['ps4'], 'rest' => []];
        $result = $entities->statuses($recognized, [
            1 => ['brand' => 'xiaomi', 'title' => 'بازی ps4', 'tags' => '', 'category' => ''],
            2 => ['brand' => 'lg', 'title' => 'بازی playstation 5', 'tags' => '', 'category' => ''],
            3 => ['brand' => '', 'title' => 'کابل', 'tags' => '', 'category' => ''],
            4 => ['brand' => 'asus', 'title' => 'بازی ps4 و ps5', 'tags' => '', 'category' => ''],
        ]);

        self::assertSame(['brand' => true, 'platform' => true], $result['active']);
        self::assertSame(['brand' => 1, 'platform' => 1], $result['status'][1]);
        self::assertSame(['brand' => -1, 'platform' => -1], $result['status'][2]);
        self::assertSame(['brand' => 0, 'platform' => 0], $result['status'][3]);
        self::assertSame(['brand' => -1, 'platform' => 1], $result['status'][4], 'a cross-platform title carries ps4');
    }

    public function testADimensionIsInactiveWhenNoCandidateCarriesTheEntity(): void
    {
        $result = $this->entities()->statuses(['brands' => ['xiaomi'], 'platforms' => [], 'rest' => []], [
            1 => ['brand' => 'lg', 'title' => '', 'tags' => '', 'category' => ''],
            2 => ['brand' => 'asus', 'title' => '', 'tags' => '', 'category' => ''],
        ]);

        self::assertSame(['brand' => false, 'platform' => false], $result['active']);
    }

    public function testABrandThatDoesNotMatchTheRestOfTheQueryIsInactive(): void
    {
        $entities = $this->entities();
        $signals = [
            1 => ['brand' => 'xiaomi', 'title' => 'گوشی موبایل', 'tags' => '', 'category' => ''],
            2 => ['brand' => 'lg', 'title' => 'مانیتور گیمینگ', 'tags' => '', 'category' => ''],
        ];
        $recognized = $entities->recognize(self::variants('مانیتور شیامی'));

        // The Xiaomi phone is not a monitor: nothing of the brand answers the query.
        self::assertSame(['brand' => false, 'platform' => false], $entities->statuses($recognized, $signals)['active']);

        $signals[3] = ['brand' => 'xiaomi', 'title' => 'مانیتور ۲۷ اینچ', 'tags' => '', 'category' => ''];
        $result = $entities->statuses($recognized, $signals);
        self::assertTrue($result['active']['brand']);
        self::assertSame(-1, $result['status'][2]['brand']);
    }

    public function testReorderPutsTheEntityFirstAndOtherBrandsLastWithoutDroppingAny(): void
    {
        $entity = [
            'active' => ['brand' => true, 'platform' => false],
            'status' => [
                10 => ['brand' => -1, 'platform' => 0],
                11 => ['brand' => 0, 'platform' => 0],
                12 => ['brand' => 1, 'platform' => 0],
                13 => ['brand' => -1, 'platform' => 0],
                14 => ['brand' => 1, 'platform' => 0],
            ],
        ];

        self::assertSame([12, 14, 11, 10, 13], QueryEntities::reorder([10, 11, 12, 13, 14], $entity));
        $inactive = ['active' => ['brand' => false, 'platform' => false]] + $entity;
        self::assertSame([10, 11, 12, 13, 14], QueryEntities::reorder([10, 11, 12, 13, 14], $inactive));
    }

    /** Live evidence: brands reached through a platform term, a publisher suffix or a generic word. */
    private function liveEntities(): QueryEntities
    {
        return new QueryEntities(
            ['playstation studios', 'xbox game studios', 'nintendo', 'sony'],
            ['playstation' => ['playstation'], 'xbox' => ['xbox']],
            new Synonyms([
                ['playstation', 'پلی استیشن', 'playstation studios'],
                ['xbox', 'ایکس باکس', 'xbox game studios'],
                ['گیفت کارت', 'gift card', 'nintendo'],
                ['نینتندو', 'nintendo'],
            ])
        );
    }

    public function testBrandsReachedFromAPlatformOrGenericWordAreNotRecognizedOrTyped(): void
    {
        $generic = static fn (string $phrase): bool => in_array($phrase, ['گیفت کارت', 'دسته'], true);
        $entities = $this->liveEntities();

        foreach (['پلی استیشن', 'دسته ایکس باکس'] as $query) {
            $found = $entities->recognize(self::variants($query), $generic);
            self::assertSame([], $found['brands'], $query);
            self::assertSame([], $found['typed'], $query);
            self::assertNotSame([], $found['platforms'], $query);
        }
        $found = $entities->recognize(self::variants('گیفت کارت'), $generic);
        self::assertSame(['nintendo'], $found['brands'], 'still recognized for ranking');
        self::assertSame([], $found['typed']);
    }

    public function testATypedBrandOrItsAliasIsReported(): void
    {
        $entities = $this->liveEntities();

        self::assertSame(['nintendo'], $entities->recognize(self::variants('نینتندو'))['typed']);
        self::assertSame(['nintendo'], $entities->recognize(self::variants('nintendo switch'))['typed']);
        self::assertSame(
            ['xiaomi'],
            $this->entities()->recognize(self::variants('مانیتور شیامی'))['typed']
        );
    }

    public function testTheFileIsParsedAndValidated(): void
    {
        $good = '{"version": 1, "brands": [{"name": "asus", "count": 5}], "platforms": {"ps4": ["ps4"]}}';
        self::assertSame(
            ['brands' => ['asus'], 'ambiguous' => [], 'platforms' => ['ps4' => ['ps4']]],
            QueryEntities::parse($good)
        );
        foreach (
            ['', '[]', '{"version": 3, "brands": [], "platforms": {}}',
            '{"version": 2, "brands": [{"name": "a", "count": 1, "ambiguous": "yes"}], "platforms": {}}',
            '{"version": 1, "brands": [{"name": "a"}], "platforms": {}}',
            '{"version": 1, "brands": ["a"], "platforms": {}}',
            '{"version": 1, "brands": [], "platforms": {"ps4": "ps4"}}',
            '{"version": 1, "brands": []}'] as $bad
        ) {
            self::assertNull(QueryEntities::parse($bad), $bad);
        }
    }

    public function testAVersionTwoFileCarriesTheAmbiguousFlagsAndVersionOneStillLoads(): void
    {
        $v2 = '{"version": 2, "brands": [{"name": "blue", "count": 2, "ambiguous": true},'
            . ' {"name": "dobe", "count": 9}], "platforms": {}}';
        self::assertSame(['blue', 'dobe'], QueryEntities::parse($v2)['brands']);
        self::assertSame(['blue'], QueryEntities::parse($v2)['ambiguous']);

        $v1 = '{"version": 1, "brands": [{"name": "blue", "count": 2}], "platforms": {}}';
        self::assertSame(['blue'], QueryEntities::parse($v1)['brands']);
        self::assertSame([], QueryEntities::parse($v1)['ambiguous'], 'a version 1 file has no flags');
    }

    private function ambiguousEntities(string $blocklist = ''): QueryEntities
    {
        return new QueryEntities(
            ['blue', 'astro', 'dobe'],
            [],
            null,
            ['blue', 'astro'],
            $blocklist,
            'for,with'
        );
    }

    public function testAnAmbiguousBrandIsRecognizedOnlyWhenItIsTheWholeQuery(): void
    {
        $entities = $this->ambiguousEntities();

        self::assertSame([], $entities->recognize(self::variants('case navy blue'))['brands']);
        self::assertSame([], $entities->recognize(self::variants('dualsense astro bot'))['brands']);
        self::assertSame(['blue'], $entities->recognize(self::variants('blue'))['brands']);
        self::assertSame(['blue'], $entities->recognize(self::variants('blue'))['ambiguous']);
        self::assertSame(['astro'], $entities->recognize(self::variants('astro for'))['brands'], 'stopwords aside');
        // A normal brand next to an ambiguous word is still found, and the ambiguous one stays out.
        $both = $entities->recognize(self::variants('dobe stand navy blue'));
        self::assertSame(['dobe'], $both['brands']);
        self::assertSame([], $both['ambiguous']);
    }

    public function testTheBrandBlocklistNeverRecognizesAName(): void
    {
        $entities = $this->ambiguousEntities('Dobe, other');

        self::assertSame([], $entities->recognize(self::variants('dobe stand'))['brands']);
        self::assertSame(['blue'], $entities->recognize(self::variants('blue'))['brands']);
        self::assertSame(['dobe'], $this->ambiguousEntities()->recognize(self::variants('dobe stand'))['brands']);
    }

    public function testStatusesReportCarriedAndMentionedPerBrandWithoutChangingTheBoostSemantics(): void
    {
        $entities = $this->entities();
        $recognized = ['brands' => ['xiaomi', 'asus'], 'platforms' => [], 'rest' => ['مانیتور']];
        $result = $entities->statuses($recognized, [
            // Empty brand, named in the title only: neutral for the boost, but mentioned.
            1 => ['brand' => '', 'title' => 'xiaomi mi monitor', 'tags' => '', 'category' => ''],
            2 => ['brand' => 'lg', 'title' => 'monitor', 'tags' => 'asus rog', 'category' => ''],
            3 => ['brand' => 'asus', 'title' => 'کابل', 'tags' => '', 'category' => ''],
            4 => ['brand' => 'lg', 'title' => 'xiaomis', 'tags' => '', 'category' => ''],
        ]);

        self::assertSame(['carried' => false, 'mentioned' => true], $result['brands']['xiaomi']);
        self::assertSame(['carried' => true, 'mentioned' => true], $result['brands']['asus']);
        self::assertSame(['brand' => 0, 'platform' => 0], $result['status'][1], 'empty brand stays neutral');
        self::assertSame(
            ['brand' => 0, 'platform' => 0],
            $result['status'][2],
            'another brand field, but the tags name asus: neutral, not demoted'
        );
        self::assertSame(['brand' => -1, 'platform' => 0], $result['status'][4], 'another brand, no mention');
        self::assertSame(
            ['brand' => false, 'platform' => false],
            $result['active'],
            'the only carrier does not hold the rest of the query'
        );
        $none = $entities->statuses(['brands' => ['xiaomi'], 'platforms' => []], [
            1 => ['brand' => 'lg', 'title' => 'xiaomis phone', 'tags' => '', 'category' => ''],
        ]);
        self::assertSame(['carried' => false, 'mentioned' => false], $none['brands']['xiaomi'], 'whole words only');
    }

    public function testNoFileMeansNoEntities(): void
    {
        self::assertTrue(QueryEntities::fromDirectory('/nonexistent/dir')->isEmpty());
    }

    /** @param list<int>|null $used */
    private function withCondition(?array $used): QueryEntities
    {
        $platforms = ['ps4' => ['ps4', 'playstation 4']];

        return new QueryEntities(['xiaomi', 'deadskull'], $platforms, null, [], '', '', $used);
    }

    public function testTheConditionWordsAreRecognizedAndLeaveTheRest(): void
    {
        $entities = $this->withCondition([1]);
        $used = ['ps4 کارکرده', 'ps4 کار کرده', 'ps4 دست دوم', 'ps4 used', 'ps4 second hand', 'ps4 secondhand'];
        foreach ($used as $query) {
            $found = $entities->recognize(self::variants($query));
            self::assertSame('used', $found['condition'], $query);
            self::assertSame(['ps4'], $found['platforms'], $query);
            self::assertSame([], $found['rest'], "$query: the condition words are not the rest of the query");
        }
        foreach (['ps4 آکبند', 'ps4 sealed'] as $query) {
            self::assertSame('new', $entities->recognize(self::variants($query))['condition'], $query);
        }
        // "new" and "نو" are ordinary title words, not a condition.
        foreach (['ps4 new', 'ps4 نو', 'ps4', 'ps4 pro'] as $query) {
            self::assertNull($entities->recognize(self::variants($query))['condition'], $query);
        }
        self::assertSame('used', $entities->recognize(self::variants('ps4 sealed used'))['condition'], 'used wins');
    }

    public function testNoConditionDataMeansNoConditionAtAll(): void
    {
        $entities = $this->withCondition(null);

        self::assertNull($entities->recognize(self::variants('ps4 کارکرده'))['condition']);
        self::assertFalse($entities->hasCondition());
        $result = $entities->statuses(['brands' => [], 'platforms' => []], [
            1 => ['brand' => '', 'title' => 'ps4', 'tags' => '', 'category' => ''],
        ]);
        self::assertSame(['brand' => false, 'platform' => false], $result['active']);
        self::assertSame(['brand' => 0, 'platform' => 0], $result['status'][1]);
        self::assertFalse($entities->isEmpty(), 'brands and platforms alone still make it non-empty');
        self::assertTrue((new QueryEntities([], [], null))->isEmpty());
        self::assertTrue((new QueryEntities([], [], null, [], '', '', []))->hasCondition(), 'an empty list is data');
    }

    public function testConditionStatuses(): void
    {
        $entities = $this->withCondition([1, 3]);
        $signals = [
            1 => ['brand' => '', 'title' => 'a', 'tags' => '', 'category' => ''],
            2 => ['brand' => '', 'title' => 'b', 'tags' => '', 'category' => ''],
            3 => ['brand' => '', 'title' => 'c', 'tags' => '', 'category' => ''],
        ];
        $base = ['brands' => [], 'platforms' => [], 'rest' => []];

        $used = $entities->statuses($base + ['condition' => 'used'], $signals);
        self::assertTrue($used['active']['condition']);
        self::assertSame([1, -1, 1], array_column($used['status'], 'condition'));
        $new = $entities->statuses($base + ['condition' => 'new'], $signals);
        self::assertSame([-1, 0, -1], array_column($new['status'], 'condition'));
        $none = $entities->statuses($base + ['condition' => null], $signals);
        self::assertSame([-1, 0, -1], array_column($none['status'], 'condition'));
    }

    public function testReorderPutsUsedItemsFirstOnlyWhenAskedAndLastOtherwise(): void
    {
        $entities = $this->withCondition([2]);
        $signals = [
            1 => ['brand' => '', 'title' => 'a', 'tags' => '', 'category' => ''],
            2 => ['brand' => '', 'title' => 'b', 'tags' => '', 'category' => ''],
            3 => ['brand' => '', 'title' => 'c', 'tags' => '', 'category' => ''],
        ];
        $base = ['brands' => [], 'platforms' => [], 'rest' => []];

        $asked = $entities->statuses($base + ['condition' => 'used'], $signals);
        self::assertSame([2, 1, 3], QueryEntities::reorder([1, 2, 3], $asked));
        $default = $entities->statuses($base + ['condition' => null], $signals);
        self::assertSame([1, 3, 2], QueryEntities::reorder([1, 2, 3], $default));
    }

    public function testTheUsedListIsParsedAndValidatedAndStaysOptional(): void
    {
        $head = '{"version": 2, "brands": [], "platforms": {}';
        self::assertSame([3, 9], QueryEntities::parse($head . ', "used": [3, 9]}')['used']);
        self::assertSame([], QueryEntities::parse($head . ', "used": []}')['used']);
        self::assertArrayNotHasKey('used', QueryEntities::parse($head . '}'), 'no key: no condition data');
        self::assertArrayNotHasKey('used', QueryEntities::parse('{"version": 1, "brands": [], "platforms": {}}'));
        foreach (['"used": "3"', '"used": {"a": 1}', '"used": ["3"]', '"used": [1.5]', '"used": null'] as $bad) {
            self::assertNull(QueryEntities::parse($head . ', ' . $bad . '}'), $bad);
        }
    }

    public function testABrandMentionedInTheTitleTagsOrCategoryIsNeutralNotDemoted(): void
    {
        $entities = $this->withCondition(null);
        $result = $entities->statuses(['brands' => ['deadskull'], 'platforms' => [], 'rest' => []], [
            1 => ['brand' => 'deadskull', 'title' => 'کاور', 'tags' => '', 'category' => ''],
            // Filed under the manufacturer "سایر" (Other) but a Deadskull product by name / tags / category.
            2 => ['brand' => 'سایر', 'title' => 'کاور deadskull ps5', 'tags' => '', 'category' => ''],
            3 => ['brand' => 'سایر', 'title' => 'کاور', 'tags' => 'deadskull', 'category' => ''],
            4 => ['brand' => 'سایر', 'title' => 'کاور', 'tags' => '', 'category' => 'deadskull'],
            // A truly different brand that does not mention it, and a look-alike word, are still demoted.
            5 => ['brand' => 'sony', 'title' => 'کاور', 'tags' => '', 'category' => ''],
            6 => ['brand' => 'سایر', 'title' => 'deadskulls cover', 'tags' => '', 'category' => ''],
        ]);

        self::assertSame(1, $result['status'][1]['brand']);
        foreach ([2, 3, 4] as $id) {
            self::assertSame(0, $result['status'][$id]['brand'], "product $id");
        }
        self::assertSame(-1, $result['status'][5]['brand']);
        self::assertSame(-1, $result['status'][6]['brand']);
        self::assertTrue($result['active']['brand'], 'the active-brand rule is unchanged');
        self::assertSame(['carried' => true, 'mentioned' => true], $result['brands']['deadskull']);
    }
}
