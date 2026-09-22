<?php

declare(strict_types=1);

namespace App\Tests;

use App\Reload;
use App\ReloadException;

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
