<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Synonyms;
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
 * Runs three shapes, so each change is measured, not assumed: the whole
 * description indexed (pre-M11 bundles), normalized_desc capped to the
 * pipeline's default desc_index_chars (M11), and capped plus ~20 attribute
 * pairs per product in normalized_specs (M13, the default bundle shape) with
 * another 10% of the catalog mentioning the query only there, so the spec
 * REGEXPs run on every matching row without a title hit. Two more shapes add
 * synonym / alias expansion (M15) at the configured alias_max_variants: every
 * variant is one more keyword search, and variants holding a short token (a
 * digit, "ps 5") take the LIKE path that scans every row. Rows are inserted
 * into the indexed table, as products.load.sql does, so the FULLTEXT index is
 * built incrementally like on the host.
 *
 * M16: a query no product fully matches pays the strict search AND the
 * any-terms fallback, which scores every row holding any word (here the 2600
 * rows of either broad word): timed together on the default bundle shape.
 */
final class KeywordLatencyGuardTest extends DatabaseTestCase
{
    private const PRODUCTS = 20000;
    private const DESC_WORDS = 300;
    private const RUNS = 5;
    /** Mirrors the default of desc_index_chars in pipeline/config.py. */
    private const DESC_INDEX_CHARS = 800;
    private const SPEC_PAIRS = 20;

    /** @return array<string, array{int, bool, ?list<string>}> */
    public static function shapes(): array
    {
        return [
            'whole description'          => [0, false, null],
            'capped description'         => [self::DESC_INDEX_CHARS, false, null],
            'capped description + specs' => [self::DESC_INDEX_CHARS, true, null],
            'capped + specs + fulltext aliases' => [
                self::DESC_INDEX_CHARS, true, ['دسته بازی', 'گیم پد', 'کنترلر بازی', 'جوی استیک', 'gamepad', 'joypad'],
            ],
            'capped + specs + LIKE aliases' => [
                self::DESC_INDEX_CHARS, true, ['دسته بازی', 'دسته 5', 'ps 5', 'بازی 4', 'gamepad 5', 'joystick 5'],
            ],
        ];
    }

    /** @param ?list<string> $aliases one alias group holding the query */
    #[DataProvider('shapes')]
    public function testBroadQueryStaysWithinBudget(int $descIndexChars, bool $withSpecs, ?array $aliases): void
    {
        $config = require self::repoRoot() . '/server/config.example.php';
        $budgetMs = (float) $config['search']['latency_budget_ms'];
        $maxVariants = (int) $config['search']['alias_max_variants'];
        $this->seedCatalog($descIndexChars, $withSpecs);

        $synonyms = $aliases === null ? null : new Synonyms([$aliases]);
        $keyword = new Keyword($this->pdo, 'products', 3, 20, null, 10.0, 1.0, 5.0, 4, 6.0, $synonyms, $maxVariants);
        $query = 'دسته بازی';
        if ($synonyms !== null) {
            self::assertCount($maxVariants, $synonyms->variants(['دسته', 'بازی'], $maxVariants));
        }

        $results = $keyword->search($query); // warm the buffer pool
        self::assertCount(20, $results);
        if ($withSpecs && $aliases === null) {
            // 800 title rows, then the 2000 spec-only rows lead the description band.
            self::assertTrue($keyword->search($query, 1000)[999]['spec_match']);
        }
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
            "\n[keyword guard] %d products, ~%d-word descriptions, index cap %s%s%s, broad query: "
            . "median %.1f ms (max %.1f, budget %d ms)\n",
            self::PRODUCTS,
            self::DESC_WORDS,
            $descIndexChars > 0 ? $descIndexChars . ' chars' : 'none',
            $withSpecs ? ', ' . self::SPEC_PAIRS . ' spec pairs' : '',
            $aliases !== null ? ', ' . $maxVariants . ' alias variants (' . $aliases[1] . ', ...)' : '',
            $median,
            max($times),
            (int) $budgetMs
        ));
        self::assertLessThan($budgetMs, $median, 'field-weighted keyword query exceeded the latency budget');
    }

    public function testAnyTermsFallbackStaysWithinBudget(): void
    {
        $config = require self::repoRoot() . '/server/config.example.php';
        $budgetMs = (float) $config['search']['latency_budget_ms'];
        $this->seedCatalog(self::DESC_INDEX_CHARS, true);
        $keyword = new Keyword($this->pdo, 'products', 3, 20, null, 10.0, 1.0, 5.0, 4, 6.0);
        $query = 'دسته بازی ناموجود';

        self::assertSame([], $keyword->search($query));
        $results = $keyword->search($query, null, false); // warm the buffer pool
        self::assertCount(20, $results);
        self::assertSame(Keyword::MATCH_PARTIAL, $results[0]['match_type']);
        self::assertTrue($results[0]['title_match']);

        $times = [];
        for ($i = 0; $i < self::RUNS; $i++) {
            $start = hrtime(true);
            $keyword->search($query);
            $keyword->search($query, null, false);
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $median = $times[intdiv(self::RUNS, 2)];

        fwrite(STDERR, sprintf(
            "\n[keyword guard] %d products, capped + specs, zero-hit strict + any-terms fallback: "
            . "median %.1f ms (max %.1f, budget %d ms)\n",
            self::PRODUCTS,
            $median,
            max($times),
            (int) $budgetMs
        ));
        self::assertLessThan($budgetMs, $median, 'strict + any-terms fallback exceeded the latency budget');
    }

    private function seedCatalog(int $descIndexChars, bool $withSpecs): void
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
            $specs = [];
            for ($k = 0; $withSpecs && $k < self::SPEC_PAIRS; $k++) {
                $specs[] = 'مشخصه' . $k . ': ' . $filler[mt_rand(0, 1999)] . ' ' . $filler[mt_rand(0, 1999)];
            }
            if ($withSpecs && $id % 10 === 5) {
                $specs[] = 'سازگار با دسته بازی'; // spec-only mentions
            }

            $batch[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $id,
                $title,
                $desc,
                $title,
                self::indexed($desc, $descIndexChars),
                implode(' | ', $specs),
                $id % 1000
            );
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
            'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc,
                                   normalized_specs, popularity)
             VALUES ' . implode(',', $batch)
        )->execute($params);
    }
}
