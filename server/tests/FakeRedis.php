<?php

declare(strict_types=1);

namespace App\Tests;

use App\RedisClient;
use RuntimeException;

/**
 * In-memory stand-in for a Redis server, behind the real RedisClient methods
 * (get / set / deleteByPrefix run unchanged; only the wire is replaced). TTLs are
 * recorded, not enforced. `$down = true` makes every command fail like an
 * unreachable server.
 */
final class FakeRedis extends RedisClient
{
    /** @var array<string, string> */
    public array $data = [];
    /** @var array<string, int> */
    public array $ttls = [];
    /** @var list<string> command names, in order */
    public array $log = [];
    public bool $down = false;

    public function __construct()
    {
        parent::__construct('fake', 0);
    }

    public function command(array $arguments)
    {
        if ($this->down) {
            throw new RuntimeException('redis: cannot connect (fake outage)');
        }
        $name = strtoupper($arguments[0]);
        $this->log[] = $name;

        switch ($name) {
            case 'GET':
                return $this->data[$arguments[1]] ?? null;
            case 'SET':
                $this->data[$arguments[1]] = $arguments[2];
                $this->ttls[$arguments[1]] = (int) $arguments[4];

                return 'OK';
            case 'SCAN':
                // One page only is enough for the logic; the real server paging is
                // covered by RedisClientTest against the RESP server.
                $glob = $arguments[3];
                $keys = array_values(array_filter(
                    array_keys($this->data),
                    static fn (string $key): bool => fnmatch($glob, $key)
                ));

                return ['0', $keys];
            case 'DEL':
                $deleted = 0;
                foreach (array_slice($arguments, 1) as $key) {
                    $deleted += isset($this->data[$key]) ? 1 : 0;
                    unset($this->data[$key], $this->ttls[$key]);
                }

                return $deleted;
        }
        throw new RuntimeException('FakeRedis: unsupported command ' . $name);
    }
}
