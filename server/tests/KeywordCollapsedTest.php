<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Normalizer;
use App\ProductLoader;

/**
 * M23, collapsed names: "farcry" = "far cry" = "Far Cry", "dualsense" =
 * "dual sense". The collapsed query is also matched against the collapsed
 * title, brand and tags (products.normalized_collapsed) as an additional
 * candidate source with its own weight; ordinary token matching is untouched.
 */
final class KeywordCollapsedTest extends DatabaseTestCase
{
    private const FAR_CRY_6 = 1;       // spaced title
    private const FARCRY_5 = 2;        // joined title
    private const FAR_CRY_TAG = 3;     // the name only in the tags
    private const SOFAR_CRYSTAL = 4;   // "sofar crystal": contains the letters, not the name
    private const DUAL_SENSE_STATION = 5;
    private const DUALSENSE_PAD = 6;
    private const DUAL_SENSE_TAG = 7;
    private const PLAYSTATION_BRAND = 8; // brand "Play Station"
    private const PERSIAN_SPACED = 9;    // "پلی استیشن ۵"
    private const PERSIAN_JOINED = 10;   // "پلی‌استیشن" (ZWNJ)
    private const KEYBOARD = 11;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::FAR_CRY_6, 'title' => 'Far Cry 6 Xbox', 'popularity' => 50],
            ['product_id' => self::FARCRY_5, 'title' => 'Farcry 5 PS4', 'popularity' => 40],
            ['product_id' => self::FAR_CRY_TAG, 'title' => 'Ubisoft shooter collection', 'tags' => 'Far Cry Primal',
                'popularity' => 30],
            ['product_id' => self::SOFAR_CRYSTAL, 'title' => 'Sofar Crystal lamp', 'popularity' => 999],
            ['product_id' => self::DUAL_SENSE_STATION, 'title' => 'Dual Sense Charging Station', 'popularity' => 20],
            ['product_id' => self::DUALSENSE_PAD, 'title' => 'Sony DualSense Controller', 'popularity' => 60],
            ['product_id' => self::DUAL_SENSE_TAG, 'title' => 'Wireless gamepad black', 'tags' => 'dual sense',
                'popularity' => 10],
            ['product_id' => self::PLAYSTATION_BRAND, 'title' => 'Console stand', 'brand' => 'Play Station',
                'popularity' => 10],
            ['product_id' => self::PERSIAN_SPACED, 'title' => 'کنسول پلی استیشن ۵', 'popularity' => 5],
            ['product_id' => self::PERSIAN_JOINED, 'title' => "دسته پلی\u{200C}استیشن", 'popularity' => 4],
            ['product_id' => self::KEYBOARD, 'title' => 'کیبورد قرمز', 'popularity' => 1],
        ]);
    }

    /** Soft AND off by default: its partial matches would blur what the collapsed match adds. */
    private function keyword(float $weight = 9.0, int $minLength = 5, int $softAndMinResults = 0): Keyword
    {
        return new Keyword(
            $this->pdo,
            'products',
            3,
            20,
            collapseWeight: $weight,
            collapseMinLength: $minLength,
            softAndMinResults: $softAndMinResults
        );
    }

    /** @return list<int> */
    private function ids(string $query, ?Keyword $keyword = null): array
    {
        return array_column(($keyword ?? $this->keyword())->search($query), 'product_id');
    }

    /** @param list<int> $expected */
    private function assertSameSet(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $message);
    }

    public function testJoinedAndSeparatedSpellingsReturnTheSameSet(): void
    {
        $expected = [self::FAR_CRY_6, self::FARCRY_5, self::FAR_CRY_TAG];
        foreach (['farcry', 'far cry', 'Far Cry', 'FARCRY', 'far-cry', 'Far  Cry'] as $query) {
            $this->assertSameSet($expected, $this->ids($query), "query {$query}");
        }
        $expected = [self::DUAL_SENSE_STATION, self::DUALSENSE_PAD, self::DUAL_SENSE_TAG];
        foreach (['dualsense', 'dual sense', 'DualSense'] as $query) {
            $this->assertSameSet($expected, $this->ids($query), "query {$query}");
        }
    }

    public function testWithoutCollapsingTheSpellingsDisagree(): void
    {
        // The reason for the feature: token matching alone splits them.
        $off = $this->keyword(0.0);

        self::assertSame([self::FARCRY_5], $this->ids('farcry', $off));
        self::assertNotContains(self::FARCRY_5, $this->ids('far cry', $off));
        self::assertSame([self::FAR_CRY_6, self::FAR_CRY_TAG], $this->ids('far cry', $off));
    }

    public function testTheNameMustStartAtAWord(): void
    {
        // "sofar crystal" holds the letters f-a-r-c-r-y, but not as a name.
        foreach (['farcry', 'far cry'] as $query) {
            self::assertNotContains(self::SOFAR_CRYSTAL, $this->ids($query), "query {$query}");
        }
    }

    public function testBrandAndPersianZwnjSpellingsCollapseToo(): void
    {
        self::assertContains(self::PLAYSTATION_BRAND, $this->ids('playstation'));
        $persian = [self::PERSIAN_SPACED, self::PERSIAN_JOINED];
        $this->assertSameSet($persian, $this->ids('پلی استیشن'));
        $this->assertSameSet($persian, $this->ids('پلی‌استیشن'));
        $this->assertSameSet($persian, $this->ids('پلیاستیشن'));
    }

    public function testRegularMatchesStayAheadOfCollapsedOnes(): void
    {
        $hits = $this->keyword()->search('far cry');

        // The spaced title (phrase match, 15) first; the joined one is a collapsed hit (9).
        self::assertSame(self::FAR_CRY_6, $hits[0]['product_id']);
        $byId = array_column($hits, null, 'product_id');
        self::assertSame(Keyword::MATCH_COLLAPSED, $byId[self::FARCRY_5]['match_type']);
        self::assertSame(9.0, $byId[self::FARCRY_5]['score']);
        self::assertTrue($byId[self::FARCRY_5]['name_all'], 'an identity match is a name match');
        self::assertTrue($byId[self::FARCRY_5]['title_match']);
        self::assertGreaterThan($byId[self::FARCRY_5]['score'], $byId[self::FAR_CRY_6]['score']);
    }

    public function testCollapsedHitsAreBandedByWhereTheNameIs(): void
    {
        $byId = array_column($this->keyword()->search('dualsense'), null, 'product_id');

        // In the title: the title band. Only in the tags: the structured-field band
        // (like a tag match), still a name match.
        self::assertSame(Keyword::MATCH_COLLAPSED, $byId[self::DUAL_SENSE_STATION]['match_type']);
        self::assertTrue($byId[self::DUAL_SENSE_STATION]['title_match']);
        self::assertSame(Keyword::MATCH_COLLAPSED, $byId[self::DUAL_SENSE_TAG]['match_type']);
        self::assertFalse($byId[self::DUAL_SENSE_TAG]['title_match']);
        self::assertTrue($byId[self::DUAL_SENSE_TAG]['spec_match']);
        self::assertTrue($byId[self::DUAL_SENSE_TAG]['name_all']);
        // The regular title token match leads both.
        self::assertSame(self::DUALSENSE_PAD, $byId[self::DUALSENSE_PAD]['product_id']);
        self::assertSame('fulltext', $byId[self::DUALSENSE_PAD]['match_type']);
    }

    public function testAProductTheTokensAlreadyFoundKeepsItsHit(): void
    {
        // Product 3 holds "far cry" in its tags: the token match finds it, and its
        // tag-band hit (not a collapsed title-band one) is what the ranking sees.
        $byId = array_column($this->keyword()->search('far cry'), null, 'product_id');

        self::assertSame('fulltext', $byId[self::FAR_CRY_TAG]['match_type']);
        self::assertFalse($byId[self::FAR_CRY_TAG]['title_match']);
        self::assertSame(Keyword::MATCH_COLLAPSED, $byId[self::FARCRY_5]['match_type']);
    }

    public function testCollapseWeightIsConfigDriven(): void
    {
        // "Far" in the title, "cry" only in the description: a title-band hit scoring
        // (10 + 1) / 2 = 5.5.
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 20, 'title' => 'Far Horizon', 'description' => 'a cry for help', 'popularity' => 1],
        ]);
        $position = static fn (array $hits, int $id): int|false =>
            array_search($id, array_column($hits, 'product_id'), true);

        $heavy = $this->keyword(9.0)->search('far cry');
        self::assertLessThan($position($heavy, 20), $position($heavy, self::FARCRY_5));

        $light = $this->keyword(4.5)->search('far cry');
        self::assertSame(4.5, array_column($light, 'score', 'product_id')[self::FARCRY_5]);
        self::assertGreaterThan($position($light, 20), $position($light, self::FARCRY_5));
    }

    public function testSoftAndPartialsRankBelowTheCollapsedHit(): void
    {
        // With soft AND on (the default), the "cry*" prefix of "crystal" is a partial
        // match of "far cry": it follows every full-coverage hit, the collapsed one included.
        $hits = $this->keyword(9.0, 5, 4)->search('far cry');
        $ids = array_column($hits, 'product_id');

        $partial = array_search(self::SOFAR_CRYSTAL, $ids, true);
        self::assertNotFalse($partial);
        self::assertSame(Keyword::MATCH_PARTIAL, $hits[$partial]['match_type']);
        foreach ([self::FAR_CRY_6, self::FAR_CRY_TAG, self::FARCRY_5] as $id) {
            self::assertLessThan($partial, array_search($id, $ids, true), "product {$id}");
        }
    }

    public function testShortCollapsedQueriesAreNotTried(): void
    {
        // "far cry" collapses to 6 characters.
        self::assertContains(self::FARCRY_5, $this->ids('far cry', $this->keyword(9.0, 6)));
        self::assertNotContains(self::FARCRY_5, $this->ids('far cry', $this->keyword(9.0, 7)));
    }

    public function testOrdinaryQueriesAreUnchanged(): void
    {
        $on = $this->keyword();
        $off = $this->keyword(0.0);

        foreach (['کیبورد قرمز', 'ubisoft shooter', 'sony controller', 'far cry 6', 'xbox', 'کیبورد'] as $query) {
            self::assertSame($this->ids($query, $off), $this->ids($query, $on), "query {$query}");
        }
        self::assertSame(self::FAR_CRY_6, $this->ids('far cry 6')[0]);
        self::assertSame(self::DUALSENSE_PAD, $this->ids('sony dualsense')[0]);
    }

    public function testHitsAreNotDuplicated(): void
    {
        foreach (['far cry', 'farcry', 'dual sense'] as $query) {
            $ids = $this->ids($query);
            self::assertSame($ids, array_values(array_unique($ids)), "query {$query}");
        }
    }

    public function testTheScanIsSkippedWhenTheTitleHitsAlreadyFillThePage(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = ['product_id' => 100 + $i, 'title' => 'Zetaquark gadget ' . $i, 'popularity' => 1];
        }
        (new ProductLoader($this->pdo, 'products'))->load($rows);
        $keyword = $this->keyword();
        $selects = function (callable $run): int {
            $count = fn (): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
            $before = $count();
            $run();

            return $count() - $before;
        };

        // 20 title hits scoring 10 fill the page: nothing collapsed (9) can enter it.
        $full = $selects(static fn () => $keyword->search('zetaquark', 20));
        // A page with room left runs the scan: exactly one more SELECT.
        $room = $selects(static fn () => $keyword->search('zetaquark', 30));

        self::assertSame($full + 1, $room);
        self::assertSame(
            array_slice(array_column($keyword->search('zetaquark', 30), 'product_id'), 0, 20),
            array_column($keyword->search('zetaquark', 20), 'product_id')
        );
    }

    public function testIdentityCompositionMatchesTheSharedFixture(): void
    {
        // Same cases as pipeline/tests/test_collapsed.py: build.py and the PHP loader agree.
        $fixture = json_decode(
            (string) file_get_contents(self::repoRoot() . '/fixtures/normalization_cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertNotEmpty($fixture['identity_cases']);
        foreach ($fixture['identity_cases'] as $case) {
            self::assertSame(
                $case['expected'],
                ProductLoader::collapsedIdentity($case['title'], $case['brand'], $case['tags']),
                $case['name']
            );
        }
    }

    public function testLoaderStoresTheCollapsedIdentity(): void
    {
        $row = $this->pdo->query(
            'SELECT normalized_collapsed FROM products WHERE product_id = ' . self::FAR_CRY_TAG
        )->fetchColumn();

        self::assertSame('ubisoftshootercollection farcryprimal', $row);
        self::assertSame('farcry', Normalizer::collapse('Far Cry'));
        // The width of the column: long tag lists are cut, never rejected.
        $long = ProductLoader::collapsedIdentity('Title', 'Brand', str_repeat('word ', 400));
        self::assertSame(700, mb_strlen($long));
        self::assertStringStartsWith('title brand wordword', $long);
    }

    public function testATableWithoutTheColumnIsSearchedWithoutIt(): void
    {
        // A live table from a pre-M23 bundle (before its first reload).
        $this->pdo->exec('ALTER TABLE products DROP INDEX idx_collapsed_scan, DROP COLUMN normalized_collapsed');
        $keyword = $this->keyword();

        self::assertSame([self::FARCRY_5], $this->ids('farcry', $keyword));
        self::assertSame([self::FAR_CRY_6, self::FAR_CRY_TAG], $this->ids('far cry', $keyword));
        // ... and keeps working on the second call, without retrying the column.
        self::assertSame([self::FARCRY_5], $this->ids('farcry', $keyword));
    }

    public function testRowsLoadedWithoutTheColumnJustMissTheCollapsedMatch(): void
    {
        $this->pdo->exec("UPDATE products SET normalized_collapsed = ''");

        self::assertSame([self::FARCRY_5], $this->ids('farcry'));
    }
}
