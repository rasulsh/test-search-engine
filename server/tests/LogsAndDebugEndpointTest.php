<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M21 over HTTP (PHP's built-in server with index.php as the router): the
 * token-guarded debug breakdown of POST /search. A second server without a
 * token proves it stays off by default. (The logs and analytics pages moved
 * behind the admin login in M39: see AdminPanelTest.)
 */
final class LogsAndDebugEndpointTest extends TestCase
{
    private const OTHER_TOKEN = 'another-secret-0123456789';
    private const DEBUG_TOKEN = 'debug-secret-0123456789';

    /** @var array<string, resource> */
    private static array $processes = [];
    /** @var array<string, string> */
    private static array $urls = [];
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('CI')) {
                self::fail('A test database is required under CI: SEARCH_TEST_DB_DSN is not set');
            }
            self::markTestSkipped('SEARCH_TEST_DB_DSN is not set');
        }
        $user = getenv('SEARCH_TEST_DB_USER') ?: '';
        $password = getenv('SEARCH_TEST_DB_PASSWORD') ?: '';
        $root = dirname(__DIR__, 2);

        try {
            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('DROP TABLE IF EXISTS products, search_logs');
            self::$pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
            self::$pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        $base = [
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => sys_get_temp_dir() . '/logs_endpoint_no_bundle'],
        ];
        self::start('armed', array_replace_recursive($base, [
            'debug' => ['token' => self::DEBUG_TOKEN],
        ]));
        self::start('off', $base);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$processes = [];
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM search_logs');
    }

    /** @param array<string, string> $settings */
    private static function start(string $name, array $settings): void
    {
        $root = dirname(__DIR__, 2);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, "Cannot allocate a port: {$errstr}");
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/server/public', $root . '/server/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            ['SEARCH_CONFIG_FILE' => TempConfig::write($settings)] + getenv()
        );
        self::assertIsResource($process, 'Failed to start built-in PHP server');
        self::$processes[$name] = $process;
        self::$urls[$name] = "http://127.0.0.1:{$port}";

        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::$urls[$name] . '/health', false, $context) !== false) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Built-in PHP server did not start in time');
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string, 2: list<string>} status, body, response headers
     */
    private function request(
        string $server,
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null
    ): array {
        $http = ['method' => $method, 'ignore_errors' => true, 'timeout' => 5, 'header' => implode("\r\n", $headers)];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $context = stream_context_create(['http' => $http]);
        $response = (string) @file_get_contents(self::$urls[$server] . $path, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $response, $http_response_header ?? []];
    }

    public function testDebugNeedsTheTokenAndRealSearchesStayUnchanged(): void
    {
        $body = '{"q":"sony","debug":1}';

        [$none, $noneBody] = $this->request('armed', 'POST', '/search', [], $body);
        [$wrong] = $this->request('armed', 'POST', '/search', ['X-Debug-Token: nope'], $body);
        [$logsToken] = $this->request('armed', 'POST', '/search', ['X-Debug-Token: ' . self::OTHER_TOKEN], $body);
        [$off] = $this->request('off', 'POST', '/search', ['X-Debug-Token: '], $body);

        self::assertSame([403, 403, 403, 403], [$none, $wrong, $logsToken, $off]);
        self::assertSame('debug_forbidden', json_decode($noneBody, true)['error']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn()); // not run

        [$status, $plain] = $this->request('armed', 'POST', '/search', [], '{"q":"sony"}');
        self::assertSame(200, $status);
        self::assertArrayNotHasKey('debug', json_decode($plain, true));
        [, $notDebug] = $this->request('armed', 'POST', '/search', [], '{"q":"sony","debug":0}');
        self::assertArrayNotHasKey('debug', json_decode($notDebug, true));
    }

    public function testDebugBreakdownWithTheTokenIsNotLogged(): void
    {
        [$status, $body] = $this->request(
            'armed',
            'POST',
            '/search',
            ['X-Debug-Token: ' . self::DEBUG_TOKEN],
            '{"q":"sony","debug":1,"with_details":true}'
        );

        self::assertSame(200, $status);
        $decoded = json_decode($body, true);
        self::assertSame(['tier', 'settings', 'results'], array_keys($decoded['debug']));
        self::assertSame('keyword_only', $decoded['debug']['tier']); // no VPS configured
        self::assertSame($decoded['product_ids'], array_column($decoded['debug']['results'], 'product_id'));
        self::assertSame('fulltext', $decoded['debug']['results'][0]['keyword_hit']['match_type']);
        self::assertArrayHasKey('products', $decoded); // composes with with_details

        $logged = self::$pdo->query('SELECT * FROM search_logs')->fetchAll();
        self::assertCount(1, $logged);
        self::assertStringNotContainsString('keyword_hit', json_encode($logged, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
