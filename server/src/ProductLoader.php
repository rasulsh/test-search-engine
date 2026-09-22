<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Loads product rows into the products table, populating the FULLTEXT-indexed
 * normalized_* columns.
 *
 * In M1 normalization is a passthrough copy of the raw text. M2 injects the real
 * Normalizer via the constructor — the single write path here means that swap
 * needs no schema change and no change to callers.
 */
final class ProductLoader
{
    private PDO $pdo;
    private string $table;
    /** @var callable(string): string */
    private $normalize;

    public function __construct(PDO $pdo, string $productsTable, ?callable $normalizer = null)
    {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($productsTable);
        $this->normalize = $normalizer ?? static fn (string $text): string => $text;
    }

    /**
     * Insert or replace the given product rows. Missing optional fields default
     * to empty/zero. Returns the number of rows processed.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function load(iterable $rows): int
    {
        $stmt = $this->pdo->prepare(
            "REPLACE INTO {$this->table}
                (product_id, title, description, normalized_title, normalized_desc,
                 brand, category, model, price, stock, url, image, popularity)
             VALUES
                (:product_id, :title, :description, :normalized_title, :normalized_desc,
                 :brand, :category, :model, :price, :stock, :url, :image, :popularity)"
        );

        $count = 0;
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $description = (string) ($row['description'] ?? '');

            $stmt->execute([
                'product_id'       => (int) ($row['product_id'] ?? 0),
                'title'            => $title,
                'description'      => $description,
                'normalized_title' => ($this->normalize)($title),
                'normalized_desc'  => ($this->normalize)($description),
                'brand'            => (string) ($row['brand'] ?? ''),
                'category'         => (string) ($row['category'] ?? ''),
                'model'            => (string) ($row['model'] ?? ''),
                'price'            => (float) ($row['price'] ?? 0),
                'stock'            => (int) ($row['stock'] ?? 0),
                'url'              => (string) ($row['url'] ?? ''),
                'image'            => (string) ($row['image'] ?? ''),
                'popularity'       => (int) ($row['popularity'] ?? 0),
            ]);
            $count++;
        }

        return $count;
    }
}
