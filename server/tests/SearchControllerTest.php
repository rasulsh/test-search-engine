<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Logger;
use App\Normalizer;
use App\SearchController;
use App\Speller;
use RuntimeException;

final class SearchControllerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
    }

    private function controller(
        string $logsTable = 'search_logs',
        int $suggestMinResults = 3,
        ?callable $spellerFactory = null
    ): SearchController {
        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20);
        $logger = new Logger($pdo, $logsTable);
        $spellerFactory ??= static fn (): Speller => Speller::fromProducts($pdo, 'products', 2, 2);

        return new SearchController(
            $keyword,
            $logger,
            $spellerFactory,
            suggestMinResults: $suggestMinResults
        );
    }

    private function addProduct(int $id, string $title, string $description = ''): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $title,
            $description,
            Normalizer::normalize($title),
            Normalizer::normalize($description),
        ]);
    }

    private function logCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn();
    }

    public function testKeywordSearchReturnsIdsAndWritesLog(): void
    {
        $result = $this->controller()->search(['q' => 'sony']);

        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([1009, 1011], $ids);
        self::assertNull($result['did_you_mean']);
        self::assertSame('sony', $result['query']['normalized']);
        self::assertSame(2, $result['count']);
        self::assertSame(1, $this->logCount());
    }

    public function testSpellCorrectionRecoversResults(): void
    {
        $result = $this->controller()->search(['q' => 'sont']);

        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([1009, 1011], $ids);
        self::assertSame('sony', $result['did_you_mean']);
        self::assertTrue($result['did_you_mean_applied']);
    }

    public function testFewResultsKeepsLiteralMatchesAndOffersABetterSuggestion(): void
    {
        // "sonny" is a catalog typo in one description; "sony" is a frequent
        // title word with more results. The literal hit is kept; the suggestion
        // is offered, not applied.
        $this->addProduct(2001, 'Portable speaker', 'works with sonny phones');

        $result = $this->controller()->search(['q' => 'sonny']);

        self::assertSame([2001], $result['product_ids']);
        self::assertSame('sony', $result['did_you_mean']);
        self::assertFalse($result['did_you_mean_applied']);
    }

    public function testNoSuggestionWhenResultsAreGood(): void
    {
        $this->addProduct(2001, 'Portable speaker', 'works with sonny phones');
        $throwing = static fn (): Speller => throw new RuntimeException('speller must not run');

        // At or above suggest_min_results the speller is never consulted.
        $result = $this->controller('search_logs', 1, $throwing)->search(['q' => 'sonny']);

        self::assertSame([2001], $result['product_ids']);
        self::assertNull($result['did_you_mean']);
        self::assertFalse($result['did_you_mean_applied']);
    }

    public function testNeverSuggestsATermThatReturnsNothing(): void
    {
        // The speller proposes "sonz", which matches no product.
        $speller = static fn (): Speller => new Speller(['sonz' => 9]);

        $result = $this->controller('search_logs', 3, $speller)->search(['q' => 'sonx']);

        self::assertNull($result['did_you_mean']);
        self::assertSame([], $result['product_ids']);
    }

    public function testNeverSuggestsATermWithNoMoreResultsThanTheQuery(): void
    {
        // Literal "sonny" has 1 hit; the proposed "sunny" also has 1.
        $this->addProduct(2001, 'Portable speaker', 'works with sonny phones');
        $this->addProduct(2002, 'Sunny beach towel');
        $speller = static fn (): Speller => new Speller(['sunny' => 9]);

        $result = $this->controller('search_logs', 3, $speller)->search(['q' => 'sonny']);

        self::assertNull($result['did_you_mean']);
        self::assertSame([2001], $result['product_ids']);
    }

    public function testSeededPersianTypoPrefersTheFrequentTitleWord(): void
    {
        // The reported case: "اصاصین" is two edits from both "اساسین" (two
        // product titles) and "آغازین" (one title, and common in descriptions).
        // Only the frequency-gated title word may be suggested.
        $this->addProduct(2001, 'بازی اساسین کرید والهالا');
        $this->addProduct(2002, 'بازی اساسین کرید میراژ');
        $this->addProduct(2003, 'کتاب آغازین', 'مرحله آغازین');
        foreach ([2004, 2005, 2006] as $id) {
            $this->addProduct($id, 'پک ' . $id, 'نسخه آغازین');
        }

        $result = $this->controller()->search(['q' => 'اصاصین']);

        self::assertSame('اساسین', $result['did_you_mean']);
        self::assertTrue($result['did_you_mean_applied']);
        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([2001, 2002], $ids);
    }

    public function testKeyboardLayoutRecoversResults(): void
    {
        // "sony" typed on a Persian layout produces "سخدغ"; the keymap recovers it.
        $result = $this->controller()->search(['q' => 'سخدغ']);

        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([1009, 1011], $ids);
        self::assertSame('sony', $result['did_you_mean']);
    }

    public function testLogRecordsNormalizedQueryAndTopIds(): void
    {
        $this->controller()->search(['q' => 'Sony', 'customer_id' => 'cust-7']);

        $row = $this->pdo->query(
            'SELECT raw_q, normalized_q, result_count, top_ids, customer_id FROM search_logs'
        )->fetch();

        self::assertSame('Sony', $row['raw_q']);
        self::assertSame('sony', $row['normalized_q']);
        self::assertSame(2, (int) $row['result_count']);
        self::assertSame('cust-7', $row['customer_id']);
        self::assertNotSame('', $row['top_ids']);
    }

    public function testBlankQueryReturnsEmptyAndStillLogs(): void
    {
        $result = $this->controller()->search(['q' => '']);

        self::assertSame([], $result['product_ids']);
        self::assertNull($result['did_you_mean']);
        self::assertSame(1, $this->logCount());
    }

    public function testOversizedInputIsStillAnsweredWhenTheLogRowIsRejected(): void
    {
        // raw_q / customer_id exceed their search_logs columns; strict SQL mode
        // rejects the log INSERT, which must not fail the search itself.
        $result = $this->controller()->search([
            'q' => 'sony' . str_repeat(' ', 600),
            'customer_id' => str_repeat('c', 100),
        ]);

        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([1009, 1011], $ids);
        self::assertSame(0, $this->logCount());
    }

    public function testLoggingFailureDoesNotFailTheSearch(): void
    {
        $result = $this->controller('missing_logs_table')->search(['q' => 'sony']);

        $ids = $result['product_ids'];
        sort($ids);
        self::assertSame([1009, 1011], $ids);
    }
}
