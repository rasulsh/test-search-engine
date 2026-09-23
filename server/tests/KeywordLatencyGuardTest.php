<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Latency guard for the field-weighted keyword score (M10).
 *
 * The per-field score runs title REGEXPs on every matching row, so its cost
 * grows with how many rows a query matches. Seeds 20000 products with ~2 KB
 * descriptions and times a broad FULLTEXT query (10% of the catalog mentions it
 * in the description, 4% in the title). The median must stay under the
 * configured budget. The LIKE fallback is not guarded here: it scans every
 * description and is dominated by that scan, not by this score. CI hardware
 * differs from cPanel, so this is a regression guard; real-host latency is a
 * production-validation item.
 *
 * Runs twice: with the whole description indexed (pre-M11 bundles) and with
 * normalized_desc capped to the pipeline's default desc_index_chars (M11), so
 * the effect of the cap is measured, not assumed.
 */
final class KeywordLatencyGuardTest extends DatabaseTestCase
{
    private const PRODUCTS = 20000;
    private const DESC_WORDS = 300;
    private const RUNS = 5;
    /** Mirrors the default of desc_index_chars in pipeline/config.py. */
    private const DESC_INDEX_CHARS = 400;

    /** @return array<string, array{int}> */
    public static function indexCaps(): array
    {
        return ['whole description' => [0], 'capped description' => [self::DESC_INDEX_CHARS]];
    }

    #[DataProvider('indexCaps')]
    public function testBroadQueryStaysWithinBudget(int $descIndexChars): void
    {
        $config = require self::repoRoot() . '/server/config.example.php';
        $budgetMs = (float) $config['search']['latency_budget_ms'];
        $this->seedCatalog($descIndexChars);

        $keyword = new Keyword($this->pdo, 'products', 3, 20);
        $query = 'دسته بازی';

        $results = $keyword->search($query); // warm the buffer pool
        self::assertCount(20, $results);
        self::assertSame('fulltext', $results[0]['match_type']);
        self::assertTrue($results[0]['title_match']);

        $times = [];
        for ($i = 0; $i < self::RUNS; $i++) {
            $start = hrtime(true);
            $keyword->search($query);
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $median = $times[intdiv(self::RUNS, 2)];

        fwrite(STDERR, sprintf(
            "\n[keyword guard] %d products, ~%d-word descriptions, index cap %s, broad query: "
            . "median %.1f ms (max %.1f, budget %d ms)\n",
            self::PRODUCTS,
            self::DESC_WORDS,
            $descIndexChars > 0 ? $descIndexChars . ' chars' : 'none',
            $median,
            max($times),
            (int) $budgetMs
        ));
        self::assertLessThan($budgetMs, $median, 'field-weighted keyword query exceeded the latency budget');
    }

    private function seedCatalog(int $descIndexChars): void
    {
        mt_srand(10);
        $filler = [];
        for ($i = 0; $i < 2000; $i++) {
            $filler[] = 'واژه' . $i;
        }

        $batch = [];
        $params = [];
        for ($id = 1; $id <= self::PRODUCTS; $id++) {
            $title = 'محصول ' . $id . ($id % 25 === 0 ? ' دسته بازی' : ' ' . $filler[$id % 2000]);
            $words = [];
            for ($w = 0; $w < self::DESC_WORDS; $w++) {
                $words[] = $filler[mt_rand(0, 1999)];
            }
            if ($id % 10 === 0) {
                // Broad description-only mentions, as on the real catalog.
                array_splice($words, mt_rand(0, self::DESC_WORDS), 0, ['سازگار', 'با', 'دسته', 'بازی']);
            }
            $desc = implode(' ', $words);

            $batch[] = '(?, ?, ?, ?, ?, ?)';
            array_push($params, $id, $title, $desc, $title, self::indexed($desc, $descIndexChars), $id % 1000);
            if (count($batch) === 500) {
                $this->flush($batch, $params);
                $batch = [];
                $params = [];
            }
        }
        if ($batch !== []) {
            $this->flush($batch, $params);
        }
    }

    /** Same cut as build.index_description(): back to whitespace, never mid-word. */
    private static function indexed(string $desc, int $maxChars): string
    {
        if ($maxChars <= 0 || mb_strlen($desc) <= $maxChars) {
            return $desc;
        }
        $head = mb_substr($desc, 0, $maxChars);
        if (!ctype_space(mb_substr($desc, $maxChars, 1))) {
            $head = (string) preg_replace('/\S+$/u', '', $head);
        }

        return rtrim($head);
    }

    /**
     * @param list<string> $batch
     * @param list<int|string> $params
     */
    private function flush(array $batch, array $params): void
    {
        $this->pdo->prepare(
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc, popularity)
             VALUES ' . implode(',', $batch)
        )->execute($params);
    }
}
