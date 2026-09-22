<?php

declare(strict_types=1);

namespace App\Tests;

use App\Identifier;
use App\Keyword;
use App\Logger;
use App\Ranker;
use App\SearchController;
use App\Speller;
use App\Vectors;

/**
 * Tier 2 (semantic) wiring in the /search flow: the vector path is additive, the
 * hybrid merge surfaces vector-only recall, and a partial reload (a vector whose
 * product_id is missing from the table) degrades instead of surfacing a dead id
 * (CLAUDE.md sec. 5; requirement #4 reader side).
 */
final class SearchControllerVectorTest extends DatabaseTestCase
{
    private const DIM = 4;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture(); // products 1001..1014
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @param array<int, list<float>> $rows product_id => 4-d vector, row order preserved
     */
    private function makeBundle(array $rows): string
    {
        $dir = sys_get_temp_dir() . '/ctlvec_' . uniqid('', true);
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

    private function controller(?string $bundleDir): SearchController
    {
        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20);
        $logger = new Logger($pdo, 'search_logs');
        $spellerFactory = static function () use ($pdo): Speller {
            $texts = [];
            foreach ($pdo->query('SELECT normalized_title, normalized_desc FROM products') as $row) {
                $texts[] = $row['normalized_title'];
                $texts[] = $row['normalized_desc'];
            }

            return new Speller(Speller::buildVocabulary($texts));
        };

        $vectors = new Vectors($bundleDir ?? '/nonexistent-bundle-dir', self::DIM, false);
        $ranker = new Ranker(60, 0.1, 0.1);
        $signalsProvider = static function (array $ids) use ($pdo): array {
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = 'SELECT product_id, stock, popularity FROM ' . Identifier::quote('products')
                 . ' WHERE product_id IN (' . $placeholders . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($ids));
            $signals = [];
            foreach ($stmt as $row) {
                $signals[(int) $row['product_id']] = [
                    'stock'      => (int) $row['stock'],
                    'popularity' => (int) $row['popularity'],
                ];
            }

            return $signals;
        };

        return new SearchController(
            $keyword,
            $logger,
            $spellerFactory,
            null,
            10,
            $vectors,
            $ranker,
            $signalsProvider,
            100
        );
    }

    public function testKeywordOnlyWhenNoVector(): void
    {
        // Only product 1007 is an English "macbook"; the Persian 1008 is not.
        $result = $this->controller(null)->search(['q' => 'macbook']);

        self::assertSame([1007], $result['product_ids']);
    }

    public function testVectorPathAddsSemanticRecall(): void
    {
        // Query vector is aligned with product 1011, which the keyword query
        // ("macbook") does not match; 1007 has no vector (keyword-only).
        $bundle = $this->makeBundle([
            1011 => [0.0, 1.0, 0.0, 0.0],
            1009 => [1.0, 0.0, 0.0, 0.0],
            1013 => [0.0, 0.0, 1.0, 0.0],
        ]);

        $result = $this->controller($bundle)->search([
            'q'        => 'macbook',
            'q_vector' => [0.0, 1.0, 0.0, 0.0],
        ]);

        self::assertContains(1011, $result['product_ids']); // vector-only recall
        self::assertContains(1007, $result['product_ids']); // keyword-only item kept
        self::assertGreaterThanOrEqual(2, $result['count']);
    }

    public function testWrongDimensionVectorIgnored(): void
    {
        $bundle = $this->makeBundle([1011 => [0.0, 1.0, 0.0, 0.0]]);

        $result = $this->controller($bundle)->search([
            'q'        => 'macbook',
            'q_vector' => [0.0, 1.0, 0.0], // 3-d, config is 4-d
        ]);

        self::assertSame([1007], $result['product_ids']); // degraded to keyword-only
    }

    public function testMissingBundleDegradesToKeyword(): void
    {
        $result = $this->controller(null)->search([
            'q'        => 'macbook',
            'q_vector' => [0.0, 1.0, 0.0, 0.0],
        ]);

        self::assertSame([1007], $result['product_ids']);
    }

    public function testSemanticIdMissingFromTableIsDropped(): void
    {
        // 9999 is not in the products table (a stale vector from a partial reload)
        // and is the top cosine hit; it must not surface.
        $bundle = $this->makeBundle([
            9999 => [0.0, 1.0, 0.0, 0.0],
            1011 => [0.0, 0.0, 1.0, 0.0],
        ]);

        $result = $this->controller($bundle)->search([
            'q'        => 'macbook',
            'q_vector' => [0.0, 1.0, 0.0, 0.0],
        ]);

        self::assertNotContains(9999, $result['product_ids']);
        self::assertContains(1007, $result['product_ids']);
    }

    public function testVectorRequestIsLoggedWithHadVector(): void
    {
        $bundle = $this->makeBundle([1011 => [0.0, 1.0, 0.0, 0.0]]);

        $this->controller($bundle)->search([
            'q'        => 'macbook',
            'q_vector' => [0.0, 1.0, 0.0, 0.0],
        ]);

        $hadVector = $this->pdo->query('SELECT had_vector FROM search_logs ORDER BY id DESC LIMIT 1')
            ->fetchColumn();
        self::assertSame(1, (int) $hadVector);
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
