<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\TokenStats;

/**
 * M44: a token held by much of the catalog ("بازی", "کارت") or listed as a stopword ("یک") is generic: it
 * can never make a product a keyword match by itself, and it adds little to the score and to the coverage
 * the panel shows. "بازی یک راه خروج" listed PS4 consoles at coverage 1.00 because "بازی" and "یک" matched.
 */
final class KeywordGenericTokenTest extends DatabaseTestCase
{
    private const EXIT_GAME = 1;      // the product named by the query
    private const GENERIC_ONLY = 2;   // only the generic words of the query
    private const HALF = 3;           // one rare word of the query, in the title
    private const CARD_APPLE = 4;
    private const CARD_NINTENDO = 5;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::EXIT_GAME, 'title' => 'بازی راه خروج', 'popularity' => 1],
            ['product_id' => self::GENERIC_ONLY, 'title' => 'کنسول پلی استیشن یک ترابایت بازی', 'popularity' => 900],
            ['product_id' => self::HALF, 'title' => 'بازی خروج اضطراری', 'popularity' => 50],
            ['product_id' => self::CARD_APPLE, 'title' => 'گیفت کارت اپل iTunes', 'tags' => 'apple', 'popularity' => 10],
            ['product_id' => self::CARD_NINTENDO, 'title' => 'گیفت کارت نینتندو eShop', 'popularity' => 800],
        ]);
    }

    private function stats(float $ratio = 0.15, string $stopwords = 'یک'): TokenStats
    {
        return new TokenStats(100, ['بازی' => 60, 'کارت' => 40, 'گیفت' => 10, 'راه' => 3, 'خروج' => 2, 'apple' => 2], $ratio, explode(',', $stopwords));
    }

    private function keyword(?TokenStats $stats): Keyword
    {
        return new Keyword($this->pdo, 'products', 3, 20, stats: $stats);
    }

    public function testGenericWordsAloneNeverQualifyAProduct(): void
    {
        $hits = $this->keyword($this->stats())->search('بازی یک راه خروج');

        self::assertSame(self::EXIT_GAME, $hits[0]['product_id'], 'the product named by the rare words leads');
        self::assertNotContains(self::GENERIC_ONLY, array_column($hits, 'product_id'));
    }

    public function testTheCoverageIsWeightedByRarity(): void
    {
        $keyword = $this->keyword($this->stats());
        $hits = array_column($keyword->search('بازی یک راه خروج'), null, 'product_id');

        // The product holds every rare word; only the stopword "یک" (weight 0.1 of about 1.9) is missing.
        self::assertGreaterThan(0.9, $hits[self::EXIT_GAME]['coverage']);
        self::assertLessThan(1.0, $hits[self::EXIT_GAME]['coverage']);
        // HALF holds "خروج" and the generic "بازی": the missing rare word "راه" costs most of the weight.
        self::assertSame(Keyword::MATCH_PARTIAL, $hits[self::HALF]['match_type']);
        self::assertLessThan(0.6, $hits[self::HALF]['coverage']);
        self::assertGreaterThan(0.3, $hits[self::HALF]['coverage']);
    }

    public function testAQueryOfGenericWordsOnlyHasNoKeywordAnswer(): void
    {
        $keyword = $this->keyword($this->stats());

        self::assertSame([], $keyword->search('بازی یک'));
        self::assertSame([], $keyword->search('بازی'));
        self::assertTrue($keyword->isGenericOnly('بازی یک'));
        self::assertFalse($keyword->isGenericOnly('بازی راه'));
        self::assertNotSame([], $this->keyword(null)->search('بازی'), 'without statistics nothing is generic');
    }

    public function testARareBrandWordOutweighsATwoGenericOnes(): void
    {
        // "گیفت کارت apple": "کارت" is generic; the Nintendo card holds "گیفت" only. It is no longer a
        // partial match worth listing, and the Apple card is the only full one.
        $hits = $this->keyword($this->stats())->search('گیفت کارت apple');

        self::assertSame([self::CARD_APPLE], array_column($hits, 'product_id'));
    }

    public function testWithoutTheStatisticsTheSoftAndTopUpStillListsThePartialCard(): void
    {
        $ids = array_column($this->keyword(null)->search('گیفت کارت apple'), 'product_id');

        self::assertContains(self::CARD_NINTENDO, $ids);
    }

    public function testStopwordsAreGenericWithoutAnyFrequencyFile(): void
    {
        $stats = new TokenStats(0, [], 0.15, ['یک', 'و']);

        self::assertTrue($stats->isGeneric('یک'));
        self::assertFalse($stats->isGeneric('راه'));
        self::assertSame(TokenStats::GENERIC_WEIGHT, $stats->weight('و'));
        self::assertSame(1.0, $stats->weight('راه'));
    }

    public function testAMoreCommonTokenWeighsLessAndTheRatioDecidesWhatIsGeneric(): void
    {
        $stats = $this->stats();

        self::assertTrue($stats->isGeneric('بازی'));
        self::assertFalse($stats->isGeneric('گیفت'));
        self::assertGreaterThan($stats->weight('گیفت'), $stats->weight('خروج'));
        self::assertGreaterThan($stats->weight('راه'), $stats->weight('خروج'));
        self::assertLessThanOrEqual(1.0, $stats->weight('never-seen'));
        self::assertFalse($this->stats(0.7)->isGeneric('بازی'), 'a looser ratio');
        self::assertFalse($this->stats(0.0)->isGeneric('بازی'), 'a ratio of 0 turns the frequency rule off');
    }

    public function testTheFileIsParsedAndValidated(): void
    {
        self::assertSame(
            ['n' => 3, 'df' => ['a' => 2]],
            TokenStats::parse('{"version": 1, "n": 3, "df": {"a": 2}}')
        );
        foreach (['', '[]', '{"version": 2, "n": 3, "df": {}}', '{"version": 1, "n": -1, "df": {}}',
            '{"version": 1, "n": 3, "df": {"a": "x"}}', '{"version": 1, "n": 3}'] as $bad) {
            self::assertNull(TokenStats::parse($bad), $bad);
        }
    }
}
