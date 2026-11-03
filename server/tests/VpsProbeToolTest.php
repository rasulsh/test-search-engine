<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- fixtures

namespace App\Tests;

use App\AdminVps;
use App\Config;
use App\VpsClient;
use PDO;

/** tools/vps-probe.php (the cron command) and the probe code it shares with the panel's button. */
final class VpsProbeToolTest extends DatabaseTestCase
{
    private static ?MockVpsServer $mock = null;

    public static function tearDownAfterClass(): void
    {
        self::$mock?->stop();
        self::$mock = null;
    }

    /**
     * @param array<string, mixed> $vps vps config overrides
     * @param list<string> $args
     * @return array{int, string, string} exit status, stdout, stderr
     */
    private function cli(array $vps, array $args = []): array
    {
        $config = TempConfig::write(['vps' => $vps, 'db' => [
            'dsn' => (string) getenv('SEARCH_TEST_DB_DSN'),
            'user' => getenv('SEARCH_TEST_DB_USER') ?: '',
            'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
        ]]);
        $env = array_filter(getenv(), static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'), ARRAY_FILTER_USE_KEY);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/vps-probe.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['SEARCH_CONFIG_FILE' => $config]
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM vps_calls ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testAHealthyVpsRecordsAnOkProbeAndExitsZero(): void
    {
        self::$mock ??= new MockVpsServer(['token' => FakeVps::TOKEN]);
        self::$mock->setScenario(['token' => FakeVps::TOKEN]);

        [$status, $out, $err] = $this->cli(['url' => self::$mock->url, 'token' => FakeVps::TOKEN, 'timeout_ms' => 1500]);

        self::assertSame([0, ''], [$status, $err]);
        self::assertMatchesRegularExpression('/^ok http=200 ms=\d+\n$/', $out);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(['probe', 'ok', 200, 1500], [$rows[0]['kind'], $rows[0]['outcome'], (int) $rows[0]['http_status'], (int) $rows[0]['timeout_ms']]);
        self::assertGreaterThan(0, (int) $rows[0]['connect_ms']);
        self::assertNull($rows[0]['normalized_q']);
        self::assertStringNotContainsString(FakeVps::TOKEN, $out . json_encode($rows));
    }

    public function testAnUnhealthyOrDeadVpsExitsOneAndStillRecords(): void
    {
        self::$mock ??= new MockVpsServer(['token' => FakeVps::TOKEN]);
        self::$mock->setScenario(['token' => FakeVps::TOKEN, 'health' => ['status' => 503, 'body' => ['ok' => false]]]);
        [$unhealthy, $out] = $this->cli(['url' => self::$mock->url, 'token' => FakeVps::TOKEN]);
        [$dead, $deadOut] = $this->cli(['url' => 'http://127.0.0.1:' . MockVpsServer::freePort(), 'timeout_ms' => 500], ['--quiet']);

        self::assertSame(1, $unhealthy);
        self::assertStringStartsWith('http_error http=503', $out);
        self::assertSame([1, ''], [$dead, $deadOut], '--quiet prints nothing');
        self::assertSame(['http_error', 'unreachable'], array_column($this->rows(), 'outcome'));
        self::assertStringNotContainsString('127.0.0.1', (string) $this->rows()[1]['error']);
    }

    public function testWithoutAUrlItRecordsNotConfiguredAndExitsOne(): void
    {
        [$status, $out] = $this->cli([]);

        self::assertSame(1, $status);
        self::assertStringStartsWith('not_configured', $out);
        self::assertSame(['not_configured'], array_column($this->rows(), 'outcome'));
    }

    public function testAMissingTableNeverChangesTheOutcomeOrTheExitCode(): void
    {
        self::$mock ??= new MockVpsServer(['token' => FakeVps::TOKEN]);
        self::$mock->setScenario(['token' => FakeVps::TOKEN]);
        $this->pdo->exec('DROP TABLE vps_calls');

        [$status, $out] = $this->cli(['url' => self::$mock->url]);

        self::assertSame(0, $status);
        self::assertStringStartsWith('ok http=200', $out);
    }

    public function testAnUnknownArgumentIsAUsageError(): void
    {
        [$status, , $err] = $this->cli([], ['--bogus']);

        self::assertSame(2, $status);
        self::assertStringContainsString('usage:', $err);
    }

    public function testTheSharedProbeWithoutAClientRecordsNotConfigured(): void
    {
        $result = AdminVps::probeWith(null, $this->pdo, Config::defaults());

        self::assertSame(['ok' => false, 'outcome' => 'not_configured'], array_intersect_key($result, ['ok' => 1, 'outcome' => 1]));
        self::assertSame('not_configured', $this->rows()[0]['outcome']);
    }

    public function testTheSharedProbeRecordsThroughAnInjectedClient(): void
    {
        $client = new VpsClient('http://vps.test', 't', 300, static fn (): array => ['status' => 200, 'body' => '{"ok":true}']);

        $result = AdminVps::probeWith($client, $this->pdo, Config::defaults());

        self::assertTrue($result['ok']);
        self::assertSame(['probe', 'ok'], [$this->rows()[0]['kind'], $this->rows()[0]['outcome']]);
    }
}
