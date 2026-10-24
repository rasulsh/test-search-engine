<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The OpenCart storefront stays on the old integration for a while: it sends only
 * {q, limit: 300, customer_id}, with q already stripped of "&" and ";". The service
 * must answer it exactly as before (plus the additive search_uuid), log it whether or
 * not the v2 columns exist, and never look at normalization_version while serving.
 */
final class LegacyStorefrontTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
    private ?PDO $pdo = null;
    private string $baseUrl = '';
    private string $dataDir = '';
    private string $dsn = '';

    protected function setUp(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('CI')) {
                self::fail('A test database is required under CI: SEARCH_TEST_DB_DSN is not set');
            }
            self::markTestSkipped('SEARCH_TEST_DB_DSN is not set');
        }
        $this->dsn = $dsn;
        $root = dirname(__DIR__, 2);
        $this->pdo = new PDO(
            $dsn,
            getenv('SEARCH_TEST_DB_USER') ?: '',
            getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events');
        $this->pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
        $this->pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));
        $this->dataDir = sys_get_temp_dir() . '/legacy_' . uniqid('', true);
        mkdir($this->dataDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->pdo?->exec('DROP USER IF EXISTS \'noalter\'@\'%\'');
        if ($this->dataDir !== '') {
            @rmdir($this->dataDir);
        }
    }

    /** @param array<string, string> $db dsn / user / password overrides */
    private function startServer(array $db = []): void
    {
        $root = dirname(__DIR__, 2);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->baseUrl = "http://127.0.0.1:{$port}";
        $env = getenv() + [];
        $env['SEARCH_CONFIG_FILE'] = TempConfig::write([
            'db' => $db + [
                'dsn' => $this->dsn,
                'user' => getenv('SEARCH_TEST_DB_USER') ?: '',
                'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
            ],
            'paths' => ['data' => $this->dataDir],
            'search' => ['suggest_min_frequency' => 1], // the sample catalog is tiny
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

    private function stopServer(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /** @return array{int, array<string, mixed>} */
    private function search(string $body): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body,
            'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $response = (string) file_get_contents($this->baseUrl . '/search.php', false, $context);
        preg_match('#HTTP/\S+ (\d+)#', implode("\n", $http_response_header ?? []), $m);

        return [(int) ($m[1] ?? 0), (array) json_decode($response, true)];
    }

    /** @return array<string, mixed> */
    private function lastLog(): array
    {
        $row = $this->pdo?->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        return (array) $row;
    }

    public function testTheOldRequestGetsTheOldResponseShape(): void
    {
        $this->startServer();
        // Pre-stripped by the old storefront: "Assassin's Creed", "&" and a quote lost their symbols.
        foreach (
            ['{"q":"sony","limit":300,"customer_id":"42"}',
            '{"q":"Assassin#039s Creed","limit":300,"customer_id":"42"}',
            '{"q":"ampamp","limit":300,"customer_id":"42"}',
            '{"q":"macbok","limit":300,"customer_id":"42"}'] as $body
        ) {
            [$status, $answer] = $this->search($body);

            self::assertSame(200, $status, $body);
            // Every key the old contract had, in its order; search_uuid is the only addition.
            self::assertSame(
                ['query', 'did_you_mean', 'did_you_mean_applied', 'count', 'product_ids'],
                array_values(array_diff(array_keys($answer), ['search_uuid', 'cosine_scores'])),
                $body
            );
            self::assertSame(['raw', 'normalized'], array_keys($answer['query']));
            self::assertTrue($answer['did_you_mean'] === null || is_string($answer['did_you_mean']));
            self::assertIsBool($answer['did_you_mean_applied']);
            self::assertSame(count($answer['product_ids']), $answer['count']);
            self::assertContainsOnly('int', $answer['product_ids']);
            self::assertLessThanOrEqual(300, $answer['count']);
        }
        [, $answer] = $this->search('{"q":"macbok","limit":300,"customer_id":"42"}');
        self::assertSame('macbook', $answer['did_you_mean']);
        self::assertContains(1007, $answer['product_ids']);
    }

    public function testAnOldRequestIsLoggedWithNoSessionFields(): void
    {
        $this->startServer();
        $this->search('{"q":"sony","limit":300,"customer_id":"42"}');
        $this->search('{"q":"sony","limit":300,"customer_id":"42"}');
        $log = $this->lastLog();

        self::assertSame('42', $log['customer_id']);
        self::assertNull($log['session_id']);
        self::assertSame('page', $log['source']);
        self::assertSame(1, (int) $log['page']);
        self::assertSame(0, (int) $log['is_duplicate'], 'no session: a repeat cannot be told from another shopper');
        self::assertSame(2, (int) $log['log_version']);
    }

    public function testAnOldTableAndAUserThatCannotAlterStillLogTheLegacyColumns(): void
    {
        // A table from before M21 / M26 / v2, and a database user with SELECT + INSERT only.
        $this->pdo?->exec('DROP TABLE search_logs');
        $this->pdo?->exec(
            "CREATE TABLE search_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, raw_q VARCHAR(512) NOT NULL,
                normalized_q VARCHAR(512) NOT NULL DEFAULT '', had_vector TINYINT(1) NOT NULL DEFAULT 0,
                result_count INT UNSIGNED NOT NULL DEFAULT 0, top_ids VARCHAR(1024) NOT NULL DEFAULT '',
                customer_id VARCHAR(64) DEFAULT NULL, latency_ms INT UNSIGNED NOT NULL DEFAULT 0)"
        );
        $dbName = (string) $this->pdo?->query('SELECT DATABASE()')->fetchColumn();
        $this->pdo?->exec("CREATE USER IF NOT EXISTS 'noalter'@'%' IDENTIFIED BY 'pw'");
        $this->pdo?->exec("GRANT SELECT, INSERT ON `{$dbName}`.* TO 'noalter'@'%'");
        $this->startServer(['user' => 'noalter', 'password' => 'pw']);

        [$status, $answer] = $this->search('{"q":"sony","limit":300,"customer_id":"42"}');

        self::assertSame(200, $status);
        self::assertNotSame([], $answer['product_ids']);
        $row = (array) $this->pdo?->query('SELECT * FROM search_logs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['sony', 'sony', '42'], [$row['raw_q'], $row['normalized_q'], $row['customer_id']]);
        self::assertArrayNotHasKey('log_version', $row);
    }
}
