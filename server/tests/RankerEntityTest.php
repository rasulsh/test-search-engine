<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ranker;
use PHPUnit\Framework\TestCase;

/** M44: the brand / platform the query names lifts its products, demotes the others, and ends their floor exemption. */
final class RankerEntityTest extends TestCase
{
    private const ENTITY = [
        'active' => ['brand' => true, 'platform' => false],
        'status' => [
            1 => ['brand' => 1, 'platform' => 0],
            2 => ['brand' => -1, 'platform' => 0],
            3 => ['brand' => 0, 'platform' => 0],
        ],
    ];

    /** @param list<array{product_id: int}> $rows @return list<int> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['product_id'], $rows);
    }

    public function testTheBrandIsLiftedAndTheOtherBrandDemotedButNothingIsRemoved(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0, 0.0, 0.0, 0.0, 2, 0, 0.15, 0.10);
        // Equal relevance: only the entity factor orders them.
        $keyword = [1 => 10.0, 2 => 10.0, 3 => 10.0];
        $out = $ranker->blend($keyword, [1 => 0.5, 2 => 0.5, 3 => 0.5], [], 10, [], 0.0, [], true, [], self::ENTITY);

        self::assertSame([1, 3, 2], self::ids($out));
        self::assertEqualsWithDelta(1.15, $out[0]['detail']['entity']['factor'], 1e-9);
        self::assertEqualsWithDelta(0.9, $out[2]['detail']['entity']['factor'], 1e-9);
        self::assertSame(['brand' => -1, 'platform' => 0, 'factor' => 0.9], $out[2]['detail']['entity']);
    }

    public function testASolidHitOfAnotherBrandLosesItsFloorExemption(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.45, 0.0, 0.0, 0.0, 2, 0, 0.15, 0.10);
        // Both are solid keyword hits the VPS barely sees (cosine 0): the floor 0.45 would drop them were
        // they not exempt. 1 carries the brand and scores above the floor on its cosine.
        $keyword = [1 => 10.0, 2 => 10.0];
        $cosines = [1 => 0.9];

        $without = $ranker->blend($keyword, $cosines, [], 10, [1, 2]);
        $with = $ranker->blend($keyword, $cosines, [], 10, [1, 2], 0.0, [], false, [], self::ENTITY);

        self::assertSame([1, 2], self::ids($without), 'a solid hit is kept whatever the VPS says');
        self::assertSame([1], self::ids($with), 'a solid hit of another brand has to clear the floor');
    }

    public function testAnInactiveDimensionChangesNothing(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0, 0.0, 0.0, 0.0, 2, 0, 0.15, 0.10);
        $inactive = ['active' => ['brand' => false, 'platform' => false]] + self::ENTITY;

        $keyword = [1 => 10.0, 2 => 10.0];
        $out = $ranker->blend($keyword, [1 => 0.5, 2 => 0.5], [], 10, [1, 2], 0.0, [], true, [], $inactive);

        self::assertSame([1, 2], self::ids($out));
        self::assertArrayNotHasKey('entity', $out[0]['detail']);
    }

    public function testBothKnobsAtZeroTurnTheFeatureOff(): void
    {
        $ranker = new Ranker(0.0, 0.0, 0.4, 0.6, 0.0);

        $keyword = [1 => 10.0, 2 => 10.0];
        $out = $ranker->blend($keyword, [1 => 0.5, 2 => 0.5], [], 10, [], 0.0, [], false, [], self::ENTITY);

        self::assertSame([1, 2], self::ids($out));
    }
}
