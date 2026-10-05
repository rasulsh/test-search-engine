<?php

declare(strict_types=1);

namespace App\Tests;

use App\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The RESP client against a local stand-in server (fake_redis_server.php). */
final class RedisClientTest extends TestCase
{
    private ?FakeRedisServer $server = null;
    private int $port = 0;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function startServer(string $password = ''): void
    {
        $this->server = new FakeRedisServer($password);
        $this->port = $this->server->port;
    }

    public function testGetSetAndMissingKey(): void
    {
        $this->startServer();
        $client = new RedisClient('127.0.0.1', $this->port);

        self::assertNull($client->get('absent'));
        $client->set('k', "value \u{0645}\r\nwith binary-safe bytes", 60);
        self::assertSame("value \u{0645}\r\nwith binary-safe bytes", $client->get('k'));
    }

    public function testAuthAndDbSelect(): void
    {
        $this->startServer('s3cret');

        $ok = new RedisClient('127.0.0.1', $this->port, 's3cret', 2);
        $ok->set('k', 'v', 60);
        self::assertSame('v', $ok->get('k'));
        $ok->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WRONGPASS');
        (new RedisClient('127.0.0.1', $this->port, 'wrong'))->get('k');
    }

    public function testCommandWithoutAuthIsRefused(): void
    {
        $this->startServer('s3cret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NOAUTH');
        (new RedisClient('127.0.0.1', $this->port))->get('k');
    }

    public function testDeleteByPrefixPagesThroughSCANAndKeepsOtherKeys(): void
    {
        $this->startServer();
        $client = new RedisClient('127.0.0.1', $this->port);
        for ($i = 0; $i < 7; $i++) {
            $client->set("search:cache:{$i}", 'x', 60);
        }
        $client->set('other:1', 'keep', 60);

        self::assertSame(7, $client->deleteByPrefix('search:cache:'));
        self::assertNull($client->get('search:cache:3'));
        self::assertSame('keep', $client->get('other:1'));
        self::assertSame(0, $client->deleteByPrefix('search:cache:'));
    }

    public function testUnreachableServerThrows(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->expectException(RuntimeException::class);
        (new RedisClient('127.0.0.1', $port, '', 0, 100))->get('k');
    }

    public function testSlowServerTimesOutWithinTheBudget(): void
    {
        $this->startServer();
        $client = new RedisClient('127.0.0.1', $this->port, '', 0, 150);

        $start = microtime(true);
        try {
            $client->command(['STALL']);
            self::fail('expected a timeout');
        } catch (RuntimeException) {
            self::assertLessThan(1.0, microtime(true) - $start);
        }
    }

    public function testConnectionIsReusedAndReopenedAfterAFailure(): void
    {
        $this->startServer();
        $client = new RedisClient('127.0.0.1', $this->port, '', 0, 150);
        $client->set('k', 'v', 60);
        try {
            $client->command(['STALL']);
        } catch (RuntimeException) {
            // timed out; the half-read connection must be dropped
        }

        self::assertSame('v', $client->get('k'));
    }
}
