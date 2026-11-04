<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- fixtures

namespace App\Tests;

use App\WarmQueue;

/** tools/warm-cache.php --if-pending as a real process, against a fake Redis server and a mock VPS. */
final class WarmCachePendingToolTest extends DatabaseTestCase
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
        $this->storage = TempTree::path('warm-pending');
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
        TempTree::remove($this->storage);
    }

    private function queue(): WarmQueue
    {
        return new WarmQueue($this->storage);
    }

    /** @param array<string, mixed> $overrides */
    private function configFile(array $overrides = []): string
    {
        return TempConfig::write(array_replace_recursive([
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
    }

    /**
     * @param list<string> $args
     * @return array{resource, array<int, resource>}
     */
    private function start(array $args, string $config): array
    {
        $env = array_filter(getenv(), static fn (string $key): bool => !str_starts_with($key, 'SEARCH_'), ARRAY_FILTER_USE_KEY);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/tools/warm-cache.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['SEARCH_CONFIG_FILE' => $config]
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $overrides
     * @return array{int, string, string} exit status, stdout, stderr
     */
    private function cli(array $args = ['--if-pending'], array $overrides = []): array
    {
        [$process, $pipes] = $this->start([...(preg_grep('/^--sleep-ms/', $args) ? [] : ['--sleep-ms=0']), ...$args], $this->configFile($overrides));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function flag(string $requestedAt, array $reasons = ['reload']): void
    {
        file_put_contents($this->storage . '/' . WarmQueue::PENDING_FILE, json_encode(['requested_at' => $requestedAt, 'reasons' => $reasons]));
    }

    private function minutesAgo(int $minutes): string
    {
        return gmdate('c', time() - $minutes * 60);
    }

    public function testWithNoFlagItExitsAtOnceTouchingNothing(): void
    {
        // A dead database and a dead Redis: any connection attempt would be visible as a failure or a file.
        $overrides = ['db' => ['dsn' => 'mysql:host=127.0.0.1;port=1;dbname=none'], 'redis' => ['port' => 1]];

        $started = microtime(true);
        [$status, $out, $err] = $this->cli(['--if-pending'], $overrides);
        [$quietStatus, $quietOut, $quietErr] = $this->cli(['--if-pending', '--quiet'], $overrides);

        self::assertSame([0, '', ''], [$status, $out, $err]);
        self::assertSame([0, '', ''], [$quietStatus, $quietOut, $quietErr]);
        self::assertSame([], glob($this->storage . '/*'), 'no lock, no status, no flag created');
        self::assertLessThan(3.0, microtime(true) - $started, 'two process starts, no work');
    }

    public function testWithAFlagItRunsOnceRemovesTheFlagAtStartAndWritesTheStatus(): void
    {
        $this->flag($this->minutesAgo(1), ['reload', 'cache_flush']);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn();

        [$status, $out, $err] = $this->cli();

        self::assertSame([0, ''], [$status, $err]);
        self::assertStringContainsString('warming 2 queries', $out);
        self::assertFalse($this->queue()->hasFlag());
        $last = $this->queue()->status();
        self::assertSame(2, $last['queries']);
        self::assertSame([2, 0, 0, 0, false, 0], [$last['warmed'], $last['already_cached'], $last['degraded'], $last['failed'], $last['stopped_early'], $last['exit_code']]);
        self::assertSame(['reload', 'cache_flush'], $last['reasons']);
        self::assertNotNull($last['finished_at']);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());

        // The flag is gone, so the next minute is a no-op and the cache is not asked again.
        [$again, $againOut] = $this->cli();
        self::assertSame([0, ''], [$again, $againOut]);
        self::assertSame(2, $this->queue()->status()['queries']);
    }

    public function testQuietPrintsNothingAndTheNumberComesFromTheConfig(): void
    {
        $this->flag($this->minutesAgo(0));

        [$status, $out, $err] = $this->cli(['--if-pending', '--quiet'], ['warm' => ['n' => 50]]);

        self::assertSame([0, '', ''], [$status, $out, $err]);
        self::assertSame(2, $this->queue()->status()['warmed']);
    }

    public function testARequestMadeDuringARunLeavesANewFlagBehind(): void
    {
        $this->flag($this->minutesAgo(1));
        [$process, $pipes] = $this->start(['--if-pending', '--sleep-ms=1500'], $this->configFile());

        $deadline = microtime(true) + 20;
        // The status file is written right after the flag is removed, so its presence marks "the run has started".
        while (($this->queue()->status() === null || !$this->queue()->isRunning()) && microtime(true) < $deadline) {
            usleep(50000);
        }
        self::assertFalse($this->queue()->hasFlag(), 'the flag went when the run started');
        self::assertTrue($this->queue()->isRunning());
        self::assertTrue($this->queue()->request('cache_flush'), 'a request during the run');

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertTrue($this->queue()->hasFlag(), 'the next minute runs again');
        self::assertSame(['cache_flush'], $this->queue()->pending()['reasons']);
        self::assertFalse($this->queue()->isRunning());
    }

    public function testRedisDownKeepsTheFlagForThirtyMinutesThenGivesUp(): void
    {
        $this->redis->stop();
        $this->flag($this->minutesAgo(5));

        [$status, , $err] = $this->cli();
        self::assertSame(1, $status);
        self::assertStringContainsString('Redis does not answer', $err);
        self::assertTrue($this->queue()->hasFlag(), 'retried next minute');
        self::assertNull($this->queue()->status());

        $this->flag($this->minutesAgo(31));
        [$gaveUp, , $gaveUpErr] = $this->cli();
        self::assertSame(1, $gaveUp);
        self::assertStringContainsString('gave up after 30 minutes', $gaveUpErr);
        self::assertFalse($this->queue()->hasFlag(), 'it cannot loop for ever');
        $last = $this->queue()->status();
        self::assertSame(['redis', 1, ['reload']], [$last['error'], $last['exit_code'], $last['reasons']]);
    }

    public function testAHeldLockLeavesTheFlagAndGivesUpToo(): void
    {
        $lock = fopen($this->storage . '/' . WarmQueue::LOCK_FILE, 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->flag($this->minutesAgo(2));

        [$status, , $err] = $this->cli();
        self::assertSame(1, $status);
        self::assertStringContainsString('another warm-up is running', $err);
        self::assertTrue($this->queue()->hasFlag());

        $this->flag($this->minutesAgo(40));
        $this->cli();
        self::assertFalse($this->queue()->hasFlag());
        self::assertSame('lock', $this->queue()->status()['error']);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testAPlainRunKeepsWorkingAndLeavesAPendingFlagAlone(): void
    {
        $this->flag($this->minutesAgo(1));

        [$status, $out] = $this->cli([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('done: warmed 2', $out);
        self::assertTrue($this->queue()->hasFlag(), 'only --if-pending consumes the flag');
        self::assertSame(2, $this->queue()->status()['warmed'], 'the nightly run is recorded too');
    }

    public function testADryRunWritesNeitherStatusNorLock(): void
    {
        [$status] = $this->cli(['--dry-run']);

        self::assertSame(0, $status);
        self::assertSame([], glob($this->storage . '/*'));
    }
}
