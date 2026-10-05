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
    /** Width of products.normalized_collapsed. */
    private const COLLAPSED_MAX_CHARS = 700;

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
                 normalized_tags, normalized_brand, normalized_category, normalized_collapsed,
                 brand, category, model, sku, normalized_sku, price, stock, url, image, popularity)
             VALUES
                (:product_id, :title, :description, :normalized_title, :normalized_desc, :normalized_specs,
                 :normalized_tags, :normalized_brand, :normalized_category, :normalized_collapsed,
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
                // M21: cleaned tag names (build.py clean_tags), brand and category.
                'normalized_tags'     => ($this->normalize)((string) ($row['tags'] ?? '')),
                'normalized_brand'    => ($this->normalize)((string) ($row['brand'] ?? '')),
                'normalized_category' => ($this->normalize)((string) ($row['category'] ?? '')),
                // M23: same composition as pipeline/build.py collapsed_identity().
                'normalized_collapsed' => self::collapsedIdentity(
                    $title,
                    (string) ($row['brand'] ?? ''),
                    (string) ($row['tags'] ?? '')
                ),
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

    /**
     * Collapsed title, brand and tags joined by a space, cut to the column's
     * width (db/schema.sql); mirrors build.py collapsed_identity().
     */
    public static function collapsedIdentity(string $title, string $brand, string $tags): string
    {
        $parts = array_filter(
            [Normalizer::collapse($title), Normalizer::collapse($brand), Normalizer::collapse($tags)],
            static fn (string $part): bool => $part !== ''
        );

        return mb_substr(implode(' ', $parts), 0, self::COLLAPSED_MAX_CHARS, 'UTF-8');
    }
}
