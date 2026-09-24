<?php

declare(strict_types=1);

namespace App\Tests;

use App\VpsClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server-to-server VPS client: request shape, response validation, the
 * floor, and — over real curl — that a dead or slow VPS fails fast, within
 * timeout_ms, instead of holding up /search.
 */
final class VpsClientTest extends TestCase
{
    private static ?MockVpsServer $mock = null;

    public static function tearDownAfterClass(): void
    {
        self::$mock?->stop();
        self::$mock = null;
    }

    /** @param array<string, mixed> $scenario */
    private static function mockVps(array $scenario): MockVpsServer
    {
        self::$mock ??= new MockVpsServer($scenario);
        self::$mock->setScenario($scenario);

        return self::$mock;
    }

    public function testFromConfigIsNullWithoutAUrl(): void
    {
        self::assertNull(VpsClient::fromConfig([]));
        self::assertNull(VpsClient::fromConfig(['url' => '  ', 'token' => 'x', 'timeout_ms' => 300]));
        self::assertInstanceOf(VpsClient::class, VpsClient::fromConfig(['url' => 'https://vps.example.com']));
    }

    public function testRequestShape(): void
    {
        $seen = [];
        $client = new VpsClient(
            'https://vps.example.com:8600/',
            'tok-0123456789abcdef',
            250,
            static function (string $url, array $headers, string $body, int $timeoutMs) use (&$seen): array {
                $seen = compact('url', 'headers', 'body', 'timeoutMs');

                return ['status' => 200, 'body' => '{"results":[]}'];
            }
        );

        self::assertSame([], $client->search('هدفون بی‌سیم', 100, 0.4));
        self::assertSame('https://vps.example.com:8600/search-vectors', $seen['url']);
        self::assertContains('Authorization: Bearer tok-0123456789abcdef', $seen['headers']);
        self::assertContains('Content-Type: application/json', $seen['headers']);
        self::assertSame(['q' => 'هدفون بی‌سیم', 'limit' => 100, 'min_score' => 0.4], json_decode($seen['body'], true));
        self::assertSame(250, $seen['timeoutMs']);
        self::assertSame('', $client->failure());
    }

    public function testParsesSortsAndAppliesTheFloor(): void
    {
        $client = new VpsClient('http://vps.test', 't', 300, static fn (): array => ['status' => 200, 'body' =>
            '{"results":[{"product_id":2,"score":0.5},{"product_id":1,"score":0.9},'
            . '{"product_id":3,"score":0.39},{"product_id":4,"score":1}],"model":"BAAI/bge-m3","took_ms":12.5}']);

        self::assertSame(
            [
                ['product_id' => 4, 'score' => 1.0],
                ['product_id' => 1, 'score' => 0.9],
                ['product_id' => 2, 'score' => 0.5],
            ],
            $client->search('q', 10, 0.4)
        );
    }

    /** @return iterable<string, array{0: array{status: int, body: string}|string, 1: string}> */
    public static function failures(): iterable
    {
        yield 'timeout' => ['timeout', 'timeout'];
        yield 'refused' => ['unreachable: Connection refused', 'unreachable: Connection refused'];
        yield 'unauthorized' => [['status' => 401, 'body' => '{}'], 'http_401'];
        yield 'no vectors loaded' => [['status' => 503, 'body' => '{}'], 'http_503'];
        yield 'redirect' => [['status' => 302, 'body' => ''], 'http_302'];
        yield 'not json' => [['status' => 200, 'body' => '<html>proxy error</html>'], 'malformed_response'];
        yield 'no results key' => [['status' => 200, 'body' => '{"detail":"x"}'], 'malformed_response'];
        yield 'string id' => [['status' => 200, 'body' => '{"results":[{"product_id":"1","score":0.9}]}'],
            'malformed_response'];
        yield 'missing score' => [['status' => 200, 'body' => '{"results":[{"product_id":1}]}'], 'malformed_response'];
        yield 'row not an object' => [['status' => 200, 'body' => '{"results":[5]}'], 'malformed_response'];
    }

    /** @param array{status: int, body: string}|string $response */
    #[DataProvider('failures')]
    public function testFailuresReturnNullWithAReason(array|string $response, string $reason): void
    {
        $client = new VpsClient('http://vps.test', 't', 300, static fn (): array|string => $response);

        self::assertNull($client->search('laptop', 10, 0.4));
        self::assertSame($reason, $client->failure());
    }

    public function testBlankQueryIsNotSent(): void
    {
        $client = new VpsClient('http://vps.test', 't', 300, static fn (): array => self::fail('sent'));

        self::assertNull($client->search('  ', 10, 0.4));
        self::assertSame('empty_query', $client->failure());
        self::assertNull($client->search('laptop', 10, NAN)); // a broken floor in config.php
        self::assertSame('encode_failed', $client->failure());
    }

    public function testCurlAgainstTheMockVps(): void
    {
        $mock = self::mockVps([
            'products' => [7 => [1.0, 0.0], 8 => [0.6, 0.8], 9 => [0.0, 1.0]],
            'queries'  => ['laptop' => [1.0, 0.0]],
        ]);
        $client = new VpsClient($mock->url, FakeVps::TOKEN, 2000);

        self::assertSame(
            [['product_id' => 7, 'score' => 1.0], ['product_id' => 8, 'score' => 0.6]],
            $client->search('laptop', 10, 0.5)
        );
        $last = $mock->lastRequest();
        self::assertSame('/search-vectors', $last['path']);
        self::assertSame('Bearer ' . FakeVps::TOKEN, $last['authorization']);
        self::assertSame(['q' => 'laptop', 'limit' => 10, 'min_score' => 0.5], $last['body']);

        self::assertNull((new VpsClient($mock->url, 'wrong-token-0123456789', 2000))->search('laptop', 10, 0.5));
    }

    public function testSlowVpsTimesOutWithinTheBudget(): void
    {
        $mock = self::mockVps(['sleep_ms' => 1500, 'products' => [7 => [1.0]], 'queries' => ['*' => [1.0]]]);
        $client = new VpsClient($mock->url, FakeVps::TOKEN, 200);

        $start = microtime(true);
        $result = $client->search('laptop', 10, 0.4);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertNull($result);
        self::assertSame('timeout', $client->failure());
        // Bounded by timeout_ms (sub-second, so CURLOPT_NOSIGNAL is in effect), not by the VPS.
        self::assertLessThan(700, $elapsedMs, sprintf('timed out after %.0f ms', $elapsedMs));
        usleep(1_400_000); // let the single-threaded mock finish before the next test
    }

    public function testUnreachableVpsFailsFast(): void
    {
        $client = new VpsClient('http://127.0.0.1:' . MockVpsServer::freePort(), 't', 300);

        $start = microtime(true);
        self::assertNull($client->search('laptop', 10, 0.4));
        self::assertLessThan(700, (microtime(true) - $start) * 1000);
        self::assertStringStartsWith('unreachable: ', $client->failure());
    }
}
