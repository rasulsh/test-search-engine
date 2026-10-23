<?php

declare(strict_types=1);

namespace App\Tests;

use App\LogsExport;

/** M36: the export class directly and tools/logs-export.php end to end (real CLI process). */
final class LogsExportTest extends DatabaseTestCase
{
    private LogsExport $export;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->export = new LogsExport($this->pdo, 'search_logs');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, mixed> $row */
    private function log(string $raw, int $hoursAgo, array $row = []): void
    {
        $row += ['normalized_q' => mb_strtolower($raw), 'result_count' => 3, 'customer_id' => null];
        $this->pdo->prepare(
            'INSERT INTO search_logs (ts, raw_q, normalized_q, result_count, customer_id, top_ids)'
            . ' VALUES (NOW() - INTERVAL :h HOUR, :raw, :norm, :res, :cust, :top)'
        )->execute([
            'h' => $hoursAgo, 'raw' => $raw, 'norm' => $row['normalized_q'], 'res' => $row['result_count'],
            'cust' => $row['customer_id'], 'top' => '1,2',
        ]);
    }

    private function seed(): void
    {
        $this->log('far cry', 1);
        $this->log('Far Cry', 2);
        $this->log('far cry', 3);
        $this->log('zzzz', 50, ['result_count' => 0]);
        $this->log('zzzz', 60, ['result_count' => 0]);
        $this->log('رزیدنت اویل', 5, ['result_count' => 0]);
        $this->log('رزیدنت اویل', 6, ['result_count' => 0]);
        $this->log('رزیدنت اویل', 240, ['result_count' => 0]); // outside 7d
        $this->log('mouse', 30, ['customer_id' => 'c-77']);
    }

    private function since(string $window): ?string
    {
        return (new \App\Analytics($this->pdo, 'search_logs'))->since($window);
    }

    /** @return list<list<mixed>> */
    private function rows(?string $since, ?string $until, bool $zero, int $limit, bool $grouped): array
    {
        return array_map('array_values', iterator_to_array(
            $this->export->rows($since, $until, $zero, $limit, $grouped),
            false
        ));
    }

    public function testZeroOnlyGroupedWithinTheWindowCountsAndOrders(): void
    {
        $this->seed();

        $rows = $this->rows($this->since('7d'), null, true, 0, true);

        // Both have 2 searches; the Persian one was seen more recently. The 10-day-old row is out.
        self::assertSame(['رزیدنت اویل', 2], [$rows[0][0], $rows[0][1]]);
        self::assertSame(['zzzz', 2], [$rows[1][0], $rows[1][1]]);
        self::assertCount(2, $rows);
        self::assertSame(LogsExport::GROUPED_COLUMNS, $this->export->header(true));
    }

    public function testGroupedMergesSpellingVariantsAndKeepsLastSeen(): void
    {
        $this->seed();
        $this->log('RAW ONLY', 4, ['normalized_q' => '']);

        $rows = $this->rows($this->since('24h'), null, false, 0, true);

        self::assertSame(['far cry', 3], [$rows[0][0], $rows[0][1]]);
        $latest = (string) $this->pdo->query("SELECT MAX(ts) FROM search_logs WHERE normalized_q = 'far cry'")
            ->fetchColumn();
        self::assertSame($latest, $rows[0][2], 'last_seen is the newest of the group');
        self::assertContains('RAW ONLY', array_column($rows, 0), 'an empty normalized form falls back to the raw text');
    }

    public function testWindowsBoundTheRawRows(): void
    {
        $this->seed();

        self::assertCount(5, $this->rows($this->since('24h'), null, false, 0, false));
        self::assertCount(8, $this->rows($this->since('7d'), null, false, 0, false));
        self::assertCount(9, $this->rows(null, null, false, 0, false));
        self::assertCount(5, $this->rows(null, null, true, 0, false), 'zero-only over all time');
    }

    public function testSinceIsInclusiveAndUntilExclusive(): void
    {
        foreach (['10:00:00', '10:01:00', '10:02:00'] as $i => $time) {
            $this->pdo->prepare('INSERT INTO search_logs (ts, raw_q, normalized_q) VALUES (:t, :q, :n)')
                ->execute(['t' => '2026-01-01 ' . $time, 'q' => 'q' . $i, 'n' => 'q' . $i]);
        }

        $middle = $this->rows('2026-01-01 10:01:00', '2026-01-01 10:02:00', false, 0, false);
        $fromMiddle = $this->rows('2026-01-01 10:01:00', null, false, 0, false);

        self::assertSame(['q1'], array_column($middle, 1));
        self::assertSame(['q2', 'q1'], array_column($fromMiddle, 1), 'raw rows are newest first');
    }

    public function testLimitAndPagingReturnExactlyTheNewestRows(): void
    {
        $values = [];
        for ($i = 1; $i <= 4500; $i++) {
            $values[] = "('q{$i}', 'q{$i}')";
        }
        $this->pdo->exec('INSERT INTO search_logs (raw_q, normalized_q) VALUES ' . implode(',', $values));

        $all = $this->rows(null, null, false, 0, false);
        $limited = $this->rows(null, null, false, 2500, false);

        self::assertCount(4500, $all);
        self::assertCount(4500, array_unique(array_column($all, 1)), 'no row twice across pages');
        self::assertCount(2500, $limited);
        self::assertSame(['q4500', 'q4499'], array_slice(array_column($limited, 1), 0, 2));
        self::assertCount(3, $this->rows(null, null, false, 3, false));
        self::assertCount(2, $this->rows(null, null, false, 2, true));
    }

    public function testCustomerIdAndTopIdsAreNeverExported(): void
    {
        $this->seed();

        $header = $this->export->header(false);

        self::assertSame(
            ['ts', 'raw_q', 'normalized_q', 'had_vector', 'result_count', 'latency_ms', 'did_you_mean', 'tier',
                'cache_hit'],
            $header
        );
        self::assertStringNotContainsString('c-77', json_encode($this->rows(null, null, false, 0, false)));
    }

    public function testATableWithoutTheLaterColumnsStillExports(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec(
            'CREATE TABLE search_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts DATETIME NOT NULL'
            . ' DEFAULT CURRENT_TIMESTAMP, raw_q VARCHAR(512) NOT NULL, normalized_q VARCHAR(512) NOT NULL'
            . " DEFAULT '', had_vector TINYINT(1) NOT NULL DEFAULT 0, result_count INT UNSIGNED NOT NULL"
            . " DEFAULT 0, top_ids VARCHAR(1024) NOT NULL DEFAULT '', customer_id VARCHAR(64) DEFAULT NULL,"
            . ' latency_ms INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id))'
        );
        $this->pdo->exec("INSERT INTO search_logs (raw_q, normalized_q) VALUES ('a', 'a')");
        $export = new LogsExport($this->pdo, 'search_logs');

        self::assertSame(
            ['ts', 'raw_q', 'normalized_q', 'had_vector', 'result_count', 'latency_ms'],
            $export->header(false)
        );
        self::assertCount(1, iterator_to_array($export->rows(null, null, false, 0, false), false));
    }

    public function testParseTime(): void
    {
        self::assertSame('2026-03-01 00:00:00', LogsExport::parseTime('2026-03-01'));
        self::assertSame('2026-03-01 14:30:05', LogsExport::parseTime('2026-03-01 14:30:05'));
        $bad = ['', 'yesterday', '2026-13-01', '2026-02-30', '2026-03-01 25:00:00', '03/01/2026', '2026-03-01x'];
        foreach ($bad as $text) {
            self::assertNull(LogsExport::parseTime($text), $text);
        }
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string} exit status, stdout, stderr
     */
    private function cli(array $args): array
    {
        $config = TempConfig::write(['db' => [
            'dsn' => (string) getenv('SEARCH_TEST_DB_DSN'),
            'user' => getenv('SEARCH_TEST_DB_USER') ?: '',
            'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
        ]]);
        $env = array_filter(
            getenv(),
            static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'),
            ARRAY_FILTER_USE_KEY
        );
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/logs-export.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['SEARCH_CONFIG_FILE' => $config]
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function outFile(string $extension = 'csv'): string
    {
        $path = sys_get_temp_dir() . '/logs_export_' . uniqid('', true) . '.' . $extension;
        $this->files[] = $path;

        return $path;
    }

    /** @return list<list<string|null>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    public function testCliWritesAGroupedZeroOnlyCsvTheAliasLoopCanRead(): void
    {
        $this->seed();
        $out = $this->outFile();

        [$status, $stdout, $stderr] = $this->cli(
            ['--zero-only', '--grouped', '--window', '30d', '--out', $out]
        );

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('wrote 2 distinct queries', $stdout);
        $csv = $this->readCsv($out);
        self::assertSame(['normalized_q', 'searches', 'last_seen'], $csv[0]);
        self::assertSame(['رزیدنت اویل', '3'], [$csv[1][0], $csv[1][1]]);
        self::assertSame(['zzzz', '2'], [$csv[2][0], $csv[2][1]]);
        self::assertFalse(str_starts_with((string) file_get_contents($out), "\xEF\xBB\xBF"), 'no BOM');
    }

    public function testCsvRoundTripsPersianCommasQuotesAndNewlinesAsUtf8(): void
    {
        $tricky = "پی اس فایو, \"ولورینگ\"\nدوم";
        $this->log($tricky, 1, ['normalized_q' => 'q']);
        $out = $this->outFile();

        [$status, , $stderr] = $this->cli(['--out', $out]);

        self::assertSame(0, $status, $stderr);
        $csv = $this->readCsv($out);
        self::assertSame($tricky, $csv[1][1]);
        self::assertSame(1, preg_match('//u', (string) file_get_contents($out)), 'valid UTF-8 throughout');
    }

    public function testCliJsonFormatAndLimit(): void
    {
        $this->seed();
        $out = $this->outFile('json');

        [$status, , $stderr] = $this->cli(['--format', 'json', '--limit', '2', '--out', $out]);

        self::assertSame(0, $status, $stderr);
        $rows = json_decode((string) file_get_contents($out), true);
        self::assertCount(2, $rows);
        self::assertSame('mouse', $rows[0]['raw_q'], 'newest (last inserted) first');
        self::assertArrayNotHasKey('customer_id', $rows[0]);
        self::assertSame([], json_decode($this->emptyJson(), true));
    }

    private function emptyJson(): string
    {
        $this->pdo->exec('DELETE FROM search_logs');
        $out = $this->outFile('json');
        [$status] = $this->cli(['--format', 'json', '--out', $out]);
        self::assertSame(0, $status);

        return (string) file_get_contents($out);
    }

    public function testCliSinceUntilWindow(): void
    {
        foreach (['10:00:00', '10:01:00', '10:02:00'] as $i => $time) {
            $this->pdo->prepare('INSERT INTO search_logs (ts, raw_q, normalized_q) VALUES (:t, :q, :n)')
                ->execute(['t' => '2026-01-01 ' . $time, 'q' => 'q' . $i, 'n' => 'q' . $i]);
        }
        $out = $this->outFile();

        [$status, , $stderr] = $this->cli(
            ['--since', '2026-01-01 10:01:00', '--until', '2026-01-01 10:02:00', '--out', $out]
        );

        self::assertSame(0, $status, $stderr);
        self::assertSame(['q1'], array_column(array_slice($this->readCsv($out), 1), 1));
    }

    public function testCliRefusesToWriteUnderThePublicWebRoot(): void
    {
        $this->seed();
        $inside = dirname(__DIR__) . '/public/export-test.csv';
        $dotted = dirname(__DIR__) . '/public/../public/export-test.csv';

        foreach ([$inside, $dotted] as $path) {
            [$status, , $stderr] = $this->cli(['--out', $path]);

            self::assertSame(2, $status);
            self::assertStringContainsString('web root', $stderr);
            self::assertFileDoesNotExist($inside);
        }
    }

    public function testCliUsageErrors(): void
    {
        $out = $this->outFile();
        $cases = [
            'no --out' => [[], '--out'],
            'bad window' => [['--window', 'yesterday', '--out', $out], '--window'],
            'window and since' => [['--window', '7d', '--since', '2026-01-01', '--out', $out], 'not both'],
            'bad since' => [['--since', 'last week', '--out', $out], '--since'],
            'bad limit' => [['--limit', '-3', '--out', $out], '--limit'],
            'bad format' => [['--format', 'xml', '--out', $out], '--format'],
            'missing directory' => [['--out', '/nonexistent-dir-xyz/out.csv'], 'does not exist'],
        ];

        foreach ($cases as $name => [$args, $message]) {
            [$status, , $stderr] = $this->cli($args);

            self::assertSame(2, $status, $name);
            self::assertStringContainsString($message, $stderr, $name);
        }
        self::assertFileDoesNotExist($out);
    }

    public function testTheToolIsCliOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/tools/logs-export.php');

        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        self::assertDirectoryDoesNotExist(dirname(__DIR__) . '/public/tools');
    }
}
