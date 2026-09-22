<?php

declare(strict_types=1);

namespace App\Tests;

use App\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2 cosine top-K correctness and graceful degradation (contract 4 reader
 * side). No database: Vectors reads only vectors.bin / vectors.idx.
 */
final class VectorsTest extends TestCase
{
    private const DIM = 3;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @param array<int, list<float>> $rows product_id => vector (row order preserved)
     */
    private function makeBundle(array $rows): string
    {
        $dir = sys_get_temp_dir() . '/vectors_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        $bin = '';
        $idx = '';
        foreach ($rows as $id => $vector) {
            $bin .= pack('g*', ...$vector);
            $idx .= $id . "\n";
        }
        file_put_contents($dir . '/vectors.bin', $bin);
        file_put_contents($dir . '/vectors.idx', $idx);

        return $dir;
    }

    /** @return array<int, list<float>> */
    private function sampleRows(): array
    {
        return [
            10 => [1.0, 0.0, 0.0],
            20 => [0.0, 1.0, 0.0],
            30 => [0.0, 0.0, 1.0],
            40 => [0.6, 0.8, 0.0], // unit vector, partially aligned with id 10
        ];
    }

    private function vectors(string $dir): Vectors
    {
        return new Vectors($dir, self::DIM, false); // APCu off for deterministic tests
    }

    public function testTopKRanksByCosineAndLimits(): void
    {
        $vectors = $this->vectors($this->makeBundle($this->sampleRows()));

        $result = $vectors->topK([1.0, 0.0, 0.0], 2);

        self::assertCount(2, $result);
        self::assertSame(10, $result[0]['product_id']);
        self::assertEqualsWithDelta(1.0, $result[0]['score'], 1e-6);
        self::assertSame(40, $result[1]['product_id']);
        self::assertEqualsWithDelta(0.6, $result[1]['score'], 1e-6);
    }

    public function testTopKReturnsAllWhenKExceedsCount(): void
    {
        $vectors = $this->vectors($this->makeBundle($this->sampleRows()));

        $result = $vectors->topK([0.0, 1.0, 0.0], 100);

        self::assertCount(4, $result);
        self::assertSame(20, $result[0]['product_id']); // exact match first
        self::assertSame(40, $result[1]['product_id']); // 0.8 component
    }

    public function testIsLoadedAndCount(): void
    {
        $vectors = $this->vectors($this->makeBundle($this->sampleRows()));

        self::assertTrue($vectors->isLoaded());
        self::assertSame(4, $vectors->count());
    }

    public function testWrongDimensionQueryDegradesToEmpty(): void
    {
        $vectors = $this->vectors($this->makeBundle($this->sampleRows()));

        self::assertSame([], $vectors->topK([1.0, 0.0], 5));          // too short
        self::assertSame([], $vectors->topK([1.0, 0.0, 0.0, 0.0], 5)); // too long
    }

    public function testMissingFilesDegradeGracefully(): void
    {
        $dir = sys_get_temp_dir() . '/vectors_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        $vectors = $this->vectors($dir);

        self::assertFalse($vectors->isLoaded());
        self::assertSame(0, $vectors->count());
        self::assertSame([], $vectors->topK([1.0, 0.0, 0.0], 5));
    }

    public function testInconsistentBinSizeIsNotLoaded(): void
    {
        $dir = $this->makeBundle($this->sampleRows());
        // Truncate one byte so the size is no longer a whole number of rows.
        $bin = (string) file_get_contents($dir . '/vectors.bin');
        file_put_contents($dir . '/vectors.bin', substr($bin, 0, -1));

        self::assertFalse($this->vectors($dir)->isLoaded());
        self::assertSame([], $this->vectors($dir)->topK([1.0, 0.0, 0.0], 5));
    }

    public function testBinRowIndexCountMismatchIsNotLoaded(): void
    {
        $dir = $this->makeBundle($this->sampleRows());
        // idx now claims a fifth product that vectors.bin does not have.
        file_put_contents($dir . '/vectors.idx', "10\n20\n30\n40\n50\n");

        self::assertFalse($this->vectors($dir)->isLoaded());
    }

    public function testZeroKReturnsEmpty(): void
    {
        $vectors = $this->vectors($this->makeBundle($this->sampleRows()));

        self::assertSame([], $vectors->topK([1.0, 0.0, 0.0], 0));
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
