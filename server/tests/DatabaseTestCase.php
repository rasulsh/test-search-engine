<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base for tests that need a real MySQL/MariaDB (FULLTEXT is engine-specific).
 *
 * Connection comes from SEARCH_TEST_DB_DSN / _USER / _PASSWORD. When those are
 * unset or unreachable the tests are skipped locally, but FAIL under CI (where a
 * database service is guaranteed) so a missing service can never hide green.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        $this->resetSchema();
    }

    protected static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function connect(): PDO
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            $this->skipOrFail('SEARCH_TEST_DB_DSN is not set');
        }

        try {
            return new PDO(
                $dsn,
                getenv('SEARCH_TEST_DB_USER') ?: '',
                getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (Throwable $e) {
            $this->skipOrFail('cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function resetSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS products, search_logs');
        $this->pdo->exec($this->readSql('db/schema.sql'));
    }

    protected function loadSampleFixture(): void
    {
        $this->pdo->exec($this->readSql('fixtures/products.sample.sql'));
    }

    private function readSql(string $relative): string
    {
        $contents = file_get_contents(self::repoRoot() . '/' . $relative);
        self::assertIsString($contents, "Unable to read {$relative}");

        return $contents;
    }

    private function skipOrFail(string $reason): never
    {
        if (getenv('CI')) {
            self::fail('A test database is required under CI: ' . $reason);
        }
        self::markTestSkipped($reason);
    }
}
