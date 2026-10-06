<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\ProductLoader;
use App\SearchController;

/**
 * M28: a genre / feature word that is an attribute VALUE shared by many
 * products ("شوتر" in the specs of 25 games) is solid and skips the relevance
 * floor, while a rare spec mention ("بلبرینگ" in one power supply) stays weak
 * and is still floored. The fake VPS knows none of the games (cosine 0), so
 * only the keyword evidence is in play, exactly as for a Persian genre word
 * against English titles.
 */
final class SearchControllerFacetTest extends DatabaseTestCase
{
    private const SHOOTERS = 25;
    private const POWER_SUPPLY = 900;
    private const RED_KEYBOARD = 1000;
    private const RED_KEYBOARD_COUNT = 22;

    protected function setUp(): void
    {
        parent::setUp();
        $rows = [];
        for ($i = 1; $i <= self::SHOOTERS; $i++) {
            $rows[] = ['product_id' => $i, 'title' => "Game Title {$i}", 'specs' => 'ژانر: شوتر | پلتفرم: PS5',
                'description' => '', 'stock' => 1, 'popularity' => $i];
        }
        // A rare spec value: only the power supply carries it.
        $rows[] = ['product_id' => self::POWER_SUPPLY, 'title' => 'Power Supply 750W',
            'specs' => 'فن: بلبرینگ', 'description' => '', 'stock' => 1, 'popularity' => 5];
        // "قرمز" is a common spec value (colour) held by many products; only the
        // keyboards also carry the title word.
        for ($i = 0; $i < self::RED_KEYBOARD_COUNT; $i++) {
            $rows[] = ['product_id' => self::RED_KEYBOARD + $i, 'title' => "کیبورد مدل {$i}",
                'specs' => 'رنگ: قرمز', 'description' => '', 'stock' => 1, 'popularity' => 10];
            $rows[] = ['product_id' => 2000 + $i, 'title' => "ماوس مدل {$i}",
                'specs' => 'رنگ: قرمز', 'description' => '', 'stock' => 1, 'popularity' => 10];
        }
        (new ProductLoader($this->pdo, 'products'))->load($rows);
    }

    /** @return list<int> */
    private function ids(string $query, int $minProducts = 20, int $limit = 20): array
    {
        $config = Config::defaults();
        $config['search']['semantic_min_score'] = 0.82;
        $config['search']['facet_min_products'] = $minProducts;
        $config['paths']['data'] = sys_get_temp_dir() . '/no-bundle-' . uniqid();
        $controller = SearchController::fromConfig($this->pdo, $config, FakeVps::client([
            'queries'  => ['*' => [1.0, 0.0, 0.0, 0.0]],
            'products' => [self::POWER_SUPPLY => [0.0, 0.0, 0.0, 1.0]],
        ]));

        return $controller->search(['q' => $query, 'limit' => $limit])['product_ids'];
    }

    public function testCommonSpecValueReturnsManyProductsByPopularity(): void
    {
        $ids = $this->ids('شوتر', 20, 30);

        self::assertCount(self::SHOOTERS, $ids);
        // Business signals order the exempt hits: most popular first.
        self::assertSame(self::SHOOTERS, $ids[0]);
        self::assertCount(20, $this->ids('شوتر'), 'capped at limit');
    }

    public function testFacetFloorExemptionCanBeSwitchedOff(): void
    {
        // Without the facet rule these spec-only hits are weak and floored away.
        self::assertSame([], $this->ids('شوتر', 0));
    }

    public function testRareSpecMentionStaysFloored(): void
    {
        self::assertSame([], $this->ids('بلبرینگ'));
    }

    public function testAllTermsStillRequiredWithAFacetWord(): void
    {
        $ids = $this->ids('کیبورد قرمز', 20, 50);

        self::assertCount(self::RED_KEYBOARD_COUNT, $ids);
        foreach ($ids as $id) {
            self::assertGreaterThanOrEqual(self::RED_KEYBOARD, $id);
            self::assertLessThan(2000, $id, 'a red mouse holds only one of the words');
        }
    }
}
