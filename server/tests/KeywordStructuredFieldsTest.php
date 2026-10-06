<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Keyword;
use App\ProductLoader;
use App\SearchController;

/**
 * M21: tags (oc_tag names), brand and category are searched fields of their
 * own, weighted tags > brand > specs > category > description, and a tag match
 * counts as a name match (`name_all`).
 */
final class KeywordStructuredFieldsTest extends DatabaseTestCase
{
    private const TAG = 1;
    private const BRAND = 2;
    private const SPEC = 3;
    private const CATEGORY = 4;
    private const DESC = 5;
    private const TITLE = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function seed(): void
    {
        $plain = ['description' => 'نمایشگر', 'popularity' => 1];
        (new ProductLoader($this->pdo, 'products'))->load([
            // Same word in a different field each; the description-only one is the most popular.
            ['product_id' => self::TAG, 'title' => 'بازی اول', 'tags' => 'Zetamax Origins'] + $plain,
            ['product_id' => self::BRAND, 'title' => 'بازی دوم', 'brand' => 'Zetamax'] + $plain,
            ['product_id' => self::SPEC, 'title' => 'بازی سوم', 'specs' => 'سازنده: Zetamax'] + $plain,
            ['product_id' => self::CATEGORY, 'title' => 'بازی چهارم', 'category' => 'Zetamax'] + $plain,
            ['product_id' => self::DESC, 'title' => 'بازی پنجم', 'description' => 'ساخته zetamax', 'popularity' => 900],
            ['product_id' => self::TITLE, 'title' => 'Zetamax console'] + $plain,
        ]);
    }

    private function keyword(): Keyword
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
            null,
            6,
            true,
            8.0,
            7.0,
            5.0,
            // Strict all-words matching is under test; see KeywordSoftAndTest.
            softAndMinResults: 0
        );
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function byId(array $results): array
    {
        return array_column($results, null, 'product_id');
    }

    public function testFieldsRankTitleTagBrandSpecCategoryThenDescription(): void
    {
        $results = $this->keyword()->search('zetamax');

        self::assertSame(
            [self::TITLE, self::TAG, self::BRAND, self::SPEC, self::CATEGORY, self::DESC],
            array_column($results, 'product_id')
        );
    }

    public function testFieldWeightsAreConfigDriven(): void
    {
        // Brand outweighs the tag: the order within the field band follows the weights.
        $keyword = new Keyword(
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
            null,
            6,
            true,
            2.0,
            9.0,
            5.0
        );

        $ids = array_column($keyword->search('zetamax'), 'product_id');

        self::assertSame(self::TITLE, $ids[0]);
        self::assertSame(self::BRAND, $ids[1]);
        // ... and a 2.0 tag weight drops the tag match below the specs (6.0) and category (5.0) ones.
        self::assertGreaterThan(array_search(self::SPEC, $ids, true), array_search(self::TAG, $ids, true));
    }

    public function testTagMatchIsANameMatchButBrandAndCategoryAreNot(): void
    {
        $hits = self::byId($this->keyword()->search('zetamax'));

        self::assertTrue($hits[self::TAG]['name_all']);
        self::assertTrue($hits[self::TAG]['spec_match']);
        self::assertFalse($hits[self::TAG]['title_match']);
        self::assertTrue($hits[self::TITLE]['name_all']);
        foreach ([self::BRAND, self::SPEC, self::CATEGORY, self::DESC] as $id) {
            self::assertFalse($hits[$id]['name_all'], "product {$id}");
        }
    }

    public function testMultiWordTagQueryNeedsEveryWordAcrossTheFields(): void
    {
        $hits = $this->keyword()->search('zetamax origins'); // both words only in product 1's tags

        self::assertSame([self::TAG], array_column($hits, 'product_id'));
        self::assertTrue($hits[0]['name_all']);
    }

    public function testShortTokensTakeTheLikePathOverTagsToo(): void
    {
        $hits = (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 20, 'title' => 'بازی', 'tags' => 'GTA V Online', 'popularity' => 1],
        ]);

        // "gta v" -> normalized "gta 5": the 1-character token forces the LIKE scan.
        self::assertSame(1, $hits);
        self::assertSame([20], array_column($this->keyword()->search('gta 5'), 'product_id'));
    }

    public function testExistingBandsStayAbovePlainDescriptionHits(): void
    {
        $hits = self::byId($this->keyword()->search('zetamax'));

        self::assertGreaterThan($hits[self::DESC]['score'], $hits[self::CATEGORY]['score']);
        self::assertGreaterThan($hits[self::CATEGORY]['score'], $hits[self::TAG]['score']);
    }

    public function testDidYouMeanLearnsTagWordsFromTheTable(): void
    {
        // Franchise names live only in tags: the table-scan dictionary includes them.
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 30, 'title' => 'بازی الف', 'tags' => 'Wolfenstein'],
            ['product_id' => 31, 'title' => 'بازی ب', 'tags' => 'Wolfenstein'],
        ]);

        $speller = \App\Speller::fromProducts($this->pdo, 'products', 2, 2);

        self::assertSame('wolfenstein', $speller->suggest('wolfenstien'));
    }

    public function testLoaderNormalizesTagsBrandAndCategoryLikeThePipeline(): void
    {
        // Contract 1 for the new fields: the tag cases of the shared fixture
        // (pipeline/tests/test_tags.py reads the same file).
        $cases = json_decode(
            (string) file_get_contents(self::repoRoot() . '/fixtures/normalization_cases.json'),
            true
        )['cases'];
        $tagCases = array_values(array_filter(
            $cases,
            static fn (array $c): bool => str_starts_with($c['name'], 'tags:')
        ));
        self::assertGreaterThanOrEqual(3, count($tagCases));

        $rows = [];
        foreach ($tagCases as $i => $case) {
            $rows[] = ['product_id' => 100 + $i, 'title' => 't', 'tags' => $case['input'],
                'brand' => $case['input'], 'category' => $case['input']];
        }
        (new ProductLoader($this->pdo, 'products'))->load($rows);

        foreach ($tagCases as $i => $case) {
            $row = $this->pdo->query('SELECT normalized_tags, normalized_brand, normalized_category FROM products
                WHERE product_id = ' . (100 + $i))->fetch();
            self::assertSame(
                ['normalized_tags' => $case['expected'], 'normalized_brand' => $case['expected'],
                    'normalized_category' => $case['expected']],
                $row,
                $case['name']
            );
        }
    }

    public function testTableFromBeforeM21IsStillSearchable(): void
    {
        // The bundle's table before M21: specs but no tags / brand / category columns.
        $this->pdo->exec('DROP TABLE IF EXISTS products');
        $this->pdo->exec(
            'CREATE TABLE products (
                product_id INT UNSIGNED NOT NULL, title VARCHAR(512) NOT NULL, description MEDIUMTEXT NOT NULL,
                normalized_title VARCHAR(640) NOT NULL, normalized_desc MEDIUMTEXT NOT NULL,
                normalized_specs MEDIUMTEXT NOT NULL, popularity INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (product_id),
                FULLTEXT KEY ft_normalized (normalized_title, normalized_specs, normalized_desc)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            "INSERT INTO products VALUES
                (1, 'x', '', 'zetamax console', '', '', 1),
                (2, 'y', '', 'بازی', '', 'سازنده zetamax', 1),
                (3, 'z', '', 'بازی', 'zetamax در توضیح', '', 1)"
        );

        $ids = array_column($this->keyword()->search('zetamax'), 'product_id');

        self::assertSame([1, 2, 3], $ids);
    }

    public function testTableFromBeforeM13IsStillSearchable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS products');
        $this->pdo->exec(
            'CREATE TABLE products (
                product_id INT UNSIGNED NOT NULL, title VARCHAR(512) NOT NULL, description MEDIUMTEXT NOT NULL,
                normalized_title VARCHAR(640) NOT NULL, normalized_desc MEDIUMTEXT NOT NULL,
                popularity INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (product_id),
                FULLTEXT KEY ft_normalized (normalized_title, normalized_desc)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            "INSERT INTO products VALUES (1, 'x', '', 'zetamax console', '', 1), (2, 'y', '', 'بازی', 'zetamax', 1)"
        );

        self::assertSame([1, 2], array_column($this->keyword()->search('zetamax'), 'product_id'));
    }

    public function testHybridSearchOnATableFromBeforeM21StillWorks(): void
    {
        // The signals query asks for the M21 text columns; without them the
        // ranker just gets no match boosts.
        $this->pdo->exec('DROP TABLE IF EXISTS products');
        $this->pdo->exec(
            "CREATE TABLE products (
                product_id INT UNSIGNED NOT NULL, title VARCHAR(512) NOT NULL, description MEDIUMTEXT NOT NULL,
                normalized_title VARCHAR(640) NOT NULL, normalized_desc MEDIUMTEXT NOT NULL,
                normalized_specs MEDIUMTEXT NOT NULL, brand VARCHAR(255) NOT NULL DEFAULT '',
                category VARCHAR(255) NOT NULL DEFAULT '', model VARCHAR(255) NOT NULL DEFAULT '',
                stock INT NOT NULL DEFAULT 0, popularity INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (product_id),
                FULLTEXT KEY ft_normalized (normalized_title, normalized_specs, normalized_desc)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "INSERT INTO products
                (product_id, title, description, normalized_title, normalized_desc, normalized_specs, stock, popularity)
             VALUES (1, 'x', '', 'zetamax console', '', '', 1, 1)"
        );
        $config = Config::defaults();
        $config['paths']['data'] = sys_get_temp_dir() . '/no-bundle';
        $vps = FakeVps::client(['products' => [1 => [1.0, 0.0]], 'queries' => ['*' => [1.0, 0.0]]]);

        $result = SearchController::fromConfig($this->pdo, $config, $vps)->search(['q' => 'zetamax']);

        self::assertSame([1], $result['product_ids']);
        self::assertArrayHasKey('cosine_scores', $result);
    }
}
