<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\IntentMap;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M34 through the production wiring (SearchController::fromConfig): an intent
 * word that no product holds is mapped to a category, which then earns the
 * existing category_match_boost; nothing new is admitted and other queries are
 * untouched. The VPS is FakeVps, answered in-process.
 */
final class SearchControllerIntentTest extends DatabaseTestCase
{
    private const GAMING = 'لوازم جانبی گیمینگ';

    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = TempTree::path('m34_data');
        mkdir($this->dataDir, 0777, true);
        IntentMap::clearCache();
        Synonyms::clearCache();

        // Equal stock / popularity: ties go to the lowest id, so the gaming product (id 2)
        // can only lead through the boost. Product 3 is gaming too but a weak neighbour.
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'alpha mouse', 'category' => 'موس', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 2, 'title' => 'beta racer', 'category' => self::GAMING, 'stock' => 5, 'popularity' => 10],
            ['product_id' => 3, 'title' => 'gamma pad', 'category' => self::GAMING, 'stock' => 5, 'popularity' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dataDir);
        IntentMap::clearCache();
        Synonyms::clearCache();
    }

    private function controller(?string $intentJson, int $maxTerms = 4): SearchController
    {
        if ($intentJson !== null) {
            file_put_contents($this->dataDir . '/' . IntentMap::FILE, $intentJson);
        }
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;
        $config['search']['intent_max_terms'] = $maxTerms;
        $unit = [1.0, 0.0, 0.0];
        $vps = FakeVps::client(['products' => [
            1 => $unit, 2 => $unit,
            3 => [0.6, 0.8, 0.0], // cosine 0.6: above the VPS floor, below the blended floor
        ], 'queries' => ['*' => $unit]]);

        return SearchController::fromConfig($this->pdo, $config, $vps);
    }

    private static function map(): string
    {
        return json_encode(['steering' => ['category' => [self::GAMING]]], JSON_UNESCAPED_UNICODE);
    }

    public function testIntentWordBoostsTheMappedCategoryAboveTheBaselineOrder(): void
    {
        $baseline = $this->controller(null)->search(['q' => 'steering']);
        $boosted = $this->controller(self::map())->search(['q' => 'steering']);

        self::assertSame([1, 2], $baseline['product_ids']);
        self::assertSame([2, 1], $boosted['product_ids']);
    }

    public function testAQueryWithoutAnIntentWordIsUnchangedByTheMap(): void
    {
        $without = $this->controller(null)->search(['q' => 'gizmo']);
        $with = $this->controller(self::map())->search(['q' => 'gizmo']);

        self::assertSame([1, 2], $without['product_ids']);
        self::assertSame($without['product_ids'], $with['product_ids']);
    }

    public function testTheIntentBoostNeverPromotesAFloorDroppedWeakHit(): void
    {
        $boosted = $this->controller(self::map())->search(['q' => 'steering']);

        // Product 3 shares the mapped category but its blended relevance is under the floor.
        self::assertNotContains(3, $boosted['product_ids']);
    }

    public function testTheBoostIsTheExistingCategoryBoostInTheDebugBreakdown(): void
    {
        $debug = $this->controller(self::map())->search(['q' => 'steering'], true)['debug'];

        $boosts = [];
        foreach ($debug['results'] as $row) {
            $boosts[$row['product_id']] = $row['blend']['boosts']['category'];
        }
        self::assertSame(0.1, $boosts[2]);
        self::assertSame(0.0, $boosts[1]);
    }

    public function testMaxTermsBoundsHowManyIntentKeysApply(): void
    {
        $json = json_encode([
            'steering' => ['category' => ['موس']],
            'wheel' => ['category' => [self::GAMING]],
        ], JSON_UNESCAPED_UNICODE);

        // Only the first key (steering -> the mouse category) may apply: the mouse leads.
        $one = $this->controller($json, 1)->search(['q' => 'steering wheel']);
        $two = $this->controller($json, 2)->search(['q' => 'steering wheel']);

        self::assertSame([1, 2], $one['product_ids']);
        self::assertSame([1, 2], $two['product_ids']); // both categories boosted: back to the tie order
    }

    public function testNoIntentMapFileMeansNoChange(): void
    {
        self::assertSame([1, 2], $this->controller(null)->search(['q' => 'steering'])['product_ids']);
    }
}
