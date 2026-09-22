<?php

declare(strict_types=1);

namespace App\Tests;

use App\ProductLoader;

final class ProductLoaderTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private function row(int $id, string $title, string $description): array
    {
        return [
            'product_id'  => $id,
            'title'       => $title,
            'description' => $description,
            'brand'       => 'BrandX',
            'category'    => 'CatY',
            'model'       => 'M-' . $id,
            'price'       => 9.99,
            'stock'       => 3,
            'url'         => '/p/' . $id,
            'image'       => '/i/' . $id . '.jpg',
            'popularity'  => 5,
        ];
    }

    public function testLoadInsertsRowsWithPassthroughNormalization(): void
    {
        $loader = new ProductLoader($this->pdo, 'products');

        $count = $loader->load([
            $this->row(1, 'Wireless Mouse', 'A wireless optical mouse'),
            $this->row(2, 'موس بی‌سیم', 'موس نوری بی‌سیم'),
        ]);

        self::assertSame(2, $count);

        $stored = $this->pdo
            ->query('SELECT product_id, title, normalized_title, normalized_desc, price, popularity
                     FROM products ORDER BY product_id')
            ->fetchAll();

        self::assertCount(2, $stored);
        // Passthrough in M1: normalized_* equals the raw text verbatim.
        self::assertSame('Wireless Mouse', $stored[0]['normalized_title']);
        self::assertSame('A wireless optical mouse', $stored[0]['normalized_desc']);
        self::assertSame('موس بی‌سیم', $stored[1]['normalized_title']);
        self::assertSame(9.99, (float) $stored[0]['price']);
    }

    public function testLoadReplacesExistingProductId(): void
    {
        $loader = new ProductLoader($this->pdo, 'products');

        $loader->load([$this->row(1, 'Old Title', 'Old description')]);
        $loader->load([$this->row(1, 'New Title', 'New description')]);

        $rows = $this->pdo->query('SELECT title, normalized_title FROM products')->fetchAll();

        self::assertCount(1, $rows);
        self::assertSame('New Title', $rows[0]['title']);
        self::assertSame('New Title', $rows[0]['normalized_title']);
    }

    public function testInjectedNormalizerPopulatesNormalizedColumns(): void
    {
        // Proves M2's Normalizer can be injected here with no other change.
        $loader = new ProductLoader(
            $this->pdo,
            'products',
            static fn (string $text): string => mb_strtoupper($text)
        );

        $loader->load([$this->row(1, 'laptop', 'gaming laptop')]);

        $row = $this->pdo->query('SELECT title, normalized_title FROM products')->fetch();

        self::assertSame('laptop', $row['title']);
        self::assertSame('LAPTOP', $row['normalized_title']);
    }
}
