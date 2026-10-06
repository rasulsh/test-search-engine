<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Keyword;
use App\Logger;
use App\ProductLoader;
use App\Ranker;
use App\SearchController;
use App\Speller;

/**
 * M16 in the /search flow: all-words keyword hits are not padded with
 * one-word semantic neighbours, a query nothing fully matches falls back to
 * the best partial matches (after the typo / layout recovery), and
 * single-word, SKU and require_all_terms-off requests keep the additive
 * semantic tier. The (fake) VPS neighbours sit above the test's 0.82 floor,
 * as black keyboards and red mice sit close to "red keyboard" in real
 * embeddings.
 */
final class SearchControllerAllTermsTest extends DatabaseTestCase
{
    private const QUERY_VECTOR = [1.0, 0.0, 0.0, 0.0];

    private const RED_KEYBOARD = 1;
    private const BLACK_KEYBOARD = 2;
    private const RED_MOUSE = 3;
    private const TITLE_RED_KEYBOARD = 4;
    private const DESC_RED_KEYBOARD = 5;
    private const RED_HEADSET = 6;
    private const SKU_KEYBOARD = 7;

    /** @var array<string, mixed> */
    private array $vps;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::RED_KEYBOARD, 'title' => 'کیبورد گیمینگ ریزر', 'specs' => 'رنگ: قرمز',
                'description' => '', 'stock' => 1, 'popularity' => 10],
            ['product_id' => self::BLACK_KEYBOARD, 'title' => 'کیبورد گیمینگ لاجیتک', 'specs' => 'رنگ: مشکی',
                'description' => '', 'stock' => 1, 'popularity' => 900],
            ['product_id' => self::RED_MOUSE, 'title' => 'ماوس گیمینگ ریزر', 'specs' => 'رنگ: قرمز',
                'description' => '', 'stock' => 1, 'popularity' => 800],
            ['product_id' => self::TITLE_RED_KEYBOARD, 'title' => 'کیبورد قرمز ارزان',
                'description' => '', 'stock' => 1, 'popularity' => 1],
            ['product_id' => self::DESC_RED_KEYBOARD, 'title' => 'کیبورد اداری',
                'description' => 'بدنه قرمز', 'stock' => 1, 'popularity' => 1],
            ['product_id' => self::RED_HEADSET, 'title' => 'هدست قرمز',
                'description' => '', 'stock' => 1, 'popularity' => 700],
            ['product_id' => self::SKU_KEYBOARD, 'title' => 'کیبورد بی سیم', 'sku' => 'RZ-100',
                'description' => '', 'stock' => 1, 'popularity' => 1],
        ]);

        $this->vps = [
            'queries'  => ['*' => self::QUERY_VECTOR],
            'products' => [
                self::RED_KEYBOARD       => [1.0, 0.0, 0.0, 0.0],
                self::TITLE_RED_KEYBOARD => [0.95, 0.31225, 0.0, 0.0],
                self::BLACK_KEYBOARD     => [0.9, 0.43589, 0.0, 0.0],   // cosine 0.90
                self::RED_MOUSE          => [0.88, 0.0, 0.47497, 0.0],  // cosine 0.88
                self::RED_HEADSET        => [0.0, 0.0, 0.0, 1.0],       // unrelated
            ],
        ];
    }

    /** @param array<string, mixed> $search overrides of the search config */
    private function controller(array $search = [], bool $semantic = false): SearchController
    {
        $config = Config::defaults();
        $config['search'] = $search + ['semantic_min_score' => 0.82] + $config['search'];
        $config['paths']['data'] = sys_get_temp_dir() . '/no-bundle-' . uniqid();

        return SearchController::fromConfig($this->pdo, $config, $semantic ? FakeVps::client($this->vps) : null);
    }

    /** @return list<int> */
    private function ids(string $query, bool $semantic = true, array $search = []): array
    {
        return $this->controller($search, $semantic)->search(['q' => $query])['product_ids'];
    }

    public function testRedKeyboardExcludesBlackKeyboardAndRedNonKeyboard(): void
    {
        $expected = [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD, self::DESC_RED_KEYBOARD];

        self::assertSame($expected, $this->ids('کیبورد قرمز', false));
        // The black keyboard (0.90) and the red mouse (0.88) clear the semantic
        // floor but hold one word each: not appended. Semantic evidence
        // reorders (the title hit with cosine 0.95 leads); the office keyboard, a description-only
        // hit the VPS did not return, falls below the blended floor.
        self::assertSame(
            [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD],
            $this->ids('کیبورد قرمز')
        );
    }

    public function testThreeWordQueryThroughTheController(): void
    {
        $strict = ['soft_and_min_results' => 0];
        self::assertSame([self::RED_KEYBOARD], $this->ids('کیبورد گیمینگ قرمز', true, $strict));
        self::assertSame([self::RED_KEYBOARD], $this->ids('کیبورد گیمینگ قرمز', false, $strict));

        // One full-coverage hit is fewer than soft_and_min_results (3): the
        // two-of-three-words products follow it, never ahead (soft AND, M23).
        $soft = $this->ids('کیبورد گیمینگ قرمز', false);
        self::assertSame(self::RED_KEYBOARD, $soft[0]);
        self::assertGreaterThan(1, count($soft));
    }

    public function testNoProductHoldsEveryWordFallsBackToPartialMatches(): void
    {
        // Nothing is blue: keyboards (one word of two) are served instead.
        $keywordOnly = $this->controller()->search(['q' => 'کیبورد آبی']);
        self::assertEqualsCanonicalizing(
            [self::RED_KEYBOARD, self::BLACK_KEYBOARD, self::TITLE_RED_KEYBOARD, self::DESC_RED_KEYBOARD,
                self::SKU_KEYBOARD],
            $keywordOnly['product_ids']
        );
        self::assertNull($keywordOnly['did_you_mean']);
        self::assertFalse($keywordOnly['did_you_mean_applied']);

        // The fallback holds no all-words hit: neighbours stay additive below.
        $withSemantic = $this->ids('کیبورد آبی');
        // (blended, M20): the keyboards the VPS knows rank by cosine; the red
        // mouse neighbour is appended by relevance; keyword hits with no
        // cosine (office / SKU keyboards) fall below the floor.
        self::assertSame(
            [self::BLACK_KEYBOARD, self::RED_KEYBOARD, self::TITLE_RED_KEYBOARD, self::RED_MOUSE],
            $withSemantic
        );
    }

    public function testNoWordMatchesAtAllStaysEmpty(): void
    {
        self::assertSame([], $this->ids('یخچال آبی', false));
    }

    public function testTypoRecoveryIsTriedBeforeThePartialFallback(): void
    {
        $result = $this->controller()->search(['q' => 'کیبورد قرمذ']);

        self::assertSame('کیبورد قرمز', $result['did_you_mean']);
        self::assertTrue($result['did_you_mean_applied']);
        self::assertSame(
            [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD, self::DESC_RED_KEYBOARD],
            $result['product_ids']
        );
    }

    public function testSingleWordQueryKeepsSemanticNeighbours(): void
    {
        // "قرمز" keyword hits exclude the black keyboard; it is a neighbour.
        self::assertNotContains(self::BLACK_KEYBOARD, $this->ids('قرمز', false));
        $ids = $this->ids('قرمز');
        self::assertContains(self::BLACK_KEYBOARD, $ids);
        // The headset (title match, no cosine) is solid: kept, not floored.
        self::assertContains(self::RED_HEADSET, $ids);
        // Its description-only hit (the office keyboard) is not among the VPS's
        // neighbours above the floor: it falls below the blended floor.
        self::assertNotContains(self::DESC_RED_KEYBOARD, $ids);
    }

    public function testSkuQueryKeepsSemanticNeighbours(): void
    {
        // "RZ-100" tokenizes to two words; a SKU hit stays first and the
        // additive neighbours follow as before M16.
        $ids = $this->ids('RZ-100');

        self::assertSame(self::SKU_KEYBOARD, $ids[0]);
        self::assertContains(self::BLACK_KEYBOARD, $ids);
        self::assertContains(self::RED_MOUSE, $ids);
    }

    public function testRequireAllTermsOffServesPartialMatchesAndNeighbours(): void
    {
        $ids = $this->ids('کیبورد قرمز', true, ['require_all_terms' => false]);

        // The black keyboard's cosine (0.90) cannot lift a one-word match
        // above a product holding both words.
        self::assertEqualsCanonicalizing(
            [self::TITLE_RED_KEYBOARD, self::RED_KEYBOARD],
            array_slice($ids, 0, 2)
        );
        self::assertContains(self::BLACK_KEYBOARD, $ids);
        self::assertContains(self::RED_MOUSE, $ids);
    }

    public function testRequireAllTermsDefaultsOn(): void
    {
        // The controller() config does not override require_all_terms.
        self::assertNotContains(self::BLACK_KEYBOARD, $this->ids('کیبورد قرمز'));
        self::assertTrue(Config::defaults()['search']['require_all_terms']);
    }
}
