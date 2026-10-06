<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M21 over HTTP (PHP's built-in server with index.php as the router): the
 * token-protected, read-only logs page (auth, newest first, zero-result and
 * slowest views, pagination, escaping) and the token-guarded debug breakdown
 * of POST /search. A second server without tokens proves both stay off by
 * default.
 */
final class LogsAndDebugEndpointTest extends TestCase
{
    private const LOGS_TOKEN = 'logs-secret-0123456789';
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
            'logs' => ['page_size' => 3],
        ];
        self::start('armed', array_replace_recursive($base, [
            'logs' => ['token' => self::LOGS_TOKEN],
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

    /** @param list<array<string, mixed>> $rows */
    private function seedLogs(array $rows): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO search_logs
                (ts, raw_q, normalized_q, had_vector, result_count, top_ids, latency_ms, tier, did_you_mean)
             VALUES
                (:ts, :raw_q, :normalized_q, :had_vector, :result_count, :top_ids, :latency_ms, :tier,
                 :did_you_mean)'
        );
        foreach ($rows as $i => $row) {
            $stmt->execute($row + [
                'ts' => sprintf('2026-01-01 10:%02d:00', $i),
                'normalized_q' => strtolower((string) $row['raw_q']),
                'had_vector' => 0,
                'result_count' => 2,
                'top_ids' => '1,2',
                'latency_ms' => 10,
                'tier' => 'keyword_only',
                'did_you_mean' => null,
            ]);
        }
    }

    /** @return list<string> the queries of the page, in the order shown */
    private static function queriesIn(string $html): array
    {
        preg_match_all('#<td class="raw_q" dir="auto">(.*?)</td>#su', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }

    public function testLogsRequireTheToken(): void
    {
        $this->seedLogs([['raw_q' => 'secret-query']]);

        [$none, $noneBody] = $this->request('armed', 'GET', '/logs.php');
        [$wrong, $wrongBody] = $this->request('armed', 'GET', '/logs.php', ['X-Logs-Token: nope']);
        [$wrongUrl] = $this->request('armed', 'GET', '/logs.php?token=nope');
        [$debugToken] = $this->request('armed', 'GET', '/logs.php', ['X-Logs-Token: ' . self::DEBUG_TOKEN]);

        self::assertSame([401, 401, 401, 401], [$none, $wrong, $wrongUrl, $debugToken]);
        self::assertStringNotContainsString('secret-query', $noneBody . $wrongBody);
        self::assertStringContainsString('<form', $noneBody); // a browser gets a token prompt
    }

    public function testLogsAreDisabledWithoutAConfiguredToken(): void
    {
        $this->seedLogs([['raw_q' => 'secret-query']]);

        [$status, $body] = $this->request('off', 'GET', '/logs.php', ['X-Logs-Token: ']);
        [$emptyToken] = $this->request('off', 'GET', '/logs.php?token=');

        self::assertSame(503, $status);
        self::assertSame(503, $emptyToken); // an empty token never matches an empty config
        self::assertStringNotContainsString('secret-query', $body);
    }

    public function testLogsPageIsReadOnlyAndGetOnly(): void
    {
        [$post] = $this->request('armed', 'POST', '/logs.php', ['X-Logs-Token: ' . self::LOGS_TOKEN], 'x=1');
        [$delete] = $this->request('armed', 'DELETE', '/logs?token=' . self::LOGS_TOKEN);

        self::assertSame([405, 405], [$post, $delete]);
    }

    public function testRecentQueriesNewestFirstWithAnalysisColumns(): void
    {
        $this->seedLogs([
            ['raw_q' => 'oldest'],
            ['raw_q' => 'middle', 'tier' => 'hybrid', 'had_vector' => 1, 'latency_ms' => 55, 'result_count' => 7],
            ['raw_q' => 'newest', 'did_you_mean' => 'newest fixed'],
        ]);

        [$status, $html, $headers] = $this->request('armed', 'GET', '/logs.php', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertSame(200, $status);
        self::assertSame(['newest', 'middle', 'oldest'], self::queriesIn($html));
        $labels = ['Time', 'Query', 'Normalized', 'Tier', 'Vector', 'Results', 'Top ids', 'ms'];
        $labels = array_merge($labels, ['Did you mean', 'Customer']);
        foreach ($labels as $label) {
            self::assertStringContainsString("<th>{$label}</th>", $html);
        }
        self::assertStringContainsString('hybrid', $html);
        self::assertStringContainsString('newest fixed', $html);
        $joined = strtolower(implode("\n", $headers));
        self::assertStringContainsString('cache-control: no-store', $joined);
        self::assertStringContainsString('x-robots-tag: noindex', $joined);
        self::assertStringContainsString("content-security-policy: default-src 'none'", $joined);
    }

    public function testZeroResultFilterShowsOnlyTheGaps(): void
    {
        $this->seedLogs([
            ['raw_q' => 'found-a'],
            ['raw_q' => 'gap-1', 'result_count' => 0],
            ['raw_q' => 'found-b'],
            ['raw_q' => 'gap-2', 'result_count' => 0],
        ]);

        [, $html] = $this->request('armed', 'GET', '/logs.php?view=zero', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertSame(['gap-2', 'gap-1'], self::queriesIn($html));
        self::assertStringContainsString('(2 rows)', $html);
    }

    public function testSlowestSortOrdersByLatency(): void
    {
        $this->seedLogs([
            ['raw_q' => 'fast', 'latency_ms' => 5],
            ['raw_q' => 'slowest', 'latency_ms' => 900],
            ['raw_q' => 'slow', 'latency_ms' => 400],
        ]);

        [, $html] = $this->request('armed', 'GET', '/logs.php?view=slowest', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertSame(['slowest', 'slow', 'fast'], self::queriesIn($html));
    }

    public function testPaginationAndTokenInLinks(): void
    {
        $this->seedLogs(array_map(static fn (int $i): array => ['raw_q' => "q{$i}"], range(1, 7)));
        $token = self::LOGS_TOKEN;

        [, $page1] = $this->request('armed', 'GET', "/logs.php?token={$token}");
        [, $page3] = $this->request('armed', 'GET', "/logs.php?token={$token}&page=3");
        [, $clamped] = $this->request('armed', 'GET', "/logs.php?token={$token}&page=99");
        [, $viaHeader] = $this->request('armed', 'GET', '/logs.php', ['X-Logs-Token: ' . $token]);

        self::assertSame(['q7', 'q6', 'q5'], self::queriesIn($page1));
        self::assertStringContainsString('Page 1 of 3 (7 rows)', $page1);
        self::assertSame(['q1'], self::queriesIn($page3));
        self::assertSame(self::queriesIn($page3), self::queriesIn($clamped)); // out of range: last page
        self::assertStringContainsString('page=2', $page1);
        self::assertStringContainsString('token=' . $token, $page1); // the URL token travels on links
        self::assertStringNotContainsString($token, $viaHeader); // a header token is never echoed
    }

    public function testQueriesAreEscaped(): void
    {
        $this->seedLogs([['raw_q' => '<script>alert(1)</script>']]);

        [, $html] = $this->request('armed', 'GET', '/logs.php', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testLogsAreReachableThroughTheFrontControllerPath(): void
    {
        [$status] = $this->request('armed', 'GET', '/logs', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertSame(200, $status);
    }

    public function testDebugNeedsTheTokenAndRealSearchesStayUnchanged(): void
    {
        $body = '{"q":"sony","debug":1}';

        [$none, $noneBody] = $this->request('armed', 'POST', '/search', [], $body);
        [$wrong] = $this->request('armed', 'POST', '/search', ['X-Debug-Token: nope'], $body);
        [$logsToken] = $this->request('armed', 'POST', '/search', ['X-Debug-Token: ' . self::LOGS_TOKEN], $body);
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

    private function recentLogs(): void
    {
        // seedLogs() stamps 2026-01-01: re-stamp to now so the default 7d window holds them.
        $this->seedLogs([
            ['raw_q' => 'far cry', 'result_count' => 4, 'tier' => 'hybrid', 'had_vector' => 1, 'latency_ms' => 30],
            ['raw_q' => 'far cry', 'result_count' => 4, 'tier' => 'hybrid', 'had_vector' => 1, 'latency_ms' => 50],
            ['raw_q' => '<b>nothing</b>', 'result_count' => 0, 'latency_ms' => 10],
        ]);
        self::$pdo->exec('UPDATE search_logs SET ts = NOW()');
    }

    public function testAnalyticsRequireTheLogsToken(): void
    {
        $this->recentLogs();

        [$none, $noneBody] = $this->request('armed', 'GET', '/analytics.php');
        [$wrong] = $this->request('armed', 'GET', '/analytics.php', ['X-Logs-Token: nope']);
        [$debugToken] = $this->request('armed', 'GET', '/analytics.php', ['X-Logs-Token: ' . self::DEBUG_TOKEN]);
        [$jsonNone, $jsonBody] = $this->request('armed', 'GET', '/analytics.php?format=json');

        self::assertSame([401, 401, 401, 401], [$none, $wrong, $debugToken, $jsonNone]);
        self::assertStringNotContainsString('far cry', $noneBody . $jsonBody);
        self::assertStringContainsString('<form', $noneBody);
    }

    public function testAnalyticsAreDisabledWithoutAConfiguredToken(): void
    {
        $this->recentLogs();

        [$status, $body] = $this->request('off', 'GET', '/analytics.php', ['X-Logs-Token: ']);

        self::assertSame(503, $status);
        self::assertStringNotContainsString('far cry', $body);
    }

    public function testAnalyticsDashboardShowsTheSectionsEscapedAndNoStore(): void
    {
        $this->recentLogs();

        [$status, $body, $headers] = $this->request(
            'armed',
            'GET',
            '/analytics',
            ['X-Logs-Token: ' . self::LOGS_TOKEN]
        );

        self::assertSame(200, $status);
        foreach (
            ['Top queries', 'Zero-result queries', 'Tier and cache', 'Latency', 'Volume', 'Cache hit rate',
            'Zero-result rate', 'Last 7 days'] as $section
        ) {
            self::assertStringContainsString($section, $body);
        }
        self::assertStringContainsString('far cry', $body);
        self::assertStringContainsString('&lt;b&gt;nothing&lt;/b&gt;', $body);
        self::assertStringNotContainsString('<b>nothing</b>', $body);
        self::assertStringNotContainsString(self::LOGS_TOKEN, $body, 'a header token is never echoed into links');
        $all = strtolower(implode("\n", $headers));
        foreach (
            ['cache-control: no-store', 'x-robots-tag: noindex', 'x-frame-options: deny',
            'content-security-policy: default-src \'none\''] as $header
        ) {
            self::assertStringContainsString($header, $all);
        }
    }

    public function testAnalyticsJsonShapeAndWindows(): void
    {
        $this->recentLogs();

        [$status, $body, $headers] = $this->request(
            'armed',
            'GET',
            '/analytics.php?format=json&window=24h',
            ['X-Logs-Token: ' . self::LOGS_TOKEN]
        );
        $report = json_decode($body, true);

        self::assertSame(200, $status);
        self::assertStringContainsString('application/json', strtolower(implode("\n", $headers)));
        self::assertSame('24h', $report['window']);
        self::assertSame(3, $report['summary']['total']);
        self::assertSame(1, $report['summary']['zero_results']);
        self::assertSame(33.3, $report['summary']['zero_rate']);
        self::assertSame(['far cry', 2], [$report['top_queries'][0]['query'], $report['top_queries'][0]['searches']]);
        self::assertSame('<b>nothing</b>', $report['zero_result_queries'][0]['query']);
        self::assertSame(66.7, $report['tier']['hybrid_share']);
        self::assertSame(30, $report['latency']['p50']);
        foreach (['cache', 'did_you_mean', 'volume', 'since'] as $key) {
            self::assertArrayHasKey($key, $report);
        }

        [, $default] = $this->request('armed', 'GET', '/analytics.php?format=json&window=bogus', [
            'X-Logs-Token: ' . self::LOGS_TOKEN,
        ]);
        self::assertSame('7d', json_decode($default, true)['window']);
    }

    public function testAnalyticsAreReadOnlyAndGetOnly(): void
    {
        $this->recentLogs();

        [$post] = $this->request('armed', 'POST', '/analytics.php', ['X-Logs-Token: ' . self::LOGS_TOKEN], '{}');
        $this->request('armed', 'GET', '/analytics.php?format=json', ['X-Logs-Token: ' . self::LOGS_TOKEN]);

        self::assertSame(405, $post);
        self::assertSame(3, (int) self::$pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }
}
