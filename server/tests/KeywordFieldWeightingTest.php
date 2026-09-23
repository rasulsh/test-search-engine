<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Normalizer;

/**
 * M10: title matches dominate description-only matches, and the query words
 * adjacent in a title earn a phrase bonus. Reproduces the real-catalog cases
 * where long descriptions (console bundles, case fans) outranked the products
 * actually named by the query.
 */
final class KeywordFieldWeightingTest extends DatabaseTestCase
{
    private const CONTROLLER = 1;
    private const BUNDLE = 2;
    private const SCATTERED = 3;
    private const BEARING = 4;
    private const CASE_FAN = 5;

    protected function setUp(): void
    {
        parent::setUp();
        // Polluters are more popular and repeat the words in their descriptions,
        // so popularity or raw FULLTEXT relevance alone would rank them first.
        $this->insert(self::CONTROLLER, 'دسته بازی دوال شاک 4 سونی', 'کنترلر بی سیم پلی استیشن', 10);
        $this->insert(
            self::BUNDLE,
            'کنسول پلی استیشن 5 به همراه دسته',
            'باندل کنسول با دسته دوال سنس. سازگار با دوال شاک و شاک دوال شاک دوال شاک',
            900
        );
        $this->insert(self::SCATTERED, 'دوال سنس با موتور شاک', 'دسته بازی نسل جدید', 5);
        $this->insert(self::BEARING, 'بلبرینگ 6202 صنعتی', 'بلبرینگ شیار عمیق', 1);
        $this->insert(
            self::CASE_FAN,
            'فن کیس 120 میلی متری RGB',
            'فن کیس با بلبرینگ دوگانه، بلبرینگ روان و بی صدا با بلبرینگ ضد لرزش',
            800
        );
    }

    private function insert(int $id, string $title, string $description, int $popularity): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc, popularity)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $title,
            $description,
            Normalizer::normalize($title),
            Normalizer::normalize($description),
            $popularity,
        ]);
    }

    private function keyword(float $title = 10.0, float $desc = 1.0, float $phrase = 5.0): Keyword
    {
        return new Keyword($this->pdo, 'products', 3, 20, null, $title, $desc, $phrase);
    }

    /**
     * @param list<array{product_id: int, score: float, match_type: string, title_match: bool}> $results
     * @return array<int, array{product_id: int, score: float, match_type: string, title_match: bool}>
     */
    private static function byId(array $results): array
    {
        return array_column($results, null, 'product_id');
    }

    public function testTitleMatchOutranksDescriptionOnlyBundle(): void
    {
        $results = $this->keyword()->search('دوال شاک');

        self::assertSame(
            [self::CONTROLLER, self::SCATTERED, self::BUNDLE],
            array_column($results, 'product_id')
        );
        $rows = self::byId($results);
        self::assertTrue($rows[self::CONTROLLER]['title_match']);
        self::assertFalse($rows[self::BUNDLE]['title_match']); // kept, but last
    }

    public function testTitleMatchOutranksDescriptionOnlyCaseFan(): void
    {
        $results = $this->keyword()->search('بلبرینگ');

        self::assertSame([self::BEARING, self::CASE_FAN], array_column($results, 'product_id'));
    }

    public function testPhraseBonusFiresOnlyForAdjacentTitleWords(): void
    {
        // CONTROLLER ("... دوال شاک ...") and SCATTERED ("دوال سنس با موتور شاک")
        // both hold the two words in the title and neither in the description,
        // so they differ by exactly the phrase bonus.
        $rows = self::byId($this->keyword(10.0, 1.0, 5.0)->search('دوال شاک'));
        self::assertEqualsWithDelta(15.0, $rows[self::CONTROLLER]['score'], 1e-6);
        self::assertEqualsWithDelta(10.0, $rows[self::SCATTERED]['score'], 1e-6);

        $flat = self::byId($this->keyword(10.0, 1.0, 0.0)->search('دوال شاک'));
        self::assertEqualsWithDelta(
            $flat[self::CONTROLLER]['score'],
            $flat[self::SCATTERED]['score'],
            1e-6
        );
    }

    public function testPhraseBonusRequiresOrderAndWordStart(): void
    {
        // Reversed order and a mid-word hit are not the phrase.
        $this->insert(10, 'شاک دوال نمونه', 'بدون ربط', 0);
        $this->insert(11, 'ایکسدوال شاک نمونه', 'دوال', 0);

        $rows = self::byId($this->keyword(10.0, 1.0, 5.0)->search('دوال شاک'));

        self::assertEqualsWithDelta(10.0, $rows[10]['score'], 1e-6);
        // Only "شاک" is a word-start hit in 11's title; "دوال" is in its desc.
        self::assertEqualsWithDelta((10.0 + 1.0) / 2, $rows[11]['score'], 1e-6);
    }

    public function testAnyTitleMatchOutranksDescriptionOnlyWhateverTheWeights(): void
    {
        // Weights inverted to favour descriptions: the title band still leads,
        // the weights only reorder inside it.
        $results = $this->keyword(1.0, 100.0, 0.0)->search('دوال شاک');

        self::assertSame(self::BUNDLE, array_column($results, 'product_id')[2]);
    }

    public function testLikeFallbackUsesTheSameFieldWeighting(): void
    {
        // "4" is below min_token_size, so this takes the LIKE path.
        $results = $this->keyword()->search('شاک 4');

        self::assertSame('like', $results[0]['match_type']);
        self::assertSame(self::CONTROLLER, $results[0]['product_id']);
        self::assertTrue($results[0]['title_match']);
    }
}
