<?php

declare(strict_types=1);

namespace App\Tests;

use App\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * Latency guard for the Tier 2 cosine scan (CLAUDE.md sec. 8).
 *
 * Synthesizes a realistic 20000 x dim vector file and measures the WARM path —
 * the matrix already parsed and cached, which is the steady state on a
 * LiteSpeed worker (reading + unpacking the ~30 MB file is the one-time cost,
 * not the dot products). CI hardware differs from cPanel, so the assertion is a
 * regression guard, NOT the production SLA; the measured number is reported in
 * the PR and the real-host latency is listed under "Needs production validation".
 *
 * If a pure-PHP 20k scan cannot meet the budget on the CI machine, this guard is
 * NOT to be relaxed to hide it — the number is reported honestly instead.
 */
final class VectorsLatencyGuardTest extends TestCase
{
    private const COUNT = 20000;
    private const DIM = 384;
    private const TOP_K = 100;

    // Regression guard, not the 200 ms production budget: generous enough to
    // absorb shared-CI variance while still catching a gross (>3x) regression.
    private const CEILING_MS = 600.0;

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            @unlink($this->dir . '/vectors.bin');
            @unlink($this->dir . '/vectors.idx');
            @rmdir($this->dir);
            $this->dir = null;
        }
    }

    public function testWarmCosineTopKStaysWithinRegressionCeiling(): void
    {
        // The 20k x 384 parsed matrix needs a few hundred MB; raise the limit so
        // the guard runs regardless of the CLI default.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1024M');

        try {
            $this->dir = $this->synthesizeBundle();
            $vectors = new Vectors($this->dir, self::DIM, false);
            self::assertTrue($vectors->isLoaded());

            $query = $this->randomUnitVector(self::DIM, 12345);

            // Warm: first call reads + unpacks + caches the parsed matrix.
            $warmup = $vectors->topK($query, self::TOP_K);
            self::assertCount(self::TOP_K, $warmup);

            // Measure the warm scan; take the best of a few to reduce noise.
            $best = INF;
            for ($i = 0; $i < 3; $i++) {
                $start = hrtime(true);
                $vectors->topK($query, self::TOP_K);
                $best = min($best, (hrtime(true) - $start) / 1_000_000.0);
            }

            fwrite(STDERR, sprintf(
                "\n[latency guard] warm cosine top-K over %d x %d: %.1f ms (budget 200 ms, ceiling %.0f ms)\n",
                self::COUNT,
                self::DIM,
                $best,
                self::CEILING_MS
            ));

            self::assertLessThan(
                self::CEILING_MS,
                $best,
                sprintf('warm top-K took %.1f ms, over the %.0f ms regression ceiling', $best, self::CEILING_MS)
            );
        } finally {
            ini_set('memory_limit', $previousLimit === false ? '-1' : $previousLimit);
        }
    }

    private function synthesizeBundle(): string
    {
        $dir = sys_get_temp_dir() . '/veclat_' . uniqid('', true);
        mkdir($dir, 0777, true);

        $handle = fopen($dir . '/vectors.bin', 'wb');
        self::assertIsResource($handle);
        $idx = '';
        for ($row = 0; $row < self::COUNT; $row++) {
            $vector = $this->randomUnitVector(self::DIM, $row + 1);
            fwrite($handle, pack('g*', ...$vector));
            $idx .= (100000 + $row) . "\n";
        }
        fclose($handle);
        file_put_contents($dir . '/vectors.idx', $idx);

        return $dir;
    }

    /**
     * Deterministic L2-normalized vector (seeded), so the guard is reproducible.
     *
     * @return list<float>
     */
    private function randomUnitVector(int $dim, int $seed): array
    {
        mt_srand($seed);
        $vector = [];
        $sumSquares = 0.0;
        for ($i = 0; $i < $dim; $i++) {
            $value = mt_rand() / mt_getrandmax() * 2.0 - 1.0;
            $vector[] = $value;
            $sumSquares += $value * $value;
        }
        $inverse = 1.0 / sqrt($sumSquares);
        foreach ($vector as $i => $value) {
            $vector[$i] = $value * $inverse;
        }

        return $vector;
    }
}
