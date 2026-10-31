<?php

declare(strict_types=1);

namespace App\Tests;

use App\Logger;

/**
 * db/migrations/2026-10-24_search_logs_v2.sql: the idempotent UP file (run whole, as
 * `mysql db < file`), the separate .down file that restores the old shape, and a
 * Logger that upgrades a table nobody migrated.
 */
final class LogMigrationTest extends DatabaseTestCase
{
    private const OLD_COLUMNS = ['id', 'ts', 'raw_q', 'normalized_q', 'had_vector', 'result_count', 'top_ids',
        'customer_id', 'latency_ms', 'did_you_mean', 'tier', 'cache_hit'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS search_logs, search_events');
        $this->pdo->exec(
            "CREATE TABLE search_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                raw_q VARCHAR(512) NOT NULL, normalized_q VARCHAR(512) NOT NULL DEFAULT '',
                had_vector TINYINT(1) NOT NULL DEFAULT 0, result_count INT UNSIGNED NOT NULL DEFAULT 0,
                top_ids VARCHAR(1024) NOT NULL DEFAULT '', customer_id VARCHAR(64) DEFAULT NULL,
                latency_ms INT UNSIGNED NOT NULL DEFAULT 0, did_you_mean VARCHAR(512) DEFAULT NULL,
                tier VARCHAR(16) NOT NULL DEFAULT 'keyword_only', cache_hit TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id), KEY idx_ts (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->pdo->exec("INSERT INTO search_logs (raw_q) VALUES ('old row')");
    }

    private const V2_COLUMNS = ['log_version', 'search_uuid', 'session_id', 'source', 'page', 'expanded_q',
        'alias_hit', 'dym_shown', 'is_capped', 'lat_keyword_ms', 'lat_vector_ms', 'lat_spell_ms',
        'is_suspicious', 'is_duplicate'];
    private const V2_INDEXES = ['idx_session', 'idx_nq', 'idx_uuid'];

    private function file(string $suffix): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/db/migrations/2026-10-24_search_logs_v2' . $suffix . '.sql'
        );
    }

    /** The whole file, exactly what `mysql db < file` would run. */
    private function up(): string
    {
        return $this->file('');
    }

    private function down(): string
    {
        return $this->file('.down');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return array_map('strval', $this->pdo->query('SHOW COLUMNS FROM search_logs')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function indexes(): array
    {
        return array_values(array_unique(array_map(
            'strval',
            $this->pdo->query('SHOW INDEX FROM search_logs')->fetchAll(\PDO::FETCH_COLUMN, 2)
        )));
    }

    public function testTheUpFileHoldsNoRollback(): void
    {
        // Piping the file into mysql must never undo itself.
        self::assertDoesNotMatchRegularExpression('/^\s*(DROP|TRUNCATE)\b/mi', $this->up());
        self::assertDoesNotMatchRegularExpression('/DROP\s+(COLUMN|INDEX)/i', $this->up());
    }

    public function testTheWholeUpFileTwiceLeavesEveryV2ColumnIndexAndTable(): void
    {
        $this->pdo->exec($this->up());
        $this->pdo->exec($this->up());

        self::assertSame([], array_diff(self::V2_COLUMNS, $this->columns()));
        self::assertCount(14, array_intersect(self::V2_COLUMNS, $this->columns()));
        self::assertSame([], array_diff(self::V2_INDEXES, $this->indexes()));
        self::assertEqualsCanonicalizing(['PRIMARY', 'idx_ts', 'idx_session', 'idx_nq', 'idx_uuid'], $this->indexes());
        self::assertSame(
            ['search_events'],
            $this->pdo->query("SHOW TABLES LIKE 'search_events'")->fetchAll(\PDO::FETCH_COLUMN)
        );
        self::assertSame(
            ['search_uuid', 'product_id', 'position', 'action', 'ts'],
            array_slice(array_map(
                'strval',
                $this->pdo->query('SHOW COLUMNS FROM search_events')->fetchAll(\PDO::FETCH_COLUMN)
            ), 1)
        );
        self::assertSame(1, (int) $this->pdo->query('SELECT log_version FROM search_logs')->fetchColumn());
    }

    public function testTheDownFileRestoresTheOldShapeAndCanRunAgain(): void
    {
        $this->pdo->exec($this->up());
        $this->pdo->exec($this->down());
        $this->pdo->exec($this->down());

        self::assertSame(self::OLD_COLUMNS, $this->columns());
        self::assertEqualsCanonicalizing(['PRIMARY', 'idx_ts'], $this->indexes());
        self::assertSame('old row', $this->pdo->query('SELECT raw_q FROM search_logs')->fetchColumn());
        self::assertSame([], $this->pdo->query("SHOW TABLES LIKE 'search_events'")->fetchAll());

        $this->pdo->exec($this->up()); // and back up again
        self::assertContains('log_version', $this->columns());
    }

    public function testTheLoggerUpgradesAnUnmigratedTableOnFirstWrite(): void
    {
        (new Logger($this->pdo, 'search_logs'))->log([
            'raw_q' => 'mafia', 'search_uuid' => '0b6a1d3e-5c52-4f7a-9d77-1c2f3a4b5c6d', 'session_id' => 's',
            'lat_keyword_ms' => 4,
        ]);

        $row = $this->pdo->query("SELECT * FROM search_logs WHERE raw_q = 'mafia'")->fetch();
        self::assertSame(2, (int) $row['log_version']);
        self::assertSame('s', $row['session_id']);
        self::assertSame(4, (int) $row['lat_keyword_ms']);
        self::assertSame(1, (int) $this->pdo->query('SELECT log_version FROM search_logs WHERE raw_q = "old row"')
            ->fetchColumn());
    }

    private function brandUnmatched(string $suffix): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/db/migrations/2026-10-28_search_logs_brand_unmatched' . $suffix . '.sql'
        );
    }

    public function testTheBrandUnmatchedMigrationRunsTwiceAndItsDownFileRemovesTheColumn(): void
    {
        $this->pdo->exec($this->brandUnmatched(''));
        $this->pdo->exec($this->brandUnmatched(''));
        self::assertContains('brand_unmatched', $this->columns());
        self::assertDoesNotMatchRegularExpression('/DROP\s+(COLUMN|INDEX)/i', $this->brandUnmatched(''));

        $this->pdo->exec($this->brandUnmatched('.down'));
        $this->pdo->exec($this->brandUnmatched('.down'));
        self::assertSame(self::OLD_COLUMNS, $this->columns());
        self::assertSame('old row', $this->pdo->query('SELECT raw_q FROM search_logs')->fetchColumn());
    }

    private function isBurst(string $suffix): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/db/migrations/2026-10-31_search_logs_is_burst' . $suffix . '.sql'
        );
    }

    public function testTheIsBurstMigrationRunsTwiceAndItsDownFileRemovesTheColumn(): void
    {
        $this->pdo->exec($this->isBurst(''));
        $this->pdo->exec($this->isBurst(''));
        self::assertContains('is_burst', $this->columns());
        self::assertDoesNotMatchRegularExpression('/DROP\s+(COLUMN|INDEX)/i', $this->isBurst(''));

        $this->pdo->exec($this->isBurst('.down'));
        $this->pdo->exec($this->isBurst('.down'));
        self::assertSame(self::OLD_COLUMNS, $this->columns());
        self::assertSame('old row', $this->pdo->query('SELECT raw_q FROM search_logs')->fetchColumn());
    }

    public function testSchemaSqlMatchesTheMigration(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec((string) file_get_contents(self::repoRoot() . '/db/schema.sql'));
        $fromSchema = $this->columns();
        $schemaIndexes = $this->indexes();

        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec('DROP TABLE search_events');
        $this->setUp();
        $this->pdo->exec($this->up());
        $this->pdo->exec($this->brandUnmatched(''));
        $this->pdo->exec($this->isBurst(''));

        self::assertEqualsCanonicalizing($fromSchema, $this->columns());
        self::assertEqualsCanonicalizing($schemaIndexes, array_merge($this->indexes(), ['idx_ts_result']));
    }
}
