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
 */
class Cache
{
    private RedisClient $redis;
    private string $prefix;
    private int $ttl;

    public function __construct(RedisClient $redis, string $prefix, int $ttlSeconds)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->ttl = max(1, $ttlSeconds);
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
            (int) $redis['ttl']
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
            $this->redis->set($key, $json, $this->ttl);
        } catch (Throwable $e) {
            error_log('search: cache write failed: ' . $e->getMessage());
        }
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
