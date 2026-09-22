<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Logger;
use App\SearchController;
use App\Speller;

final class SearchControllerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
    }

    private function controller(string $logsTable = 'search_logs'): SearchController
    {
        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20);
        $logger = new Logger($pdo, $logsTable);
        $spellerFactory = static function () use ($pdo): Speller {
            $texts = [];
            foreach ($pdo->query('SELECT normalized_title, normalized_desc FROM products') as $row) {
                $texts[] = $row['normalized_title'];
                $texts[] = $row['normalized_desc'];
            }

            return new Speller(Speller::buildVocabulary($texts));
        };

        return new SearchController($keyword, $logger, $spellerFactory);
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
