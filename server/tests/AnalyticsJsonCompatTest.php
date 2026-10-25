<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M38: the Persian dashboard is presentation only. `analytics.php?format=json` over a fixed log
 * fixture (fixtures/analytics_logs.sample.sql, dated 2099 so no clock moves it) must stay
 * byte-identical to fixtures/analytics_report.golden.json: same keys, values and number formats.
 * Regenerate the golden file only for a deliberate change of the report itself
 * (UPDATE_GOLDEN=1 vendor/bin/phpunit --filter AnalyticsJsonCompatTest).
 */
final class AnalyticsJsonCompatTest extends TestCase
{
    private const TOKEN = 'compat-token-0123456789';

    /** @var resource|null */
    private static $process = null;
    private static string $url = '';

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
            $pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events');
            foreach (['db/schema.sql', 'fixtures/products.sample.sql', 'fixtures/analytics_logs.sample.sql'] as $file) {
                $pdo->exec((string) file_get_contents($root . '/' . $file));
            }
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, "Cannot allocate a port: {$errstr}");
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $config = TempConfig::write([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => sys_get_temp_dir() . '/analytics_compat_no_bundle'],
            'logs' => ['token' => self::TOKEN, 'refresh_seconds' => 30],
        ]);
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/server/public', $root . '/server/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            ['SEARCH_CONFIG_FILE' => $config] + getenv()
        );
        self::assertIsResource($process, 'Failed to start built-in PHP server');
        self::$process = $process;
        self::$url = "http://127.0.0.1:{$port}";

        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::$url . '/health', false, $context) !== false) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Built-in PHP server did not start in time');
    }

    public static function tearDownAfterClass(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn !== false && $dsn !== '') {
            // Leave the empty schema, as the other endpoint tests expect to find it.
            $pdo = new PDO($dsn, getenv('SEARCH_TEST_DB_USER') ?: '', getenv('SEARCH_TEST_DB_PASSWORD') ?: '');
            $pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events');
            $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql'));
        }
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private function json(string $query): string
    {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true, 'timeout' => 10, 'header' => 'X-Logs-Token: ' . self::TOKEN,
        ]]);

        return (string) file_get_contents(self::$url . '/analytics.php?format=json&' . $query, false, $context);
    }

    public function testTheJsonOutputIsByteIdenticalToTheGoldenFile(): void
    {
        $golden = dirname(__DIR__, 2) . '/fixtures/analytics_report.golden.json';
        $body = $this->json('window=all');
        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($golden, $body);
        }

        self::assertSame((string) file_get_contents($golden), $body);
        $report = json_decode($body, true);
        self::assertSame(98, $report['summary']['total']);
        self::assertTrue($report['log_v2']['available']);
    }
}
