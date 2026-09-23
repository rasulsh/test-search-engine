<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\Synonyms;

/**
 * M16: a multi-word query matches only products holding EVERY word across
 * title, specs and description combined; products matching some words are
 * excluded. The colour lives in the specs (attributes), as on the real
 * catalog. The black keyboard is the most popular product, so any path that
 * let a one-word match through would surface it.
 */
final class KeywordAllTermsTest extends DatabaseTestCase
{
    private const RED_KEYBOARD = 1;       // keyboard title, red in specs
    private const BLACK_KEYBOARD = 2;     // keyboard title, black in specs
    private const RED_MOUSE = 3;          // red in specs, not a keyboard
    private const TITLE_RED_KEYBOARD = 4; // both words, adjacent, in the title
    private const DESC_RED_KEYBOARD = 5;  // keyboard title, red only in the description
    private const RED_HEADSET = 6;        // red in the title, not a keyboard
    private const BLACK_K2 = 7;           // short model token (LIKE path), black
    private const EN_RED_KEYBOARD = 8;    // English title only (alias variant)
    private const EN_BLACK_KEYBOARD = 9;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            [
                'product_id'  => self::RED_KEYBOARD,
                'title'       => 'کیبورد گیمینگ ریزر',
                'description' => 'نور پس زمینه',
                'specs'       => 'رنگ: قرمز | نوع: مکانیکال',
                'popularity'  => 10,
            ],
            [
                'product_id'  => self::BLACK_KEYBOARD,
                'title'       => 'کیبورد گیمینگ لاجیتک',
                'description' => 'نور پس زمینه',
                'specs'       => 'رنگ: مشکی | نوع: مکانیکال',
                'popularity'  => 900,
            ],
            [
                'product_id'  => self::RED_MOUSE,
                'title'       => 'ماوس گیمینگ ریزر',
                'description' => 'سبک و دقیق',
                'specs'       => 'رنگ: قرمز',
                'popularity'  => 800,
            ],
            [
                'product_id'  => self::TITLE_RED_KEYBOARD,
                'title'       => 'کیبورد قرمز ارزان',
                'description' => '',
                'popularity'  => 1,
            ],
            [
                'product_id'  => self::DESC_RED_KEYBOARD,
                'title'       => 'کیبورد اداری',
                'description' => 'بدنه قرمز و کلیدهای بی صدا',
                'popularity'  => 1,
            ],
            [
                'product_id'  => self::RED_HEADSET,
                'title'       => 'هدست قرمز',
                'description' => '',
                'popularity'  => 700,
            ],
            [
                'product_id'  => self::BLACK_K2,
                'title'       => 'کیبورد مدل k2',
                'description' => '',
                'specs'       => 'رنگ: مشکی',
                'popularity'  => 1,
            ],
            [
                'product_id'  => self::EN_RED_KEYBOARD,
                'title'       => 'Gaming Keyboard Red',
                'description' => '',
                'popularity'  => 1,
            ],
            [
                'product_id'  => self::EN_BLACK_KEYBOARD,
                'title'       => 'Gaming Keyboard Black',
                'description' => '',
                'popularity'  => 600,
            ],
        ]);
    }

    private function keyword(bool $requireAllTerms = true, ?Synonyms $synonyms = null): Keyword
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
            $synonyms,
            6,
            $requireAllTerms
        );
    }

    /** @return list<int> */
    private function ids(string $query, ?Keyword $keyword = null, bool $allTerms = true): array
    {
        return array_column(($keyword ?? $this->keyword())->search($query, null, $allTerms), 'product_id');
    }

    public function testRedKeywordMatchesOnlyProductsHoldingBothWords(): void
    {
        // Title + phrase (15) > title + spec (8) > title + description (5.5).
        self::assertSame(
            [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD, self::DESC_RED_KEYBOARD],
            $this->ids('کیبورد قرمز')
        );
    }

    public function testTitleMatchWithBothWordsRanksAboveTitlePlusSpecMatch(): void
    {
        // Before M16 any spec hit sorted first inside the title band, so the
        // product named "red keyboard" ranked below one with red in its specs.
        $results = $this->keyword()->search('کیبورد قرمز');

        self::assertSame(self::TITLE_RED_KEYBOARD, $results[0]['product_id']);
        self::assertFalse($results[0]['spec_match']);
        self::assertTrue($results[1]['spec_match']);
        self::assertSame(['fulltext'], array_values(array_unique(array_column($results, 'match_type'))));
    }

    public function testThreeWordQueryNeedsAllThree(): void
    {
        // The black keyboard is mechanical but not red; the other red
        // keyboards are not mechanical.
        self::assertSame([self::RED_KEYBOARD], $this->ids('کیبورد قرمز مکانیکال'));
        // Equal field scores: popularity breaks the tie.
        self::assertSame([self::BLACK_KEYBOARD, self::RED_KEYBOARD], $this->ids('کیبورد گیمینگ مکانیکال'));
    }

    public function testShortTokenLikePathNeedsAllWords(): void
    {
        self::assertSame([], $this->ids('k2 قرمز'));
        self::assertSame([self::BLACK_K2], $this->ids('k2 مشکی'));
    }

    public function testAnyTermsRanksByWordsMatchedThenFieldScore(): void
    {
        $results = $this->keyword()->search('کیبورد قرمز مکانیکال', null, false);

        $ids = array_column($results, 'product_id');
        self::assertSame(
            [
                self::RED_KEYBOARD,       // 3 of 3
                self::TITLE_RED_KEYBOARD, // 2 of 3, both in title
                self::BLACK_KEYBOARD,     // 2 of 3, title + spec
                self::DESC_RED_KEYBOARD,  // 2 of 3, title + description
            ],
            array_slice($ids, 0, 4)
        );
        // 1 of 3: title matches (tied score) above the spec-only match.
        $titleTail = array_slice($ids, 4, 2);
        sort($titleTail);
        self::assertSame([self::RED_HEADSET, self::BLACK_K2], $titleTail);
        self::assertSame(self::RED_MOUSE, $ids[6]);
        self::assertCount(7, $results);
        self::assertSame('fulltext', $results[0]['match_type']);
        foreach (array_slice($results, 1) as $row) {
            self::assertSame(Keyword::MATCH_PARTIAL, $row['match_type']);
        }
    }

    public function testAnyTermsOnTheLikePath(): void
    {
        $results = $this->keyword()->search('k2 قرمز', null, false);
        $types = array_column($results, 'match_type', 'product_id');

        self::assertArrayHasKey(self::BLACK_K2, $types);
        self::assertArrayHasKey(self::RED_MOUSE, $types);
        self::assertSame([Keyword::MATCH_PARTIAL], array_values(array_unique($types)));
    }

    public function testRequireAllTermsOffServesPartialMatches(): void
    {
        $keyword = $this->keyword(false);

        self::assertFalse($keyword->requiresAllTerms());
        $ids = $this->ids('کیبورد قرمز', $keyword);
        self::assertSame(
            [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD, self::DESC_RED_KEYBOARD],
            array_slice($ids, 0, 3)
        );
        self::assertContains(self::BLACK_KEYBOARD, $ids);
        self::assertContains(self::RED_MOUSE, $ids);
    }

    public function testSingleWordQueryIsTheSameInBothModes(): void
    {
        foreach (['قرمز', 'k2', 'کیبورد'] as $query) {
            $strict = $this->keyword()->search($query);
            self::assertNotSame([], $strict);
            self::assertSame($strict, $this->keyword(false)->search($query), $query);
            self::assertSame($strict, $this->keyword()->search($query, null, false), $query);
            self::assertNotContains(Keyword::MATCH_PARTIAL, array_column($strict, 'match_type'));
        }
    }

    public function testAllWordsAppliesPerAliasVariant(): void
    {
        // Literal "کیبورد red" matches nothing; the variant "keyboard red"
        // matches the red English title only, not the black one.
        $synonyms = new Synonyms([['کیبورد', 'keyboard'], ['کیبورد گیمینگ', 'gaming keyboard']]);

        self::assertSame([self::EN_RED_KEYBOARD], $this->ids('کیبورد red', $this->keyword(true, $synonyms)));
        // The union across variants: the literal's hits plus the variant's.
        $ids = $this->ids('کیبورد گیمینگ', $this->keyword(true, $synonyms));
        sort($ids);
        self::assertSame(
            [self::RED_KEYBOARD, self::BLACK_KEYBOARD, self::EN_RED_KEYBOARD, self::EN_BLACK_KEYBOARD],
            $ids
        );
    }

    public function testFullVariantHitLeadsPartialLiteralHitsInAnyTermsMode(): void
    {
        // Any-terms: the literal "کیبورد red" holds one word in many products;
        // the variant "keyboard red" holds both in one title.
        $synonyms = new Synonyms([['کیبورد', 'keyboard']]);
        $results = $this->keyword(false, $synonyms)->search('کیبورد red');

        self::assertSame(self::EN_RED_KEYBOARD, $results[0]['product_id']);
        self::assertSame(Keyword::MATCH_ALIAS, $results[0]['match_type']);
        self::assertNotContains(self::EN_BLACK_KEYBOARD, array_column($results, 'product_id'));
        foreach (array_slice($results, 1) as $row) {
            self::assertSame(Keyword::MATCH_PARTIAL, $row['match_type']);
        }
    }
}
