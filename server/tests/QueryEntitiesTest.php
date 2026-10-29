<?php

declare(strict_types=1);

namespace App\Tests;

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
        return array_map(static fn (string $q): array => explode(' ', $q), $queries);
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
            ['brands' => [], 'platforms' => [], 'rest' => []],
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

    public function testTheFileIsParsedAndValidated(): void
    {
        $good = '{"version": 1, "brands": [{"name": "asus", "count": 5}], "platforms": {"ps4": ["ps4"]}}';
        self::assertSame(
            ['brands' => ['asus'], 'platforms' => ['ps4' => ['ps4']]],
            QueryEntities::parse($good)
        );
        foreach (
            ['', '[]', '{"version": 2, "brands": [], "platforms": {}}',
            '{"version": 1, "brands": [{"name": "a"}], "platforms": {}}',
            '{"version": 1, "brands": ["a"], "platforms": {}}',
            '{"version": 1, "brands": [], "platforms": {"ps4": "ps4"}}',
            '{"version": 1, "brands": []}'] as $bad
        ) {
            self::assertNull(QueryEntities::parse($bad), $bad);
        }
    }

    public function testNoFileMeansNoEntities(): void
    {
        self::assertTrue(QueryEntities::fromDirectory('/nonexistent/dir')->isEmpty());
    }
}
