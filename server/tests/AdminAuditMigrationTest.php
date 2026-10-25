<?php

declare(strict_types=1);

namespace App\Tests;

/** db/migrations/2026-10-25_admin_audit.sql: idempotent up, a separate down, same shape as schema.sql. */
final class AdminAuditMigrationTest extends DatabaseTestCase
{
    private function file(string $suffix): string
    {
        $path = self::repoRoot() . '/db/migrations/2026-10-25_admin_audit' . $suffix . '.sql';

        return (string) file_get_contents($path);
    }

    private function exists(): bool
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'admin_audit'"
        )->fetchColumn() === 1;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return $this->pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'admin_audit' ORDER BY ordinal_position"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testUpIsIdempotentKeepsRowsAndDownDropsTheTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS admin_audit');

        $this->pdo->exec($this->file(''));
        self::assertTrue($this->exists());
        $this->pdo->exec("INSERT INTO admin_audit (ip, action) VALUES ('1.1.1.1', 'login_ok')");

        $this->pdo->exec($this->file(''));
        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn();
        self::assertSame(1, $rows, 'a rerun keeps data');

        $this->pdo->exec($this->file('.down'));
        self::assertFalse($this->exists());
        $this->pdo->exec($this->file('.down'));
        self::assertFalse($this->exists(), 'down is repeatable too');
    }

    public function testTheMigrationAndSchemaSqlAgreeOnTheTable(): void
    {
        self::assertTrue($this->exists(), 'schema.sql creates it');
        $fromSchema = $this->columns();
        $this->pdo->exec('DROP TABLE admin_audit');
        $this->pdo->exec($this->file(''));

        self::assertSame(['id', 'ts', 'ip', 'action', 'detail'], $fromSchema);
        self::assertSame($fromSchema, $this->columns());
    }

    public function testTheUpFileDoesNotUndoItself(): void
    {
        self::assertStringNotContainsStringIgnoringCase('DROP TABLE', $this->file(''));
    }
}
