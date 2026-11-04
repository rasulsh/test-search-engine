<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- long literals and assertion messages in a fixture test

namespace App\Tests;

use App\AdminOps;
use App\Audit;
use App\Cache;
use App\Config;
use App\Reload;
use App\VpsClient;
use App\WarmQueue;

/** The pending flag: written by each trigger, coalesced, never written by a failed operation. */
final class WarmQueueTest extends DatabaseTestCase
{
    private const PREFIX = 'search:cache:';

    private string $storage = '';
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        $this->pdo->exec('DELETE FROM admin_audit');
        $this->storage = TempTree::path('warm-queue');
        mkdir($this->storage, 0777, true);
        $this->dirs[] = $this->storage;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            TempTree::remove($dir);
        }
        $this->dirs = [];
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): array
    {
        return Config::merge(array_replace_recursive([
            'paths' => ['storage' => $this->storage],
            'redis' => ['enabled' => true],
        ], $overrides));
    }

    private function ops(?FakeRedis $redis, ?VpsClient $vps = null, array $overrides = []): AdminOps
    {
        $cache = $redis === null ? null : new Cache($redis, self::PREFIX, 60);

        return new AdminOps($this->config($overrides), $this->pdo, new Audit($this->pdo, 'admin_audit', 180), '203.0.113.5', $cache, $vps);
    }

    private function queue(): WarmQueue
    {
        return new WarmQueue($this->storage);
    }

    /** @return list<array<string, mixed>> */
    private function warmAudit(): array
    {
        return array_map(
            static fn (array $row): array => json_decode($row['detail'], true),
            array_reverse((new Audit($this->pdo, 'admin_audit', 180))->latest([Audit::WARM_REQUESTED], 10))
        );
    }

    private function vps(array|string $response): VpsClient
    {
        return new VpsClient('http://vps.test/', 'vps-token', 300, static fn (): array|string => $response, 45000);
    }

    public function testRequestWritesTheFlagAndCoalescesKeepingTheFirstTimeAndAppendingReasons(): void
    {
        $queue = $this->queue();
        self::assertFalse($queue->hasFlag());

        self::assertTrue($queue->request('reload'));
        $first = $queue->pending();
        self::assertSame(['reload'], $first['reasons']);
        self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $first['requested_at']));

        // A later request keeps the first requested_at (the age counts from the oldest wait).
        file_put_contents($this->storage . '/warm-cache.pending', json_encode(['requested_at' => '2026-01-01T00:00:00+00:00', 'reasons' => ['reload']]));
        foreach (['cache_flush', 'cache_flush', 'vps_reload', 'manual', 'rollback', 'extra'] as $reason) {
            $queue->request($reason);
        }
        $merged = $queue->pending();
        self::assertSame('2026-01-01T00:00:00+00:00', $merged['requested_at']);
        self::assertSame(['reload', 'cache_flush', 'vps_reload', 'manual', 'rollback'], $merged['reasons'], 'no repeats, at most five');
        self::assertSame([], glob($this->storage . '/*.tmp'), 'atomic write leaves no temp file');
    }

    public function testACorruptFlagIsReplacedNotTrusted(): void
    {
        file_put_contents($this->storage . '/warm-cache.pending', '{not json');
        self::assertNull($this->queue()->pending());

        self::assertTrue($this->queue()->request('manual'));
        self::assertSame(['manual'], $this->queue()->pending()['reasons']);
    }

    public function testAnUnwritableStorageFailsQuietly(): void
    {
        $queue = new WarmQueue($this->storage . '/missing/dir');

        self::assertFalse(@$queue->request('reload'));
        self::assertFalse($queue->hasFlag());
    }

    public function testASuccessfulPanelFlushFlagsAWarmUpAndAuditsIt(): void
    {
        $redis = new FakeRedis();
        $redis->data = [self::PREFIX . 'a' => '1'];

        $result = $this->ops($redis)->flushCache();

        self::assertTrue($result['ok']);
        self::assertTrue($result['warm_requested']);
        self::assertSame(['cache_flush'], $this->queue()->pending()['reasons']);
        self::assertSame([['ok' => true, 'for' => 'cache_flush']], $this->warmAudit());
        self::assertStringNotContainsString('token', (string) json_encode($this->warmAudit()));
    }

    public function testAFailedFlushOrRedisOffWritesNoFlag(): void
    {
        $down = new FakeRedis();
        $down->down = true;
        $failed = $this->ops($down)->flushCache();
        self::assertFalse($failed['ok']);
        self::assertFalse($this->queue()->hasFlag());

        // Redis off: nothing to warm, so no flag even though the panel asks.
        $off = $this->ops(null, null, ['redis' => ['enabled' => false]])->flushCache();
        self::assertFalse($off['ok']);
        self::assertFalse($this->queue()->hasFlag());
        self::assertSame([], $this->warmAudit());
    }

    public function testWarmAutoOffFlagsNothingAutomaticallyButTheButtonStillWorks(): void
    {
        $ops = $this->ops(new FakeRedis(), null, ['warm' => ['auto' => false]]);

        $flush = $ops->flushCache();
        self::assertTrue($flush['ok']);
        self::assertArrayNotHasKey('warm_requested', $flush);
        self::assertFalse($this->queue()->hasFlag());

        $now = $ops->warmNow();
        self::assertTrue($now['ok']);
        self::assertSame(['manual'], $this->queue()->pending()['reasons']);
        self::assertSame('warm_requested', $now['action']);
    }

    public function testWarmNowNeedsTheResultCacheAndAWritableStorage(): void
    {
        $off = $this->ops(null, null, ['redis' => ['enabled' => false]])->warmNow();
        self::assertSame('redis_off', $off['reason']);

        $broken = (new AdminOps(
            $this->config(['paths' => ['storage' => $this->storage . '/missing']]),
            $this->pdo,
            new Audit($this->pdo, 'admin_audit', 180),
            '203.0.113.5'
        ))->warmNow();
        self::assertSame('warm_flag_unwritable', $broken['reason']);
        self::assertFalse($broken['ok']);
    }

    public function testASuccessfulVpsReloadFlushesTheCacheThenFlagsAWarmUp(): void
    {
        $redis = new FakeRedis();
        $redis->data = [self::PREFIX . 'a' => '1', 'other:x' => 'keep'];
        $body = (string) json_encode(['ok' => true, 'count' => 10, 'model' => 'm', 'built_at' => '2026-10-20']);

        $result = $this->ops($redis, $this->vps(['status' => 200, 'body' => $body]))->vpsReload();

        self::assertTrue($result['ok']);
        self::assertTrue($result['cache_flushed']);
        self::assertTrue($result['warm_requested']);
        self::assertSame(['other:x' => 'keep'], $redis->data, 'only the result cache keys went');
        self::assertSame(['vps_reload'], $this->queue()->pending()['reasons']);
        $ids = array_map(static fn (array $r): string => $r['action'], (new Audit($this->pdo, 'admin_audit', 180))->latest([Audit::VPS_RELOAD, Audit::WARM_REQUESTED], 5));
        self::assertSame(['vps_reload', 'warm_requested'], $ids, 'newest first: the flag is audited inside the reload, which then writes its own row');
    }

    public function testAFailedVpsReloadFlushesNothingAndFlagsNothing(): void
    {
        $redis = new FakeRedis();
        $redis->data = [self::PREFIX . 'a' => '1'];

        $result = $this->ops($redis, $this->vps(['status' => 422, 'body' => '{"ok":false,"error":"model mismatch"}']))->vpsReload();

        self::assertFalse($result['ok']);
        self::assertSame([self::PREFIX . 'a' => '1'], $redis->data);
        self::assertFalse($this->queue()->hasFlag());
        self::assertSame([], $this->warmAudit());
    }

    public function testASuccessfulBundleReloadFlagsAWarmUpAndAFailedOneDoesNot(): void
    {
        $data = TempTree::path('warm-data');
        $this->dirs[] = $data;
        $this->dirs[] = $data . '_old';
        $config = $this->config(['paths' => ['data' => $data, 'data_incoming' => TempTree::path('warm-in')], 'model' => ['normalization_version' => 2]]);
        $incoming = $config['paths']['data_incoming'];
        $this->dirs[] = $incoming;
        mkdir($incoming);
        $this->pdo->exec('CREATE TABLE products_new LIKE products');
        $this->pdo->exec("INSERT INTO products_new (product_id, title, description, normalized_title, normalized_desc) VALUES (1, 't', 'd', 't', 'd')");

        // A count that disagrees with the staging rows: refused, nothing flagged.
        file_put_contents($incoming . '/meta.json', json_encode(['normalization_version' => 2, 'count' => 9, 'built_at' => '2026-01-01T00:00:00+00:00']));
        try {
            (new Reload($this->pdo, $config, new Cache(new FakeRedis(), self::PREFIX, 60)))->run();
            self::fail('the bundle should have been refused');
        } catch (\App\ReloadException) {
        }
        self::assertFalse($this->queue()->hasFlag());

        mkdir($data);
        file_put_contents($data . '/meta.json', json_encode(['normalization_version' => 2, 'count' => 0, 'built_at' => '2025-01-01T00:00:00+00:00']));
        file_put_contents($incoming . '/meta.json', json_encode(['normalization_version' => 2, 'count' => 1, 'built_at' => '2026-01-01T00:00:00+00:00']));
        $result = (new Reload($this->pdo, $config, new Cache(new FakeRedis(), self::PREFIX, 60)))->run();

        self::assertTrue($result['warm_requested']);
        self::assertSame(['reload'], $this->queue()->pending()['reasons']);
        self::assertSame([['ok' => true, 'for' => 'reload']], $this->warmAudit());

        // A rollback also empties the cache, so it is flagged too (coalesced into the same flag).
        $rolled = (new Reload($this->pdo, $config, new Cache(new FakeRedis(), self::PREFIX, 60)))->rollback();
        self::assertTrue($rolled['warm_requested']);
        self::assertSame(['reload', 'rollback'], $this->queue()->pending()['reasons']);
    }

    public function testAnUnwritableFlagNeverFailsTheOperation(): void
    {
        $result = $this->ops(new FakeRedis(), null, ['paths' => ['storage' => $this->storage . '/missing']])->flushCache();

        self::assertTrue($result['ok'], 'the flush stands');
        self::assertArrayNotHasKey('warm_requested', $result);
    }

    public function testTheStateIsIdleWaitingOrRunning(): void
    {
        $queue = $this->queue();
        self::assertSame('idle', $queue->describe()['state']);

        $queue->request('reload');
        self::assertSame('waiting', $queue->describe()['state']);

        $lock = fopen($this->storage . '/' . WarmQueue::LOCK_FILE, 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        self::assertSame('running', $queue->describe()['state']);
        flock($lock, LOCK_UN);
        fclose($lock);
        self::assertSame('waiting', $queue->describe()['state'], 'the lock is not left behind');
    }
}
