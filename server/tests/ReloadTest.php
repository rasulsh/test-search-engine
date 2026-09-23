<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Reload;
use App\ReloadException;
use App\Speller;
use App\Synonyms;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReloadTest extends DatabaseTestCase
{
    private const DIM = 4;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    // --- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $model @return array<string, mixed> */
    private function config(string $dataDir, string $incomingDir, array $model = []): array
    {
        return [
            'paths' => ['data' => $dataDir, 'data_incoming' => $incomingDir],
            'db' => ['products_table' => 'products'],
            'model' => array_merge(
                ['name' => 'intfloat/multilingual-e5-small', 'dim' => self::DIM, 'normalization_version' => 2],
                $model
            ),
        ];
    }

    private function newTempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir() . '/reload_' . uniqid('', true) . $suffix;
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @param array<string, mixed> $metaOverrides */
    private function makeIncoming(int $count, int $dim = self::DIM, array $metaOverrides = []): string
    {
        $dir = $this->newTempDir('_in');
        $floats = array_fill(0, $count * $dim, 0.5);
        $bin = $count === 0 ? '' : pack('g*', ...$floats);
        file_put_contents($dir . '/vectors.bin', $bin);

        $idx = '';
        for ($i = 0; $i < $count; $i++) {
            $idx .= (3000 + $i) . "\n";
        }
        file_put_contents($dir . '/vectors.idx', $idx);

        $meta = array_merge([
            'model' => 'intfloat/multilingual-e5-small',
            'revision' => 'main',
            'dim' => $dim,
            'normalization_version' => 2,
            'count' => $count,
            'built_at' => '2026-01-01T00:00:00+00:00',
            'checksum' => hash('sha256', $bin),
        ], $metaOverrides);
        file_put_contents($dir . '/meta.json', json_encode($meta));

        return $dir;
    }

    private function insertProduct(string $table, int $id): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table} (product_id, title, description, normalized_title, normalized_desc)
             VALUES (:id, :t, :d, :nt, :nd)"
        );
        $stmt->execute(['id' => $id, 't' => "t{$id}", 'd' => "d{$id}", 'nt' => "t{$id}", 'nd' => "d{$id}"]);
    }

    private function createStaging(int $count): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS products_new');
        $this->pdo->exec('CREATE TABLE products_new LIKE products');
        for ($i = 0; $i < $count; $i++) {
            $this->insertProduct('products_new', 3000 + $i);
        }
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // --- tests -------------------------------------------------------------

    public function testHappyPathSwapsTablesAndDirectories(): void
    {
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);

        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/marker.txt', 'old');
        $incomingDir = $this->makeIncoming(3);

        $result = (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['count']);

        // Tables: new data live, previous kept for rollback.
        self::assertSame(3, $this->rowCount('products'));
        self::assertSame(2, $this->rowCount('products_old'));
        self::assertSame([3000, 3001, 3002], array_map('intval', $this->pdo
            ->query('SELECT product_id FROM products ORDER BY product_id')
            ->fetchAll(\PDO::FETCH_COLUMN)));

        // Directories: incoming became data; previous data kept as data_old.
        self::assertFileExists($dataDir . '/meta.json');
        self::assertFileExists($dataDir . '_old/marker.txt');
        self::assertDirectoryDoesNotExist($incomingDir);
        $this->tempDirs[] = $dataDir . '_old';
    }

    public function testReloadServesTheNewSpellcheckDictionary(): void
    {
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/spellcheck.txt', "sony\t4\n");
        $this->tempDirs[] = $dataDir . '_old';

        // Warm the previous bundle's dictionary, as a serving worker would have.
        $path = $dataDir . '/' . Speller::DICTIONARY_FILE;
        self::assertSame('sony', Speller::fromDictionary($path, false)?->suggest('sont'));

        $incomingDir = $this->makeIncoming(2);
        file_put_contents($incomingDir . '/spellcheck.txt', "sent\t4\n");
        (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();

        self::assertSame('sent', Speller::fromDictionary($path, false)?->suggest('sont'));
    }

    public function testReloadServesTheNewAliasFile(): void
    {
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/' . Synonyms::ALIASES_FILE, '[["gta", "grand theft auto"]]');
        $this->tempDirs[] = $dataDir . '_old';
        // Warm the previous bundle's aliases, as a serving worker would have.
        self::assertSame(
            [['gta'], ['grand', 'theft', 'auto']],
            Synonyms::fromDirectory($dataDir, 4)->variants(['gta'], 6)
        );

        // The owner added a Persian form; the rebuilt bundle ships it.
        $incomingDir = $this->makeIncoming(2);
        file_put_contents(
            $incomingDir . '/' . Synonyms::ALIASES_FILE,
            '[["gta", "grand theft auto", "جی تی ای"], ["ps5", "پلی استیشن 5"]]'
        );
        (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();

        $synonyms = Synonyms::fromDirectory($dataDir, 4);
        self::assertSame(
            [['gta'], ['grand', 'theft', 'auto'], ['جی', 'تی', 'ای']],
            $synonyms->variants(['gta'], 6)
        );
        self::assertSame([['ps5'], ['پلی', 'استیشن', '5']], $synonyms->variants(['ps5'], 6));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedGroupFiles(): iterable
    {
        yield 'alias JSON syntax error' => [
            Synonyms::ALIASES_FILE, '[["gta", "grand theft auto"],]', 'invalid_aliases',
        ];
        yield 'alias group not a list' => [Synonyms::ALIASES_FILE, '{"gta": "grand theft auto"}', 'invalid_aliases'];
        yield 'synonym term not a string' => [Synonyms::SYNONYMS_FILE, '[["laptop", 5]]', 'invalid_synonyms'];
    }

    #[DataProvider('malformedGroupFiles')]
    public function testMalformedAliasOrSynonymFileRejectedWithoutSwap(string $file, string $json, string $reason): void
    {
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3);
        file_put_contents($incomingDir . '/' . $file, $json);

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame($reason, $e->reason());
            self::assertSame(['file' => $file], $e->details());
        }

        self::assertSame(2, $this->rowCount('products'));
        self::assertSame(3, $this->rowCount('products_new'));
        self::assertDirectoryExists($incomingDir);
    }

    public function testFirstLoadWithoutExistingProducts(): void
    {
        $this->createStaging(2);              // clone schema while products exists
        $this->pdo->exec('DROP TABLE IF EXISTS products');
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(2);

        $result = (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();

        self::assertSame(2, $result['count']);
        self::assertSame(2, $this->rowCount('products'));
    }

    public function testDirectorySwapFailureRollsBackTables(): void
    {
        // Validated bundle: the table swap succeeds, then the directory swap
        // fails (its target's parent does not exist). The tables must roll back
        // so they never disagree with the untouched directory.
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);

        // A data dir whose parent is missing makes rename() into place fail.
        $missingParent = sys_get_temp_dir() . '/reload_missing_' . uniqid('', true);
        $dataDir = $missingParent . '/data';
        $incomingDir = $this->makeIncoming(3);

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('directory_swap_failed', $e->reason());
        }

        // All-old: the previous rows are live again, staging is restored, and no
        // half-committed backup table is left behind.
        self::assertSame(2, $this->rowCount('products'));
        self::assertSame([1, 2], array_map('intval', $this->pdo
            ->query('SELECT product_id FROM products ORDER BY product_id')
            ->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(3, $this->rowCount('products_new'));
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'products_old'"
        )->fetchColumn());
        self::assertDirectoryExists($incomingDir); // staged bundle untouched
    }

    public function testModelMismatchRejectedWithoutSwap(): void
    {
        $this->insertProduct('products', 1);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3, self::DIM, ['model' => 'other/model']);

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('model_mismatch', $e->reason());
        }

        // No swap: products untouched, staging intact, incoming still present.
        self::assertSame(1, $this->rowCount('products'));
        self::assertSame(3, $this->rowCount('products_new'));
        self::assertDirectoryExists($incomingDir);
    }

    public function testDimMismatchRejected(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, self::DIM, ['dim' => 8]);
        $this->expectReloadReason($incoming, 'dim_mismatch');
    }

    public function testNormalizationVersionMismatchRejected(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, self::DIM, ['normalization_version' => 1]);
        $this->expectReloadReason($incoming, 'normalization_version_mismatch');
    }

    public function testCountMismatchRejected(): void
    {
        $this->createStaging(3);              // staging has 3 rows
        $incoming = $this->makeIncoming(2);   // meta.count and idx say 2
        $this->expectReloadReason($incoming, 'count_mismatch');
    }

    public function testChecksumMismatchRejected(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, self::DIM, ['checksum' => 'deadbeef']);
        $this->expectReloadReason($incoming, 'checksum_mismatch');
    }

    // --- one-command release: load staging from products.load.sql (M12) ------

    private const LOAD_SAMPLE_IDS = [2001, 2002, 2003, 2004, 2005, 2006];

    /** Incoming bundle for fixtures/products.load.sample.sql (6 products). */
    private function makeIncomingWithLoadFile(?string $loadSql = null): string
    {
        $dir = $this->makeIncoming(count(self::LOAD_SAMPLE_IDS));
        $sample = (string) file_get_contents(self::repoRoot() . '/fixtures/products.load.sample.sql');
        file_put_contents($dir . '/products.load.sql', $loadSql ?? $sample);

        return $dir;
    }

    private function sampleLoadSql(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/fixtures/products.load.sample.sql');
    }

    private function stagingExists(): bool
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'products_new'"
        )->fetchColumn() > 0;
    }

    /** Live table and directory are exactly as before a rejected reload. */
    private function assertNothingSwapped(string $dataDir, string $incomingDir): void
    {
        self::assertSame([1, 2], array_map('intval', $this->pdo
            ->query('SELECT product_id FROM products ORDER BY product_id')
            ->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'products_old'"
        )->fetchColumn());
        self::assertFileExists($dataDir . '/marker.txt');
        self::assertDirectoryDoesNotExist($dataDir . '_old');
        self::assertDirectoryExists($incomingDir);
    }

    /** @return array{0: string, 1: string} data dir (with a marker) and a failing reason */
    private function runLoadExpectingFailure(string $incomingDir): array
    {
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/marker.txt', 'old');
        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run(true);
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            return [$dataDir, $e->reason()];
        }
    }

    public function testLoadStagingFromBundleThenSwap(): void
    {
        // Live table from before the sku (M12) and specs (M13) columns existed:
        // the load file recreates staging from the current schema, so the swap
        // brings the new columns and FULLTEXT index.
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->pdo->exec(
            'ALTER TABLE products DROP INDEX idx_normalized_sku, DROP COLUMN normalized_sku, DROP COLUMN sku'
        );
        $this->pdo->exec('ALTER TABLE products DROP INDEX ft_normalized, DROP COLUMN normalized_specs');
        $this->pdo->exec('ALTER TABLE products ADD FULLTEXT KEY ft_normalized (normalized_title, normalized_desc)');
        $this->pdo->exec('CREATE TABLE products_new (stale INT)'); // leftover from an earlier attempt
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/marker.txt', 'old');
        $this->tempDirs[] = $dataDir . '_old';
        $incomingDir = $this->makeIncomingWithLoadFile();

        $result = (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run(true);

        self::assertSame(count(self::LOAD_SAMPLE_IDS), $result['count']);
        self::assertSame(self::LOAD_SAMPLE_IDS, array_map('intval', $this->pdo
            ->query('SELECT product_id FROM products ORDER BY product_id')
            ->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(2, $this->rowCount('products_old'));
        self::assertFileExists($dataDir . '/products.load.sql');
        self::assertFileExists($dataDir . '_old/marker.txt');
        // Escaped text survived the statement splitter intact.
        self::assertSame("world's best noise cancelling, over-ear", $this->pdo
            ->query('SELECT description FROM products WHERE product_id = 2002')->fetchColumn());
        // The swapped-in table serves SKU search.
        $top = (new Keyword($this->pdo, 'products'))->search('apl ip15p 128')[0];
        self::assertSame([2001, Keyword::MATCH_SKU], [$top['product_id'], $top['match_type']]);
        // ...and specs search: the feature title outranks a description mention.
        $hits = (new Keyword($this->pdo, 'products'))->search('540 هرتز');
        self::assertSame([2005, 2006], array_column($hits, 'product_id'));
        self::assertTrue($hits[0]['spec_match']);
    }

    public function testFailedStagingStatementDoesNotSwap(): void
    {
        // The second REPLACE names a column that does not exist: the load stops
        // there, with the first statement's rows already in staging.
        $sql = $this->sampleLoadSql();
        $second = strpos($sql, 'REPLACE INTO', strpos($sql, 'REPLACE INTO') + 1);
        self::assertIsInt($second);
        $sql = substr($sql, 0, $second) . str_replace('(product_id,', '(no_such_column,', substr($sql, $second));
        $incomingDir = $this->makeIncomingWithLoadFile($sql);

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('staging_load_failed', $reason);
        $this->assertNothingSwapped($dataDir, $incomingDir);
        self::assertGreaterThan(0, $this->rowCount('products_new')); // partial, never swapped
    }

    public function testTruncatedLoadFileDoesNotSwap(): void
    {
        // Cut mid-statement (an interrupted upload).
        $sql = $this->sampleLoadSql();
        $incomingDir = $this->makeIncomingWithLoadFile(substr($sql, 0, (int) (strlen($sql) * 0.8)));

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('staging_load_failed', $reason);
        $this->assertNothingSwapped($dataDir, $incomingDir);
    }

    public function testLoadMissingItsLastStatementIsCaughtByTheCountCheck(): void
    {
        // Cut exactly at a statement boundary: every statement succeeds, but
        // staging holds fewer rows than meta.count, so nothing is swapped.
        $sql = $this->sampleLoadSql();
        $incomingDir = $this->makeIncomingWithLoadFile(substr($sql, 0, (int) strrpos($sql, 'REPLACE INTO')));

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('count_mismatch', $reason);
        $this->assertNothingSwapped($dataDir, $incomingDir);
    }

    public function testStatementOutsideTheStagingTableIsRefused(): void
    {
        $incomingDir = $this->makeIncomingWithLoadFile("SET NAMES utf8mb4;\nDROP TABLE products;\n");

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('unexpected_statement', $reason);
        $this->assertNothingSwapped($dataDir, $incomingDir);
    }

    public function testMissingLoadFileIsRejected(): void
    {
        $incomingDir = $this->makeIncoming(4); // no products.load.sql

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('missing_load_sql', $reason);
        $this->assertNothingSwapped($dataDir, $incomingDir);
    }

    public function testIncompatibleBundleIsRejectedBeforeLoading(): void
    {
        $incomingDir = $this->makeIncomingWithLoadFile();
        file_put_contents($incomingDir . '/meta.json', (string) json_encode(array_merge(
            json_decode((string) file_get_contents($incomingDir . '/meta.json'), true),
            ['model' => 'other/model']
        )));

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('model_mismatch', $reason);
        self::assertFalse($this->stagingExists()); // the load never started
        $this->assertNothingSwapped($dataDir, $incomingDir);
    }

    private function expectReloadReason(string $incomingDir, string $reason): void
    {
        $dataDir = $this->newTempDir('_data');
        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail("expected ReloadException {$reason}");
        } catch (ReloadException $e) {
            self::assertSame($reason, $e->reason());
        }
        self::assertDirectoryExists($incomingDir);  // no swap happened
    }
}
