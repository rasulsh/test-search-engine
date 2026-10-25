<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M38: the spoken-Persian alias "پی اس فایو" (seeded in M37) must find the product
 * titled "PS5" through the production wiring, with the shipped alias file and the
 * default (soft AND) search settings, on both the bare phrase and a phrase with an
 * extra word ("دسته پی اس فایو").
 */
final class SearchControllerAliasTest extends DatabaseTestCase
{
    private const PS5 = 1;
    private const PS5_FA = 2;

    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/m38_data_' . uniqid('', true);
        mkdir($this->dataDir, 0777, true);
        copy(self::repoRoot() . '/pipeline/aliases.json', $this->dataDir . '/' . Synonyms::ALIASES_FILE);
        Synonyms::clearCache();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::PS5, 'title' => 'PS5 Console Slim 1TB', 'stock' => 5, 'popularity' => 10],
            ['product_id' => self::PS5_FA, 'title' => 'کنسول پلی استیشن ۵ اسلیم', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 3, 'title' => 'Xbox Series X', 'stock' => 5, 'popularity' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dataDir . '/*') ?: []);
        rmdir($this->dataDir);
        Synonyms::clearCache();
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;

        return SearchController::fromConfig($this->pdo, $config)->search(['q' => $query])['product_ids'];
    }

    public function testSpokenPersianPs5FindsEveryPs5Title(): void
    {
        // "ps5" sits in two owner groups; only the merged group reaches the Persian title.
        $ids = $this->ids('پی اس فایو');
        self::assertContains(self::PS5, $ids);
        self::assertContains(self::PS5_FA, $ids);
        self::assertNotContains(3, $ids);
    }

    public function testExtraWordStillFindsThePs5Title(): void
    {
        // "دسته" (controller) is in no title: the soft-AND top-up serves the alias variant.
        $ids = $this->ids('دسته پی اس فایو');
        self::assertContains(self::PS5, $ids);
        self::assertNotContains(3, $ids);
    }
}
