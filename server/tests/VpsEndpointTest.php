<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M18 end to end: POST /search on the built-in server (the cPanel side) calls
 * a mock VPS over real HTTP, merges its neighbours, and — with the VPS
 * failing, slow or down — still answers 200 with keyword-only results and
 * logs the degradation to the PHP error log.
 */
final class VpsEndpointTest extends TestCase
{
    private const TIMEOUT_MS = 300;

    private static ?MockVpsServer $vps = null;
    /** @var array<string, array{process: resource, url: string, log: string}> */
    private static array $sites = [];

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

        self::$vps = new MockVpsServer([]);
        $env = [
            'SEARCH_DB_DSN' => $dsn,
            'SEARCH_DB_USER' => $user,
            'SEARCH_DB_PASSWORD' => $password,
            'SEARCH_DATA_DIR' => sys_get_temp_dir() . '/vps_endpoint_no_bundle',
            'SEARCH_VPS_TOKEN' => FakeVps::TOKEN,
            'SEARCH_VPS_TIMEOUT_MS' => (string) self::TIMEOUT_MS,
            'SEARCH_SEMANTIC_MIN_SCORE' => '0.4',
        ];
        self::$sites['up'] = self::startSite($root, $env + ['SEARCH_VPS_URL' => self::$vps->url]);
        // Nothing listens on this port: connection refused.
        self::$sites['down'] = self::startSite(
            $root,
            $env + ['SEARCH_VPS_URL' => 'http://127.0.0.1:' . MockVpsServer::freePort()]
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$sites as $site) {
            proc_terminate($site['process']);
            proc_close($site['process']);
            @unlink($site['log']);
        }
        self::$sites = [];
        self::$vps?->stop();
        self::$vps = null;
    }

    /**
     * @param array<string, string> $env
     * @return array{process: resource, url: string, log: string}
     */
    private static function startSite(string $root, array $env): array
    {
        $port = MockVpsServer::freePort();
        $log = (string) tempnam(sys_get_temp_dir(), 'cpanel_log_');
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=' . $log, '-S', "127.0.0.1:{$port}", '-t', $root . '/server/public',
                $root . '/server/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            $env + getenv()
        );
        self::assertIsResource($process);
        $url = "http://127.0.0.1:{$port}";
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50 && @file_get_contents($url . '/health', false, $context) === false; $i++) {
            usleep(100_000);
        }

        return ['process' => $process, 'url' => $url, 'log' => $log];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{0: int, 1: array<string, mixed>, 2: float} status, body, elapsed ms
     */
    private function search(string $site, array $request): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => (string) json_encode($request),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $start = microtime(true);
        $body = @file_get_contents(self::$sites[$site]['url'] . '/search', false, $context);
        $elapsedMs = (microtime(true) - $start) * 1000;
        preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), (array) json_decode((string) $body, true), $elapsedMs];
    }

    private function siteLog(string $site): string
    {
        return (string) file_get_contents(self::$sites[$site]['log']);
    }

    /** @param array<string, mixed> $extra */
    private function scenario(array $extra = []): void
    {
        self::$vps?->setScenario($extra + [
            'products' => [1011 => [1.0, 0.0], 1009 => [0.0, 1.0]],
            'queries'  => ['macbook' => [1.0, 0.0]],
        ]);
    }

    public function testSearchMergesTheVpsNeighbours(): void
    {
        $this->scenario();

        [$status, $body] = $this->search('up', ['q' => 'macbook', 'customer_id' => 'c-42']);

        self::assertSame(200, $status);
        self::assertSame([1007, 1011], $body['product_ids']); // keyword hit, then the VPS neighbour
        self::assertEquals([null, 1.0], $body['cosine_scores']); // JSON: 1.0 arrives as 1
        $sent = self::$vps?->lastRequest() ?? [];
        self::assertSame('/search-vectors', $sent['path']);
        self::assertSame('Bearer ' . FakeVps::TOKEN, $sent['authorization']);
        self::assertSame(['q' => 'macbook', 'limit' => 100, 'min_score' => 0.4], $sent['body']);
    }

    public function testWithDetailsCarriesTheCosineOfEachResult(): void
    {
        $this->scenario();

        [, $body] = $this->search('up', ['q' => 'macbook', 'with_details' => true]);

        self::assertSame([1007, 1011], array_column($body['products'], 'id'));
        self::assertEquals([null, 1.0], $body['cosine_scores']); // JSON: 1.0 arrives as 1
    }

    public function testVpsErrorFallsBackToKeywordOnlyAndIsLogged(): void
    {
        $this->scenario(['status' => 500]);

        [$status, $body] = $this->search('up', ['q' => 'macbook']);

        self::assertSame(200, $status);
        self::assertSame([1007], $body['product_ids']);
        self::assertArrayNotHasKey('cosine_scores', $body);
        self::assertStringContainsString('semantic tier unavailable (http_500)', $this->siteLog('up'));
    }

    public function testSlowVpsTimesOutAndFallsBackWithinTheBudget(): void
    {
        $this->scenario(['sleep_ms' => 1500]);

        [$status, $body, $elapsedMs] = $this->search('up', ['q' => 'macbook']);

        self::assertSame(200, $status);
        self::assertSame([1007], $body['product_ids']);
        self::assertStringContainsString('semantic tier unavailable (timeout)', $this->siteLog('up'));
        // The whole /search round trip, including the 300 ms VPS budget.
        self::assertLessThan(1000, $elapsedMs, sprintf('/search took %.0f ms', $elapsedMs));
        usleep(1_400_000); // the single-threaded mock is still sleeping
    }

    public function testUnreachableVpsFallsBackToKeywordOnlyAndIsLogged(): void
    {
        [$status, $body, $elapsedMs] = $this->search('down', ['q' => 'macbook']);

        self::assertSame(200, $status);
        self::assertSame([1007], $body['product_ids']);
        self::assertArrayNotHasKey('cosine_scores', $body);
        self::assertStringContainsString('semantic tier unavailable (unreachable: ', $this->siteLog('down'));
        self::assertLessThan(1000, $elapsedMs);
    }

    public function testQVectorFromAnOldStorefrontIsIgnored(): void
    {
        $this->scenario();

        [$status, $body] = $this->search('up', ['q' => 'macbook', 'q_vector' => [0.0, 1.0]]);

        self::assertSame(200, $status);
        self::assertSame([1007, 1011], $body['product_ids']); // the VPS decided, not the stale vector
    }
}
