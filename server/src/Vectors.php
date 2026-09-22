<?php

declare(strict_types=1);

namespace App;

/**
 * Tier 2 semantic search: global cosine top-K over the precomputed product
 * vectors in the bundle (CLAUDE.md sec. 5.4).
 *
 * `vectors.bin` is little-endian float32, row-major `count x dim`, each row
 * L2-normalized at build time (contract 4). The query vector arrives already
 * L2-normalized from the browser embedder (contract 2), so cosine similarity is
 * just the dot product — no per-row renormalization.
 *
 * Cost model (measured, see PR): the dot-product scan is cheap (~130 ms warm for
 * 20k x 384); the expensive part is READING and UNPACKING the ~30 MB file. So we
 * cache the *parsed* matrix for the lifetime of the PHP worker (LiteSpeed keeps
 * workers warm across requests) and, when APCu is present, cache the raw bytes so
 * a fresh worker skips the disk read. Everything degrades to a plain disk read
 * when APCu is absent. The cache key embeds the file's size+mtime, so the atomic
 * reload swap (which replaces the directory) invalidates it automatically.
 */
final class Vectors
{
    /**
     * Parsed bundles for this process, keyed by {@see cacheKey}.
     *
     * @var array<string, array{ids: list<int>, flat: list<float>, count: int}>
     */
    private static array $processCache = [];

    /**
     * Memoized vectors.idx line counts, keyed by {@see cacheKey}, so a warm
     * worker does not re-read the index file on every isLoaded()/topK() call.
     *
     * @var array<string, int>
     */
    private static array $idxCountCache = [];

    private string $binPath;
    private string $idxPath;
    private int $dim;
    private bool $apcu;

    public function __construct(string $dataDir, int $dim, ?bool $useApcu = null)
    {
        $dataDir = rtrim($dataDir, '/');
        $this->binPath = $dataDir . '/vectors.bin';
        $this->idxPath = $dataDir . '/vectors.idx';
        $this->dim = max(1, $dim);
        $this->apcu = $useApcu ?? (function_exists('apcu_enabled') && apcu_enabled());
    }

    /**
     * True when a consistent bundle is present: both files exist and the byte
     * size is a whole number of `dim`-float rows equal to the index line count.
     * An inconsistent or missing bundle degrades to keyword-only (never errors).
     */
    public function isLoaded(): bool
    {
        if (!is_file($this->binPath) || !is_file($this->idxPath)) {
            return false;
        }
        $size = filesize($this->binPath);
        $rowBytes = $this->dim * 4;
        if ($size === false || $size <= 0 || $size % $rowBytes !== 0) {
            return false;
        }

        return intdiv($size, $rowBytes) === $this->countIdxLines();
    }

    /** Number of vectors currently loadable, or 0 when no consistent bundle. */
    public function count(): int
    {
        return $this->isLoaded() ? $this->countIdxLines() : 0;
    }

    /**
     * Global cosine top-K. Returns at most $k rows ordered by score descending.
     * Returns [] (degrade to keyword-only) when the bundle is unavailable or the
     * query vector's length does not match the configured dimension.
     *
     * @param list<float> $queryVector
     * @return list<array{product_id: int, score: float}>
     */
    public function topK(array $queryVector, int $k): array
    {
        if ($k < 1 || count($queryVector) !== $this->dim || !$this->isLoaded()) {
            return [];
        }

        $data = $this->load();
        $ids = $data['ids'];
        $flat = $data['flat'];
        $count = $data['count'];
        $dim = $this->dim;

        // Query as a plain indexed float array; dot product against each row.
        $q = [];
        foreach ($queryVector as $value) {
            $q[] = (float) $value;
        }

        $scores = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $sum = 0.0;
            for ($j = 0; $j < $dim; $j++) {
                $sum += $q[$j] * $flat[$offset + $j];
            }
            $scores[$i] = $sum;
            $offset += $dim;
        }

        // Stable sort (PHP 8) keeps lower row order on ties, so results are
        // deterministic for equal scores.
        arsort($scores);

        $out = [];
        foreach ($scores as $row => $score) {
            $out[] = ['product_id' => $ids[$row], 'score' => $score];
            if (count($out) >= $k) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{ids: list<int>, flat: list<float>, count: int}
     */
    private function load(): array
    {
        $key = $this->cacheKey();
        if (isset(self::$processCache[$key])) {
            return self::$processCache[$key];
        }

        $ids = $this->readIdx($key);
        $blob = $this->readBlob($key);
        /** @var list<float> $flat */
        $flat = array_values(unpack('g*', $blob)); // little-endian float32
        $count = count($ids);

        // Defensive: a torn read/unpack shorter than expected serves fewer rows
        // rather than reading past the array.
        $available = intdiv(count($flat), $this->dim);
        if ($available < $count) {
            $count = $available;
        }

        $parsed = ['ids' => $ids, 'flat' => $flat, 'count' => $count];
        self::$processCache[$key] = $parsed;

        return $parsed;
    }

    private function readBlob(string $key): string
    {
        $apcuKey = 'search.vec.bin.' . $key;
        if ($this->apcu) {
            $hit = apcu_fetch($apcuKey, $ok);
            if ($ok === true && is_string($hit)) {
                return $hit;
            }
        }
        $blob = (string) file_get_contents($this->binPath);
        if ($this->apcu) {
            // Best-effort: a bundle larger than the APCu segment simply is not
            // cached (apcu_store returns false); the disk read still served it.
            @apcu_store($apcuKey, $blob);
        }

        return $blob;
    }

    /**
     * @return list<int>
     */
    private function readIdx(string $key): array
    {
        $apcuKey = 'search.vec.idx.' . $key;
        if ($this->apcu) {
            $hit = apcu_fetch($apcuKey, $ok);
            if ($ok === true && is_array($hit)) {
                /** @var list<int> $hit */
                return $hit;
            }
        }
        $lines = file($this->idxPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $ids = $lines === false ? [] : array_map('intval', $lines);
        if ($this->apcu) {
            @apcu_store($apcuKey, $ids);
        }

        return $ids;
    }

    private function countIdxLines(): int
    {
        $key = $this->cacheKey();
        if (isset(self::$idxCountCache[$key])) {
            return self::$idxCountCache[$key];
        }
        $lines = file($this->idxPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return self::$idxCountCache[$key] = ($lines === false ? 0 : count($lines));
    }

    /** Version tag: a swapped-in bundle changes the file size/mtime, so a stale
     * parse is never reused after a reload. */
    private function cacheKey(): string
    {
        $size = @filesize($this->binPath) ?: 0;
        $mtime = @filemtime($this->binPath) ?: 0;

        return md5($this->binPath . '|' . $this->dim . '|' . $size . '|' . $mtime);
    }
}
