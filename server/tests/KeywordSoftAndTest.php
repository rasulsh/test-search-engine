<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M23, soft AND: a multi-word query that fewer than soft_and_min_results
 * products fully match is topped up with partial-coverage matches, ranked below
 * every full-coverage hit; alias variants bridge a transliteration to the Latin
 * title, matching the title, tags and specs (not the description).
 *
 * The Latin "Modern Warfare" product is the motivating case: "مدرن وارفار" held
 * a word nothing matched ("وارفار"), so strict all-words returned nothing.
 */
final class KeywordSoftAndTest extends DatabaseTestCase
{
    private const MW_TITLE = 1;        // Latin title "... Modern Warfare 2"
    private const MW_TAGS = 2;         // unrelated title, tags "modern warfare"
    private const MW_SPECS = 3;        // unrelated title, specs "series: modern warfare"
    private const MW_DESC = 4;         // unrelated title, description mentions modern warfare
    private const MODERN_DESK = 5;     // Persian "modern" only
    private const RED_KEYBOARD = 10;
    private const RED_KEYBOARD_2 = 11;
    private const RED_KEYBOARD_3 = 12;
    private const BLACK_KEYBOARD = 13; // the popular product matching one word
    private const RED_MOUSE = 14;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::MW_TITLE, 'title' => 'Call of Duty Modern Warfare II', 'description' => '',
                'popularity' => 10],
            ['product_id' => self::MW_TAGS, 'title' => 'Activision shooter', 'description' => '',
                'tags' => 'modern warfare', 'popularity' => 10],
            ['product_id' => self::MW_SPECS, 'title' => 'Activision collection', 'description' => '',
                'specs' => 'series: modern warfare', 'popularity' => 10],
            ['product_id' => self::MW_DESC, 'title' => 'Activision poster', 'description' => 'modern warfare fans',
                'popularity' => 10],
            ['product_id' => self::MODERN_DESK, 'title' => 'میز مدرن چوبی', 'description' => '', 'popularity' => 900],
            ['product_id' => self::RED_KEYBOARD, 'title' => 'کیبورد قرمز ریزر', 'description' => '', 'popularity' => 1],
            ['product_id' => self::RED_KEYBOARD_2, 'title' => 'کیبورد قرمز لاجیتک', 'description' => '',
                'popularity' => 1],
            ['product_id' => self::RED_KEYBOARD_3, 'title' => 'کیبورد قرمز ارزان', 'description' => '',
                'popularity' => 1],
            ['product_id' => self::BLACK_KEYBOARD, 'title' => 'کیبورد مشکی', 'description' => '', 'popularity' => 999],
            ['product_id' => self::RED_MOUSE, 'title' => 'ماوس قرمز', 'description' => '', 'popularity' => 999],
        ]);
    }

    /** @param list<list<string>> $groups */
    private function keyword(
        array $groups = [],
        int $minResults = 3,
        float $coverage = 0.5,
        float $penalty = 0.5
    ): Keyword {
        return new Keyword(
            $this->pdo,
            'products',
            3,
            20,
            synonyms: $groups === [] ? null : new Synonyms($groups),
            softAndMinResults: $minResults,
            softAndMinCoverage: $coverage,
            softAndPartialPenalty: $penalty
        );
    }

    /** @return list<int> */
    private function ids(Keyword $keyword, string $query): array
    {
        return array_column($keyword->search($query), 'product_id');
    }

    public function testTransliterationAliasBridgesToTheLatinTitle(): void
    {
        // Nothing holds "وارفار": the desk (modern only) is the only partial match.
        self::assertSame([self::MODERN_DESK], $this->ids($this->keyword(), 'مدرن وارفار'));

        // Seeded alias for the single transliterated token only: the variant
        // "مدرن warfare" holds one of its two words in the title / tags / specs.
        $ids = $this->ids($this->keyword([['وارفار', 'warfare']]), 'مدرن وارفار');
        self::assertContains(self::MW_TITLE, $ids);
        self::assertContains(self::MW_TAGS, $ids);
        self::assertContains(self::MW_SPECS, $ids);
    }

    public function testBothWordsAliasedFindsTheProductWithFullCoverageAboveThePartialOnes(): void
    {
        // Three full-coverage hits would meet soft_and_min_results: ask for four.
        $keyword = $this->keyword([['وارفار', 'warfare'], ['مدرن', 'modern']], 4);
        $hits = $keyword->search('مدرن وارفار');
        $byId = array_column($hits, null, 'product_id');

        // "modern warfare" is a variant (both words swapped): full coverage in the
        // title, tags and specs; the desk holds one of two words and ranks last.
        foreach ([self::MW_TITLE, self::MW_TAGS, self::MW_SPECS] as $id) {
            self::assertNotSame(Keyword::MATCH_PARTIAL, $byId[$id]['match_type'], "product {$id}");
            self::assertSame(1.0, $byId[$id]['coverage']);
        }
        self::assertSame(Keyword::MATCH_PARTIAL, $byId[self::MODERN_DESK]['match_type']);
        self::assertSame(0.5, $byId[self::MODERN_DESK]['coverage']);
        self::assertSame(self::MODERN_DESK, $hits[count($hits) - 1]['product_id']);
        self::assertSame(self::MW_TITLE, $hits[0]['product_id'], 'a title match leads the tag and spec ones');
    }

    public function testAliasVariantsNeverMatchOnADescriptionMention(): void
    {
        $keyword = $this->keyword([['وارفار', 'warfare'], ['مدرن', 'modern']]);

        self::assertNotContains(self::MW_DESC, $this->ids($keyword, 'مدرن وارفار'));
        // ... while the literal query does search descriptions, as before.
        self::assertContains(self::MW_DESC, $this->ids($keyword, 'modern warfare'));
    }

    public function testFullCoverageAlwaysRanksAbovePartialWhateverThePopularity(): void
    {
        $hits = $this->keyword()->search('کیبورد قرمز', null, true);
        // Three full hits meet soft_and_min_results: no partial is added.
        self::assertSame(
            [self::RED_KEYBOARD, self::RED_KEYBOARD_2, self::RED_KEYBOARD_3],
            array_column($hits, 'product_id')
        );

        $topUp = $this->keyword([], 4)->search('کیبورد قرمز');
        $ids = array_column($topUp, 'product_id');
        self::assertSame(
            [self::RED_KEYBOARD, self::RED_KEYBOARD_2, self::RED_KEYBOARD_3],
            array_slice($ids, 0, 3),
            'every full-coverage hit leads'
        );
        self::assertEqualsCanonicalizing([self::BLACK_KEYBOARD, self::RED_MOUSE], array_slice($ids, 3));
        foreach (array_slice($topUp, 3) as $partial) {
            self::assertSame(Keyword::MATCH_PARTIAL, $partial['match_type']);
        }
        // The more popular partial hits never overtake a full one, even with no penalty.
        $noPenalty = array_column($this->keyword([], 4, 0.5, 1.0)->search('کیبورد قرمز'), 'product_id');
        self::assertSame(array_slice($ids, 0, 3), array_slice($noPenalty, 0, 3));
    }

    public function testOneWordNothingMatchesStaysEmpty(): void
    {
        self::assertSame([], $this->ids($this->keyword(), 'بلبرینگ'));
        self::assertSame([], $this->ids($this->keyword([['بلبرینگ', 'ball bearing']]), 'بلبرینگ'));
    }

    public function testMinResultsDecidesWhetherPartialsAreAdded(): void
    {
        // One full hit (the title match): partials only while it is below the minimum.
        self::assertSame([self::MW_TITLE], $this->ids($this->keyword([], 0), 'modern warfare 2'));
        self::assertSame([self::MW_TITLE], $this->ids($this->keyword([], 1), 'modern warfare 2'));
        self::assertGreaterThan(1, count($this->ids($this->keyword([], 2), 'modern warfare 2')));
        // 0 turns soft AND off: strict all-words only.
        self::assertSame([], $this->ids($this->keyword([], 0), 'مدرن وارفار'));
    }

    public function testCoverageRatioSetsHowManyWordsAPartialMatchHolds(): void
    {
        // Three words, no product holds all of them: the Modern Warfare products
        // hold two, the desk one ("میز").
        $query = 'modern warfare میز';
        $oneWord = $this->ids($this->keyword([], 3, 0.3), $query);
        $twoWords = $this->ids($this->keyword([], 3, 0.5), $query);
        $all = $this->ids($this->keyword([], 3, 1.0), $query);

        self::assertContains(self::MODERN_DESK, $oneWord);
        self::assertContains(self::MW_TITLE, $oneWord);
        self::assertContains(self::MW_TITLE, $twoWords);
        self::assertNotContains(self::MODERN_DESK, $twoWords);
        self::assertSame([], $all, 'coverage 1 leaves strict all-words only');
    }

    public function testPartialPenaltyScalesTheKeywordScoreOfPartialHitsOnly(): void
    {
        $scores = static function (Keyword $keyword): array {
            return array_column($keyword->search('مدرن وارفار'), 'score', 'product_id');
        };
        $groups = [['وارفار', 'warfare'], ['مدرن', 'modern']];
        $full = $scores($this->keyword($groups, 4, 0.5, 1.0));
        $halved = $scores($this->keyword($groups, 4, 0.5, 0.5));

        self::assertEqualsWithDelta($full[self::MODERN_DESK] * 0.5, $halved[self::MODERN_DESK], 1e-9);
        self::assertEqualsWithDelta($full[self::MW_TITLE], $halved[self::MW_TITLE], 1e-9);
    }

    public function testAPageFullOfFullCoverageHitsGetsNoTopUp(): void
    {
        // limit 2 < soft_and_min_results 3, yet two full hits fill the page.
        $keyword = $this->keyword();

        self::assertSame(
            [self::RED_KEYBOARD, self::RED_KEYBOARD_2],
            array_column($keyword->search('کیبورد قرمز', 2), 'product_id')
        );
        // Without the cap on the threshold the top-up would run for nothing; assert it did not.
        $selects = function (callable $run): int {
            $count = fn (): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
            $before = $count();
            $run();

            return $count() - $before;
        };
        $strict = $this->keyword([], 0);
        self::assertSame(
            $selects(static fn () => $strict->search('کیبورد قرمز', 2)),
            $selects(static fn () => $keyword->search('کیبورد قرمز', 2))
        );
    }

    public function testAnyTermsRequestsAreUnchanged(): void
    {
        // The any-terms fallback is a different mode: no variants, no top-up logic.
        $hits = $this->keyword()->search('مدرن وارفار', null, false);

        self::assertSame([self::MODERN_DESK], array_column($hits, 'product_id'));
    }

    public function testSearchEndpointWiringReadsAliasesFromTheDataDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/softand_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/' . Synonyms::ALIASES_FILE, '[["وارفار", "warfare"], ["مدرن", "modern"]]');
        Synonyms::clearCache();
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'stock_boost' => 0.1, 'popularity_boost' => 0.1,
            ],
            'paths'  => ['data' => $dir],
        ];

        try {
            $ids = SearchController::fromConfig($this->pdo, $config)->search(['q' => 'مدرن وارفار'])['product_ids'];
            self::assertSame(self::MW_TITLE, $ids[0]);

            // soft_and_min_results = 0 in the config: strict again, but the alias still bridges.
            $config['search']['soft_and_min_results'] = 0;
            $strict = SearchController::fromConfig($this->pdo, $config)->search(['q' => 'مدرن وارفار']);
            self::assertEqualsCanonicalizing(
                [self::MW_TITLE, self::MW_TAGS, self::MW_SPECS],
                $strict['product_ids']
            );
        } finally {
            unlink($dir . '/' . Synonyms::ALIASES_FILE);
            rmdir($dir);
            Synonyms::clearCache();
        }
    }

    public function testPartialMatchesStayWeakInTheHybridBlend(): void
    {
        // The VPS knows the Latin title only. The desk, a partial keyword hit with
        // no semantic support, falls under the relevance floor; the title hit is kept.
        $vps = FakeVps::client([
            'queries'  => ['*' => [1.0, 0.0, 0.0, 0.0]],
            'products' => [self::MW_TITLE => [1.0, 0.0, 0.0, 0.0]],
        ]);
        $dir = sys_get_temp_dir() . '/softand_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/' . Synonyms::ALIASES_FILE, '[["وارفار", "warfare"]]');
        Synonyms::clearCache();
        $config = [
            'db'     => ['products_table' => 'products', 'search_logs_table' => 'search_logs'],
            'search' => [
                'default_limit' => 20, 'min_token_size' => 3, 'semantic_top_k' => 100,
                'stock_boost' => 0.1, 'popularity_boost' => 0.1, 'semantic_min_score' => 0.82,
            ],
            'paths'  => ['data' => $dir],
        ];

        try {
            $keywordOnly = SearchController::fromConfig($this->pdo, $config)->search(['q' => 'مدرن وارفار']);
            self::assertContains(self::MODERN_DESK, $keywordOnly['product_ids']);

            $hybrid = SearchController::fromConfig($this->pdo, $config, $vps)->search(['q' => 'مدرن وارفار']);
            self::assertContains(self::MW_TITLE, $hybrid['product_ids']);
            self::assertNotContains(self::MODERN_DESK, $hybrid['product_ids']);
        } finally {
            unlink($dir . '/' . Synonyms::ALIASES_FILE);
            rmdir($dir);
            Synonyms::clearCache();
        }
    }
}
