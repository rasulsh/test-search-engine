<?php

declare(strict_types=1);

namespace App\Tests;

use App\Identifier;
use App\Keyword;
use App\Logger;
use App\Normalizer;
use App\ProductLoader;
use App\Ranker;
use App\SearchController;
use App\Speller;
use App\VpsClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tier 2 (semantic) in the /search flow, sourced from the VPS (M18): cPanel
 * sends the query text, merges the returned neighbours by RRF, applies the
 * floor and the description-only gate, and falls back to keyword-only results
 * (logged, never an error) whenever the VPS is off, down, slow or broken
 * (CLAUDE.md sec. 5). The VPS is FakeVps, answered in-process.
 */
final class SearchControllerVpsTest extends DatabaseTestCase
{
    private const QUERY = [1.0, 0.0, 0.0, 0.0];

    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture(); // products 1001..1014
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'vpslog_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    private function controller(?VpsClient $vps, float $floor = 0.82, int $topK = 100): SearchController
    {
        $pdo = $this->pdo;
        $signalsProvider = static function (array $ids) use ($pdo): array {
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('SELECT product_id, stock, popularity FROM ' . Identifier::quote('products')
                . ' WHERE product_id IN (' . $placeholders . ')');
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
            new Keyword($pdo, 'products', 3, 20),
            new Logger($pdo, 'search_logs'),
            static fn (): Speller => Speller::fromProducts($pdo, 'products'),
            null,
            10,
            $vps,
            new Ranker(60, 0.1, 0.1),
            $signalsProvider,
            $topK,
            20,
            $floor
        );
    }

    /**
     * @param array<int, list<float>> $products
     * @param list<array<string, mixed>> $calls
     */
    private function vps(array $products, array &$calls = [], array $extra = []): VpsClient
    {
        return FakeVps::client(['products' => $products, 'queries' => ['*' => self::QUERY]] + $extra, $calls);
    }

    /** @return array<string, mixed> */
    private function lastLog(): array
    {
        return (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testKeywordOnlyWhenNoVpsIsConfigured(): void
    {
        // Only product 1007 is an English "macbook"; the Persian 1008 is not.
        $result = $this->controller(null)->search(['q' => 'macbook']);

        self::assertSame([1007], $result['product_ids']);
        self::assertArrayNotHasKey('cosine_scores', $result);
        self::assertSame(0, (int) $this->lastLog()['had_vector']);
        self::assertSame('', (string) file_get_contents($this->errorLog)); // not configured is not degraded
    }

    public function testVpsNeighboursAddSemanticRecall(): void
    {
        // The VPS puts 1011 (not a keyword hit for "macbook") first; 1007 is
        // a keyword hit the VPS did not return.
        $calls = [];
        $result = $this->controller($this->vps([
            1011 => [1.0, 0.0, 0.0, 0.0],
            1009 => [0.0, 1.0, 0.0, 0.0],
        ], $calls), 0.82, 50)->search(['q' => ' macbook ', 'customer_id' => 'c-1']);

        self::assertSame([1007, 1011], $result['product_ids']); // keyword hit leads, neighbour below
        self::assertSame([null, 1.0], $result['cosine_scores']);
        self::assertSame(1, (int) $this->lastLog()['had_vector']);
        // What cPanel sent: the raw query text, K and the floor, to /search-vectors.
        self::assertCount(1, $calls);
        self::assertSame('http://vps.test/search-vectors', $calls[0]['url']);
        self::assertSame(' macbook ', $calls[0]['q']);
        self::assertSame(50, $calls[0]['limit']);
        self::assertSame(0.82, $calls[0]['min_score']);
        self::assertArrayNotHasKey('customer_id', $calls[0]);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string, 2?: string}> */
    public static function vpsFailures(): iterable
    {
        yield 'server error' => [['status' => 500], 'http_500'];
        yield 'no vectors loaded' => [['status' => 503], 'http_503'];
        yield 'malformed body' => [['malformed' => true], 'malformed_response'];
        yield 'wrong token' => [[], 'http_401', 'not-the-vps-token-000'];
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('vpsFailures')]
    public function testVpsFailureFallsBackToKeywordOnlyAndIsLogged(
        array $scenario,
        string $reason,
        string $token = FakeVps::TOKEN
    ): void {
        $calls = [];
        $vps = FakeVps::client(
            $scenario + ['products' => [1011 => self::QUERY], 'queries' => ['*' => self::QUERY]],
            $calls,
            $token
        );

        $result = $this->controller($vps)->search(['q' => 'macbook']);

        self::assertSame([1007], $result['product_ids']);
        self::assertArrayNotHasKey('cosine_scores', $result);
        self::assertSame(0, (int) $this->lastLog()['had_vector']);
        self::assertStringContainsString(
            "search: semantic tier unavailable ({$reason}); served keyword-only results",
            (string) file_get_contents($this->errorLog)
        );
    }

    public function testUnreachableOrTimedOutTransportFallsBack(): void
    {
        foreach (['timeout', 'unreachable: Connection refused'] as $error) {
            $vps = new VpsClient('http://vps.test', 't', 300, static fn (): string => $error);

            $result = $this->controller($vps)->search(['q' => 'macbook']);

            self::assertSame([1007], $result['product_ids']);
            self::assertStringContainsString("({$error})", (string) file_get_contents($this->errorLog));
        }
    }

    public function testSemanticIdMissingFromTableIsDropped(): void
    {
        // 9999 is not in the products table (VPS vectors newer than the
        // catalog) and is the top cosine hit; it must not surface.
        $result = $this->controller($this->vps([
            9999 => [1.0, 0.0, 0.0, 0.0],
            1011 => [0.9, 0.43589, 0.0, 0.0],
        ]))->search(['q' => 'macbook']);

        self::assertSame([1007, 1011], $result['product_ids']);
    }

    public function testNoKeywordHitAndNoNeighbourAboveTheFloorReturnsEmpty(): void
    {
        // A "ball bearing" query: no keyword match anywhere, and the nearest
        // vector (0.6) is below the floor. Nothing is padded in.
        $result = $this->controller($this->vps([
            1009 => [0.6, 0.8, 0.0, 0.0],
            1013 => [0.0, 0.0, 1.0, 0.0],
        ]))->search(['q' => 'بلبرینگ']);

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['product_ids']);
        self::assertSame([], $result['cosine_scores']);
        self::assertNull($result['did_you_mean']);
    }

    public function testFloorIsAppliedToNeighbours(): void
    {
        $products = [
            1011 => [0.9, 0.43589, 0.0, 0.0], // cosine 0.9
            1013 => [0.6, 0.8, 0.0, 0.0],     // cosine 0.6
        ];

        $strict = $this->controller($this->vps($products), 0.82)->search(['q' => 'بلبرینگ']);
        self::assertSame([1011], $strict['product_ids']);
        self::assertEqualsWithDelta(0.9, $strict['cosine_scores'][0], 1e-4);

        $loose = $this->controller($this->vps($products), 0.4)->search(['q' => 'بلبرینگ']);
        self::assertEqualsCanonicalizing([1011, 1013], $loose['product_ids']); // business ranking orders them
    }

    public function testFloorHoldsAgainstAVpsThatIgnoresMinScore(): void
    {
        // An older VPS without min_score answers with its own floor only.
        $vps = new VpsClient('http://vps.test', 't', 300, static fn (): array => ['status' => 200, 'body' =>
            '{"results":[{"product_id":1011,"score":0.9},{"product_id":1013,"score":0.6}]}']);

        self::assertSame([1011], $this->controller($vps, 0.82)->search(['q' => 'بلبرینگ'])['product_ids']);
    }

    public function testKeywordHitsOutrankAHighlyBoostedSemanticNeighbour(): void
    {
        // "sony" keyword hits: 1009, 1011. 1001 (the catalog's most popular,
        // in stock) is the exact cosine match but not a keyword hit. Plain RRF
        // ranked it above 1011; it must come after both keyword hits.
        $result = $this->controller($this->vps([
            1001 => [1.0, 0.0, 0.0, 0.0],
            1009 => [0.9, 0.43589, 0.0, 0.0],
            1013 => [0.0, 0.0, 1.0, 0.0],
        ]))->search(['q' => 'sony']);

        self::assertSame([1009, 1011, 1001], $result['product_ids']);
        // Cosine per result, aligned with product_ids; the VPS did not return 1011.
        self::assertEqualsWithDelta(0.9, $result['cosine_scores'][0], 1e-4);
        self::assertNull($result['cosine_scores'][1]);
        self::assertEqualsWithDelta(1.0, $result['cosine_scores'][2], 1e-4);
    }

    public function testFromConfigBuildsTheVpsClientAndDefaultsTheFloorForBgeM3(): void
    {
        // A config without semantic_min_score gets the bge-m3 default (0.4):
        // a 0.6 neighbour is kept, a 0.3 one dropped.
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'rrf_k' => 60, 'stock_boost' => 0.1, 'popularity_boost' => 0.1,
            ],
            'paths'  => ['data' => sys_get_temp_dir() . '/no-bundle'],
        ];
        $calls = [];
        $result = SearchController::fromConfig($this->pdo, $config, $this->vps([
            1011 => [0.6, 0.8, 0.0, 0.0],
            1013 => [0.3, 0.95394, 0.0, 0.0],
        ], $calls))->search(['q' => 'بلبرینگ']);

        self::assertSame([1011], $result['product_ids']);
        self::assertSame(0.4, $calls[0]['min_score']);
        // No vps section (a config.php from before M18): keyword-only, no error.
        self::assertNull(VpsClient::fromConfig($config['vps'] ?? []));
        $keywordOnly = SearchController::fromConfig($this->pdo, $config)->search(['q' => 'بلبرینگ']);
        self::assertSame([], $keywordOnly['product_ids']);
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

        $result = $this->controller($this->vps([
            2002 => [1.0, 0.0, 0.0, 0.0],
            2001 => [0.85, 0.52678, 0.0, 0.0],
        ]))->search(['q' => 'دوال شاک']);

        self::assertSame([2001, 2002], $result['product_ids']);
    }

    /**
     * "bearing" catalog for the M11 description-only gate: 3001 names it in the
     * title; 3002 (a case fan, cosine 0.75), 3003 (cosine 0.9) and 3004 (not
     * returned by the VPS) mention it only in the description.
     *
     * @return array<int, list<float>>
     */
    private function seedBearingCatalog(): array
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (
            [
                [3001, 'Bearing puller tool', 'Steel puller'],
                [3002, 'Silent case fan 120mm', 'Quiet fan with bearing motor'],
                [3003, 'Wheel hub assembly', 'Includes a sealed bearing'],
                [3004, 'Cooler heatsink', 'Fluid bearing'],
            ] as [$id, $title, $desc]
        ) {
            $insert->execute([$id, $title, $desc, Normalizer::normalize($title), Normalizer::normalize($desc)]);
        }

        return [
            3001 => [0.1, 0.99499, 0.0, 0.0],  // cosine 0.1, title match
            3002 => [0.75, 0.66144, 0.0, 0.0], // cosine 0.75, description only
            3003 => [0.9, 0.43589, 0.0, 0.0],  // cosine 0.9, description only
        ];
    }

    public function testDescriptionOnlyMatchesTheVpsDidNotReturnAreDropped(): void
    {
        $result = $this->controller($this->vps($this->seedBearingCatalog()))->search(['q' => 'bearing']);

        // The VPS list is shorter than K, so everything it left out (3002 at
        // 0.75, 3004) is below the floor.
        self::assertSame(3001, $result['product_ids'][0]); // title match, low cosine: kept, first
        self::assertEqualsCanonicalizing([3001, 3003], $result['product_ids']);
    }

    public function testDescriptionOnlyMatchesAreKeptWhenTheVpsListIsFull(): void
    {
        // K = 1: the VPS returns only 3003; 3002 and 3004 may still clear the
        // floor further down, so there is no evidence against them.
        $result = $this->controller($this->vps($this->seedBearingCatalog()), 0.82, 1)
            ->search(['q' => 'bearing']);

        self::assertEqualsCanonicalizing([3001, 3002, 3003, 3004], $result['product_ids']);
    }

    public function testLowCosineSpecMatchIsKeptAboveDescriptionOnly(): void
    {
        // M13: a spec (attribute / feature title) match is high-signal, so the
        // description-only gate does not apply to it, and it leads the
        // description-only hits even when they are closer in vector space.
        $vectors = $this->seedBearingCatalog();
        (new ProductLoader($this->pdo, 'products'))->load([[
            'product_id'  => 3005,
            'title'       => 'Wheel hub kit',
            'description' => 'Steel hub',
            'specs'       => 'Type: bearing | Size: 6202',
        ]]);
        $vectors[3005] = [0.1, 0.99499, 0.0, 0.0]; // spec match, far below the floor

        $result = $this->controller($this->vps($vectors))->search(['q' => 'bearing']);

        self::assertSame([3001, 3005], array_slice($result['product_ids'], 0, 2));
        self::assertEqualsCanonicalizing([3001, 3005, 3003], $result['product_ids']);
    }

    public function testDescriptionOnlyMatchesAreKeptWithoutTheSemanticTier(): void
    {
        $this->seedBearingCatalog();

        foreach ([null, FakeVps::client(['status' => 500])] as $vps) {
            $result = $this->controller($vps)->search(['q' => 'bearing']);

            self::assertSame(3001, $result['product_ids'][0]);
            self::assertEqualsCanonicalizing([3001, 3002, 3003, 3004], $result['product_ids']);
        }
    }

    public function testQueryMatchingOnlyLowCosineDescriptionsReturnsEmpty(): void
    {
        // Real-catalog "بلبرینگ": no product is a bearing, the fans that mention
        // one in their specs are far in vector space. Clean "no results".
        $insert = $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([[4001, 'فن کیس ۱۲۰', 'فن کیس با بلبرینگ'], [4002, 'فن پردازنده', 'دارای بلبرینگ']] as [$id, $t, $d]) {
            $insert->execute([$id, $t, $d, Normalizer::normalize($t), Normalizer::normalize($d)]);
        }

        $keywordOnly = $this->controller(null)->search(['q' => 'بلبرینگ']);
        self::assertEqualsCanonicalizing([4001, 4002], $keywordOnly['product_ids']);

        $result = $this->controller($this->vps([
            4001 => [0.75, 0.66144, 0.0, 0.0],
            4002 => [0.7, 0.71414, 0.0, 0.0],
        ]))->search(['q' => 'بلبرینگ']);

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['product_ids']);
    }

    public function testDescriptionOnlyGateCanBeDisabledInConfig(): void
    {
        $vectors = $this->seedBearingCatalog();
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'rrf_k' => 60, 'stock_boost' => 0.1, 'popularity_boost' => 0.1,
                'semantic_min_score' => 0.82, 'desc_only_needs_semantic' => false,
            ],
            'paths'  => ['data' => sys_get_temp_dir() . '/no-bundle'],
        ];
        $search = fn (array $config): array =>
            SearchController::fromConfig($this->pdo, $config, $this->vps($vectors))->search(['q' => 'bearing']);

        self::assertContains(3002, $search($config)['product_ids']);

        unset($config['search']['desc_only_needs_semantic']); // older config.php: gate on by default
        self::assertNotContains(3002, $search($config)['product_ids']);
    }

    public function testQVectorFromAnOldClientIsIgnored(): void
    {
        // Browsers no longer embed (M18); a stale storefront still sending
        // q_vector gets the same answer, and the VPS is asked as usual.
        $calls = [];
        $result = $this->controller($this->vps([1011 => self::QUERY], $calls))
            ->search(['q' => 'macbook', 'q_vector' => [0.0, 1.0, 0.0, 0.0]]);

        self::assertSame([1007, 1011], $result['product_ids']);
        self::assertCount(1, $calls);
        self::assertArrayNotHasKey('q_vector', $calls[0]);
    }

    public function testBlankQueryDoesNotCallTheVps(): void
    {
        $calls = [];
        $result = $this->controller($this->vps([1011 => self::QUERY], $calls))->search(['q' => '   ']);

        self::assertSame([], $result['product_ids']);
        self::assertSame([], $calls);
    }
}
