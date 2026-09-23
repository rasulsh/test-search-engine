<?php

declare(strict_types=1);

namespace App\Tests;

use App\ReloadException;
use App\StagingLoader;

/**
 * Statement splitting of products.load.sql: a ";" at a line end only ends a
 * statement outside a string literal, whatever quoting the value uses.
 */
final class StagingLoaderTest extends DatabaseTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products_new');
        $this->file = (string) tempnam(sys_get_temp_dir(), 'load_');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testLiteralsWithSemicolonsQuotesAndBackslashesSurvive(): void
    {
        $values = [
            1 => "ends with a semicolon;\nand continues;",
            2 => "it's quoted ''twice'' \\' escaped;",
            3 => "trailing backslash \\",
            4 => "-- not a comment;\n-- still data",
        ];
        $sql = "-- header comment\nSET NAMES utf8mb4;\n\n"
             . "DROP TABLE IF EXISTS `products_new`;\n"
             . "CREATE TABLE `products_new` (\n    product_id INT NOT NULL,\n    description TEXT NOT NULL\n);\n";
        foreach ($values as $id => $text) {
            $quoted = "'" . str_replace(['\\', "'"], ['\\\\', "''"], $text) . "'";
            $sql .= "REPLACE INTO `products_new`\n    (product_id, description)\nVALUES\n    ({$id}, {$quoted});\n";
        }
        file_put_contents($this->file, $sql);

        $executed = (new StagingLoader($this->pdo, 'products_new'))->load($this->file);

        self::assertSame(3 + count($values), $executed);
        $stored = $this->pdo->query('SELECT product_id, description FROM products_new ORDER BY product_id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame($values, array_combine(array_map('intval', array_keys($stored)), array_values($stored)));
    }

    public function testCrlfLineEndingsAreAccepted(): void
    {
        // build.py run on Windows writes \r\n.
        file_put_contents($this->file, str_replace("\n", "\r\n", (string) file_get_contents(
            self::repoRoot() . '/fixtures/products.load.sample.sql'
        )));

        (new StagingLoader($this->pdo, 'products_new'))->load($this->file);

        self::assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM products_new')->fetchColumn());
    }

    public function testStatementForAnotherTableIsRefusedBeforeRunning(): void
    {
        file_put_contents($this->file, "REPLACE INTO `products` (product_id) VALUES (1);\n");

        try {
            (new StagingLoader($this->pdo, 'products_new'))->load($this->file);
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('unexpected_statement', $e->reason());
            self::assertSame(1, $e->details()['statement']);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }
}
