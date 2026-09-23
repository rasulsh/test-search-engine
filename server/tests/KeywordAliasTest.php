<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M15: a product listed under one name form is found by another — an owner
 * alias (GTA = Grand Theft Auto = جی تی ای, PS5 = پلی استیشن ۵), a generated
 * catalog synonym, or a Roman numeral (GTA V = GTA 5) — and expansion swaps
 * whole terms only, so it never pulls in unrelated products.
 */
final class KeywordAliasTest extends DatabaseTestCase
{
    private const GTA_EN = 1;
    private const GTA_FA = 2;
    private const PS5_EN = 3;
    private const PS5_FA = 4;
    private const GTAX_CABLE = 5;
    private const CAR_CHARGER = 6;
    private const LAPTOP_FA = 7;

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            $this->row(self::GTA_EN, 'Grand Theft Auto V Premium Edition', 50),
            $this->row(self::GTA_FA, 'بازی جی تی ای ۵ نسخه پریمیوم', 40),
            $this->row(self::PS5_EN, 'PS5 Console Slim 1TB', 30),
            $this->row(self::PS5_FA, 'کنسول پلی استیشن ۵ اسلیم', 20),
            $this->row(self::GTAX_CABLE, 'GTAX HDMI cable', 900),
            $this->row(self::CAR_CHARGER, 'Auto car charger', 800),
            $this->row(self::LAPTOP_FA, 'لپ تاپ ایسوس', 10),
        ]);

        // The shipped, owner-editable alias file plus a generated synonym.
        $this->dataDir = sys_get_temp_dir() . '/alias_' . uniqid('', true);
        mkdir($this->dataDir);
        copy(self::repoRoot() . '/pipeline/aliases.json', $this->dataDir . '/' . Synonyms::ALIASES_FILE);
        file_put_contents($this->dataDir . '/' . Synonyms::SYNONYMS_FILE, '[["laptop", "لپ تاپ"]]');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dataDir . '/*') ?: []);
        rmdir($this->dataDir);
        Synonyms::clearCache();
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $title, int $popularity): array
    {
        return [
            'product_id' => $id,
            'title' => $title,
            'description' => 'store item',
            'stock' => 1,
            'popularity' => $popularity,
        ];
    }

    private function keyword(bool $expand = true, int $maxVariants = 6): Keyword
    {
        return new Keyword(
            $this->pdo,
            'products',
            3,
            20,
            null,
            10.0,
            1.0,
            5.0,
            4,
            6.0,
            $expand ? Synonyms::fromDirectory($this->dataDir, 4) : null,
            $maxVariants
        );
    }

    /** @return list<int> */
    private function ids(string $query, ?Keyword $keyword = null): array
    {
        return array_column(($keyword ?? $this->keyword())->search($query), 'product_id');
    }

    public function testAliasFindsTheProductListedUnderAnotherName(): void
    {
        // Neither GTA product contains "gta": only the aliases connect them.
        self::assertNotContains(self::GTA_EN, $this->ids('gta 5', $this->keyword(false)));

        foreach (['gta 5', 'GTA V', 'Grand Theft Auto 5', 'جی تی ای ۵', 'grand theft auto v'] as $query) {
            $ids = $this->ids($query);
            self::assertContains(self::GTA_EN, $ids, "query {$query}");
            self::assertContains(self::GTA_FA, $ids, "query {$query}");
        }
    }

    public function testPersianAndLatinConsoleNamesFindEachOther(): void
    {
        foreach (['ps5', 'PS 5', 'پلی استیشن ۵', 'پلی‌استیشن ۵ اسلیم', 'playstation 5'] as $query) {
            $ids = $this->ids($query);
            self::assertContains(self::PS5_FA, $ids, "query {$query}");
            if ($query !== 'پلی‌استیشن ۵ اسلیم') {
                self::assertContains(self::PS5_EN, $ids, "query {$query}");
            }
        }
    }

    public function testGeneratedCatalogSynonymsAreUsed(): void
    {
        self::assertSame([], $this->ids('laptop', $this->keyword(false)));
        self::assertSame([self::LAPTOP_FA], $this->ids('laptop'));
    }

    public function testExpansionNeverMatchesInsideAWordOrAPhrase(): void
    {
        // "gtax" is not the alias "gta"; "auto" alone is not "grand theft auto".
        self::assertSame([self::GTAX_CABLE], $this->ids('gtax'));
        self::assertSame($this->ids('auto charger', $this->keyword(false)), $this->ids('auto charger'));
        self::assertSame([self::CAR_CHARGER], $this->ids('auto charger'));
    }

    public function testAliasHitsAreTitleMatchesRankedAboveUnrelatedRows(): void
    {
        $results = $this->keyword()->search('gta 5');

        self::assertSame(
            [self::GTA_EN, self::GTA_FA],
            array_slice(array_column($results, 'product_id'), 0, 2)
        );
        self::assertTrue($results[0]['title_match']);
        self::assertNotContains(self::GTAX_CABLE, array_column($results, 'product_id'));
    }

    public function testOneVariantDisablesExpansion(): void
    {
        self::assertSame($this->ids('gta 5', $this->keyword(false)), $this->ids('gta 5', $this->keyword(true, 1)));
    }

    public function testVariantsMatchTitlesOnly(): void
    {
        // A description that merely mentions an alias is not that product.
        (new ProductLoader($this->pdo, 'products'))->load([[
            'product_id' => 8,
            'title' => 'HDMI cable',
            'description' => 'works with playstation 5 and grand theft auto fans',
            'popularity' => 999,
        ]]);

        self::assertNotContains(8, $this->ids('ps5'));
        self::assertNotContains(8, $this->ids('gta'));
        self::assertContains(8, $this->ids('playstation 5')); // the literal query still searches descriptions
    }

    public function testATableWithoutTheTitleIndexServesTheLiteralQuery(): void
    {
        // A live table from a pre-M15 bundle (before its first reload): no
        // variants, and no error, rather than a full-row scan per variant.
        $this->pdo->exec('ALTER TABLE products DROP INDEX idx_title_scan');
        $keyword = $this->keyword();

        self::assertSame(
            $this->ids('grand theft auto', $this->keyword(false)),
            $this->ids('grand theft auto', $keyword)
        );
        self::assertContains(self::GTA_EN, $this->ids('grand theft auto', $keyword));
        self::assertSame([], $this->ids('gta 5', $keyword));
    }

    public function testSearchEndpointWiringExpandsFromTheDataDirectory(): void
    {
        /** @var array<string, mixed> $config */
        $config = require self::repoRoot() . '/server/config.example.php';
        $config['paths']['data'] = $this->dataDir;
        $config['db']['products_table'] = 'products';
        $config['db']['search_logs_table'] = 'search_logs';

        $response = SearchController::fromConfig($this->pdo, $config)->search(['q' => 'GTA V']);

        self::assertSame('gta 5', $response['query']['normalized']);
        self::assertSame([self::GTA_EN, self::GTA_FA], array_slice($response['product_ids'], 0, 2));
        self::assertNull($response['did_you_mean']);
    }
}
