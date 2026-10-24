<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The click loop over HTTP: POST /search returns a search_uuid, a text/plain
 * body (what navigator.sendBeacon sends) to POST /events records the click.
 */
final class EventsEndpointTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
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
        $this->pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events');
        $this->pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
        $this->pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));

        $this->dataDir = sys_get_temp_dir() . '/eventsendpoint_' . uniqid('', true);
        mkdir($this->dataDir, 0777, true);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->baseUrl = "http://127.0.0.1:{$port}";
        $env = getenv() + [];
        $env['SEARCH_CONFIG_FILE'] = TempConfig::write([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => $this->dataDir],
            'events' => ['allowed_origin' => 'https://shop.example'],
        ]);
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
        if ($this->dataDir !== '') {
            @rmdir($this->dataDir);
        }
    }

    /** @return array{int, string, string} status, body, headers */
    private function call(string $method, string $path, string $body, string $type): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => "Content-Type: {$type}\r\n", 'content' => $body,
            'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $response = (string) file_get_contents($this->baseUrl . $path, false, $context);
        $headers = implode("\n", $http_response_header ?? []);
        preg_match('#HTTP/\S+ (\d+)#', $headers, $m);

        return [(int) ($m[1] ?? 0), $response, $headers];
    }

    public function testASearchClickIsRecordedFromABeaconBody(): void
    {
        [, $body] = $this->call('POST', '/search', '{"q":"sony","session_id":"s-1"}', 'application/json');
        $answer = json_decode($body, true);
        $uuid = $answer['search_uuid'];

        [$status, , $headers] = $this->call('POST', '/events', (string) json_encode([
            'search_uuid' => $uuid, 'product_id' => $answer['product_ids'][0], 'position' => 1, 'action' => 'click',
        ]), 'text/plain;charset=UTF-8');

        self::assertSame(204, $status);
        self::assertStringContainsString('Access-Control-Allow-Origin: https://shop.example', $headers);
        $row = $this->pdo?->query('SELECT * FROM search_events')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($uuid, $row['search_uuid']);
        self::assertSame($answer['product_ids'][0], (int) $row['product_id']);
    }

    public function testBadRequestsAreRefused(): void
    {
        self::assertSame(400, $this->call('POST', '/events', 'not json', 'text/plain')[0]);
        self::assertSame(400, $this->call('POST', '/events', '{"search_uuid":"x","product_id":1}', 'text/plain')[0]);
        self::assertSame(404, $this->call('POST', '/events', (string) json_encode([
            'search_uuid' => '11111111-1111-4111-8111-111111111111', 'product_id' => 1, 'position' => 1,
        ]), 'text/plain')[0]);
        self::assertSame(405, $this->call('GET', '/events', '', 'text/plain')[0]);
        self::assertSame(204, $this->call('OPTIONS', '/events', '', 'text/plain')[0]);
    }
}
