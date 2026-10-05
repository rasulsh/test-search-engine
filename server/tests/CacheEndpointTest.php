<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * POST /search over HTTP with the cache switched on by environment variables
 * (SEARCH_REDIS_*), against a local RESP server: the config -> search.php wiring,
 * a hit identical to the miss, and an outage that costs nothing but the cache.
 */
final class CacheEndpointTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
    private ?FakeRedisServer $redis = null;
    private ?PDO $pdo = null;
    private string $baseUrl = '';
    private string $dataDir = '';

    protected function setUp(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('CI')) {
                self::fail('A test database is required under CI: SEARCH_TEST_DB_DSN is not set');
            }
            self::markTestSkipped('SEARCH_TEST_DB_DSN is not set');
        }
        $root = dirname(__DIR__, 2);
        $user = getenv('SEARCH_TEST_DB_USER') ?: '';
        $password = getenv('SEARCH_TEST_DB_PASSWORD') ?: '';
        $this->pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('DROP TABLE IF EXISTS products, search_logs');
        $this->pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
        $this->pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));

        $this->redis = new FakeRedisServer('cache-pw');
        $this->dataDir = sys_get_temp_dir() . '/cacheendpoint_' . uniqid('', true);
        mkdir($this->dataDir, 0777, true);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->baseUrl = "http://127.0.0.1:{$port}";
        $env = getenv() + [];
        $env['SEARCH_DB_DSN'] = $dsn;
        $env['SEARCH_DB_USER'] = $user;
        $env['SEARCH_DB_PASSWORD'] = $password;
        $env['SEARCH_DATA_DIR'] = $this->dataDir;
        $env['SEARCH_REDIS_ENABLED'] = '1';
        $env['SEARCH_REDIS_HOST'] = '127.0.0.1';
        $env['SEARCH_REDIS_PORT'] = (string) $this->redis->port;
        $env['SEARCH_REDIS_AUTH'] = 'cache-pw';
        $env['SEARCH_REDIS_TTL'] = '60';
        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/server/public', $root . '/server/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            $env
        );
        for ($i = 0; $i < 50; $i++) {
            if (($socket = @fsockopen('127.0.0.1', $port)) !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        self::fail('the PHP server did not start in time');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->redis?->stop();
        if ($this->dataDir !== '') {
            @rmdir($this->dataDir);
        }
    }

    private function search(string $body): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        return (string) file_get_contents($this->baseUrl . '/search', false, $context);
    }

    /** @return list<string> cache_hit flags, oldest first */
    private function cacheHits(): array
    {
        return array_map('strval', (array) $this->pdo?->query('SELECT cache_hit FROM search_logs ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testSecondIdenticalRequestIsServedFromRedisWithTheSameBody(): void
    {
        $first = $this->search('{"q":"sony"}');
        $second = $this->search('{"q":"sony"}');

        self::assertNotSame([], json_decode($first, true)['product_ids']);
        self::assertSame($first, $second);
        self::assertSame(['0', '1'], $this->cacheHits());
    }

    public function testRedisOutageStillAnswersEveryRequest(): void
    {
        $first = $this->search('{"q":"sony"}');
        $this->redis?->stop();

        $second = $this->search('{"q":"sony"}');

        self::assertSame($first, $second);
        self::assertSame(['0', '0'], $this->cacheHits());
    }
}
