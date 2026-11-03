<?php

declare(strict_types=1);

namespace App\Tests;

use App\Analytics;
use App\Cache;
use App\CacheWarmer;
use App\Config;
use App\GoldenEval;
use App\SearchController;
use App\VpsClient;
use InvalidArgumentException;

/** tools/warm-cache.php's engine: same cache key as a shopper, no log row, never a degraded entry. */
final class CacheWarmerTest extends DatabaseTestCase
{
    private const LIMIT = GoldenEval::STOREFRONT_DEPTH;

    private FakeRedis $redis;
    private Cache $cache;
    /** @var list<array<string, mixed>> every request the fake VPS received */
    private array $calls = [];
    /** @var array<string, int> failures left per query text; a negative number fails forever */
    private array $failing = [];
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        $this->redis = new FakeRedis();
        $this->cache = new Cache($this->redis, 'search:cache:', 300, 900, 3, 60);
        $this->calls = [];
        $this->failing = [];
        $this->slept = [];
    }

    private function vps(): VpsClient
    {
        $products = [1011 => [1.0, 0.0, 0.0, 0.0], 1007 => [0.8, 0.6, 0.0, 0.0]];

        return new VpsClient('http://vps.test', FakeVps::TOKEN, 300, function (string $url, array $headers, string $body) use ($products): array {
            $request = (array) json_decode($body, true);
            $this->calls[] = $request;
            $text = (string) $request['q'];
            $left = $this->failing[$text] ?? 0;
            if ($left !== 0) {
                $this->failing[$text] = $left > 0 ? $left - 1 : $left;

                return ['status' => 500, 'body' => '{}'];
            }

            return FakeVps::respond(
                ['products' => $products, 'queries' => ['*' => [1.0, 0.0, 0.0, 0.0]]],
                'Bearer ' . FakeVps::TOKEN,
                $body
            );
        });
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = Config::defaults();
        $config['paths']['data'] = sys_get_temp_dir() . '/no-bundle';
        $config['db']['products_table'] = 'products';
        $config['db']['search_logs_table'] = 'search_logs';
        $config['search']['semantic_min_score'] = 0.5;

        return $config;
    }

    private function warmer(int $maxFailures = 5, int $sleepMs = 250): CacheWarmer
    {
        $controller = SearchController::fromConfig($this->pdo, $this->config(), $this->vps(), $this->cache, true, false);

        return new CacheWarmer($controller, self::LIMIT, $sleepMs, $maxFailures, function (int $ms): void {
            $this->slept[] = $ms;
        });
    }

    private function shopper(): SearchController
    {
        return SearchController::fromConfig($this->pdo, $this->config(), $this->vps(), $this->cache);
    }

    private function logRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn();
    }

    public function testAWarmedQueryIsTheShoppersCacheHitAndLeavesNoLogRow(): void
    {
        $summary = $this->warmer()->run(['macbook'], false);

        self::assertSame([1, 0, 0, 0], [$summary['warmed'], $summary['cached'], $summary['degraded'], $summary['failed']]);
        self::assertSame(0, $this->logRows());
        self::assertCount(1, $this->calls);
        // Only the result entry: a warm-up does not count as a lookup for the adaptive TTL.
        self::assertCount(1, $this->redis->data);
        self::assertNotContains('INCR', $this->redis->log);

        $served = $this->shopper()->search(['q' => 'macbook', 'limit' => self::LIMIT]);

        self::assertCount(1, $this->calls, 'the shopper was served from the cache');
        self::assertSame([1007, 1011], $served['product_ids']);
        self::assertSame(1, (int) $this->pdo->query('SELECT cache_hit FROM search_logs')->fetchColumn());
    }

    public function testOnlyMissingIsTheDefaultAndForceRecomputes(): void
    {
        $this->warmer()->run(['macbook'], false);

        $again = $this->warmer()->run(['macbook', 'iphone'], false);
        self::assertSame([1, 1], [$again['warmed'], $again['cached']]);
        self::assertCount(2, $this->calls, 'the cached query cost no VPS call');

        $forced = $this->warmer()->run(['macbook'], true);
        self::assertSame([1, 0], [$forced['warmed'], $forced['cached']]);
        self::assertCount(3, $this->calls);
    }

    public function testItSleepsOnlyAfterQueriesThatReachedTheVps(): void
    {
        $this->warmer()->run(['macbook'], false);
        $this->slept = [];

        $this->warmer(5, 40)->run(['macbook', 'iphone', 'ipad'], false);

        self::assertSame([40, 40], $this->slept);
    }

    public function testADegradedResultIsNotCachedAndIsRetriedOnceAtTheEnd(): void
    {
        $this->failing = ['macbook' => 1];

        $summary = $this->warmer()->run(['macbook', 'iphone'], false);

        self::assertSame([2, 0, 0], [$summary['warmed'], $summary['degraded'], $summary['failed']]);
        self::assertSame(['macbook', 'iphone', 'macbook'], array_column($this->calls, 'q'));
        self::assertCount(2, $this->redis->data);
    }

    public function testAQueryThatStaysDegradedIsReportedAndNeverCached(): void
    {
        $this->failing = ['macbook' => -1];

        $summary = $this->warmer()->run(['macbook', 'iphone'], false);

        self::assertSame([1, 1], [$summary['warmed'], $summary['degraded']]);
        self::assertSame(['macbook'], $summary['degraded_queries']);
        self::assertSame(['macbook', 'iphone', 'macbook'], array_column($this->calls, 'q'), 'one retry only');
        self::assertCount(1, $this->redis->data);
        self::assertFalse($summary['stopped']);
    }

    public function testItStopsAfterKConsecutiveVpsFailuresWithoutRetrying(): void
    {
        foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $query) {
            $this->failing[$query] = -1;
        }
        $lines = [];

        $summary = $this->warmer(3)->run(['a1', 'a2', 'a3', 'a4', 'a5'], false, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertTrue($summary['stopped']);
        self::assertSame(['a1', 'a2', 'a3'], array_column($this->calls, 'q'));
        self::assertSame([3, 2], [$summary['degraded'], $summary['not_attempted']]);
        self::assertMatchesRegularExpression('/stopping after 3 consecutive VPS failures/', implode("\n", $lines));
    }

    public function testASuccessResetsTheFailureStreak(): void
    {
        $this->failing = ['a1' => -1, 'a2' => -1, 'a4' => -1, 'a5' => -1];

        $summary = $this->warmer(3)->run(['a1', 'a2', 'a3', 'a4', 'a5'], false);

        self::assertFalse($summary['stopped']);
        self::assertSame([1, 4], [$summary['warmed'], $summary['degraded']]);
    }

    public function testAProgressLineEveryFiftyQueries(): void
    {
        $queries = [];
        for ($i = 1; $i <= 100; $i++) {
            $queries[] = 'q' . $i;
        }
        $lines = [];

        $this->warmer(5, 0)->run($queries, false, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame(
            ['progress 50/100: warmed 50, already cached 0, degraded 0, failed 0',
                'progress 100/100: warmed 100, already cached 0, degraded 0, failed 0'],
            $lines
        );
    }

    public function testDryRunSearchesAndWritesNothing(): void
    {
        $this->warmer()->run(['macbook'], false);
        $before = $this->redis->data;
        $this->calls = [];

        $plan = $this->warmer()->plan(['macbook', 'iphone'], false);

        self::assertSame([['query' => 'macbook', 'cached' => true], ['query' => 'iphone', 'cached' => false]], $plan);
        self::assertSame([], $this->calls);
        self::assertSame($before, $this->redis->data);
        self::assertSame([false, false], array_column($this->warmer()->plan(['macbook', 'iphone'], true), 'cached'));
    }

    public function testQueriesComeMostSearchedFirstWithoutTheRowsTheAnalyticsIgnore(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO search_logs (raw_q, normalized_q, is_suspicious, is_duplicate, is_burst, page, source, ts)'
            . ' VALUES (:q, :q, :s, :d, :b, :p, :src, NOW() - INTERVAL :age DAY)'
        );
        $row = static fn (string $q, int $times, array $o = []): array => [$q, $times, $o + [
            's' => 0, 'd' => 0, 'b' => 0, 'p' => 1, 'src' => 'page', 'age' => 1,
        ]];
        foreach ([
            $row('ps5', 3), $row('ps4', 2), $row('zelda', 5, ['d' => 1]), $row('drop table', 4, ['s' => 1]),
            $row('gifts', 4, ['b' => 1]), $row('paging', 4, ['p' => 2]), $row('reload', 4, ['src' => 'filter']),
            $row('old', 9, ['age' => 60]), $row('mario', 1),
        ] as [$q, $times, $o]) {
            for ($i = 0; $i < $times; $i++) {
                $insert->execute(['q' => $q] + $o);
            }
        }
        $analytics = new Analytics($this->pdo, 'search_logs');

        $top = array_column($analytics->topQueries($analytics->since('30d'), 3), 'query');

        self::assertSame(['ps5', 'ps4', 'mario'], $top);
    }

    public function testTheQueryFileIsAppendedAndEachQueryAppearsOnce(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'warm_');
        file_put_contents($path, "\xEF\xBB\xBFnormalized_q,searches,last_seen\nps5,9,2026-01-01\n\"زلدا, نفس\",2,2026-01-02\nPS5 ,1,2026-01-03\n,1,x\n");

        $fromFile = CacheWarmer::readQueryFile($path);
        @unlink($path);

        self::assertSame(['ps5', 'زلدا, نفس', 'PS5'], $fromFile);
        self::assertSame(['mario', 'ps5', 'زلدا, نفس'], CacheWarmer::unique(['mario', 'ps5'], $fromFile));
    }

    public function testAFileWithoutTheColumnIsRefused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'warm_');
        file_put_contents($path, "query,n\nps5,1\n");

        try {
            $this->expectException(InvalidArgumentException::class);
            CacheWarmer::readQueryFile($path);
        } finally {
            @unlink($path);
        }
    }
}
