<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Display fields for a result page. Opt-in only (`"with_details": true` on
 * POST /search): the default response stays ids-only, so storefront callers that
 * render from OpenCart are unaffected.
 *
 * Relative `url` / `image` values are joined to the configured storefront
 * bases when those are set; empty bases return them as exported.
 */
final class ProductDetails
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $productsTable,
        private readonly string $storeBase = '',
        private readonly string $imageBase = ''
    ) {
    }

    /**
     * Rows in the SAME order as $ids. Ids missing from the table are skipped.
     *
     * @param list<int> $ids
     * @return list<array{id: int, title: string, url: string, image: string, price: float}>
     */
    public function fetch(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT product_id, title, url, image, price FROM '
             . Identifier::quote($this->productsTable)
             . ' WHERE product_id IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($ids));

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['product_id']] = [
                'id'    => (int) $row['product_id'],
                'title' => (string) $row['title'],
                'url'   => self::resolve((string) $row['url'], $this->storeBase),
                'image' => self::resolve((string) $row['image'], $this->imageBase),
                'price' => (float) $row['price'],
            ];
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    private static function resolve(string $value, string $base): string
    {
        if ($value === '' || $base === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            return $value;
        }

        return rtrim($base, '/') . '/' . ltrim($value, '/');
    }
}
