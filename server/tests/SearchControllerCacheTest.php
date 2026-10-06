<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cache;
use App\Config;
use App\Identifier;
use App\Keyword;
use App\Logger;
use App\Ranker;
use App\SearchController;
use App\Speller;
use App\VpsClient;

/**
 * The result cache in the /search flow (M26): a hit returns what the miss
 * computed, skips the recompute, is logged, never serves a degraded or debug
 * response, and a Redis outage is just a miss.
 */
final class SearchControllerCacheTest extends DatabaseTestCase
{
    private const QUERY = [1.0, 0.0, 0.0, 0.0];

    private FakeRedis $redis;
    private Cache $cache;
    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        $this->redis = new FakeRedis();
        $this->cache = new Cache($this->redis, 'search:cache:', 300);
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'cachectl_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    private function controller(?VpsClient $vps = null, ?Cache $cache = null): SearchController
    {
        $pdo = $this->pdo;
        $signalsProvider = static function (array $ids) use ($pdo): array {
            if ($ids === []) {
                return [];
            }
            $stmt = $pdo->prepare('SELECT product_id, stock, popularity FROM ' . Identifier::quote('products')
                . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute(array_values($ids));
            $signals = [];
            foreach ($stmt as $row) {
                $signals[(int) $row['product_id']] = [
                    'stock' => (int) $row['stock'],
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
            new Ranker(0.1, 0.1),
            $signalsProvider,
            100,
            20,
            0.5,
            3,
            $cache ?? $this->cache
        );
    }

    /** @return array<string, mixed> */
    private function lastLog(): array
    {
        return (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testMissStoresAndHitReturnsTheSameResultWithoutRecomputing(): void
    {
        $calls = [];
        $vps = FakeVps::client([
            'products' => [1011 => [1.0, 0.0, 0.0, 0.0], 1007 => [0.8, 0.6, 0.0, 0.0]],
            'queries' => ['*' => self::QUERY],
        ], $calls);
        $controller = $this->controller($vps);

        $miss = $controller->search(['q' => 'macbook']);
        self::assertCount(1, $calls);
        self::assertCount(1, $this->redis->data);
        self::assertSame(300, array_values($this->redis->ttls)[0]);
        self::assertSame(0, (int) $this->lastLog()['cache_hit']);

        // The catalog changes under the cache: a hit must not notice (no recompute).
        $this->pdo->exec('DELETE FROM products');
        $hit = $controller->search(['q' => 'macbook']);

        self::assertSame($miss, $hit);
        self::assertSame([1007, 1011], $hit['product_ids']);
        self::assertCount(1, $calls); // the VPS was not asked again
        $log = $this->lastLog();
        self::assertSame(1, (int) $log['cache_hit']);
        self::assertSame(1, (int) $log['had_vector']);
        self::assertSame('hybrid', $log['tier']);
        self::assertSame('1007,1011', $log['top_ids']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }

    public function testNormalizedEquivalentQueriesShareAnEntryAndKeepTheirOwnRawText(): void
    {
        $controller = $this->controller();
        $controller->search(['q' => 'macbook']);

        $hit = $controller->search(['q' => '  MacBook ']);

        self::assertCount(1, $this->redis->data);
        self::assertSame('  MacBook ', $hit['query']['raw']);
        self::assertSame('macbook', $hit['query']['normalized']);
        self::assertSame([1007], $hit['product_ids']);
        self::assertSame(1, (int) $this->lastLog()['cache_hit']);
        self::assertSame('  MacBook ', $this->lastLog()['raw_q']);
    }

    public function testHitMatchesAnUncachedControllerForSuggestionsToo(): void
    {
        $cached = $this->controller();
        $plain = $this->controller(null, new Cache(new FakeRedis(), 'x:', 1));
        $uncached = (new SearchController(
            new Keyword($this->pdo, 'products', 3, 20),
            new Logger($this->pdo, 'search_logs'),
            fn (): Speller => Speller::fromProducts($this->pdo, 'products')
        ));

        $cached->search(['q' => 'macbok']);
        $hit = $cached->search(['q' => 'macbok']);

        self::assertSame($uncached->search(['q' => 'macbok']), $hit);
        self::assertSame($plain->search(['q' => 'macbok']), $hit);
    }

    public function testLimitIsPartOfTheKey(): void
    {
        $controller = $this->controller();
        $one = $controller->search(['q' => 'apple', 'limit' => 1]);
        $all = $controller->search(['q' => 'apple', 'limit' => 20]);

        self::assertCount(2, $this->redis->data);
        self::assertSame(1, $one['count']);
        self::assertGreaterThan(1, $all['count']);
    }

    public function testDegradedSemanticTierIsNotCached(): void
    {
        $down = new VpsClient('http://vps.test', 't', 300, static fn (): string => 'timeout');
        $this->controller($down)->search(['q' => 'macbook']);
        self::assertSame([], $this->redis->data);

        $up = FakeVps::client(['products' => [1011 => self::QUERY], 'queries' => ['*' => self::QUERY]]);
        $this->controller($up)->search(['q' => 'macbook']);
        self::assertCount(1, $this->redis->data);
    }

    public function testKeywordOnlyConfigurationIsCachedAndKeyedApartFromHybrid(): void
    {
        $this->controller(null)->search(['q' => 'macbook']);
        $keywordKey = array_key_first($this->redis->data);
        $up = FakeVps::client(['products' => [1011 => self::QUERY], 'queries' => ['*' => self::QUERY]]);
        $this->controller($up)->search(['q' => 'macbook']);

        self::assertCount(2, $this->redis->data);
        self::assertNotNull($keywordKey);
    }

    public function testDebugIsNeverCachedOrServedFromTheCache(): void
    {
        $controller = $this->controller();
        $controller->search(['q' => 'macbook'], true);
        self::assertSame([], $this->redis->data);
        self::assertSame([], $this->redis->log);

        $controller->search(['q' => 'macbook']);
        $debug = $controller->search(['q' => 'macbook'], true);
        self::assertArrayHasKey('debug', $debug);
        self::assertSame(0, (int) $this->lastLog()['cache_hit']);
    }

    public function testEmptyQueryBypassesTheCache(): void
    {
        $this->controller()->search(['q' => '   ']);

        self::assertSame([], $this->redis->log);
    }

    public function testRedisDownBypassesTheCacheAndTheSearchStillAnswers(): void
    {
        $this->redis->down = true;
        $controller = $this->controller();

        $first = $controller->search(['q' => 'macbook']);
        $second = $controller->search(['q' => 'macbook']);

        self::assertSame([1007], $first['product_ids']);
        self::assertSame($first, $second);
        self::assertSame(0, (int) $this->lastLog()['cache_hit']);
        self::assertStringContainsString('cache read failed', (string) file_get_contents($this->errorLog));
    }

    public function testCorruptCachedEntryIsRecomputed(): void
    {
        $controller = $this->controller();
        $controller->search(['q' => 'macbook']);
        $key = (string) array_key_first($this->redis->data);
        $this->redis->data[$key] = '{"response": {"product_ids": "oops"}}';

        $result = $controller->search(['q' => 'macbook']);

        self::assertSame([1007], $result['product_ids']);
        self::assertSame(0, (int) $this->lastLog()['cache_hit']);
    }

    public function testLogTableFromBeforeM26GainsTheCacheHitColumn(): void
    {
        $this->pdo->exec('ALTER TABLE search_logs DROP COLUMN cache_hit');
        $controller = $this->controller();

        $controller->search(['q' => 'macbook']);
        $controller->search(['q' => 'macbook']);

        $log = $this->lastLog();
        self::assertSame(1, (int) $log['cache_hit']);
        self::assertSame('keyword_only', $log['tier']); // M21 columns kept, not dropped to the original
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }

    public function testFromConfigWiresTheCacheOnlyWhenEnabled(): void
    {
        $config = Config::defaults();
        $config['paths']['data'] = sys_get_temp_dir() . '/no-such-data-dir';

        SearchController::fromConfig($this->pdo, $config)->search(['q' => 'macbook']);
        self::assertSame([], $this->redis->data);

        SearchController::fromConfig($this->pdo, $config, null, $this->cache)->search(['q' => 'macbook']);
        self::assertCount(1, $this->redis->data);
    }
}
