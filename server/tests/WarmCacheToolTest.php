<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- fixtures

namespace App\Tests;

/** tools/warm-cache.php as a real process, against a fake Redis server and a mock VPS. */
final class WarmCacheToolTest extends DatabaseTestCase
{
    private ?FakeRedisServer $redis = null;
    private ?MockVpsServer $vps = null;
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        $this->redis = new FakeRedisServer('cache-pw');
        $this->vps = new MockVpsServer([
            'token' => FakeVps::TOKEN,
            'products' => [1011 => [1.0, 0.0, 0.0, 0.0], 1007 => [0.8, 0.6, 0.0, 0.0]],
            'queries' => ['*' => [1.0, 0.0, 0.0, 0.0]],
        ]);
        $this->storage = TempTree::path('warm-storage');
        mkdir($this->storage, 0777, true);
        foreach (['macbook' => 3, 'iphone' => 1] as $query => $times) {
            for ($i = 0; $i < $times; $i++) {
                $this->pdo->prepare('INSERT INTO search_logs (raw_q, normalized_q) VALUES (?, ?)')->execute([$query, $query]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->redis?->stop();
        $this->vps?->stop();
        @unlink($this->storage . '/warm-cache.lock');
        @rmdir($this->storage);
    }

    private function logRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn();
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $overrides
     * @return array{int, string, string} exit status, stdout, stderr
     */
    private function cli(array $args = [], array $overrides = []): array
    {
        $config = TempConfig::write(array_replace_recursive([
            'db' => [
                'dsn' => (string) getenv('SEARCH_TEST_DB_DSN'),
                'user' => getenv('SEARCH_TEST_DB_USER') ?: '',
                'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: '',
            ],
            'paths' => ['data' => $this->storage . '/no-bundle', 'storage' => $this->storage],
            'redis' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => $this->redis->port, 'auth' => 'cache-pw'],
            'vps' => ['url' => $this->vps->url, 'token' => FakeVps::TOKEN, 'timeout_ms' => 1500],
            'search' => ['semantic_min_score' => 0.5],
        ], $overrides));
        $env = array_filter(getenv(), static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'), ARRAY_FILTER_USE_KEY);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/warm-cache.php', ...(preg_grep('/^--sleep-ms/', $args) ? [] : ['--sleep-ms=0']), ...$args],
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

    public function testWarmsThenSkipsWhatIsCachedAndForceRedoesIt(): void
    {
        [$status, $out, $err] = $this->cli();

        self::assertSame([0, ''], [$status, $err]);
        self::assertStringContainsString('warming 2 queries (2 from the log, 0 from the file), depth 300', $out);
        self::assertMatchesRegularExpression('/done: warmed 2, already cached 0, degraded 0, failed 0 in [\d.]+ s/', $out);
        self::assertSame(4, $this->logRows(), 'the warm-up wrote no search_logs row');

        [, $second] = $this->cli();
        self::assertStringContainsString('done: warmed 0, already cached 2', $second);

        [$dryStatus, $dry] = $this->cli(['--dry-run']);
        self::assertSame(0, $dryStatus);
        self::assertStringContainsString('cached    macbook', $dry);
        self::assertStringContainsString('dry run: 2 queries (2 from the log, 0 from the file), 0 to warm, 2 already cached', $dry);

        [, $forced] = $this->cli(['--force']);
        self::assertStringContainsString('done: warmed 2, already cached 0', $forced);
        self::assertSame(4, $this->logRows());
    }

    public function testDryRunOnAnEmptyCacheListsWhatItWouldDoAndWritesNothing(): void
    {
        [$status, $out] = $this->cli(['--dry-run']);

        self::assertSame(0, $status);
        self::assertStringContainsString('would warm macbook', $out);
        self::assertStringContainsString('2 to warm, 0 already cached', $out);
        [, $after] = $this->cli(['--dry-run']);
        self::assertStringContainsString('2 to warm', $after, 'nothing was stored');
    }

    public function testTheQueryFileIsAddedAfterTheLoggedQueries(): void
    {
        $file = TempTree::path('warm-queries') . '.csv';
        file_put_contents($file, "normalized_q,searches,last_seen\nipad,5,2026-01-01\nmacbook,2,2026-01-01\n");

        [$status, $out] = $this->cli(['--queries=' . $file, '--dry-run']);
        @unlink($file);

        self::assertSame(0, $status);
        self::assertStringContainsString('3 queries (2 from the log, 2 from the file)', $out);
        self::assertSame(
            ['would warm macbook', 'would warm iphone', 'would warm ipad'],
            array_values(array_filter(explode("\n", $out), static fn (string $l): bool => str_starts_with($l, 'would warm')))
        );
    }

    public function testADeadVpsLeavesQueriesDegradedAndTheExitCodeOne(): void
    {
        $this->vps->setScenario(['token' => FakeVps::TOKEN, 'status' => 503, 'body' => '{}']);

        [$status, $out] = $this->cli();

        self::assertSame(1, $status);
        self::assertStringContainsString('done: warmed 0, already cached 0, degraded 2, failed 0', $out);
        self::assertStringContainsString('still degraded: macbook', $out);
        [, $dry] = $this->cli(['--dry-run']);
        self::assertStringContainsString('2 to warm', $dry, 'a degraded answer was not cached');
    }

    public function testStopsAfterKFailuresWithAClearMessage(): void
    {
        $this->vps->setScenario(['token' => FakeVps::TOKEN, 'status' => 503, 'body' => '{}']);

        [$status, $out] = $this->cli(['--max-failures=1']);

        self::assertSame(1, $status);
        self::assertStringContainsString('stopping after 1 consecutive VPS failures', $out);
        self::assertStringContainsString('not attempted 1', $out);
    }

    public function testASecondRunWhileOneIsActiveIsRefused(): void
    {
        $lock = fopen($this->storage . '/warm-cache.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        [$status, $out, $err] = $this->cli();
        flock($lock, LOCK_UN);
        fclose($lock);

        self::assertSame(1, $status);
        self::assertStringContainsString('another warm-up is running', $err);
        self::assertSame('', $out);
        [$after] = $this->cli();
        self::assertSame(0, $after, 'the lock is free again');
    }

    public function testAnOffOrUnreachableCacheIsReportedNotSilentlyIgnored(): void
    {
        [$off, , $offErr] = $this->cli([], ['redis' => ['enabled' => false]]);
        [$down, , $downErr] = $this->cli([], ['redis' => ['port' => MockVpsServer::freePort()]]);

        self::assertSame([1, 1], [$off, $down]);
        self::assertStringContainsString('the result cache is off', $offErr);
        self::assertStringContainsString('Redis does not answer', $downErr);
    }

    public function testUsageErrorsExitTwo(): void
    {
        foreach ([['--n=0'], ['--n=5000'], ['--window=2y'], ['--force', '--only-missing'], ['--queries=/nope.csv'], ['--sleep-ms=x']] as $args) {
            [$status, , $err] = $this->cli($args);
            self::assertSame(2, $status, implode(' ', $args));
            self::assertStringStartsWith('warm-cache: ', $err);
        }
    }
}
