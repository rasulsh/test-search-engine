<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Validates a staged bundle and atomically swaps it in (CLAUDE.md sec. 6,
 * contract 3).
 *
 * Preconditions (the operator's upload step): the bundle is in the data_incoming
 * directory and products.load.sql has been loaded into the "<products>_new"
 * staging table — by the operator (mysql / phpMyAdmin), or by this class when
 * run with $loadStaging (M12), after the compatibility check and before the
 * consistency check, so a failed or partial load is never swapped in. This
 * class then, BEFORE swapping, rejects the bundle unless
 * normalization_version matches the code AND meta.count == staging rows,
 * and a present synonyms.json / intent_map.json parses (M15; aliases left the bundle in M41).
 * cPanel holds no vectors since M42 (the VPS serves the semantic tier). During the transition a
 * bundle that still carries vectors.bin / vectors.idx is accepted and keeps its old checks
 * (idx lines == meta.count, vectors.bin size and checksum); one without them is checked on meta
 * and the staging rows alone.
 * On success it swaps the tables and the directory, keeping the previous table
 * and directory for one-step rollback ({@see rollback()}).
 *
 * Every operation that changes the live tables or directory (run, rollback) and the read-only
 * {@see validate()} take the same non-blocking file lock (`reload.lock` next to the data
 * directory), so two reloads can never overlap, whether they come from POST /reload or the admin
 * panel (double click, two tabs): the second one fails with the reason `busy`.
 */
final class Reload
{
    private PDO $pdo;
    private string $dataDir;
    private string $incomingDir;
    private string $productsTable;
    private string $stagingTable;
    private string $backupTable;
    private bool $hadPreviousTable = false;
    private int $normalizationVersion;
    private ?Cache $cache;

    /**
     * @param array<string, mixed> $config
     * @param Cache|null $cache replaces the result cache built from $config['redis'] (tests)
     */
    public function __construct(PDO $pdo, array $config, ?Cache $cache = null)
    {
        $this->cache = $cache ?? Cache::fromConfig($config);
        $this->pdo = $pdo;
        $this->dataDir = rtrim((string) $config['paths']['data'], '/');
        $this->incomingDir = rtrim((string) $config['paths']['data_incoming'], '/');
        $this->productsTable = (string) $config['db']['products_table'];
        $this->stagingTable = $this->productsTable . '_new';
        $this->backupTable = $this->productsTable . '_old';
        $this->normalizationVersion = (int) $config['model']['normalization_version'];
    }

    /**
     * @return array{ok: true, count: int, cache_flushed?: bool}
     * @throws ReloadException when the bundle is incompatible or inconsistent.
     */
    public function run(bool $loadStaging = false): array
    {
        return $this->locked(fn (): array => $this->swapIn($loadStaging));
    }

    /**
     * Everything run() checks before it would swap, and nothing it changes: the staged bundle's
     * compatibility, its group files, and the counts. With $willLoadStaging the staging table is
     * not loaded yet (apply would load it), so its row count cannot be compared.
     *
     * @return array{ok: true, count: int, staging: string}
     * @throws ReloadException the reason run() would refuse the bundle for.
     */
    public function validate(bool $willLoadStaging = false): array
    {
        return $this->locked(function () use ($willLoadStaging): array {
            $meta = $this->readMeta();
            $this->assertCompatible($meta);
            $this->assertGroupFiles($meta);
            if ($willLoadStaging) {
                $path = $this->incomingDir . '/' . StagingLoader::LOAD_FILE;
                if (!is_file($path)) {
                    throw new ReloadException('missing_load_sql', ['path' => $path]);
                }
            }
            $count = $this->assertConsistent($meta, !$willLoadStaging);

            return [
                'ok' => true,
                'count' => $count,
                'staging' => $willLoadStaging ? 'will_load' : 'loaded',
            ];
        });
    }

    /**
     * Swap back to the previous version: `<products>_old` and `<data>_old` trade places with the live
     * ones, so a second rollback undoes the first. Refused (nothing changes) when either half of the
     * previous version is missing or its bundle no longer matches this code's normalization version.
     *
     * @return array{ok: true, count: int, cache_flushed?: bool}
     * @throws ReloadException nothing_to_roll_back, an incompatibility reason, or directory_swap_failed
     */
    public function rollback(): array
    {
        return $this->locked(function (): array {
            $why = $this->rollbackBlocker();
            if ($why !== null) {
                throw new ReloadException('nothing_to_roll_back', ['missing' => $why]);
            }
            $this->assertCompatible($this->readMetaFile($this->dataDir . '_old/meta.json'));

            $products = Identifier::quote($this->productsTable);
            $backup = Identifier::quote($this->backupTable);
            $spare = Identifier::quote($this->productsTable . '_swap');
            $swap = "RENAME TABLE {$products} TO {$spare}, {$backup} TO {$products}, {$spare} TO {$backup}";
            $this->pdo->exec($swap);
            try {
                $this->exchangeDirectories();
            } catch (Throwable $e) {
                $this->pdo->exec($swap);
                throw new ReloadException('directory_swap_failed', ['error' => $e->getMessage()]);
            }

            return $this->finish((int) (($this->readMetaFile($this->dataDir . '/meta.json'))['count'] ?? 0));
        });
    }

    /**
     * What the operations page shows: the staged bundle next to the live one, how they differ, the
     * staging table and whether a rollback is possible. Read-only, takes no lock.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $incoming = $this->summarize($this->incomingDir);
        $live = $this->summarize($this->dataDir);
        $diff = null;
        if ($incoming['present'] && $live['present']) {
            $changed = [];
            foreach (['built_at', 'normalization_version', 'count'] as $field) {
                if ($incoming[$field] !== $live[$field]) {
                    $changed[] = $field;
                }
            }
            $ids = $this->idDifference();
            $diff = [
                'changed' => $changed,
                'count_delta' => is_int($incoming['count']) && is_int($live['count'])
                    ? $incoming['count'] - $live['count']
                    : null,
                'added' => $ids['added'] ?? null,
                'removed' => $ids['removed'] ?? null,
            ];
        }
        $stagingExists = $this->safely(fn (): bool => $this->tableExists($this->stagingTable)) === true;
        $blocker = $this->safely(fn (): ?string => $this->rollbackBlocker());

        return [
            'incoming' => $incoming,
            'live' => $live,
            'diff' => $diff,
            'load_sql' => is_file($this->incomingDir . '/' . StagingLoader::LOAD_FILE),
            'staging' => [
                'exists' => $stagingExists,
                'rows' => $stagingExists ? $this->safely(fn (): int => $this->countStagingRows()) : null,
            ],
            'rollback' => [
                'possible' => $blocker === null,
                'missing' => $blocker,
                'previous' => $this->summarize($this->dataDir . '_old'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function swapIn(bool $loadStaging): array
    {
        $meta = $this->readMeta();
        $this->assertCompatible($meta);
        $this->assertGroupFiles($meta);
        if ($loadStaging) {
            (new StagingLoader($this->pdo, $this->stagingTable))
                ->load($this->incomingDir . '/' . StagingLoader::LOAD_FILE);
        }
        $count = $this->assertConsistent($meta);

        // Everything validated — swap. Tables first (RENAME TABLE is atomic for
        // the pair), then the directory. If the directory swap fails, roll the
        // tables back so the two never disagree: the result is all-old or
        // all-new, never a new products table pointing at old vectors.
        $this->swapTables();
        try {
            $this->swapDirectories();
        } catch (Throwable $e) {
            $this->rollbackTables();
            throw new ReloadException('directory_swap_failed', ['error' => $e->getMessage()]);
        }

        return $this->finish($count);
    }

    /**
     * After a committed swap (reload or rollback): drop the worker's cached maps, warm the speller
     * (best effort) and flush the result cache.
     *
     * @return array{ok: true, count: int, cache_flushed?: bool}
     */
    private function finish(int $count): array
    {
        // Searches in this worker re-read synonyms.json (and the alias file) at once.
        Synonyms::clearCache();
        IntentMap::clearCache();
        TokenStats::clearCache();
        QueryEntities::clearCache();

        // The swap is committed; warming the speller is best-effort. Other
        // workers pick up the new file on their own (the cache key is the file's
        // identity), so a failure here only costs one cold parse later.
        try {
            Speller::reloadDictionary($this->dataDir . '/' . Speller::DICTIONARY_FILE);
        } catch (Throwable $e) {
            error_log('reload: speller warm-up failed: ' . $e->getMessage());
        }

        $result = [
            'ok' => true,
            'count' => $count,
        ];
        // Cached results describe the old catalog (M26). A failed flush is
        // reported, not raised: the swap stands and entries expire with their TTL.
        if ($this->cache !== null) {
            $result['cache_flushed'] = $this->cache->flush();
        }

        return $result;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws ReloadException busy when another reload or rollback holds the lock
     */
    private function locked(callable $work): array
    {
        $path = dirname($this->dataDir) . '/reload.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new ReloadException('lock_unavailable', ['path' => $path]);
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new ReloadException('busy');
            }

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Which half of the previous version is missing, or null when a rollback is possible. */
    private function rollbackBlocker(): ?string
    {
        if (!$this->tableExists($this->productsTable)) {
            return 'products_table';
        }
        if (!$this->tableExists($this->backupTable)) {
            return 'old_table';
        }

        return is_file($this->dataDir . '_old/meta.json') ? null : 'old_directory';
    }

    /**
     * @param callable(): mixed $read
     * @return mixed null when the read fails (the page still renders)
     */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function summarize(string $dir): array
    {
        $path = $dir . '/meta.json';
        $meta = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (!is_array($meta)) {
            return ['present' => false, 'built_at' => null, 'normalization_version' => null, 'count' => null];
        }

        return [
            'present' => true,
            'built_at' => isset($meta['built_at']) ? (string) $meta['built_at'] : null,
            'normalization_version' => isset($meta['normalization_version'])
                ? (int) $meta['normalization_version']
                : null,
            'count' => isset($meta['count']) ? (int) $meta['count'] : null,
        ];
    }

    /**
     * Products the staged bundle adds to / removes from the live ones. Both sides come from the
     * tables (staging vs live), so it needs no vectors.idx; null while the staging table is not
     * loaded or there is no live table.
     *
     * @return array{added: int, removed: int}|null
     */
    private function idDifference(): ?array
    {
        return $this->safely(function (): ?array {
            if (!$this->tableExists($this->stagingTable) || !$this->tableExists($this->productsTable)) {
                return null;
            }
            $products = Identifier::quote($this->productsTable);
            $staging = Identifier::quote($this->stagingTable);
            $count = fn (string $from, string $other): int => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM {$from} f LEFT JOIN {$other} o ON o.product_id = f.product_id
                 WHERE o.product_id IS NULL"
            )->fetchColumn();

            return ['added' => $count($staging, $products), 'removed' => $count($products, $staging)];
        });
    }

    /** @return array<string, mixed> */
    private function readMeta(): array
    {
        return $this->readMetaFile($this->incomingDir . '/meta.json');
    }

    /** @return array<string, mixed> */
    private function readMetaFile(string $path): array
    {
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
        if ((int) ($meta['normalization_version'] ?? -1) !== $this->normalizationVersion) {
            throw new ReloadException('normalization_version_mismatch', [
                'expected' => $this->normalizationVersion,
                'actual' => $meta['normalization_version'] ?? null,
            ]);
        }
    }

    /**
     * synonyms.json and intent_map.json are optional, but a present one must parse: a typo
     * should fail the reload loudly rather than silently switch the feature off. A bundled
     * aliases.json is ignored (M41): the owner's aliases live in storage/aliases.json, which a
     * reload never reads, validates or writes.
     */
    private function assertGroupFiles(array $meta): void
    {
        if (!$this->groupsFileParses(Synonyms::SYNONYMS_FILE)) {
            throw new ReloadException('invalid_synonyms', ['file' => Synonyms::SYNONYMS_FILE]);
        }
        $intentPath = $this->incomingDir . '/' . IntentMap::FILE;
        if (is_file($intentPath) && IntentMap::parse((string) file_get_contents($intentPath)) === null) {
            throw new ReloadException('invalid_intent_map', ['file' => IntentMap::FILE]);
        }
        // M44: token_df.json and entities.json are optional (a bundle built before them has none), but a
        // present one must parse, carry the version meta.json names and (token_df) count the same products,
        // so a broken file fails the reload instead of silently switching the feature off.
        $statsPath = $this->incomingDir . '/' . TokenStats::FILE;
        if (is_file($statsPath)) {
            $stats = TokenStats::parse((string) file_get_contents($statsPath));
            if (
                $stats === null || $stats['n'] !== (int) ($meta['count'] ?? -1)
                || (int) ($meta['token_df_version'] ?? TokenStats::VERSION) !== TokenStats::VERSION
            ) {
                throw new ReloadException('invalid_token_df', ['file' => TokenStats::FILE]);
            }
        }
        $entitiesPath = $this->incomingDir . '/' . QueryEntities::FILE;
        if (
            is_file($entitiesPath)
            && (QueryEntities::parse((string) file_get_contents($entitiesPath)) === null
                || (int) ($meta['entities_version'] ?? QueryEntities::VERSION) !== QueryEntities::VERSION)
        ) {
            throw new ReloadException('invalid_entities', ['file' => QueryEntities::FILE]);
        }
    }

    private function groupsFileParses(string $file): bool
    {
        $path = $this->incomingDir . '/' . $file;

        return !is_file($path) || Synonyms::parseGroups((string) file_get_contents($path)) !== null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return int the validated product count
     */
    private function assertConsistent(array $meta, bool $withStaging = true): int
    {
        $metaCount = (int) ($meta['count'] ?? -1);
        $legacy = is_file($this->incomingDir . '/vectors.bin') || is_file($this->incomingDir . '/vectors.idx');
        $idxCount = $legacy ? $this->countIdxLines() : $metaCount;
        $stagingCount = $withStaging ? $this->countStagingRows() : $metaCount;

        if ($metaCount < 0 || $metaCount !== $idxCount || $metaCount !== $stagingCount) {
            $details = ['meta' => $metaCount, 'staging_rows' => $stagingCount];
            if ($legacy) {
                $details['vectors_idx'] = $idxCount;
            }
            throw new ReloadException('count_mismatch', $details);
        }
        if ($legacy) {
            $this->assertLegacyVectors($meta, $metaCount);
        }

        return $metaCount;
    }

    /**
     * The pre-M42 bundle shape: vectors.bin must be count x meta.dim float32 and match its checksum.
     * The dimension is the bundle's own (cPanel no longer configures a model).
     *
     * @param array<string, mixed> $meta
     */
    private function assertLegacyVectors(array $meta, int $count): void
    {
        $binPath = $this->incomingDir . '/vectors.bin';
        if (!is_file($binPath)) {
            throw new ReloadException('missing_vectors');
        }
        $expectedBytes = $count * (int) ($meta['dim'] ?? 0) * 4;
        if (filesize($binPath) !== $expectedBytes) {
            throw new ReloadException('vectors_size_mismatch', [
                'expected' => $expectedBytes,
                'actual' => filesize($binPath),
            ]);
        }
        if (isset($meta['checksum']) && hash_file('sha256', $binPath) !== $meta['checksum']) {
            throw new ReloadException('checksum_mismatch');
        }
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
        $this->hadPreviousTable = $this->tableExists($this->productsTable);
        if ($this->hadPreviousTable) {
            $this->pdo->exec("RENAME TABLE {$products} TO {$backup}, {$staging} TO {$products}");
        } else {
            $this->pdo->exec("RENAME TABLE {$staging} TO {$products}");
        }
    }

    /**
     * Undo {@see swapTables}: the just-installed rows go back to staging and, if
     * there was one, the previous table is restored as live. Used only when the
     * directory swap fails, so the tables match the (unchanged) directory.
     */
    private function rollbackTables(): void
    {
        $products = Identifier::quote($this->productsTable);
        $staging = Identifier::quote($this->stagingTable);
        $backup = Identifier::quote($this->backupTable);

        if ($this->hadPreviousTable) {
            $this->pdo->exec("RENAME TABLE {$products} TO {$staging}, {$backup} TO {$products}");
        } else {
            $this->pdo->exec("RENAME TABLE {$products} TO {$staging}");
        }
    }

    /**
     * Move the staged bundle into place, keeping the previous directory as
     * `<data>_old` for rollback. On failure it restores the previous directory
     * before throwing, so the directory is left all-old (matching the table
     * rollback the caller then performs).
     */
    private function swapDirectories(): void
    {
        $backupDir = $this->dataDir . '_old';
        $this->removeDir($backupDir);

        $movedData = false;
        if (is_dir($this->dataDir)) {
            if (!@rename($this->dataDir, $backupDir)) {
                throw new RuntimeException("failed to move {$this->dataDir} aside");
            }
            $movedData = true;
        }

        if (!@rename($this->incomingDir, $this->dataDir)) {
            if ($movedData) {
                @rename($backupDir, $this->dataDir); // restore previous bundle
            }
            throw new RuntimeException("failed to move {$this->incomingDir} into place");
        }
    }

    /** `<data>` and `<data>_old` trade places (the rollback); restores itself when a step fails. */
    private function exchangeDirectories(): void
    {
        $backupDir = $this->dataDir . '_old';
        $spare = $this->dataDir . '_swap';
        $this->removeDir($spare);
        if (!@rename($this->dataDir, $spare)) {
            throw new RuntimeException("failed to move {$this->dataDir} aside");
        }
        if (!@rename($backupDir, $this->dataDir)) {
            @rename($spare, $this->dataDir);
            throw new RuntimeException("failed to move {$backupDir} into place");
        }
        if (!@rename($spare, $backupDir)) {
            @rename($this->dataDir, $backupDir);
            @rename($spare, $this->dataDir);
            throw new RuntimeException("failed to keep {$this->dataDir} as {$backupDir}");
        }
    }

    /** Only `<data>_old` / `<data>_swap` next to the data directory are ever removed; see SafePath. */
    private function removeDir(string $dir): void
    {
        SafePath::removeTree($dir, dirname($this->dataDir));
    }
}
