<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Optional Redis cache of /search results (M26). Off by default; a missing,
 * slow or failing Redis only means a miss: nothing here throws, so the search
 * never fails because of the cache (same spirit as the VPS fallback).
 *
 * Keys are `<prefix><sha1 of the result-affecting inputs>`; the prefix is the
 * keyspace /reload flushes after a successful swap. Values are JSON.
 *
 * Adaptive TTL (M33): every lookup (hit or miss) bumps a per-query counter
 * `<prefix>freq:<hash>` (it expires `freq_window` after its creation, so counts
 * roll off). A write only reads it: a query looked up at least
 * `ttl_hot_threshold` times lives `ttl_hot` instead of `ttl`. Counting lookups,
 * not writes, matters because writes are themselves rationed by the TTL. The
 * counters sit under the prefix, so the /reload flush clears them too. A
 * counter error only means the base TTL.
 */
class Cache
{
    private RedisClient $redis;
    private string $prefix;
    private int $ttl;
    private int $hotTtl;
    private int $hotThreshold;
    private int $freqWindow;

    /** A $hotThreshold of 0 disables the adaptive tier (always the base TTL). */
    public function __construct(
        RedisClient $redis,
        string $prefix,
        int $ttlSeconds,
        int $hotTtlSeconds = 0,
        int $hotThreshold = 0,
        int $freqWindowSeconds = 0
    ) {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->ttl = max(1, $ttlSeconds);
        $this->hotTtl = max(1, $hotTtlSeconds);
        $this->hotThreshold = max(0, $hotThreshold);
        $this->freqWindow = max(1, $freqWindowSeconds);
    }

    /** @param array<string, mixed> $config the server config array; null when the cache is off */
    public static function fromConfig(array $config): ?self
    {
        $redis = $config['redis'];
        if (!$redis['enabled']) {
            return null;
        }

        return new self(
            new RedisClient(
                (string) $redis['host'],
                (int) $redis['port'],
                (string) $redis['auth'],
                (int) $redis['db'],
                (int) $redis['timeout_ms']
            ),
            (string) $redis['prefix'],
            (int) $redis['ttl'],
            (int) $redis['ttl_hot'],
            (int) $redis['ttl_hot_threshold'],
            (int) $redis['freq_window']
        );
    }

    /**
     * Everything that changes a result: the normalized query, the limit and
     * whether the semantic tier is on. (/search has no filters; add any new
     * result-affecting request parameter here.) The raw text, customer id,
     * `debug` and `with_details` do not change the cached product list.
     */
    public function key(string $normalizedQuery, ?int $limit, bool $semantic): string
    {
        return $this->prefix . sha1((string) json_encode([$normalizedQuery, $limit, $semantic]));
    }

    /** @return array<string, mixed>|null the stored value; null on a miss or any Redis error */
    public function get(string $key): ?array
    {
        try {
            $json = $this->redis->get($key);
            $value = $json === null ? null : json_decode($json, true);

            return is_array($value) ? $value : null;
        } catch (Throwable $e) {
            error_log('search: cache read failed: ' . $e->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value): void
    {
        try {
            $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $this->redis->set($key, $json, $this->ttlFor($key));
        } catch (Throwable $e) {
            error_log('search: cache write failed: ' . $e->getMessage());
        }
    }

    /** Counts one search for this result key (a hit or a miss); best effort, never throws. */
    public function registerLookup(string $key): void
    {
        if ($this->hotThreshold === 0) {
            return;
        }
        try {
            $counter = $this->counterKey($key);
            if ($this->redis->incr($counter) === 1) {
                $this->redis->expire($counter, $this->freqWindow);
            }
        } catch (Throwable $e) {
            error_log('search: cache counter failed: ' . $e->getMessage());
        }
    }

    /** Base TTL, or the hot TTL once the query's lookup counter reaches the threshold. */
    private function ttlFor(string $key): int
    {
        if ($this->hotThreshold === 0) {
            return $this->ttl;
        }
        try {
            $count = (int) $this->redis->get($this->counterKey($key));

            return $count >= $this->hotThreshold ? $this->hotTtl : $this->ttl;
        } catch (Throwable $e) {
            error_log('search: cache counter failed: ' . $e->getMessage());

            return $this->ttl;
        }
    }

    private function counterKey(string $key): string
    {
        return $this->prefix . 'freq:' . substr($key, strlen($this->prefix));
    }

    /** Drops every cached result; false when Redis could not be reached. */
    public function flush(): bool
    {
        try {
            $this->redis->deleteByPrefix($this->prefix);

            return true;
        } catch (Throwable $e) {
            error_log('search: cache flush failed: ' . $e->getMessage());

            return false;
        }
    }
}
