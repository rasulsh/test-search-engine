<?php

declare(strict_types=1);

namespace App\Tests;

use App\Evaluator;
use App\Keyword;
use App\Logger;
use App\ProductLoader;
use App\SearchController;
use App\Speller;
use App\Synonyms;

/**
 * The eval harness end to end over the committed fixtures/eval_queries.json,
 * driving the real (keyword-tier) search flow against the seeded catalog.
 *
 * The labels are the TRUE bilingual relevant sets, so the keyword tier recovers
 * exactly the in-language half here — a measurable, honest 0.5 recall that Tier 2
 * (cross-language, real model) is meant to lift. That gap is the point of the
 * harness; see the PR's "Needs production validation".
 *
 * M23 cases ride on the file's own seed_products / seed_aliases: joined vs
 * separated spellings, a transliteration alias, a partial-coverage query and a
 * negative query (nothing must come back).
 */
final class EvalHarnessTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        (new ProductLoader($this->pdo, 'products'))->load($this->fixture()['seed_products']);
    }

    /** @return array{queries: list<array{q: string, expected_ids: list<int>}>, seed_products: list<array<string, mixed>>, seed_aliases: list<list<string>>} */
    private function fixture(): array
    {
        $path = self::repoRoot() . '/fixtures/eval_queries.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('queries', $decoded);

        return $decoded;
    }

    /** @return list<array{q: string, expected_ids: list<int>}> */
    private function cases(): array
    {
        return $this->fixture()['queries'];
    }

    private function keywordSearcher(): Evaluator
    {
        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20, synonyms: new Synonyms($this->fixture()['seed_aliases']));
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

        // Every labeled query recovers at least one labeled product on the keyword
        // tier; a negative query (no labeled product) returns nothing.
        foreach ($report['per_query'] as $row) {
            if ($row['expected'] === 0) {
                self::assertSame(0, $row['retrieved'], "negative query returned results: {$row['q']}");
                continue;
            }
            self::assertGreaterThanOrEqual(1, $row['hits'], "no hit for query: {$row['q']}");
        }
    }

    public function testRecallCasesOfM23(): void
    {
        $report = $this->keywordSearcher()->run($this->cases(), 5);
        $byQuery = array_column($report['per_query'], null, 'q');

        // The first eight (bilingual) cases: 1 of each 2-item label in the top 5
        // (the other language half is Tier 2's job): recall 0.5, precision 1/5.
        foreach (array_slice($report['per_query'], 0, 8) as $row) {
            self::assertEqualsWithDelta(0.5, $row['recall'], 1e-9, $row['q']);
            self::assertEqualsWithDelta(0.2, $row['precision'], 1e-9, $row['q']);
        }
        // Joined and separated spellings find both products (spacing, M23 part 1).
        foreach (['farcry', 'far cry', 'Far Cry', 'dualsense', 'dual sense'] as $query) {
            self::assertEqualsWithDelta(1.0, $byQuery[$query]['recall'], 1e-9, $query);
            self::assertEqualsWithDelta(0.4, $byQuery[$query]['precision'], 1e-9, $query);
        }
        // Soft AND + alias (part 2): the transliterated query finds the Latin title.
        self::assertEqualsWithDelta(1.0, $byQuery['مدرن وارفار']['recall'], 1e-9);
        self::assertEqualsWithDelta(1.0, $byQuery['کیبورد قرمز']['recall'], 1e-9);
        // The negative query scores 1 / 1 because nothing came back.
        self::assertEqualsWithDelta(1.0, $byQuery['بلبرینگ']['recall'], 1e-9);

        // Deterministic on this seed (16 queries).
        self::assertEqualsWithDelta(0.75, $report['recall_at_k'], 1e-9);
        self::assertEqualsWithDelta(0.3125, $report['precision_at_k'], 1e-9);
    }

    public function testFullCoverageLeadsThePartialMatches(): void
    {
        $pdo = $this->pdo;
        $controller = new SearchController(
            new Keyword($pdo, 'products', 3, 20, synonyms: new Synonyms($this->fixture()['seed_aliases'])),
            new Logger($pdo, 'search_logs'),
            static fn (): Speller => Speller::fromProducts($pdo, 'products')
        );

        // One red keyboard (full coverage) leads; the black keyboard and the red
        // mouse, more popular, follow as partial matches.
        $ids = $controller->search(['q' => 'کیبورد قرمز'])['product_ids'];
        self::assertSame(2005, $ids[0]);
        self::assertEqualsCanonicalizing([2006, 2007], array_slice($ids, 1, 2));
        // One-word queries that match nothing stay empty.
        self::assertSame([], $controller->search(['q' => 'بلبرینگ'])['product_ids']);
    }
}
