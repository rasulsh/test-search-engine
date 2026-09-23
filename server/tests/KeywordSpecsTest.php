<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;

/**
 * M13: attribute pairs and feature titles (normalized_specs) rank between title
 * matches and description-only matches. The description-only product is the
 * most popular, so popularity or raw FULLTEXT relevance alone would rank it
 * first.
 */
final class KeywordSpecsTest extends DatabaseTestCase
{
    private const TITLE = 1;
    private const SPEC = 2;
    private const DESC = 3;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            [
                'product_id'  => self::TITLE,
                'title'       => 'مانیتور ASUS ROG Swift',
                'description' => 'نمایشگر گیمینگ',
                'popularity'  => 1,
            ],
            [
                'product_id'  => self::SPEC,
                'title'       => 'مانیتور گیمینگ ۲۷ اینچ',
                'description' => 'نمایشگر سریع برای بازی',
                // As build.py composes it: attribute pairs, then feature titles.
                'specs'       => 'برند: ASUS ROG | نرخ نوسازی تصویر 540 هرتز | زمان پاسخ‌دهی 0.02 میلی ثانیه',
                'popularity'  => 5,
            ],
            [
                'product_id'  => self::DESC,
                'title'       => 'کابل دیسپلی پورت',
                'description' => 'مناسب مانیتور ASUS ROG با نرخ نوسازی 540 هرتز',
                'popularity'  => 900,
            ],
        ]);
    }

    private function keyword(float $title = 10.0, float $desc = 1.0, float $spec = 6.0): Keyword
    {
        return new Keyword($this->pdo, 'products', 3, 20, null, $title, $desc, 5.0, 4, $spec);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private static function byId(array $results): array
    {
        return array_column($results, null, 'product_id');
    }

    public function testAttributeValueMatchRanksBetweenTitleAndDescription(): void
    {
        $results = $this->keyword()->search('ASUS ROG');

        self::assertSame([self::TITLE, self::SPEC, self::DESC], array_column($results, 'product_id'));
        $rows = self::byId($results);
        self::assertTrue($rows[self::TITLE]['title_match']);
        self::assertSame([false, true], [$rows[self::SPEC]['title_match'], $rows[self::SPEC]['spec_match']]);
        self::assertSame([false, false], [$rows[self::DESC]['title_match'], $rows[self::DESC]['spec_match']]);
    }

    public function testFeatureTitleMatchRanksAboveDescriptionOnly(): void
    {
        $results = $this->keyword()->search('540 هرتز');

        self::assertSame([self::SPEC, self::DESC], array_column($results, 'product_id'));
        self::assertSame('fulltext', $results[0]['match_type']);
    }

    public function testSpecTokensAreScoredWithSpecWeight(): void
    {
        $rows = self::byId($this->keyword(10.0, 1.0, 6.0)->search('540 هرتز'));
        self::assertEqualsWithDelta(6.0, $rows[self::SPEC]['score'], 1e-6);
        self::assertEqualsWithDelta(1.0, $rows[self::DESC]['score'], 1e-6);

        // "گیمینگ" in the title, "540" only in the specs: title band, mixed score.
        $mixed = self::byId($this->keyword(10.0, 1.0, 6.0)->search('گیمینگ 540'));
        self::assertTrue($mixed[self::SPEC]['title_match']);
        self::assertEqualsWithDelta((10.0 + 6.0) / 2, $mixed[self::SPEC]['score'], 1e-6);

        $rows = self::byId($this->keyword(10.0, 1.0, 3.0)->search('540 هرتز'));
        self::assertEqualsWithDelta(3.0, $rows[self::SPEC]['score'], 1e-6);
    }

    public function testSpecBandLeadsDescriptionOnlyWhateverTheWeights(): void
    {
        // Weights inverted to favour descriptions: the band still decides.
        $results = $this->keyword(10.0, 100.0, 0.0)->search('540 هرتز');

        self::assertSame([self::SPEC, self::DESC], array_column($results, 'product_id'));
    }

    public function testNormalizedSpecTextMatchesNormalizedQuery(): void
    {
        // ZWNJ and the decimal point are normalized the same way on both sides.
        self::assertSame([self::SPEC], array_column($this->keyword()->search('پاسخ‌دهی 0.02'), 'product_id'));
        self::assertSame([self::SPEC], array_column($this->keyword()->search('پاسخدهی'), 'product_id'));
    }

    public function testLikeFallbackSearchesSpecs(): void
    {
        // "54" is below min_token_size, so this takes the LIKE path.
        $results = $this->keyword()->search('هرتز 54');

        self::assertSame([self::SPEC, self::DESC], array_column($results, 'product_id'));
        self::assertSame('like', $results[0]['match_type']);
        self::assertTrue($results[0]['spec_match']);
    }

    public function testTableFromBeforeTheSpecsColumnIsStillSearched(): void
    {
        // Live table from a pre-M13 bundle (new code uploaded before the reload).
        $this->pdo->exec('ALTER TABLE products DROP INDEX ft_normalized, DROP COLUMN normalized_specs');
        $this->pdo->exec('ALTER TABLE products ADD FULLTEXT KEY ft_normalized (normalized_title, normalized_desc)');
        $keyword = $this->keyword();

        foreach (['ASUS ROG', 'rog 54'] as $query) { // FULLTEXT and LIKE paths
            $results = $keyword->search($query);
            self::assertContains(self::DESC, array_column($results, 'product_id'), $query);
            self::assertNotContains(self::SPEC, array_column($results, 'product_id'), $query);
            self::assertFalse($results[0]['spec_match']);
        }
    }
}
