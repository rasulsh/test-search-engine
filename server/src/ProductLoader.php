<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Loads product rows into the products table, populating the FULLTEXT-indexed
 * normalized_* columns.
 *
 * The normalized_* columns are filled by the canonical Normalizer (contract 1),
 * so programmatic loads match query-time normalization. A different normalizer
 * can be injected for tests. This single write path means the rule can change
 * with no schema change and no change to callers.
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
        $this->normalize = $normalizer ?? static fn (string $text): string => Normalizer::normalize($text);
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
                (product_id, title, description, normalized_title, normalized_desc, normalized_specs,
                 brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
             VALUES
                (:product_id, :title, :description, :normalized_title, :normalized_desc, :normalized_specs,
                 :brand, :category, :model, :sku, :normalized_sku, :price, :stock, :url, :image, :popularity)"
        );

        $count = 0;
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $description = (string) ($row['description'] ?? '');
            $sku = (string) ($row['sku'] ?? '');
            $normalizedSku = Normalizer::normalizeSku($sku);

            $stmt->execute([
                'product_id'       => (int) ($row['product_id'] ?? 0),
                'title'            => $title,
                'description'      => $description,
                // Same composition as pipeline/build.py: the SKU is title-weighted text.
                'normalized_title' => trim(($this->normalize)($title) . ' ' . $normalizedSku),
                'normalized_desc'  => ($this->normalize)($description),
                // Pre-composed spec text (build.py composes it from attributes
                // and feature titles).
                'normalized_specs' => ($this->normalize)((string) ($row['specs'] ?? '')),
                'brand'            => (string) ($row['brand'] ?? ''),
                'category'         => (string) ($row['category'] ?? ''),
                'model'            => (string) ($row['model'] ?? ''),
                'sku'              => $sku,
                'normalized_sku'   => $normalizedSku,
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
