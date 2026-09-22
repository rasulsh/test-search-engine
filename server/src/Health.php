<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * GET /health logic. Reports database connectivity and the product count so a
 * monitor can tell "up" from "up but empty index".
 */
final class Health
{
    private Db $db;
    private string $productsTable;

    public function __construct(Db $db, string $productsTable)
    {
        $this->db = $db;
        $this->productsTable = $productsTable;
    }

    /**
     * @return array{status: string, checks: array{database: bool, product_count: int|null}}
     */
    public function check(): array
    {
        $databaseUp = $this->db->ping();
        $productCount = null;

        if ($databaseUp) {
            try {
                $table = Identifier::quote($this->productsTable);
                $productCount = (int) $this->db->pdo()
                    ->query("SELECT COUNT(*) FROM {$table}")
                    ->fetchColumn();
            } catch (Throwable) {
                $databaseUp = false;
            }
        }

        return [
            'status' => $databaseUp ? 'ok' : 'degraded',
            'checks' => [
                'database'      => $databaseUp,
                'product_count' => $productCount,
            ],
        ];
    }
}
