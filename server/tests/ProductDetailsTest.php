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

    public function testJoinsRelativeValuesToConfiguredStorefrontBases(): void
    {
        $this->pdo->exec(
            "UPDATE products SET url = 'index.php?route=product/product&product_id=1001',
                                 image = 'catalog/x.jpg' WHERE product_id = 1001"
        );
        $this->pdo->exec(
            "UPDATE products SET url = 'https://other.example/p', image = '//cdn.example/y.jpg'
             WHERE product_id = 1002"
        );
        $details = new ProductDetails(
            $this->pdo,
            'products',
            'https://shop.example.com/',
            'https://shop.example.com/image'
        );

        [$relative, $absolute] = $details->fetch([1001, 1002]);

        self::assertSame(
            'https://shop.example.com/index.php?route=product/product&product_id=1001',
            $relative['url']
        );
        self::assertSame('https://shop.example.com/image/catalog/x.jpg', $relative['image']);
        // Already absolute (or protocol-relative) values are left alone.
        self::assertSame('https://other.example/p', $absolute['url']);
        self::assertSame('//cdn.example/y.jpg', $absolute['image']);
    }
}
