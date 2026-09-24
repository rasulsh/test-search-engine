<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Logger;
use App\ProductLoader;
use App\Ranker;
use App\SearchController;
use App\Speller;

/**
 * M12: a query naming a product's SKU returns that product first, above
 * products whose title mentions the same text, and SKU prefix matches follow
 * the exact match. Also covers the SKU pin in the hybrid merge.
 */
final class KeywordSkuTest extends DatabaseTestCase
{
    private const TARGET = 1;       // sku AB-1234
    private const TITLE_TWIN = 2;   // title names "ab1234", more popular
    private const PREFIX = 3;       // sku AB-12345, starts with the target code
    private const SONY_CODE = 4;    // sku SONY-X9 (letters-only prefix must not match "sony")
    private const SONY_TITLE = 5;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            $this->row(self::TARGET, 'Industrial bearing', 'AB-1234', 1),
            $this->row(self::TITLE_TWIN, 'Adapter AB1234 compatible bearing', 'ZZ-9', 900),
            $this->row(self::PREFIX, 'Bearing kit', 'AB-12345', 800),
            $this->row(self::SONY_CODE, 'Remote control', 'SONY-X9', 700),
            $this->row(self::SONY_TITLE, 'Sony headphones', '', 10),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $title, string $sku, int $popularity): array
    {
        return [
            'product_id' => $id,
            'title' => $title,
            'description' => 'spare part',
            'sku' => $sku,
            'stock' => 1,
            'popularity' => $popularity,
        ];
    }

    private function keyword(int $skuPrefixMinLength = 4): Keyword
    {
        return new Keyword($this->pdo, 'products', 3, 20, null, 10.0, 1.0, 5.0, $skuPrefixMinLength);
    }

    /** @return list<int> */
    private function ids(string $query, ?Keyword $keyword = null): array
    {
        return array_column(($keyword ?? $this->keyword())->search($query), 'product_id');
    }

    public function testExactSkuRanksFirstAboveTitleMatches(): void
    {
        $results = $this->keyword()->search('AB-1234');

        self::assertSame(self::TARGET, $results[0]['product_id']);
        self::assertSame(Keyword::MATCH_SKU, $results[0]['match_type']);
        self::assertTrue($results[0]['title_match']);
        // The popular title twin still appears, below the SKU hits.
        self::assertContains(self::TITLE_TWIN, $this->ids('AB-1234'));
    }

    public function testSkuSeparatorsAndCaseDoNotMatter(): void
    {
        foreach (['ab1234', 'AB 1234', 'ab.12-34', '  Ab_1234 '] as $query) {
            self::assertSame(self::TARGET, $this->ids($query)[0], "query {$query}");
        }
    }

    public function testPrefixMatchesFollowTheExactMatch(): void
    {
        self::assertSame([self::TARGET, self::PREFIX], array_slice($this->ids('AB-1234'), 0, 2));
        // A prefix alone (no exact product) puts the prefix matches first,
        // shortest code first, then the text matches.
        $results = $this->keyword()->search('ab12');
        self::assertSame([self::TARGET, self::PREFIX], array_slice(array_column($results, 'product_id'), 0, 2));
        self::assertSame(['sku', 'sku', 'fulltext'], array_column($results, 'match_type'));
    }

    public function testPrefixNeedsADigitAndTheMinimumLength(): void
    {
        // "sony" is a word, not a code: no SKU prefix hit is pinned above the
        // text matches. (The indexed code "sonyx9" is still title text, so that
        // product matches in the title band like any other.)
        $results = $this->keyword()->search('sony');
        self::assertNotContains(Keyword::MATCH_SKU, array_column($results, 'match_type'));
        self::assertContains(self::SONY_TITLE, array_column($results, 'product_id'));
        // Too short for a SKU prefix match (min length 4): "ab1" is served by
        // text search only; with the minimum lowered it becomes a SKU hit.
        self::assertNotContains(Keyword::MATCH_SKU, array_column($this->keyword()->search('ab1'), 'match_type'));
        self::assertSame(Keyword::MATCH_SKU, $this->keyword(3)->search('ab1')[0]['match_type']);
        // Exact SKU still matches regardless of length or digits.
        self::assertSame(self::SONY_CODE, $this->ids('sony x9')[0]);
    }

    public function testSkuIsTitleWeightedTextAsAFallback(): void
    {
        // Two tokens, neither a SKU on its own: FULLTEXT on the title text finds
        // the product through the indexed SKU.
        self::assertContains(self::TARGET, $this->ids('bearing ab1234'));
    }

    public function testSkuHitDoesNotTriggerDidYouMean(): void
    {
        $pdo = $this->pdo;
        $controller = new SearchController(
            $this->keyword(),
            new Logger($pdo, 'search_logs'),
            static fn (): Speller => Speller::fromProducts($pdo, 'products')
        );

        $response = $controller->search(['q' => 'SONY-X9']);

        self::assertSame(self::SONY_CODE, $response['product_ids'][0]);
        self::assertNull($response['did_you_mean']);
    }

    public function testExactSkuStaysFirstInTheHybridMerge(): void
    {
        // The VPS's query vector points exactly at the title twin (and a
        // semantic-only product); semantic evidence must not lift it above the SKU hit.
        $vectors = [
            self::TARGET => [0.0, 1.0, 0.0, 0.0],
            self::TITLE_TWIN => [1.0, 0.0, 0.0, 0.0],
            self::PREFIX => [0.0, 0.0, 1.0, 0.0],
            self::SONY_TITLE => [0.99, 0.141, 0.0, 0.0],
        ];

        $pdo = $this->pdo;
        $signals = static function (array $ids) use ($pdo): array {
            $stmt = $pdo->query('SELECT product_id, stock, popularity FROM products');
            $out = [];
            foreach ($stmt as $row) {
                if (in_array((int) $row['product_id'], $ids, true)) {
                    $out[(int) $row['product_id']] = ['stock' => 1, 'popularity' => (int) $row['popularity']];
                }
            }

            return $out;
        };
        $controller = new SearchController(
            $this->keyword(),
            new Logger($pdo, 'search_logs'),
            static fn (): Speller => Speller::fromProducts($pdo, 'products'),
            null,
            10,
            FakeVps::client(['products' => $vectors, 'queries' => ['AB-1234' => [1.0, 0.0, 0.0, 0.0]]]),
            new Ranker(60, 0.1, 0.1),
            $signals,
            100,
            20,
            0.5
        );

        $response = $controller->search(['q' => 'AB-1234']);

        self::assertArrayHasKey('cosine_scores', $response); // hybrid path taken
        self::assertSame([self::TARGET, self::PREFIX], array_slice($response['product_ids'], 0, 2));
        self::assertContains(self::SONY_TITLE, $response['product_ids']); // semantic-only, below
        self::assertCount(count($response['product_ids']), $response['cosine_scores']);
        // Below the floor, so the VPS did not return it: no score to show.
        self::assertNull($response['cosine_scores'][0]);
    }

    public function testLongestTitlePlusSkuFitsTheIndexedTitle(): void
    {
        // OpenCart names are 255 characters per language; the SKU adds up to 64.
        $title = str_repeat('a', 255) . ' ' . str_repeat('b', 255);
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 99, 'title' => $title, 'sku' => str_repeat('C', 64)],
        ]);

        self::assertSame(576, (int) $this->pdo->query(
            'SELECT CHAR_LENGTH(normalized_title) FROM products WHERE product_id = 99'
        )->fetchColumn());
        self::assertSame(99, $this->ids(str_repeat('c', 64))[0]);
    }

    public function testPreM12TableWithoutSkuColumnDegradesToTextSearch(): void
    {
        $this->pdo->exec('ALTER TABLE products DROP INDEX idx_normalized_sku, DROP COLUMN normalized_sku');

        self::assertContains(self::TITLE_TWIN, $this->ids('AB1234'));
    }
}
