<?php

declare(strict_types=1);

namespace App\Tests;

use App\ProductDetails;

final class ProductDetailsTest extends DatabaseTestCase
{
    private ProductDetails $details;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        $this->details = new ProductDetails($this->pdo, 'products');
    }

    public function testReturnsDisplayFieldsInRequestedOrder(): void
    {
        $rows = $this->details->fetch([1011, 1002, 1009]);

        self::assertSame([1011, 1002, 1009], array_column($rows, 'id'));
        self::assertSame([
            'id'    => 1002,
            'title' => 'گوشی موبایل اپل آیفون ۱۵ پرو مکس',
            'url'   => '/product/iphone-15-pro-max-fa',
            'image' => '/image/iphone15.jpg',
            'price' => 1299.0,
        ], $rows[1]);
    }

    public function testSkipsIdsMissingFromTheTable(): void
    {
        self::assertSame([1001], array_column($this->details->fetch([999999, 1001]), 'id'));
    }

    public function testEmptyIdsNeedNoQuery(): void
    {
        self::assertSame([], $this->details->fetch([]));
    }
}
