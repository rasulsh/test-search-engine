<?php

declare(strict_types=1);

namespace App\Tests;

use App\Paging;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The page arithmetic behind the panel's paginated tables (M42). */
final class PagingTest extends TestCase
{
    public function testAnEmptyListHasOneEmptyPage(): void
    {
        self::assertSame(
            ['page' => 1, 'pages' => 1, 'offset' => 0, 'limit' => 50, 'from' => 0, 'to' => 0, 'total' => 0],
            Paging::of(0, 1, 50)
        );
        self::assertSame(1, Paging::of(0, 7, 50)['page'], 'any requested page of nothing is page 1');
    }

    public function testTheLastPageMayBePartialAndTheRangeIsOneBased(): void
    {
        $last = Paging::of(101, 3, 50);
        self::assertSame(
            [3, 3, 100, 101, 101],
            [$last['page'], $last['pages'], $last['offset'], $last['from'], $last['to']]
        );

        $exact = Paging::of(100, 2, 50);
        self::assertSame([2, 50, 51, 100], [$exact['pages'], $exact['offset'], $exact['from'], $exact['to']]);

        $single = Paging::of(1, 1, 25);
        self::assertSame([1, 1, 1, 1], [$single['pages'], $single['from'], $single['to'], $single['total']]);
    }

    #[DataProvider('outOfRange')]
    public function testAnOutOfRangePageIsClamped(int $requested, int $expected): void
    {
        self::assertSame($expected, Paging::of(130, $requested, 50)['page']);
    }

    /** @return array<string, array{int, int}> */
    public static function outOfRange(): array
    {
        return ['zero' => [0, 1], 'negative' => [-4, 1], 'past the end' => [99, 3], 'huge' => [PHP_INT_MAX, 3],
            'the last' => [3, 3], 'the first' => [1, 1]];
    }

    public function testAZeroOrNegativePageSizeNeverDividesByZero(): void
    {
        self::assertSame(1, Paging::of(5, 1, 0)['limit']);
        self::assertSame(5, Paging::of(5, 1, -3)['pages']);
        self::assertSame(0, Paging::of(-9, 1, 10)['total']);
    }

    #[DataProvider('sizes')]
    public function testOnlyTheOfferedPageSizesAreAccepted(mixed $raw, int $expected): void
    {
        self::assertSame($expected, Paging::size($raw, 50));
    }

    /** @return array<string, array{mixed, int}> */
    public static function sizes(): array
    {
        return [
            '25' => [25, 25], 'string 100' => ['100', 100], '200' => ['200', 200], 'not offered' => [7, 50],
            'huge' => ['100000', 50], 'zero' => [0, 50], 'negative' => ['-25', 50], 'null' => [null, 50],
            'trailing junk' => ['25abc', 50], 'array' => [[25], 50], 'float text' => ['25.5', 50], 'empty' => ['', 50],
        ];
    }

    public function testAConfiguredDefaultOutsideTheListIsOfferedAndAccepted(): void
    {
        self::assertSame([25, 50, 100, 200], Paging::sizes(100));
        self::assertSame([20, 25, 50, 100, 200], Paging::sizes(20));
        self::assertSame(20, Paging::size(null, 20));
        self::assertSame(20, Paging::size('20', 20));
        self::assertSame(50, Paging::size('50', 20));
    }

    public function testTheWindowLinksTheEndsAndTheNeighboursAndMarksGaps(): void
    {
        self::assertSame([1], Paging::window(1, 1));
        self::assertSame([1, 2, 3, 4, 5], Paging::window(3, 5));
        self::assertSame([1, 2, 3, null, 20], Paging::window(1, 20));
        self::assertSame([1, null, 8, 9, 10, 11, 12, null, 20], Paging::window(10, 20));
        self::assertSame([1, null, 18, 19, 20], Paging::window(20, 20));
        self::assertSame([1, 2, 3, 4, 5, null, 9], Paging::window(3, 9));
    }
}
