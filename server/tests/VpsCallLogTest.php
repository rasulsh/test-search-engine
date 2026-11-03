<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- fixtures and assertion messages

namespace App\Tests;

use App\VpsCallLog;
use App\VpsClient;
use PDO;

/**
 * Every PHP -> VPS call lands in vps_calls with the right outcome and fields; recording never changes a
 * result and never throws; old rows are purged. Transport stubs give exact fields, the mock VPS over real
 * curl gives real timings.
 */
final class VpsCallLogTest extends DatabaseTestCase
{
    private const URL = 'https://secret-vps.example.com:8600';
    private const TOKEN = 'tok-never-in-the-table-0123';

    private static ?MockVpsServer $mock = null;
    private string $errorLog = '';
    private string $previousErrorLog = '';

    public static function tearDownAfterClass(): void
    {
        self::$mock?->stop();
        self::$mock = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'vpscalls_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    /** @param callable $transport */
    private function client(callable $transport, int $timeoutMs = 600, string $url = self::URL): VpsClient
    {
        return (new VpsClient($url, self::TOKEN, $timeoutMs, $transport, 45000))
            ->recordTo(new VpsCallLog($this->pdo, 30, 1));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM vps_calls ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function only(): array
    {
        $rows = $this->rows();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    public function testASuccessfulSearchRecordsEveryField(): void
    {
        $seen = [];
        $client = $this->client(static function (string $url, array $headers, string $body, int $ms, string $method) use (&$seen): array {
            $seen = compact('headers', 'method');

            return ['status' => 200, 'body' => '{"results":[{"product_id":7,"score":0.9},{"product_id":8,"score":0.2}]}',
                'headers' => ['server-timing' => 'queue;dur=1.5, embed;dur=40.25, search;dur=9', 'x-vps-inflight' => '3'],
            ];
        });

        $results = $client->search('هدفون بی‌سیم', 100, 0.4);

        self::assertCount(1, $results, 'the floor is still applied after recording');
        $row = $this->only();
        self::assertSame(['search', 'ok', 200, 600], [$row['kind'], $row['outcome'], (int) $row['http_status'], (int) $row['timeout_ms']]);
        self::assertSame(2, (int) $row['neighbours'], 'what the VPS returned, before the local floor');
        self::assertSame(strlen('{"results":[{"product_id":7,"score":0.9},{"product_id":8,"score":0.2}]}'), (int) $row['response_bytes']);
        self::assertEqualsWithDelta([1.5, 40.25, 9.0, 3], [(float) $row['vps_queue_ms'], (float) $row['vps_embed_ms'], (float) $row['vps_search_ms'], (int) $row['vps_inflight']], 0.001);
        self::assertSame('هدفون بی‌سیم', $row['normalized_q']);
        self::assertNull($row['error']);
        self::assertSame('POST', $seen['method']);
        self::assertContains('X-Request-Id: ' . $row['request_id'], $seen['headers'], 'the id sent is the id stored');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $row['request_id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $row['ts']);
        $drift = abs(strtotime($row['ts'] . ' UTC') - time());
        self::assertLessThan(5, $drift, 'ts is UTC');
    }

    public function testEveryFailureHasItsOutcomeAndTheReasonNeverHoldsTheUrlOrToken(): void
    {
        $cases = [
            'timeout' => ['timeout', 'timeout', null],
            'unreachable' => ['unreachable: Failed to connect to secret-vps.example.com port 8600 using ' . self::TOKEN, 'unreachable', null],
            'curl missing' => ['curl_unavailable', 'unreachable', null],
            'http 503' => [['status' => 503, 'body' => 'down'], 'http_error', 503],
            'http 401' => [['status' => 401, 'body' => '{}'], 'http_error', 401],
            'bad json' => [['status' => 200, 'body' => 'not json'], 'bad_body', 200],
            'bad shape' => [['status' => 200, 'body' => '{"results":"x"}'], 'bad_body', 200],
            'bad row' => [['status' => 200, 'body' => '{"results":[{"product_id":"a","score":1}]}'], 'bad_body', 200],
        ];
        foreach ($cases as $name => [$response, $outcome, $status]) {
            $this->pdo->exec('DELETE FROM vps_calls');
            $client = $this->client(static fn (): array|string => $response);

            self::assertNull($client->search('laptop', 10, 0.4), $name);
            $row = $this->only();
            self::assertSame($outcome, $row['outcome'], $name);
            self::assertSame($status, $row['http_status'] === null ? null : (int) $row['http_status'], $name);
            self::assertNotSame('', (string) $row['error'], $name);
            self::assertNull($row['neighbours'], $name);
            self::assertStringNotContainsString('secret-vps', (string) $row['error'], $name);
            self::assertStringNotContainsString(self::TOKEN, (string) $row['error'], $name);
            self::assertNotSame('', $client->failure(), 'the error_log reason is unchanged');
        }
    }

    public function testTheErrorLogLineAndFailureReasonAreAsBefore(): void
    {
        $client = $this->client(static fn (): string => 'timeout');

        self::assertNull($client->search('laptop', 10, 0.4));
        self::assertSame('timeout', $client->failure());
    }

    public function testAQueryThatIsNotAProductSearchIsMaskedAndCut(): void
    {
        $ok = static fn (): array => ['status' => 200, 'body' => '{"results":[]}'];
        $this->client($ok)->search('call 09123456789 now', 10, 0.4);
        $this->client($ok)->search(str_repeat('ب', 300), 10, 0.4);
        $this->client($ok)->search('ps5', 10, 0.4);

        $queries = array_column($this->rows(), 'normalized_q');
        self::assertSame(['call # now', '[long query]', 'ps5'], $queries);
    }

    public function testNoCallIsMadeOrRecordedForAnEmptyQuery(): void
    {
        $client = $this->client(static fn (): array => self::fail('no request for an empty query'));

        self::assertNull($client->search('  ', 10, 0.4));
        self::assertSame([], $this->rows());
    }

    public function testReloadAndProbeAreRecordedWithTheirKind(): void
    {
        $answer = ['status' => 200, 'body' => '{"ok":true,"count":5,"model":"m","built_at":"x"}'];
        $client = $this->client(static function () use (&$answer): array {
            return $answer;
        });
        $reloaded = $client->reload();
        $probed = $client->probe();
        $answer = ['status' => 503, 'body' => '{"ok":false}'];
        $failed = $client->probe();
        $answer = ['status' => 200, 'body' => '<html>'];
        $garbage = $client->probe();

        self::assertTrue($reloaded['ok']);
        self::assertTrue($probed['ok']);
        self::assertSame(['ok' => false, 'outcome' => 'http_error', 'status' => 503], array_intersect_key($failed, array_flip(['ok', 'outcome', 'status'])));
        self::assertSame('bad_body', $garbage['outcome']);
        $rows = $this->rows();
        self::assertSame(['reload', 'probe', 'probe', 'probe'], array_column($rows, 'kind'));
        self::assertSame(['ok', 'ok', 'http_error', 'bad_body'], array_column($rows, 'outcome'));
        self::assertSame(45000, (int) $rows[0]['timeout_ms'], 'a reload carries its own limit');
        self::assertSame(600, (int) $rows[1]['timeout_ms']);
        self::assertNull($rows[0]['normalized_q']);
        self::assertNull($rows[1]['normalized_q']);
    }

    public function testTheProbeIsAGetToHealthWithoutTheToken(): void
    {
        $seen = [];
        $client = $this->client(static function (string $url, array $headers, string $body, int $ms, string $method) use (&$seen): array {
            $seen = compact('url', 'headers', 'method', 'body');

            return ['status' => 200, 'body' => '{"ok":true,"count":3}'];
        });

        $client->probe();

        self::assertSame(['https://secret-vps.example.com:8600/health', 'GET', ''], [$seen['url'], $seen['method'], $seen['body']]);
        self::assertStringNotContainsString('Authorization', implode("\n", $seen['headers']));
    }

    public function testALoggingFailureNeverChangesTheResultOrThrows(): void
    {
        $answer = static fn (): array => ['status' => 200, 'body' => '{"results":[{"product_id":7,"score":0.9}]}'];
        $expected = (new VpsClient(self::URL, self::TOKEN, 600, $answer))->search('laptop', 10, 0.4);

        $this->pdo->exec('DROP TABLE vps_calls');
        $recorded = $this->client($answer)->search('laptop', 10, 0.4);

        self::assertSame($expected, $recorded);
        self::assertStringContainsString('vps_calls: could not record a call', (string) file_get_contents($this->errorLog));
        self::assertStringNotContainsString(self::TOKEN, (string) file_get_contents($this->errorLog));
    }

    public function testALoggerThatThrowsOnEverythingIsContained(): void
    {
        // VpsCallLog is final: a connection that cannot run the statement is the realistic failure.
        $dead = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $client = (new VpsClient(self::URL, self::TOKEN, 600, static fn (): array => ['status' => 200, 'body' => '{"results":[],"ok":true}']))
            ->recordTo(new VpsCallLog($dead, 30, 1));

        self::assertSame([], $client->search('laptop', 10, 0.4));
        self::assertSame(['ok' => true, 'status' => 200], array_intersect_key($client->probe(), ['ok' => 1, 'status' => 1]));
    }

    public function testRetentionPurgesOldRowsOnWriteAndKeepsRecentOnes(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO vps_calls (ts, kind, outcome) VALUES (UTC_TIMESTAMP() - INTERVAL :h HOUR, 'search', 'ok')");
        foreach ([24 * 31, 24 * 29, 1] as $hours) {
            $insert->execute(['h' => $hours]);
        }

        $this->client(static fn (): array => ['status' => 200, 'body' => '{"results":[]}'])->search('laptop', 10, 0.4);

        self::assertSame(3, count($this->rows()), 'the 31-day-old row went, the new one came');
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM vps_calls WHERE ts < UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchColumn());
    }

    public function testThePurgeIsRareByDefaultAndBounded(): void
    {
        $this->pdo->exec("INSERT INTO vps_calls (ts, kind, outcome) VALUES (UTC_TIMESTAMP() - INTERVAL 90 DAY, 'search', 'ok')");
        $log = new VpsCallLog($this->pdo, 30);   // one write in 50 purges
        $purged = 0;
        for ($i = 0; $i < 400 && $purged === 0; $i++) {
            $log->record(['kind' => 'search', 'outcome' => 'ok']);
            $purged = (int) $this->pdo->query("SELECT COUNT(*) FROM vps_calls WHERE ts < UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchColumn() === 0 ? 1 : 0;
        }
        self::assertSame(1, $purged, 'a purge happens within a few hundred writes');
        self::assertLessThan(200, $i, 'but not on (nearly) every write');

        $this->pdo->exec('DELETE FROM vps_calls');
        $this->pdo->exec("INSERT INTO vps_calls (ts, kind, outcome) SELECT UTC_TIMESTAMP() - INTERVAL 90 DAY, 'search', 'ok' FROM seq_1_to_700");
        self::assertSame(500, $log->purge(), 'one purge deletes at most a batch');
    }

    public function testUnknownKindAndOutcomeAreNormalised(): void
    {
        (new VpsCallLog($this->pdo, 30, 1))->record(['kind' => 'weird', 'outcome' => 'nope', 'error' => str_repeat('x', 500)]);

        $row = $this->only();
        self::assertSame(['search', 'unreachable'], [$row['kind'], $row['outcome']]);
        self::assertSame(200, strlen((string) $row['error']));
    }

    public function testRealCurlTimingsAndHeadersAgainstTheMockVps(): void
    {
        self::$mock ??= new MockVpsServer([]);
        $mock = self::$mock;
        $mock->setScenario(['products' => [1 => [1.0, 0.0]], 'queries' => ['*' => [1.0, 0.0]], 'token' => self::TOKEN,
            'server_timing' => 'queue;dur=0.5, embed;dur=20, search;dur=4', 'inflight' => 2]);
        $client = (new VpsClient($mock->url, self::TOKEN, 2000))->recordTo(new VpsCallLog($this->pdo, 30, 1));

        self::assertNotNull($client->search('x', 10, 0.1));
        $row = $this->only();
        self::assertSame('ok', $row['outcome']);
        self::assertGreaterThan(0, (int) $row['connect_ms']);
        self::assertGreaterThanOrEqual((int) $row['connect_ms'], (int) $row['ttfb_ms']);
        self::assertGreaterThanOrEqual((int) $row['ttfb_ms'], (int) $row['duration_ms']);
        self::assertEqualsWithDelta([0.5, 20.0, 4.0, 2], [(float) $row['vps_queue_ms'], (float) $row['vps_embed_ms'], (float) $row['vps_search_ms'], (int) $row['vps_inflight']], 0.001);
        self::assertSame(strtolower($row['request_id']), $row['request_id']);
        self::assertSame($row['request_id'], $mock->lastRequest()['request_id'], 'the VPS saw the id that was stored');
    }

    public function testRealCurlTimeoutAfterConnectingHasAConnectTimeButNoFirstByte(): void
    {
        self::$mock ??= new MockVpsServer([]);
        self::$mock->setScenario(['token' => self::TOKEN, 'sleep_ms' => 700]);
        $client = (new VpsClient(self::$mock->url, self::TOKEN, 250))->recordTo(new VpsCallLog($this->pdo, 30, 1));

        $started = microtime(true);
        self::assertNull($client->search('x', 10, 0.1));
        $took = microtime(true) - $started;

        $row = $this->only();
        self::assertSame('timeout', $row['outcome']);
        self::assertGreaterThan(0, (int) $row['connect_ms'], 'connected: the VPS is up but slow');
        self::assertNull($row['ttfb_ms'], 'no first byte before the limit');
        self::assertGreaterThanOrEqual(240, (int) $row['duration_ms']);
        self::assertLessThan(0.65, $took, 'recording adds nothing noticeable to the timeout');
        self::assertStringContainsString('[curl 28]', (string) $row['error']);
    }

    public function testRealCurlUnreachableHasNoConnectTime(): void
    {
        $port = MockVpsServer::freePort();
        $client = (new VpsClient("http://127.0.0.1:{$port}", self::TOKEN, 500))->recordTo(new VpsCallLog($this->pdo, 30, 1));

        self::assertNull($client->search('x', 10, 0.1));

        $row = $this->only();
        self::assertSame('unreachable', $row['outcome']);
        self::assertNull($row['connect_ms']);
        self::assertNull($row['http_status']);
        self::assertStringNotContainsString('127.0.0.1', (string) $row['error'], 'the host is scrubbed from curl\'s message');
        self::assertStringNotContainsString("http://127.0.0.1:{$port}", (string) $row['error']);
    }

    public function testServerTimingParserIgnoresGarbage(): void
    {
        self::assertSame([], VpsClient::serverTiming(''));
        self::assertSame(['queue' => 1.5, 'search' => 2.0], VpsClient::serverTiming('queue;dur=1.5, junk, embed;dur=x, search;dur=2'));
        self::assertSame('ok', VpsClient::outcome(''));
        self::assertSame('http_error', VpsClient::outcome('http_500'));
        self::assertSame('bad_body', VpsClient::outcome('malformed_response'));
        self::assertSame('unreachable', VpsClient::outcome('unreachable: boom'));
    }
}
