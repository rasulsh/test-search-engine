<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Validates a staged bundle and atomically swaps it in (CLAUDE.md sec. 6,
 * contract 3).
 *
 * Preconditions (the operator's upload step): the bundle is in the data_incoming
 * directory and products.load.sql has been loaded into the "<products>_new"
 * staging table. This class then, BEFORE swapping, rejects the bundle unless
 * model + dim + normalization_version match server config AND
 * meta.count == vectors.idx lines == staging rows == vectors.bin size/checksum.
 * On success it swaps the tables and the directory, keeping the previous table
 * and directory for one-step rollback.
 */
final class Reload
{
    private PDO $pdo;
    private string $dataDir;
    private string $incomingDir;
    private string $productsTable;
    private string $stagingTable;
    private string $backupTable;
    /** @var array{name: string, dim: int, normalization_version: int} */
    private array $model;

    /** @param array<string, mixed> $config */
    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->dataDir = rtrim((string) $config['paths']['data'], '/');
        $this->incomingDir = rtrim((string) $config['paths']['data_incoming'], '/');
        $this->productsTable = (string) $config['db']['products_table'];
        $this->stagingTable = $this->productsTable . '_new';
        $this->backupTable = $this->productsTable . '_old';
        $this->model = [
            'name' => (string) $config['model']['name'],
            'dim' => (int) $config['model']['dim'],
            'normalization_version' => (int) $config['model']['normalization_version'],
        ];
    }

    /**
     * @return array{ok: true, count: int, model: string, dim: int}
     * @throws ReloadException when the bundle is incompatible or inconsistent.
     */
    public function run(): array
    {
        $meta = $this->readMeta();
        $this->assertCompatible($meta);
        $count = $this->assertConsistent($meta);

        // Everything validated — swap. Tables first (RENAME TABLE is atomic for
        // the pair), then the directory.
        $this->swapTables();
        $this->swapDirectories();

        return [
            'ok' => true,
            'count' => $count,
            'model' => $this->model['name'],
            'dim' => $this->model['dim'],
        ];
    }

    /** @return array<string, mixed> */
    private function readMeta(): array
    {
        $path = $this->incomingDir . '/meta.json';
        if (!is_file($path)) {
            throw new ReloadException('missing_meta', ['path' => $path]);
        }
        $meta = json_decode((string) file_get_contents($path), true);
        if (!is_array($meta)) {
            throw new ReloadException('invalid_meta');
        }

        return $meta;
    }

    /** @param array<string, mixed> $meta */
    private function assertCompatible(array $meta): void
    {
        if (($meta['model'] ?? null) !== $this->model['name']) {
            throw new ReloadException('model_mismatch', [
                'expected' => $this->model['name'],
                'actual' => $meta['model'] ?? null,
            ]);
        }
        if ((int) ($meta['dim'] ?? -1) !== $this->model['dim']) {
            throw new ReloadException('dim_mismatch', [
                'expected' => $this->model['dim'],
                'actual' => $meta['dim'] ?? null,
            ]);
        }
        if ((int) ($meta['normalization_version'] ?? -1) !== $this->model['normalization_version']) {
            throw new ReloadException('normalization_version_mismatch', [
                'expected' => $this->model['normalization_version'],
                'actual' => $meta['normalization_version'] ?? null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return int the validated product count
     */
    private function assertConsistent(array $meta): int
    {
        $metaCount = (int) ($meta['count'] ?? -1);
        $idxCount = $this->countIdxLines();
        $stagingCount = $this->countStagingRows();

        if ($metaCount < 0 || $metaCount !== $idxCount || $metaCount !== $stagingCount) {
            throw new ReloadException('count_mismatch', [
                'meta' => $metaCount,
                'vectors_idx' => $idxCount,
                'staging_rows' => $stagingCount,
            ]);
        }

        $binPath = $this->incomingDir . '/vectors.bin';
        if (!is_file($binPath)) {
            throw new ReloadException('missing_vectors');
        }
        $expectedBytes = $metaCount * $this->model['dim'] * 4;
        if (filesize($binPath) !== $expectedBytes) {
            throw new ReloadException('vectors_size_mismatch', [
                'expected' => $expectedBytes,
                'actual' => filesize($binPath),
            ]);
        }
        if (isset($meta['checksum']) && hash_file('sha256', $binPath) !== $meta['checksum']) {
            throw new ReloadException('checksum_mismatch');
        }

        return $metaCount;
    }

    private function countIdxLines(): int
    {
        $path = $this->incomingDir . '/vectors.idx';
        if (!is_file($path)) {
            throw new ReloadException('missing_index');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : count($lines);
    }

    private function countStagingRows(): int
    {
        if (!$this->tableExists($this->stagingTable)) {
            throw new ReloadException('missing_staging_table', ['table' => $this->stagingTable]);
        }
        $table = Identifier::quote($this->stagingTable);

        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :name'
        );
        $stmt->execute(['name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function swapTables(): void
    {
        $products = Identifier::quote($this->productsTable);
        $staging = Identifier::quote($this->stagingTable);
        $backup = Identifier::quote($this->backupTable);

        $this->pdo->exec("DROP TABLE IF EXISTS {$backup}");
        if ($this->tableExists($this->productsTable)) {
            $this->pdo->exec("RENAME TABLE {$products} TO {$backup}, {$staging} TO {$products}");
        } else {
            $this->pdo->exec("RENAME TABLE {$staging} TO {$products}");
        }
    }

    private function swapDirectories(): void
    {
        $backupDir = $this->dataDir . '_old';
        $this->removeDir($backupDir);
        if (is_dir($this->dataDir)) {
            rename($this->dataDir, $backupDir);
        }
        rename($this->incomingDir, $this->dataDir);
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
}
