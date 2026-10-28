<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\Synonyms;

/**
 * M43: "gta6" / "gta 6", "fc27" / "fc 27", "rtx4060" / "rtx 4060" meet each other. Normalization is
 * untouched; the collapsed-name scan takes letter-digit names below collapse_min_length, and an alias
 * group that lists one spelling also answers to the other.
 */
final class KeywordLetterDigitTest extends DatabaseTestCase
{
    private const GTA_SPACED = 1;
    private const GTA_JOINED = 2;
    private const FC_SPACED = 3;
    private const GPU_SPACED = 4;
    private const GTA_V = 5;
    private const PERSIAN_ONLY = 6;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::GTA_SPACED, 'title' => 'GTA 6 PS5', 'popularity' => 5],
            ['product_id' => self::GTA_JOINED, 'title' => 'GTA6 Xbox', 'popularity' => 4],
            ['product_id' => self::FC_SPACED, 'title' => 'EA Sports FC 27', 'popularity' => 3],
            ['product_id' => self::GPU_SPACED, 'title' => 'Nvidia RTX 4060 8GB', 'popularity' => 2],
            ['product_id' => self::GTA_V, 'title' => 'GTA V PS4', 'popularity' => 1],
            ['product_id' => self::PERSIAN_ONLY, 'title' => 'بازی جی تی ای 6', 'popularity' => 1],
        ]);
    }

    /** @return list<int> */
    private function ids(string $query, ?Synonyms $synonyms = null): array
    {
        $keyword = new Keyword($this->pdo, 'products', 3, 20, synonyms: $synonyms, softAndMinResults: 0);
        $ids = array_column($keyword->search($query), 'product_id');
        sort($ids);

        return $ids;
    }

    public function testJoinedAndSpacedNamesFindTheSameProductsWithoutAnAlias(): void
    {
        foreach (['gta6', 'gta 6', 'GTA6', 'GTA-6'] as $query) {
            self::assertSame([self::GTA_SPACED, self::GTA_JOINED], $this->ids($query), $query);
        }
        foreach (['fc27', 'fc 27'] as $query) {
            self::assertSame([self::FC_SPACED], $this->ids($query), $query);
        }
        foreach (['rtx4060', 'rtx 4060'] as $query) {
            self::assertSame([self::GPU_SPACED], $this->ids($query), $query);
        }
    }

    public function testAnAliasWrittenWithOneSpellingServesTheOther(): void
    {
        $synonyms = new Synonyms([['gta 6', 'جی تی ای 6']]);

        self::assertSame(
            [self::GTA_SPACED, self::GTA_JOINED, self::PERSIAN_ONLY],
            $this->ids('gta6', $synonyms)
        );
        self::assertSame([self::PERSIAN_ONLY], $this->ids('بازی جی تی ای 6', $synonyms));
        self::assertNotContains(self::GTA_V, $this->ids('gta6', $synonyms));
    }
}
