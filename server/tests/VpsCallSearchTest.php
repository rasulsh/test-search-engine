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

    public function testTheTesterAndAKeywordOnlyDeploymentRecordNothing(): void
    {
        SearchController::fromConfig($this->pdo, $this->config(), $this->vps(), null, false)->search(['q' => 'macbook']);
        SearchController::fromConfig($this->pdo, $this->config())->search(['q' => 'macbook']);

        self::assertSame(0, $this->callRows());
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
