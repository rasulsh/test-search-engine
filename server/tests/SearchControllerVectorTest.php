<?php

declare(strict_types=1);

namespace App\Tests;

use App\Identifier;
use App\Keyword;
use App\Logger;
use App\Normalizer;
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
        $spellerFactory = static fn (): Speller => Speller::fromProducts($pdo, 'products');

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
            100,
            20,
            0.82
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

    public function testNoKeywordHitAndNoNeighbourAboveTheFloorReturnsEmpty(): void
    {
        // A "ball bearing" query: no keyword match anywhere, and the nearest
        // vector (0.6) is below the 0.82 floor. Nothing is padded in.
        $bundle = $this->makeBundle([
            1009 => [0.6, 0.8, 0.0, 0.0],
            1013 => [0.0, 0.0, 1.0, 0.0],
        ]);

        $result = $this->controller($bundle)->search([
            'q'        => 'بلبرینگ',
            'q_vector' => [1.0, 0.0, 0.0, 0.0],
        ]);

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['product_ids']);
        self::assertSame([], $result['cosine_scores']);
        self::assertNull($result['did_you_mean']);
    }

    public function testNeighboursBelowTheFloorAreDroppedButAboveAreKept(): void
    {
        $bundle = $this->makeBundle([
            1011 => [0.9, 0.43589, 0.0, 0.0], // cosine 0.9: kept
            1013 => [0.6, 0.8, 0.0, 0.0],     // cosine 0.6: dropped
        ]);

        $result = $this->controller($bundle)->search([
            'q'        => 'بلبرینگ',
            'q_vector' => [1.0, 0.0, 0.0, 0.0],
        ]);

        self::assertSame([1011], $result['product_ids']);
        self::assertEqualsWithDelta(0.9, $result['cosine_scores'][0], 1e-4);
    }

    public function testKeywordHitsOutrankAHighlyBoostedSemanticNeighbour(): void
    {
        // "sony" keyword hits: 1009, 1011. 1001 (the catalog's most popular,
        // in stock) is the exact cosine match but not a keyword hit. Plain RRF
        // ranked it above 1011; it must come after both keyword hits.
        $bundle = $this->makeBundle([
            1001 => [1.0, 0.0, 0.0, 0.0],
            1009 => [0.6, 0.8, 0.0, 0.0],
            1013 => [0.0, 0.0, 1.0, 0.0],
        ]);

        $result = $this->controller($bundle)->search([
            'q'        => 'sony',
            'q_vector' => [1.0, 0.0, 0.0, 0.0],
        ]);

        self::assertSame([1009, 1011, 1001], $result['product_ids']);
        // Cosine per result, aligned with product_ids; 1011 has no vector. A
        // keyword hit below the floor still reports its cosine.
        self::assertCount(3, $result['cosine_scores']);
        self::assertEqualsWithDelta(0.6, $result['cosine_scores'][0], 1e-4);
        self::assertNull($result['cosine_scores'][1]);
        self::assertEqualsWithDelta(1.0, $result['cosine_scores'][2], 1e-4);
    }

    public function testKeywordOnlyResponseHasNoCosineScores(): void
    {
        $result = $this->controller(null)->search(['q' => 'sony', 'q_vector' => [1.0, 0.0, 0.0, 0.0]]);

        self::assertArrayNotHasKey('cosine_scores', $result);
    }

    public function testFromConfigAcceptsAConfigWithoutTheM9Keys(): void
    {
        // A server/config.php copied before M9 lacks the new search keys; the
        // documented defaults (floor 0.82) apply instead of an error.
        $bundle = $this->makeBundle([
            1011 => [0.9, 0.43589, 0.0, 0.0],
            1013 => [0.6, 0.8, 0.0, 0.0],
        ]);
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'model'  => ['dim' => self::DIM],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'rrf_k' => 60, 'stock_boost' => 0.1, 'popularity_boost' => 0.1,
            ],
            'paths'  => ['data' => $bundle],
        ];

        $result = SearchController::fromConfig($this->pdo, $config)->search([
            'q'        => 'بلبرینگ',
            'q_vector' => [1.0, 0.0, 0.0, 0.0],
        ]);

        self::assertSame([1011], $result['product_ids']);
    }

    public function testHybridKeepsTitleMatchAboveDescriptionOnlyNeighbour(): void
    {
        // Real-catalog case: the PS5 bundle mentions "دوال شاک" only in its
        // description, is popular and in stock, and is the query's nearest
        // vector. The controller named by the query must still rank first.
        $insert = $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc,
                                   stock, popularity)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (
            [
                [2001, 'دسته بازی دوال شاک 4', 'کنترلر بی سیم', 0, 1],
                [2002, 'کنسول پلی استیشن 5', 'باندل با دسته. سازگار با دوال شاک و دوال شاک', 50, 1000],
            ] as [$id, $title, $desc, $stock, $popularity]
        ) {
            $insert->execute([
                $id, $title, $desc, Normalizer::normalize($title), Normalizer::normalize($desc), $stock, $popularity,
            ]);
        }
        $bundle = $this->makeBundle([
            2002 => [1.0, 0.0, 0.0, 0.0],
            2001 => [0.85, 0.52678, 0.0, 0.0],
        ]);
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'model'  => ['dim' => self::DIM],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'rrf_k' => 60, 'stock_boost' => 0.1, 'popularity_boost' => 0.1,
                'title_weight' => 10.0, 'desc_weight' => 1.0, 'phrase_bonus' => 5.0,
            ],
            'paths'  => ['data' => $bundle],
        ];

        $result = SearchController::fromConfig($this->pdo, $config)->search([
            'q'        => 'دوال شاک',
            'q_vector' => [1.0, 0.0, 0.0, 0.0],
        ]);

        self::assertSame([2001, 2002], $result['product_ids']);
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
