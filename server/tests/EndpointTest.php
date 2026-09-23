<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * End-to-end checks of the front controller over HTTP (GET /health, POST
 * /search, and error paths), using PHP's built-in server with index.php as the
 * router against the seeded test database.
 */
final class EndpointTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $baseUrl = '';
    private static ?string $dataDir = null;
    private static ?string $incomingDir = null;

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
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP TABLE IF EXISTS products, search_logs');
            $pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
            $pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        $port = self::freePort();
        self::$baseUrl = "http://127.0.0.1:{$port}";

        // The server reads DB settings from these env vars (see config.example.php).
        $env = getenv();
        $env['SEARCH_DB_DSN'] = $dsn;
        $env['SEARCH_DB_USER'] = $user;
        $env['SEARCH_DB_PASSWORD'] = $password;
        $env['SEARCH_PRODUCTS_TABLE'] = 'products';
        $env['SEARCH_SEARCH_LOGS_TABLE'] = 'search_logs';
        $env['SEARCH_RELOAD_TOKEN'] = 'test-secret';

        // A bundle holding only the spellcheck dictionary. Its frequencies are
        // deliberately the reverse of the catalog's, so a suggestion can only
        // come out this way if /search reads the dictionary (not the table).
        self::$dataDir = sys_get_temp_dir() . '/endpoint_data_' . uniqid('', true);
        mkdir(self::$dataDir, 0777, true);
        // Both clear the default suggest_min_frequency (2).
        file_put_contents(self::$dataDir . '/spellcheck.txt', "body\t9\nsony\t2\n");
        $env['SEARCH_DATA_DIR'] = self::$dataDir;

        // A staged bundle whose meta is compatible but which has no
        // products.load.sql, so /reload stops before any swap either way.
        self::$incomingDir = sys_get_temp_dir() . '/endpoint_incoming_' . uniqid('', true);
        mkdir(self::$incomingDir, 0777, true);
        file_put_contents(self::$incomingDir . '/meta.json', (string) json_encode([
            'model' => getenv('SEARCH_MODEL') ?: 'intfloat/multilingual-e5-small',
            'dim' => (int) (getenv('SEARCH_MODEL_DIM') ?: 384),
            'normalization_version' => 2,
            'count' => 0,
        ]));
        file_put_contents(self::$incomingDir . '/vectors.idx', '');
        $env['SEARCH_DATA_INCOMING_DIR'] = self::$incomingDir;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $command = [
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            $root . '/server/public',
            $root . '/server/public/index.php',
        ];

        $process = proc_open($command, $descriptors, $pipes, $root, $env);
        self::assertIsResource($process, 'Failed to start built-in PHP server');
        self::$process = $process;

        self::waitForServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::$process = null;
        if (self::$dataDir !== null) {
            @unlink(self::$dataDir . '/spellcheck.txt');
            @rmdir(self::$dataDir);
            self::$dataDir = null;
        }
        if (self::$incomingDir !== null) {
            @unlink(self::$incomingDir . '/meta.json');
            @unlink(self::$incomingDir . '/vectors.idx');
            @rmdir(self::$incomingDir);
            self::$incomingDir = null;
        }
    }

    public function testHealthEndpointReturnsOkJson(): void
    {
        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['status']);
        self::assertTrue($decoded['checks']['database']);
        self::assertSame(14, $decoded['checks']['product_count']);
    }

    public function testUnknownPathReturns404(): void
    {
        [$status, $body] = $this->request('GET', '/does-not-exist');

        self::assertSame(404, $status);
        self::assertSame('not_found', json_decode((string) $body, true)['error']);
    }

    public function testWrongMethodOnHealthReturns405(): void
    {
        [$status, $body] = $this->request('POST', '/health');

        self::assertSame(405, $status);
        self::assertSame('method_not_allowed', json_decode((string) $body, true)['error']);
    }

    public function testSearchReturnsProductIds(): void
    {
        [$status, $body] = $this->request('POST', '/search', '{"q":"sony"}');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertIsArray($decoded);
        self::assertNull($decoded['did_you_mean']);
        self::assertSame('sony', $decoded['query']['normalized']);
        self::assertContains(1009, $decoded['product_ids']);
        self::assertContains(1011, $decoded['product_ids']);
    }

    public function testSearchReturnsDidYouMeanForTypo(): void
    {
        [$status, $body] = $this->request('POST', '/search', '{"q":"sont"}');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertSame('sony', $decoded['did_you_mean']);
        self::assertNotEmpty($decoded['product_ids']);
    }

    public function testDidYouMeanComesFromBundleDictionary(): void
    {
        // "sody" is one edit from both "sony" (catalog frequency 4) and "body"
        // (catalog frequency 1). The table scan would pick "sony"; the bundle
        // dictionary ranks "body" higher.
        [$status, $body] = $this->request('POST', '/search', '{"q":"sody"}');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertSame('body', $decoded['did_you_mean']);
        self::assertSame([1001], $decoded['product_ids']);
    }

    public function testDefaultSearchResponseHasNoDetails(): void
    {
        [, $body] = $this->request('POST', '/search', '{"q":"sony"}');

        $decoded = json_decode((string) $body, true);
        self::assertSame(
            ['query', 'did_you_mean', 'did_you_mean_applied', 'count', 'product_ids'],
            array_keys($decoded)
        );
    }

    public function testSearchWithDetailsReturnsDisplayFieldsInResultOrder(): void
    {
        [$status, $body] = $this->request('POST', '/search.php', '{"q":"sony","with_details":true}');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertNotEmpty($decoded['product_ids']);
        self::assertSame($decoded['product_ids'], array_column($decoded['products'], 'id'));
        foreach ($decoded['products'] as $product) {
            self::assertSame(['id', 'title', 'url', 'image', 'price'], array_keys($product));
        }
        $byId = array_column($decoded['products'], null, 'id');
        self::assertSame('Sony WH-1000XM5 Wireless Headphones', $byId[1009]['title']);
        self::assertSame('/product/sony-wh-1000xm5', $byId[1009]['url']);
        self::assertSame('/image/sony-headphones.jpg', $byId[1009]['image']);
        self::assertEquals(349, $byId[1009]['price']);
    }

    public function testWithDetailsMustBeBooleanTrue(): void
    {
        [, $body] = $this->request('POST', '/search', '{"q":"sony","with_details":"true"}');

        self::assertArrayNotHasKey('products', json_decode((string) $body, true));
    }

    public function testWithDetailsOnEmptyResultIsEmptyList(): void
    {
        [$status, $body] = $this->request('POST', '/search', '{"q":"zzzzqqqq","with_details":true}');

        self::assertSame(200, $status);
        $decoded = json_decode((string) $body, true);
        self::assertSame([], $decoded['product_ids']);
        self::assertSame([], $decoded['products']);
    }

    public function testSearchWrongMethodReturns405(): void
    {
        [$status] = $this->request('GET', '/search');

        self::assertSame(405, $status);
    }

    public function testSearchInvalidJsonReturns400(): void
    {
        [$status, $body] = $this->request('POST', '/search', 'not-json');

        self::assertSame(400, $status);
        self::assertSame('invalid_json', json_decode((string) $body, true)['error']);
    }

    public function testReloadWithoutTokenReturns401(): void
    {
        // A reload token is configured, so an unauthenticated call is rejected
        // before any swap happens.
        [$status, $body] = $this->request('POST', '/reload', '{}');

        self::assertSame(401, $status);
        self::assertSame('unauthorized', json_decode((string) $body, true)['error']);
    }

    public function testReloadLoadFlagLoadsStagingFromTheBundle(): void
    {
        // Without ?load=1 the operator must have loaded staging already; with it
        // the endpoint loads products.load.sql itself (missing here).
        [$status, $body] = $this->request('POST', '/reload', '{"token":"test-secret"}');
        self::assertSame(422, $status);
        self::assertSame('missing_staging_table', json_decode((string) $body, true)['reason']);

        [$status, $body] = $this->request('POST', '/reload.php?load=1', '{"token":"test-secret"}');
        self::assertSame(422, $status);
        self::assertSame('missing_load_sql', json_decode((string) $body, true)['reason']);
    }

    public function testReloadWrongMethodReturns405(): void
    {
        [$status] = $this->request('GET', '/reload');

        self::assertSame(405, $status);
    }

    /**
     * @return array{0: int, 1: string|false}
     */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $http = ['method' => $method, 'ignore_errors' => true, 'timeout' => 5];
        if ($body !== null) {
            $http['header'] = "Content-Type: application/json\r\n";
            $http['content'] = $body;
        }
        $context = stream_context_create(['http' => $http]);
        $response = @file_get_contents(self::$baseUrl . $path, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $response];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, "Cannot allocate a port: {$errstr}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitForServer(): void
    {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::$baseUrl . '/health', false, $context) !== false) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Built-in PHP server did not start in time');
    }
}
