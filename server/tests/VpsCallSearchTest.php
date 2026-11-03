<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- SQL and assertion messages

namespace App\Tests;

use App\Config;
use App\SearchController;

/**
 * vps_calls through the real /search wiring (SearchController::fromConfig): a cache miss records one row,
 * a cache hit and the admin tester record none, and a broken vps_calls table changes nothing the
 * shopper receives.
 */
final class VpsCallSearchTest extends DatabaseTestCase
{
    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'vpssearch_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = Config::defaults();
        $config['paths']['data'] = sys_get_temp_dir() . '/no-such-data-dir';

        return $config;
    }

    private function callRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM vps_calls')->fetchColumn();
    }

    /** @param array<string, mixed> $scenario */
    private function vps(array $scenario = []): \App\VpsClient
    {
        return FakeVps::client($scenario + ['products' => [1001 => [1.0, 0.0]], 'queries' => ['*' => [1.0, 0.0]]]);
    }

    public function testASearchRecordsOneRowAndAFailingVpsRecordsItsOutcome(): void
    {
        $up = SearchController::fromConfig($this->pdo, $this->config(), $this->vps())->search(['q' => 'macbook']);
        $down = SearchController::fromConfig($this->pdo, $this->config(), $this->vps(['status' => 503]))
            ->search(['q' => 'macbook']);

        self::assertSame([['search', 'ok', 'macbook'], ['search', 'http_error', 'macbook']], array_map(
            'array_values',
            $this->pdo->query('SELECT kind, outcome, normalized_q FROM vps_calls ORDER BY id')->fetchAll()
        ));
        self::assertNotSame([], $up['product_ids']);
        self::assertNotSame([], $down['product_ids'], 'keyword-only results are still served');
        self::assertStringContainsString('semantic tier unavailable (http_503)', (string) file_get_contents($this->errorLog));
    }

    public function testTheTesterRecordsNothing(): void
    {
        SearchController::fromConfig($this->pdo, $this->config(), $this->vps(), null, false)->search(['q' => 'macbook']);
        SearchController::fromConfig($this->pdo, $this->config(), null, null, false)->search(['q' => 'macbook']);

        self::assertSame(0, $this->callRows());
    }

    public function testASearchThatSkipsTheTierByDesignIsRecordedAsSkippedWithTheReason(): void
    {
        // No VPS configured: keyword-only by design.
        SearchController::fromConfig($this->pdo, $this->config())->search(['q' => 'macbook']);
        // A query that normalizes to nothing: the tier is not asked.
        SearchController::fromConfig($this->pdo, $this->config(), $this->vps())->search(['q' => "\u{0640}"]);  // a tatweel alone

        $rows = $this->pdo->query('SELECT kind, outcome, source, error, normalized_q, duration_ms FROM vps_calls ORDER BY id')
            ->fetchAll();
        self::assertSame([
            ['kind' => 'search', 'outcome' => 'skipped', 'source' => 'none', 'error' => 'not_configured',
                'normalized_q' => 'macbook', 'duration_ms' => null],
            ['kind' => 'search', 'outcome' => 'skipped', 'source' => 'none', 'error' => 'empty_query',
                'normalized_q' => '', 'duration_ms' => null],
        ], $rows);
    }

    public function testTheRowRecordsWhatWasSentWhenItIsNotTheNormalizedQuery(): void
    {
        $calls = [];
        $client = FakeVps::client(['products' => [1001 => [1.0, 0.0]], 'queries' => ['*' => [1.0, 0.0]]], $calls);
        $result = SearchController::fromConfig($this->pdo, $this->config(), $client)->search(['q' => 'sont']);

        self::assertTrue($result['did_you_mean_applied'], 'the suggestion replaced the query');
        $row = $this->pdo->query('SELECT * FROM vps_calls ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('sony', $calls[0]['q'], 'the VPS got the applied suggestion');
        self::assertSame('sont', $row['normalized_q'], 'the shopper\'s query stays in normalized_q');
        self::assertSame([1, substr(sha1('sony'), 0, 8)], [(int) $row['sent_differs'], $row['sent_hash']]);
        self::assertSame('ok', $row['outcome']);
        self::assertEqualsWithDelta(12.5, (float) $row['vps_took_ms'], 0.001, 'took_ms of the response body');

        $same = SearchController::fromConfig($this->pdo, $this->config(), $client)->search(['q' => 'macbook']);
        $row = $this->pdo->query('SELECT * FROM vps_calls ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame([0, substr(sha1('macbook'), 0, 8)], [(int) $row['sent_differs'], $row['sent_hash']]);
        self::assertNotEmpty($same['product_ids']);
    }

    public function testABrokenCallTableDoesNotChangeWhatTheShopperGets(): void
    {
        $with = SearchController::fromConfig($this->pdo, $this->config(), $this->vps())->search(['q' => 'macbook']);
        $this->pdo->exec('DROP TABLE vps_calls');

        $without = SearchController::fromConfig($this->pdo, $this->config(), $this->vps())->search(['q' => 'macbook']);

        unset($with['latency_ms'], $without['latency_ms'], $with['search_uuid'], $without['search_uuid']);
        self::assertSame($with, $without);
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn(), 'search_logs still written');
    }
}
