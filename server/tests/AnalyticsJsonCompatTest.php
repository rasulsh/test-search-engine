<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M38: the Persian dashboard is presentation only. The analytics JSON (since M39 behind the panel login:
 * `admin.php?p=analytics&format=json`) over a fixed log
 * fixture (fixtures/analytics_logs.sample.sql, dated 2099 so no clock moves it) must stay
 * byte-identical to fixtures/analytics_report.golden.json: same keys, values and number formats.
 * Regenerate the golden file only for a deliberate change of the report itself
 * (UPDATE_GOLDEN=1 vendor/bin/phpunit --filter AnalyticsJsonCompatTest).
 */
final class AnalyticsJsonCompatTest extends TestCase
{
    private static ?AdminServer $server = null;
    private static AdminClient $client;

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
            $pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events, admin_audit');
            foreach (['db/schema.sql', 'fixtures/products.sample.sql', 'fixtures/analytics_logs.sample.sql'] as $file) {
                $pdo->exec((string) file_get_contents($root . '/' . $file));
            }
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        self::$server = new AdminServer([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => sys_get_temp_dir() . '/analytics_compat_no_bundle'],
            'logs' => ['refresh_seconds' => 30],
        ]);
        self::$client = self::$server->client();
        self::assertSame(303, self::$client->login()['status']);
    }

    public static function tearDownAfterClass(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn !== false && $dsn !== '') {
            // Leave the empty schema, as the other endpoint tests expect to find it.
            $pdo = new PDO($dsn, getenv('SEARCH_TEST_DB_USER') ?: '', getenv('SEARCH_TEST_DB_PASSWORD') ?: '');
            $pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events, admin_audit');
            $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql'));
        }
        self::$server?->stop();
        self::$server = null;
    }

    private function json(string $query): string
    {
        return self::$client->request('GET', '/admin.php?p=analytics&format=json&' . $query)['body'];
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
