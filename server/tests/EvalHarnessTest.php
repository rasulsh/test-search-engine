<?php

declare(strict_types=1);

namespace App\Tests;

use App\Evaluator;
use App\Keyword;
use App\Logger;
use App\SearchController;
use App\Speller;

/**
 * The eval harness end to end over the committed fixtures/eval_queries.json,
 * driving the real (keyword-tier) search flow against the seeded catalog.
 *
 * The labels are the TRUE bilingual relevant sets, so the keyword tier recovers
 * exactly the in-language half here — a measurable, honest 0.5 recall that Tier 2
 * (cross-language, real model) is meant to lift. That gap is the point of the
 * harness; see the PR's "Needs production validation".
 */
final class EvalHarnessTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
    }

    /** @return list<array{q: string, expected_ids: list<int>}> */
    private function cases(): array
    {
        $path = self::repoRoot() . '/fixtures/eval_queries.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('queries', $decoded);

        return $decoded['queries'];
    }

    private function keywordSearcher(): Evaluator
    {
        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20);
        $logger = new Logger($pdo, 'search_logs');
        $spellerFactory = static fn (): Speller => Speller::fromProducts($pdo, 'products');
        // Keyword-only controller (no vectors wired): the CI-deterministic tier.
        $controller = new SearchController($keyword, $logger, $spellerFactory);

        return new Evaluator(
            static fn (array $case): array =>
                $controller->search(['q' => $case['q'], 'limit' => 20])['product_ids']
        );
    }

    public function testHarnessReportsMeasurableMetrics(): void
    {
        $cases = $this->cases();
        $report = $this->keywordSearcher()->run($cases, 5);

        self::assertSame(count($cases), $report['query_count']);
        self::assertSame(5, $report['k']);

        // Every query recovers at least one labeled product on the keyword tier.
        foreach ($report['per_query'] as $row) {
            self::assertGreaterThanOrEqual(1, $row['hits'], "no hit for query: {$row['q']}");
        }

        // Deterministic on this seed: 1 of each 2-item bilingual label found in
        // top-5 -> recall 0.5, precision 1/5.
        self::assertEqualsWithDelta(0.5, $report['recall_at_k'], 1e-9);
        self::assertEqualsWithDelta(0.2, $report['precision_at_k'], 1e-9);
    }
}
