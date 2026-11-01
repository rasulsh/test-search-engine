<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\IntentMap;
use App\ProductLoader;
use App\QueryEntities;
use App\SearchController;
use App\Synonyms;
use App\TokenStats;

/**
 * M44 through the production wiring: the brand / platform a query names reorders the keyword-only answer
 * and the hybrid one, a brand nothing carries is reported as `brand_unmatched` (debug, search_logs), and a
 * query of generic words falls back to the owner's intent map.
 */
final class SearchControllerEntitiesTest extends DatabaseTestCase
{
    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = TempTree::path('m44_data');
        mkdir($this->dataDir, 0777, true);
        file_put_contents(
            $this->dataDir . '/aliases.json',
            json_encode([['شیامی', 'شیائومی', 'xiaomi']], JSON_UNESCAPED_UNICODE)
        );
        $this->entities(true);
        Synonyms::clearCache();
        QueryEntities::clearCache();
        IntentMap::clearCache();
        TokenStats::clearCache();
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dataDir);
        Synonyms::clearCache();
        QueryEntities::clearCache();
        IntentMap::clearCache();
        TokenStats::clearCache();
    }

    private function entities(bool $present): void
    {
        $path = $this->dataDir . '/' . QueryEntities::FILE;
        if (!$present) {
            @unlink($path);

            return;
        }
        file_put_contents($path, json_encode([
            'version' => 1,
            'brands' => [
                ['name' => 'xiaomi', 'count' => 1], ['name' => 'asus', 'count' => 1], ['name' => 'lg', 'count' => 1],
            ],
            'platforms' => ['ps4' => ['ps4', 'playstation 4'], 'xbox' => ['xbox one']],
        ], JSON_UNESCAPED_UNICODE));
        QueryEntities::clearCache();
    }

    /** @param array<string, mixed> $search config overrides */
    private function controller(array $search = [], ?array $vectors = null): SearchController
    {
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;
        $config['search'] = $search + $config['search'];
        $vps = $vectors === null
            ? null
            : FakeVps::client(['products' => $vectors, 'queries' => ['*' => [1.0, 0.0, 0.0]]]);

        return SearchController::fromConfig($this->pdo, $config, $vps);
    }

    private function load(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'مانیتور گیمینگ ایسوس ۲۷ اینچ', 'brand' => 'Asus', 'stock' => 1,
                'popularity' => 100],
            ['product_id' => 2, 'title' => 'مانیتور ال جی ۲۷ اینچ', 'brand' => 'LG', 'stock' => 1, 'popularity' => 90],
            ['product_id' => 3, 'title' => 'گوشی موبایل ردمی', 'brand' => 'Xiaomi', 'stock' => 1, 'popularity' => 80],
        ]);
    }

    public function testABrandNoCandidateCarriesWithTheRestOfTheQueryIsReportedUnmatched(): void
    {
        $this->load();

        $out = $this->controller()->search(['q' => 'مانیتور شیامی'], true);

        // Results as before: the monitors of the other brands, in their popularity order.
        self::assertSame([1, 2], array_slice($out['product_ids'], 0, 2));
        self::assertSame(['xiaomi'], $out['debug']['entities']['brand_unmatched']);
        self::assertSame(['xiaomi'], $out['debug']['entities']['brands']);
        self::assertSame(
            'xiaomi',
            $this->pdo->query('SELECT brand_unmatched FROM search_logs ORDER BY id DESC LIMIT 1')->fetchColumn()
        );
    }

    public function testNoBrandRecognizedMeansNoEntityInfo(): void
    {
        $this->load();

        $out = $this->controller()->search(['q' => 'مانیتور ال'], true);

        self::assertNull($out['debug']['entities']);
        $logged = $this->pdo->query('SELECT brand_unmatched FROM search_logs ORDER BY id DESC LIMIT 1');
        self::assertNull($logged->fetchColumn());
    }

    public function testAPlatformNoCandidateAnswersTheRestOfTheQueryWithKeepsTheOrder(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'fifa deluxe xbox one', 'popularity' => 100],
            ['product_id' => 2, 'title' => 'fifa ps4 standard', 'popularity' => 1],
            ['product_id' => 3, 'title' => 'fifa deluxe', 'popularity' => 50],
            // Keeps "ps4" as common as "deluxe" in the table, so the engine's relevance does not decide.
            ['product_id' => 4, 'title' => 'ps4 headset', 'popularity' => 5],
        ]);
        $query = ['q' => 'fifa ps4 deluxe'];

        $off = $this->controller(['entities_enabled' => false])->search($query);
        $on = $this->controller()->search($query, true);

        // Partial matches only, and no ps4 product holds "fifa" and "deluxe" too: nothing to prefer, so the
        // xbox title is not demoted below the ps4 one.
        self::assertSame([1, 3, 2], $off['product_ids']);
        self::assertSame($off['product_ids'], $on['product_ids']);
        self::assertSame(['ps4'], $on['debug']['entities']['platforms']);
        self::assertFalse($on['debug']['entities']['active']['platform']);
    }

    public function testThePlatformOrdersTheHybridAnswerAndKeepsTheRest(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'fifa 24 xbox one', 'popularity' => 100],
            ['product_id' => 2, 'title' => 'fifa 24 ps4', 'popularity' => 1],
            ['product_id' => 3, 'title' => 'fifa 24 switch', 'popularity' => 50],
        ]);
        $same = [1.0, 0.0, 0.0];
        $controller = $this->controller([], [1 => $same, 2 => $same, 3 => $same]);

        $out = $controller->search(['q' => 'fifa ps4'], true);

        self::assertSame(2, $out['product_ids'][0]);
        // The ps4 one first; the xbox one is demoted below the title that names no known platform (the
        // test lexicon has no switch) despite its popularity; nothing is removed.
        self::assertSame([2, 3, 1], $out['product_ids']);
        $rows = array_column($out['debug']['results'], null, 'product_id');
        self::assertSame(1, $rows[2]['blend']['entity']['platform']);
        self::assertSame(-1, $rows[1]['blend']['entity']['platform']);
    }

    public function testBothKnobsAtZeroTurnTheFeatureOff(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'fifa deluxe xbox one', 'popularity' => 100],
            ['product_id' => 2, 'title' => 'fifa ps4 standard', 'popularity' => 1],
        ]);

        $controller = $this->controller(['entity_boost' => 0.0, 'entity_demote' => 0.0]);
        $out = $controller->search(['q' => 'fifa ps4 deluxe'], true);

        self::assertSame([1, 2], $out['product_ids']);
        self::assertNull($out['debug']['entities']);
    }

    public function testTheHybridAnswerBoostsTheBrandAndExplainsIt(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'مانیتور ایسوس ۲۷', 'brand' => 'Asus', 'popularity' => 100],
            ['product_id' => 2, 'title' => 'مانیتور شیائومی ۲۷', 'brand' => 'Xiaomi', 'popularity' => 1],
        ]);
        // Equal cosine: only popularity (and the entity factor) can order them.
        $out = $this->controller([], [1 => [1.0, 0.0, 0.0], 2 => [1.0, 0.0, 0.0]])
            ->search(['q' => 'مانیتور شیامی'], true);

        self::assertSame(2, $out['product_ids'][0], 'the Xiaomi monitor leads');
        $detail = array_column($out['debug']['results'], null, 'product_id');
        self::assertSame(1, $detail[2]['blend']['entity']['brand']);
        self::assertSame(-1, $detail[1]['blend']['entity']['brand']);
        self::assertSame([], $out['debug']['entities']['brand_unmatched']);
    }

    public function testAGenericOnlyQueryFallsBackToTheIntentMapNotToSubstrings(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'کنسول پلی استیشن یک ترابایت', 'category' => 'کنسول', 'popularity' => 900],
            ['product_id' => 2, 'title' => 'ماوس ریزر', 'category' => 'لوازم جانبی گیمینگ', 'popularity' => 5],
        ]);
        file_put_contents(
            $this->dataDir . '/' . TokenStats::FILE,
            json_encode(['version' => 1, 'n' => 10, 'df' => ['بازی' => 6]], JSON_UNESCAPED_UNICODE)
        );
        $without = $this->controller()->search(['q' => 'بازی یک']);

        file_put_contents(
            $this->dataDir . '/' . IntentMap::FILE,
            json_encode(['بازی یک' => ['category' => ['لوازم جانبی گیمینگ']]], JSON_UNESCAPED_UNICODE)
        );
        IntentMap::clearCache();
        $with = $this->controller()->search(['q' => 'بازی یک']);

        self::assertSame([], $without['product_ids'], 'no keyword answer, and no substring scan, for generic words');
        self::assertSame([2], $with['product_ids']);
    }

    /** @param list<array{name: string, count: int, ambiguous?: bool}> $brands */
    private function entityFile(array $brands, int $version = 2): void
    {
        file_put_contents($this->dataDir . '/' . QueryEntities::FILE, json_encode([
            'version' => $version,
            'brands' => $brands,
            'platforms' => ['ps5' => ['ps5', 'playstation 5']],
        ], JSON_UNESCAPED_UNICODE));
        QueryEntities::clearCache();
    }

    private function loadLiveCatalog(): void
    {
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'dobe stand for ps5 slim', 'brand' => 'Dobe', 'stock' => 1],
            ['product_id' => 2, 'title' => 'dobe cooling fan ps5 slim', 'brand' => '', 'stock' => 1],
            ['product_id' => 3, 'title' => 'deadskull ps5 slim skin cover', 'brand' => '', 'stock' => 1],
            ['product_id' => 4, 'title' => 'quest pro vr headset', 'brand' => 'Meta', 'stock' => 1],
            ['product_id' => 5, 'title' => 'logitech g29 racing wheel', 'brand' => '', 'stock' => 1],
            ['product_id' => 6, 'title' => 'case ps5 slim bag navy', 'brand' => 'Nahl', 'stock' => 1],
            ['product_id' => 7, 'title' => 'ps5 slim case Midnight Blue', 'brand' => 'Hori', 'stock' => 1],
            ['product_id' => 8, 'title' => 'dualsense astro bot limited edition', 'brand' => 'Sony', 'stock' => 1],
            ['product_id' => 9, 'title' => 'astro a50 wireless headset', 'brand' => 'Astro', 'stock' => 1],
            ['product_id' => 10, 'title' => 'asus gaming monitor 27 inch', 'brand' => 'Asus', 'stock' => 1],
            ['product_id' => 11, 'title' => 'lg gaming monitor 27 inch', 'brand' => 'LG', 'stock' => 1],
        ]);
    }

    /** @return list<string> the live queries of the "brand asked for, not in results" card */
    public static function liveQueries(): array
    {
        return [
            'nahl case ps5 slim bag navy blue',
            'dualsense astro bot joyful limited edition',
            'dobe ps5 slim stand',
            'deadskull ps5 slim skin',
            'meta quest pro',
            'logitech g29 racing wheel',
        ];
    }

    public function testTheLiveQueriesNeverReportABrandTheResultsShow(): void
    {
        $this->loadLiveCatalog();
        // "blue" and "astro" flagged ambiguous by the build; all others are plain brands.
        $this->entityFile([
            ['name' => 'blue', 'count' => 1, 'ambiguous' => true], ['name' => 'astro', 'count' => 1, 'ambiguous' => true],
            ['name' => 'dobe', 'count' => 1], ['name' => 'deadskull', 'count' => 1], ['name' => 'meta', 'count' => 1],
            ['name' => 'logitech', 'count' => 1], ['name' => 'nahl', 'count' => 1], ['name' => 'sony', 'count' => 1],
        ]);

        foreach (self::liveQueries() as $query) {
            $out = $this->controller()->search(['q' => $query], true);

            self::assertNotEmpty($out['product_ids'], $query);
            self::assertSame([], $out['debug']['entities']['brand_unmatched'] ?? [], $query);
            self::assertNotContains('blue', $out['debug']['entities']['brands'] ?? [], $query);
            self::assertNotContains('astro', $out['debug']['entities']['brands'] ?? [], $query);
        }
    }

    public function testEvidenceInTheTitleAloneMakesABrandMentionedNotUnmatched(): void
    {
        $this->loadLiveCatalog();
        $this->entityFile([
            ['name' => 'deadskull', 'count' => 1], ['name' => 'logitech', 'count' => 1],
        ]);

        $out = $this->controller()->search(['q' => 'deadskull ps5 slim skin'], true);
        $status = $out['debug']['entities']['brand_status']['deadskull'];

        // Product 3 has an empty brand field and names the brand in its title only.
        self::assertSame(
            ['carried' => false, 'mentioned' => true, 'ambiguous' => false, 'unmatched' => false],
            $status
        );
        self::assertSame([], $out['debug']['entities']['brand_unmatched']);
    }

    public function testAnUnflaggedBrandWordIsStillNotUnmatchedWhenCandidatesCarryOrNameIt(): void
    {
        $this->loadLiveCatalog();
        // A version 1 style file: no flags. "blue" is named by a Hori title, "astro" carried by the headset.
        $this->entityFile([['name' => 'blue', 'count' => 1], ['name' => 'astro', 'count' => 1]], 1);

        $blue = $this->controller()->search(['q' => 'nahl case ps5 slim bag navy blue'], true);
        $astro = $this->controller()->search(['q' => 'dualsense astro bot joyful limited edition'], true);

        self::assertSame([], $blue['debug']['entities']['brand_unmatched']);
        self::assertTrue($blue['debug']['entities']['brand_status']['blue']['mentioned']);
        self::assertSame([], $astro['debug']['entities']['brand_unmatched']);
        self::assertFalse($astro['debug']['entities']['brand_status']['astro']['ambiguous']);
    }

    public function testABrandNoCandidateCarriesOrNamesIsStillReported(): void
    {
        $this->loadLiveCatalog();
        $this->entityFile([['name' => 'xiaomi', 'count' => 1], ['name' => 'asus', 'count' => 1]]);

        $out = $this->controller()->search(['q' => 'xiaomi monitor'], true);

        self::assertNotEmpty($out['product_ids']);
        self::assertSame(['xiaomi'], $out['debug']['entities']['brand_unmatched']);
        self::assertTrue($out['debug']['entities']['brand_status']['xiaomi']['unmatched']);
    }

    public function testAnAmbiguousBrandIsReportedWhenItIsTheWholeQuery(): void
    {
        $this->loadLiveCatalog();
        $this->entityFile([['name' => 'astro', 'count' => 1, 'ambiguous' => true]]);

        $out = $this->controller()->search(['q' => 'astro'], true);

        self::assertSame(['astro'], $out['debug']['entities']['brands']);
        self::assertTrue($out['debug']['entities']['brand_status']['astro']['ambiguous']);
        self::assertTrue($out['debug']['entities']['brand_status']['astro']['carried']);
    }

    public function testTheBrandBlocklistKeepsANameFromBeingRecognized(): void
    {
        $this->loadLiveCatalog();
        $this->entityFile([['name' => 'xiaomi', 'count' => 1], ['name' => 'asus', 'count' => 1]]);

        $off = $this->controller(['brand_blocklist' => 'Xiaomi, other'])->search(['q' => 'xiaomi monitor'], true);

        self::assertNull($off['debug']['entities'], 'no brand left to recognize');
    }
}
