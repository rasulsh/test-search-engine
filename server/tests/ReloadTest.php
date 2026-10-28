<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
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
        return Config::merge([
            'paths' => ['data' => $dataDir, 'data_incoming' => $incomingDir],
            'model' => array_merge(
                ['normalization_version' => 2],
                $model
            ),
        ]);
    }

    private function newTempDir(string $suffix): string
    {
        $dir = TempTree::path('reload') . $suffix;
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * The bundle shape since M42 (meta.json and the data files, no vectors); $legacy adds the pre-M42
     * vectors.bin / vectors.idx and the meta fields that described them.
     *
     * @param array<string, mixed> $metaOverrides
     */
    private function makeIncoming(int $count, array $metaOverrides = [], bool $legacy = false): string
    {
        $dir = $this->newTempDir('_in');
        $meta = ['normalization_version' => 2, 'count' => $count, 'built_at' => '2026-01-01T00:00:00+00:00'];
        if ($legacy) {
            $bin = $count === 0 ? '' : pack('g*', ...array_fill(0, $count * self::DIM, 0.5));
            file_put_contents($dir . '/vectors.bin', $bin);
            $idx = '';
            for ($i = 0; $i < $count; $i++) {
                $idx .= (3000 + $i) . "\n";
            }
            file_put_contents($dir . '/vectors.idx', $idx);
            $meta += [
                'model' => 'intfloat/multilingual-e5-small', 'revision' => 'main', 'dim' => self::DIM,
                'checksum' => hash('sha256', $bin),
            ];
        }
        file_put_contents($dir . '/meta.json', json_encode(array_merge($meta, $metaOverrides)));

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
        TempTree::remove($dir);
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

    public function testSuccessfulSwapFlushesTheResultCache(): void
    {
        $redis = new FakeRedis();
        $cache = new \App\Cache($redis, 'search:cache:', 60);
        $cache->set('search:cache:a', ['a' => 1]);
        $redis->data['other:x'] = 'keep';
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';

        $result = (new Reload($this->pdo, $this->config($dataDir, $this->makeIncoming(2)), $cache))->run();

        self::assertTrue($result['ok']);
        self::assertTrue($result['cache_flushed']);
        self::assertSame(['other:x' => 'keep'], $redis->data);
    }

    public function testSuccessfulSwapAlsoClearsTheFrequencyCounters(): void
    {
        $redis = new FakeRedis();
        $cache = new \App\Cache($redis, 'search:cache:', 60, 600, 5, 300);
        $cache->registerLookup('search:cache:a');
        $cache->set('search:cache:a', ['a' => 1]);
        self::assertArrayHasKey('search:cache:freq:a', $redis->data);
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';

        (new Reload($this->pdo, $this->config($dataDir, $this->makeIncoming(2)), $cache))->run();

        self::assertSame([], $redis->data);
    }

    public function testRejectedBundleLeavesTheResultCacheAlone(): void
    {
        $redis = new FakeRedis();
        $cache = new \App\Cache($redis, 'search:cache:', 60);
        $cache->set('search:cache:a', ['a' => 1]);
        $this->createStaging(2);
        $incomingDir = $this->makeIncoming(2, ['normalization_version' => 9]);

        try {
            (new Reload($this->pdo, $this->config($this->newTempDir('_data'), $incomingDir), $cache))->run();
            self::fail('expected a ReloadException');
        } catch (ReloadException) {
            self::assertCount(1, $redis->data);
        }
    }

    public function testRedisDownDuringReloadDoesNotFailTheSwap(): void
    {
        $redis = new FakeRedis();
        $redis->down = true;
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';
        $previous = ini_set('error_log', '/dev/null');

        $result = (new Reload(
            $this->pdo,
            $this->config($dataDir, $this->makeIncoming(2)),
            new \App\Cache($redis, 'search:cache:', 60)
        ))->run();
        ini_set('error_log', (string) $previous);

        self::assertTrue($result['ok']);
        self::assertFalse($result['cache_flushed']);
        self::assertSame(2, $this->rowCount('products'));
    }

    public function testReloadWithoutACacheReportsNoCacheField(): void
    {
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';

        $result = (new Reload($this->pdo, $this->config($dataDir, $this->makeIncoming(2))))->run();

        self::assertArrayNotHasKey('cache_flushed', $result);
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

    public function testReloadIgnoresABundledAliasFileAndNeverTouchesStorage(): void
    {
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $storage = $this->newTempDir('_storage');
        $this->tempDirs[] = $dataDir . '_old';
        $aliasFile = $storage . '/' . Synonyms::ALIASES_FILE;
        file_put_contents($aliasFile, '[["gta", "grand theft auto"]]');
        $before = (string) file_get_contents($aliasFile);
        $variants = static fn () => Synonyms::fromDirectory($dataDir, 4, $aliasFile)->variants(['gta'], 6);
        self::assertSame([['gta'], ['grand', 'theft', 'auto']], $variants());

        // A malformed bundled file would have failed the reload before M41; now nobody reads it.
        $incomingDir = $this->makeIncoming(2);
        file_put_contents($incomingDir . '/' . Synonyms::ALIASES_FILE, '[["ps5", "پلی استیشن 5"],]');
        (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();

        self::assertSame($before, (string) file_get_contents($aliasFile), 'storage is never written by a reload');
        self::assertSame([['gta'], ['grand', 'theft', 'auto']], $variants());
        self::assertSame([['ps5']], Synonyms::fromDirectory($dataDir, 4, $aliasFile)->variants(['ps5'], 6));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedGroupFiles(): iterable
    {
        yield 'intent map entry not an object' => [\App\IntentMap::FILE, '{"a": ["x"]}', 'invalid_intent_map'];
        yield 'intent map unknown field' => [\App\IntentMap::FILE, '{"a": {"colour": ["x"]}}', 'invalid_intent_map'];
        yield 'token df wrong version' => [\App\TokenStats::FILE, '{"version": 9, "n": 3, "df": {}}', 'invalid_token_df'];
        yield 'token df count not a number' => [\App\TokenStats::FILE, '{"version": 1, "n": 3, "df": {"a": "x"}}', 'invalid_token_df'];
        yield 'entities brand without a count' => [\App\QueryEntities::FILE, '{"version": 1, "brands": [{"name": "a"}], "platforms": {}}', 'invalid_entities'];
        yield 'entities platform not a list' => [\App\QueryEntities::FILE, '{"version": 1, "brands": [], "platforms": {"ps4": "x"}}', 'invalid_entities'];
        yield 'synonym term not a string' => [Synonyms::SYNONYMS_FILE, '[["laptop", 5]]', 'invalid_synonyms'];
    }

    #[DataProvider('malformedGroupFiles')]
    public function testMalformedGroupFileIsRejectedWithoutSwap(string $file, string $json, string $reason): void
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

    public function testASymlinkedBackupDirectoryIsRefusedAndItsTargetSurvives(): void
    {
        // `<data>_old` is the only directory a reload deletes. A symlink there must neither be
        // followed (it would empty the link's target) nor deleted through: the swap fails, tables roll back.
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3);
        $outside = $this->newTempDir('_outside');
        file_put_contents($outside . '/keep.txt', 'precious');
        symlink($outside, $dataDir . '_old');

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('directory_swap_failed', $e->reason());
            self::assertStringContainsString('symlink', (string) json_encode($e->details()));
        } finally {
            unlink($dataDir . '_old'); // the link only; the guard is what protects the target
        }

        self::assertFileExists($outside . '/keep.txt');
        self::assertSame(2, $this->rowCount('products'), 'tables rolled back with the refused swap');
        self::assertDirectoryExists($incomingDir);
    }

    public function testDirectorySwapFailureRollsBackTables(): void
    {
        // Validated bundle: the table swap succeeds, then the directory swap
        // fails (a file sits where the data directory must go). The tables must
        // roll back so they never disagree with the untouched directory.
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);

        // rename() of a directory onto an existing file fails.
        $dataDir = TempTree::path('reload_datafile');
        file_put_contents($dataDir, 'not a directory');
        $incomingDir = $this->makeIncoming(3);

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('directory_swap_failed', $e->reason());
        } finally {
            @unlink($dataDir);
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

    public function testIncompatibleBundleRejectedWithoutSwap(): void
    {
        $this->insertProduct('products', 1);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3, ['normalization_version' => 9]);

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
            self::fail('expected ReloadException');
        } catch (ReloadException $e) {
            self::assertSame('normalization_version_mismatch', $e->reason());
        }

        // No swap: products untouched, staging intact, incoming still present.
        self::assertSame(1, $this->rowCount('products'));
        self::assertSame(3, $this->rowCount('products_new'));
        self::assertDirectoryExists($incomingDir);
    }

    public function testAnOldBundleVectorsSizeMustMatchItsOwnDim(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, ['dim' => 8], true); // vectors.bin is 3 x 4 floats
        $this->expectReloadReason($incoming, 'vectors_size_mismatch');
    }

    public function testNoModelOrDimIsCheckedAnyMore(): void
    {
        $this->createStaging(2);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';
        $incoming = $this->makeIncoming(2, ['model' => 'any/model', 'dim' => 12345, 'revision' => 'x']);

        self::assertSame(2, (new Reload($this->pdo, $this->config($dataDir, $incoming)))->run()['count']);
    }

    public function testNormalizationVersionMismatchRejected(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, ['normalization_version' => 1]);
        $this->expectReloadReason($incoming, 'normalization_version_mismatch');
    }

    public function testCountMismatchRejected(): void
    {
        $this->createStaging(3);              // staging has 3 rows
        $incoming = $this->makeIncoming(2);   // meta.count says 2
        $this->expectReloadReason($incoming, 'count_mismatch');
    }

    public function testAnOldBundleIsStillAcceptedWithItsVectorsAndCheckedOnThem(): void
    {
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $this->tempDirs[] = $dataDir . '_old';
        $incoming = $this->makeIncoming(3, [], true);

        self::assertSame(3, (new Reload($this->pdo, $this->config($dataDir, $incoming)))->run()['count']);
    }

    public function testAnOldBundlesIndexMustAgreeWithMetaCount(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, [], true);
        file_put_contents($incoming . '/vectors.idx', "3000\n3001\n"); // 2 lines, meta + staging say 3
        $this->expectReloadReason($incoming, 'count_mismatch');
    }

    public function testAnOldBundleMissingOneOfItsVectorFilesIsRefused(): void
    {
        $this->createStaging(3);
        $noBin = $this->makeIncoming(3, [], true);
        unlink($noBin . '/vectors.bin');
        $this->expectReloadReason($noBin, 'missing_vectors');

        $noIdx = $this->makeIncoming(3, [], true);
        unlink($noIdx . '/vectors.idx');
        $this->expectReloadReason($noIdx, 'missing_index');
    }

    public function testChecksumMismatchRejected(): void
    {
        $this->createStaging(3);
        $incoming = $this->makeIncoming(3, ['checksum' => 'deadbeef'], true);
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
            ['normalization_version' => 9]
        )));

        [$dataDir, $reason] = $this->runLoadExpectingFailure($incomingDir);

        self::assertSame('normalization_version_mismatch', $reason);
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

    // --- validate / rollback / lock / describe (M40) ---------------------------

    private function lockHolder(string $dataDir): mixed
    {
        $handle = fopen(dirname($dataDir) . '/reload.lock', 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        return $handle;
    }

    public function testValidateChecksEverythingAndChangesNothing(): void
    {
        $this->insertProduct('products', 1);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3);

        $result = (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->validate();

        self::assertSame(['ok' => true, 'count' => 3, 'staging' => 'loaded'], $result);
        self::assertSame(1, $this->rowCount('products'), 'the live table is untouched');
        self::assertSame(3, $this->rowCount('products_new'));
        self::assertDirectoryExists($incomingDir);
        self::assertFalse($this->tableExists('products_old'));
    }

    public function testValidateRefusesWhatRunWouldRefuse(): void
    {
        $this->createStaging(2); // meta says 3
        $dataDir = $this->newTempDir('_data');

        $cases = [
            [
                'incoming' => $this->makeIncoming(3, ['normalization_version' => 9]),
                'reason' => 'normalization_version_mismatch',
            ],
            ['incoming' => $this->makeIncoming(3), 'reason' => 'count_mismatch'],
        ];
        foreach ($cases as $case) {
            try {
                (new Reload($this->pdo, $this->config($dataDir, $case['incoming'])))->validate();
                self::fail('expected ' . $case['reason']);
            } catch (ReloadException $e) {
                self::assertSame($case['reason'], $e->reason());
            }
        }
    }

    public function testValidateBeforeTheStagingLoadSkipsTheStagingCountButNeedsTheLoadFile(): void
    {
        $dataDir = $this->newTempDir('_data');
        $incomingDir = $this->makeIncoming(3); // no products_new table, no products.load.sql
        $reload = new Reload($this->pdo, $this->config($dataDir, $incomingDir));

        try {
            $reload->validate(true);
            self::fail('expected missing_load_sql');
        } catch (ReloadException $e) {
            self::assertSame('missing_load_sql', $e->reason());
        }
        file_put_contents($incomingDir . '/products.load.sql', '-- staged');

        self::assertSame('will_load', $reload->validate(true)['staging']);
        $this->expectException(ReloadException::class);
        $this->expectExceptionMessage('missing_staging_table');
        $reload->validate(false);
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        )->fetchColumn() === 1;
    }

    /** @return array{string, string} data and incoming dirs after one successful reload (old = 2 rows, new = 3 rows) */
    private function reloadedOnce(): array
    {
        $this->insertProduct('products', 1);
        $this->insertProduct('products', 2);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/meta.json', json_encode([
            'normalization_version' => 2, 'count' => 2, 'built_at' => 'old-build',
        ]));
        $incomingDir = $this->makeIncoming(3);
        (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->run();
        $this->tempDirs[] = $dataDir . '_old';

        return [$dataDir, $incomingDir];
    }

    public function testRollbackSwapsTablesAndDirectoriesBackAndCanBeUndone(): void
    {
        [$dataDir, $incomingDir] = $this->reloadedOnce();
        $reload = new Reload($this->pdo, $this->config($dataDir, $incomingDir));
        self::assertSame(3, $this->rowCount('products'));

        $result = $reload->rollback();

        self::assertSame(2, $result['count']);
        self::assertSame(2, $this->rowCount('products'), 'the previous table is live again');
        self::assertSame(3, $this->rowCount('products_old'), 'the replaced one is kept');
        $live = json_decode((string) file_get_contents($dataDir . '/meta.json'), true);
        self::assertSame('old-build', $live['built_at']);
        self::assertSame(3, json_decode((string) file_get_contents($dataDir . '_old/meta.json'), true)['count']);
        self::assertFalse($this->tableExists('products_swap'));
        self::assertDirectoryDoesNotExist($dataDir . '_swap');

        self::assertSame(3, $reload->rollback()['count'], 'a second rollback undoes the first');
        self::assertSame(3, $this->rowCount('products'));
    }

    public function testRollbackWithNothingToRollBackToChangesNothing(): void
    {
        $this->insertProduct('products', 1);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/meta.json', '{}');
        $reload = new Reload($this->pdo, $this->config($dataDir, $this->newTempDir('_in')));

        try {
            $reload->rollback();
            self::fail('expected nothing_to_roll_back');
        } catch (ReloadException $e) {
            self::assertSame('nothing_to_roll_back', $e->reason());
            self::assertSame(['missing' => 'old_table'], $e->details());
        }
        self::assertSame(1, $this->rowCount('products'));
        self::assertFileExists($dataDir . '/meta.json');
    }

    public function testRollbackRefusesAPreviousVersionThatNoLongerMatchesTheCode(): void
    {
        [$dataDir, $incomingDir] = $this->reloadedOnce();
        $meta = json_decode((string) file_get_contents($dataDir . '_old/meta.json'), true);
        $meta['normalization_version'] = 1; // the old bundle predates the current normalization
        file_put_contents($dataDir . '_old/meta.json', json_encode($meta));

        try {
            (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->rollback();
            self::fail('expected a mismatch');
        } catch (ReloadException $e) {
            self::assertSame('normalization_version_mismatch', $e->reason());
        }
        self::assertSame(3, $this->rowCount('products'), 'nothing swapped');
    }

    public function testRollbackRestoresTheTablesWhenTheDirectoriesCannotBeSwapped(): void
    {
        [$dataDir, $incomingDir] = $this->reloadedOnce();
        // A plain file where the spare directory must go makes the first rename fail.
        file_put_contents($dataDir . '_swap', 'in the way');
        $this->tempDirs[] = $dataDir . '_swapfile';
        $reload = new Reload($this->pdo, $this->config($dataDir, $incomingDir));

        try {
            $reload->rollback();
            self::fail('expected directory_swap_failed');
        } catch (ReloadException $e) {
            self::assertSame('directory_swap_failed', $e->reason());
        } finally {
            @unlink($dataDir . '_swap');
        }
        self::assertSame(3, $this->rowCount('products'), 'tables are back as they were');
        self::assertSame(2, $this->rowCount('products_old'));
        self::assertSame(3, json_decode((string) file_get_contents($dataDir . '/meta.json'), true)['count']);
    }

    public function testRollbackFlushesTheResultCache(): void
    {
        [$dataDir, $incomingDir] = $this->reloadedOnce();
        $redis = new FakeRedis();
        $cache = new \App\Cache($redis, 'search:cache:', 60);
        $cache->set('search:cache:a', ['a' => 1]);

        $result = (new Reload($this->pdo, $this->config($dataDir, $incomingDir), $cache))->rollback();

        self::assertTrue($result['cache_flushed']);
        self::assertSame([], $redis->data);
    }

    public function testASecondReloadOrRollbackWhileOneRunsIsRefusedAsBusy(): void
    {
        [$dataDir, $incomingDir] = $this->reloadedOnce();
        $reload = new Reload($this->pdo, $this->config($dataDir, $incomingDir));
        $held = $this->lockHolder($dataDir);

        $calls = [
            'run' => fn () => $reload->run(),
            'rollback' => fn () => $reload->rollback(),
            'validate' => fn () => $reload->validate(),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("{$name} must be refused while the lock is held");
            } catch (ReloadException $e) {
                self::assertSame('busy', $e->reason(), $name);
            }
        }
        self::assertSame(3, $this->rowCount('products'), 'refused calls changed nothing');

        flock($held, LOCK_UN);
        fclose($held);
        self::assertSame(2, $reload->rollback()['count'], 'the lock was released, work goes through');
    }

    public function testTheLockIsReleasedWhenTheWorkFails(): void
    {
        $dataDir = $this->newTempDir('_data');
        $reload = new Reload($this->pdo, $this->config($dataDir, $this->newTempDir('_in')));

        foreach ([1, 2] as $_) { // a leaked lock would turn the second reason into "busy"
            try {
                $reload->run();
                self::fail('expected missing_meta');
            } catch (ReloadException $e) {
                self::assertSame('missing_meta', $e->reason());
            }
        }
    }

    public function testDescribeShowsTheStagedBundleNextToTheLiveOneAndTheDiff(): void
    {
        $this->insertProduct('products', 1);
        $this->createStaging(3);
        $dataDir = $this->newTempDir('_data');
        file_put_contents($dataDir . '/meta.json', json_encode([
            'normalization_version' => 2, 'count' => 2, 'built_at' => 'old-build',
        ]));
        $this->insertProduct('products', 9999); // live: 1, 9999; staged: 3000, 3001, 3002
        $incomingDir = $this->makeIncoming(3);
        file_put_contents($incomingDir . '/products.load.sql', '-- staged');

        $d = (new Reload($this->pdo, $this->config($dataDir, $incomingDir)))->describe();

        self::assertSame(3, $d['incoming']['count']);
        self::assertSame('2026-01-01T00:00:00+00:00', $d['incoming']['built_at']);
        self::assertSame(2, $d['live']['count']);
        self::assertSame(['built_at', 'count'], $d['diff']['changed']);
        self::assertSame(1, $d['diff']['count_delta']);
        self::assertSame([3, 2], [$d['diff']['added'], $d['diff']['removed']]);
        self::assertSame(['exists' => true, 'rows' => 3], $d['staging']);
        self::assertTrue($d['load_sql']);
        self::assertFalse($d['rollback']['possible']);
        self::assertSame('old_table', $d['rollback']['missing']);
    }

    public function testDescribeToleratesMissingBundlesAndTables(): void
    {
        $d = (new Reload($this->pdo, $this->config($this->newTempDir('_data'), $this->newTempDir('_in'))))->describe();

        self::assertFalse($d['incoming']['present']);
        self::assertFalse($d['live']['present']);
        self::assertNull($d['diff']);
        self::assertSame(['exists' => false, 'rows' => null], $d['staging']);
    }
}
