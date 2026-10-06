<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cache;
use App\Config;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'cachelog_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    public function testDisabledByDefaultAndUntilEnabled(): void
    {
        self::assertNull(Cache::fromConfig(Config::defaults()));
        self::assertNull(Cache::fromConfig(Config::merge(['redis' => ['enabled' => false]])));
        self::assertInstanceOf(Cache::class, Cache::fromConfig(Config::merge(['redis' => ['enabled' => true]])));
    }

    public function testKeyDependsOnEveryResultAffectingInput(): void
    {
        $cache = new Cache(new FakeRedis(), 'p:', 60);
        $key = $cache->key('macbook', 20, true);

        self::assertSame($key, $cache->key('macbook', 20, true));
        self::assertStringStartsWith('p:', $key);
        self::assertNotSame($key, $cache->key('macbook pro', 20, true));
        self::assertNotSame($key, $cache->key('macbook', 10, true));
        self::assertNotSame($key, $cache->key('macbook', null, true));
        self::assertNotSame($key, $cache->key('macbook', 20, false));
    }

    public function testSetStoresJsonWithTheTtlAndGetReturnsIt(): void
    {
        $redis = new FakeRedis();
        $cache = new Cache($redis, 'p:', 120);
        $value = ['response' => ['product_ids' => [3, 1], 'q' => "\u{0645}"], 'had_vector' => true];

        self::assertNull($cache->get('p:k'));
        $cache->set('p:k', $value);

        self::assertSame(120, $redis->ttls['p:k']);
        self::assertSame($value, $cache->get('p:k'));
    }

    public function testCorruptValueIsAMiss(): void
    {
        $redis = new FakeRedis();
        $redis->data['p:k'] = 'not json';

        self::assertNull((new Cache($redis, 'p:', 60))->get('p:k'));
    }

    public function testRedisDownNeverThrows(): void
    {
        $redis = new FakeRedis();
        $redis->down = true;
        $cache = new Cache($redis, 'p:', 60);

        self::assertNull($cache->get('p:k'));
        $cache->set('p:k', ['a' => 1]);
        self::assertFalse($cache->flush());
        self::assertStringContainsString('cache read failed', (string) file_get_contents($this->errorLog));
        self::assertStringContainsString('cache flush failed', (string) file_get_contents($this->errorLog));
    }

    public function testFlushDropsOnlyThePrefixKeyspace(): void
    {
        $redis = new FakeRedis();
        $cache = new Cache($redis, 'search:cache:', 60);
        $cache->set('search:cache:a', ['a' => 1]);
        $cache->set('search:cache:b', ['b' => 1]);
        $redis->data['other:x'] = 'keep';

        self::assertTrue($cache->flush());
        self::assertSame(['other:x' => 'keep'], $redis->data);
    }
}
