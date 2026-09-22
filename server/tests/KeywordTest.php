<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;

final class KeywordTest extends DatabaseTestCase
{
    private Keyword $keyword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadSampleFixture();
        // min_token_size 3 mirrors innodb_ft_min_token_size; default_limit 20.
        $this->keyword = new Keyword($this->pdo, 'products', 3, 20);
    }

    /**
     * @param list<array{product_id: int, score: float, match_type: string}> $results
     * @return list<int>
     */
    private function ids(array $results): array
    {
        return array_map(static fn (array $r): int => $r['product_id'], $results);
    }

    public function testFulltextFindsEnglishTermAcrossRows(): void
    {
        $results = $this->keyword->search('sony');

        $ids = $this->ids($results);
        sort($ids);
        self::assertSame([1009, 1011], $ids);
        self::assertSame(['fulltext'], array_values(array_unique(array_column($results, 'match_type'))));
    }

    public function testFulltextFindsPlainPersianTerm(): void
    {
        // Persian FULLTEXT works for whitespace-delimited tokens (no ZWNJ).
        self::assertSame([1004], $this->ids($this->keyword->search('سامسونگ')));
    }

    public function testFulltextRanksByScoreThenPopularity(): void
    {
        // Both rows match "apple" in the title (equal score); popularity breaks
        // the tie: 1001 (1000) before 1007 (700).
        self::assertSame([1001, 1007], $this->ids($this->keyword->search('apple')));
    }

    public function testFulltextRequiresAllTerms(): void
    {
        self::assertSame([1011], $this->ids($this->keyword->search('sony camera')));
        self::assertSame([1009], $this->ids($this->keyword->search('sony headphones')));
    }

    public function testLikeFallbackForShortEnglishToken(): void
    {
        $results = $this->keyword->search('tv');

        self::assertSame([1013], $this->ids($results));
        self::assertSame('like', $results[0]['match_type']);
    }

    public function testLikeFallbackForShortPersianTokenOrdersByPopularity(): void
    {
        $results = $this->keyword->search('لپ');

        // Both laptop rows contain "لپ"; ordered by popularity: 1008 (680), 1006 (480).
        self::assertSame([1008, 1006], $this->ids($results));
        self::assertSame('like', $results[0]['match_type']);
    }

    public function testNormalizationFindsZwnjTermViaFulltext(): void
    {
        // M2: the Normalizer strips ZWNJ from both the indexed columns and the
        // query, so the joined term is a single FULLTEXT token — the M1 LIKE
        // workaround is no longer needed.
        $results = $this->keyword->search('بی‌سیم');

        self::assertSame([1010], $this->ids($results));
        self::assertSame('fulltext', $results[0]['match_type']);
    }

    public function testModelNameCanonicalizationMatchesHyphenatedModel(): void
    {
        // "WH-1000XM5" normalizes to "wh1000xm5", matching the indexed model.
        $results = $this->keyword->search('WH-1000XM5');

        $ids = $this->ids($results);
        sort($ids);
        self::assertSame([1009, 1010], $ids);
        self::assertSame('fulltext', $results[0]['match_type']);
    }

    public function testPersianDigitsAreFolded(): void
    {
        // "۲۵۶" folds to "256", matching both iPhone rows.
        $ids = $this->ids($this->keyword->search('۲۵۶'));
        sort($ids);
        self::assertSame([1001, 1002], $ids);
    }

    public function testNoMatchReturnsEmpty(): void
    {
        self::assertSame([], $this->keyword->search('zzzznomatch'));
    }

    public function testBlankQueryReturnsEmpty(): void
    {
        self::assertSame([], $this->keyword->search(''));
        self::assertSame([], $this->keyword->search('   '));
    }

    public function testLimitCapsResults(): void
    {
        self::assertSame([1001], $this->ids($this->keyword->search('apple', 1)));
    }
}
