<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- SQL and assertion messages

namespace App\Tests;

/** db/migrations/2026-11-03_vps_calls.sql: idempotent up, a separate down, the same table as schema.sql. */
final class VpsCallsMigrationTest extends DatabaseTestCase
{
    private function file(string $suffix): string
    {
        return (string) file_get_contents(self::repoRoot() . '/db/migrations/2026-11-03_vps_calls' . $suffix . '.sql');
    }

    private function exists(): bool
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'vps_calls'"
        )->fetchColumn() === 1;
    }

    /** @return list<string> */
    private function shape(): array
    {
        return $this->pdo->query(
            "SELECT CONCAT(column_name, ' ', column_type, ' ', is_nullable) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'vps_calls' ORDER BY ordinal_position"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function indexes(): array
    {
        return $this->pdo->query(
            "SELECT CONCAT(index_name, ':', GROUP_CONCAT(column_name ORDER BY seq_in_index)) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'vps_calls' GROUP BY index_name ORDER BY index_name"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testUpIsIdempotentKeepsRowsAndDownDropsTheTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS vps_calls');

        $this->pdo->exec($this->file(''));
        self::assertTrue($this->exists());
        $this->pdo->exec("INSERT INTO vps_calls (kind, outcome) VALUES ('search', 'ok')");

        $this->pdo->exec($this->file(''));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM vps_calls')->fetchColumn(), 'a rerun keeps data');

        $this->pdo->exec($this->file('.down'));
        self::assertFalse($this->exists());
        $this->pdo->exec($this->file('.down'));
        self::assertFalse($this->exists(), 'down is repeatable too');
    }

    public function testTheMigrationAndSchemaSqlAgreeOnTheTable(): void
    {
        self::assertTrue($this->exists(), 'schema.sql creates it');
        $columns = $this->shape();
        $indexes = $this->indexes();
        $this->pdo->exec('DROP TABLE vps_calls');
        $this->pdo->exec($this->file(''));

        self::assertSame($columns, $this->shape());
        self::assertSame($indexes, $this->indexes());
        self::assertEqualsCanonicalizing(['PRIMARY:id', 'idx_outcome_ts:outcome,ts', 'idx_ts:ts'], $indexes);
        self::assertContains('ts datetime NO', $columns);
        self::assertNotContains('customer_id', array_map(static fn (string $c): string => explode(' ', $c)[0], $columns));
    }

    public function testTheTimestampDefaultsToUtc(): void
    {
        $this->pdo->exec("INSERT INTO vps_calls (kind, outcome) VALUES ('probe', 'ok')");

        $delta = (int) $this->pdo->query('SELECT ABS(TIMESTAMPDIFF(SECOND, ts, UTC_TIMESTAMP())) FROM vps_calls')->fetchColumn();

        self::assertLessThan(5, $delta);
    }
}
