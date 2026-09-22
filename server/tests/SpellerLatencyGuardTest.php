<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\Logger;
use App\Normalizer;
use App\SearchController;
use App\Speller;

/**
 * Latency guard for the "did you mean" path (queries with no keyword match).
 *
 * Seeds a synthetic 20000-product bilingual catalog, writes the bundle
 * dictionary, and times full SearchController requests on the WARM path
 * (dictionary already parsed by this worker). The median must stay under the
 * configured latency budget. The old per-request table scan is timed once on
 * the same data for the report only. CI hardware differs from cPanel, so this
 * is a regression guard; real-host latency is a production-validation item.
 */
final class SpellerLatencyGuardTest extends DatabaseTestCase
{
    private const PRODUCTS = 20000;
    private const WORD_POOL = 12000;
    private const RUNS = 5;

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            @unlink($this->dir . '/' . Speller::DICTIONARY_FILE);
            @rmdir($this->dir);
            $this->dir = null;
        }
    }

    public function testNoKeywordMatchQueryStaysWithinBudgetOnWarmPath(): void
    {
        $config = require self::repoRoot() . '/server/config.example.php';
        $budgetMs = (float) $config['search']['latency_budget_ms'];

        $words = $this->wordPool();
        $texts = $this->seedCatalog($words);
        $dictionary = $this->writeDictionary($texts);

        $pdo = $this->pdo;
        $keyword = new Keyword($pdo, 'products', 3, 20);
        $controller = new SearchController(
            $keyword,
            new Logger($pdo, 'search_logs'),
            // Same wiring as public/search.php, minus the fallback: this test
            // must fail rather than silently time the table scan.
            static fn (): Speller => Speller::fromDictionary($dictionary, false)
                ?? throw new \RuntimeException('dictionary missing')
        );

        // Every token is one edit away from a word of the same product
        // (recoverable: keyword search needs all tokens to match) or nowhere
        // near one (full vocabulary scan, nothing found).
        $titleWords = explode(' ', $texts[0]);
        $recoverable = implode(' ', array_map($this->typo(...), array_slice($titleWords, 0, 3)));
        $cases = [
            'recoverable'   => $recoverable,
            'unrecoverable' => 'qxzqxzq vbnvbnv kqkqkqk',
        ];

        $report = [];
        foreach ($cases as $label => $query) {
            self::assertSame([], $keyword->search($query), "precondition: '{$query}' must have no keyword match");

            $warm = $controller->search(['q' => $query]); // parses + caches the dictionary
            if ($label === 'recoverable') {
                self::assertNotNull($warm['did_you_mean']);
                self::assertNotEmpty($warm['product_ids']);
            } else {
                self::assertNull($warm['did_you_mean']);
            }

            $timings = [];
            for ($i = 0; $i < self::RUNS; $i++) {
                $start = hrtime(true);
                $controller->search(['q' => $query]);
                $timings[] = (hrtime(true) - $start) / 1_000_000.0;
            }
            sort($timings);
            $median = $timings[intdiv(self::RUNS, 2)];
            $report[] = sprintf('%s median %.1f ms (max %.1f)', $label, $median, max($timings));

            self::assertLessThan(
                $budgetMs,
                $median,
                sprintf('%s no-match query took %.1f ms (median), over the %.0f ms budget', $label, $median, $budgetMs)
            );
        }

        $start = hrtime(true);
        Speller::fromProducts($pdo, 'products')->suggest(Normalizer::normalize($recoverable));
        $fallbackMs = (hrtime(true) - $start) / 1_000_000.0;

        fwrite(STDERR, sprintf(
            "\n[speller guard] %d products, %d-term dictionary, warm: %s; "
            . "table-scan fallback speller alone: %.1f ms (budget %.0f ms)\n",
            self::PRODUCTS,
            count(Speller::parseDictionary((string) file_get_contents($dictionary))),
            implode('; ', $report),
            $fallbackMs,
            $budgetMs
        ));
    }

    /**
     * Deterministic pseudo-words, half Persian and half Latin, so the dictionary
     * has a realistic size and alphabet.
     *
     * @return list<string>
     */
    private function wordPool(): array
    {
        mt_srand(7);
        $persian = mb_str_split('ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی');
        $latin = str_split('abcdefghijklmnopqrstuvwxyz');
        $pool = [];
        while (count($pool) < self::WORD_POOL) {
            $alphabet = count($pool) % 2 === 0 ? $latin : $persian;
            $word = '';
            for ($i = mt_rand(4, 9); $i > 0; $i--) {
                $word .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $pool[$word] = true;
        }

        return array_map('strval', array_keys($pool));
    }

    /**
     * @param list<string> $words
     * @return list<string> the normalized texts, for the dictionary
     */
    private function seedCatalog(array $words): array
    {
        $texts = [];
        $rows = [];
        $last = count($words) - 1;
        for ($id = 1; $id <= self::PRODUCTS; $id++) {
            $title = [];
            for ($i = 0; $i < 4; $i++) {
                $title[] = $words[mt_rand(0, $last)];
            }
            $title[] = 'M' . $id . 'X';
            $desc = [];
            for ($i = 0; $i < 12; $i++) {
                $desc[] = $words[mt_rand(0, $last)];
            }
            $normalizedTitle = Normalizer::normalize(implode(' ', $title));
            $normalizedDesc = Normalizer::normalize(implode(' ', $desc));
            $texts[] = $normalizedTitle;
            $texts[] = $normalizedDesc;
            array_push($rows, $id, $normalizedTitle, $normalizedDesc, $normalizedTitle, $normalizedDesc);

            if (count($rows) === 500 * 5 || $id === self::PRODUCTS) {
                $count = intdiv(count($rows), 5);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO products (product_id, title, description, normalized_title, normalized_desc) VALUES '
                    . implode(',', array_fill(0, $count, '(?, ?, ?, ?, ?)'))
                );
                $stmt->execute($rows);
                $rows = [];
            }
        }

        return $texts;
    }

    /**
     * Write spellcheck.txt in the pipeline format (count desc, then token).
     *
     * @param list<string> $texts
     */
    private function writeDictionary(array $texts): string
    {
        $vocabulary = Speller::buildVocabulary($texts);
        uksort($vocabulary, static fn ($a, $b): int
            => [$vocabulary[$b], (string) $a] <=> [$vocabulary[$a], (string) $b]);
        $lines = '';
        foreach ($vocabulary as $token => $count) {
            $lines .= $token . "\t" . $count . "\n";
        }

        $this->dir = sys_get_temp_dir() . '/spellguard_' . uniqid('', true);
        mkdir($this->dir, 0777, true);
        $path = $this->dir . '/' . Speller::DICTIONARY_FILE;
        file_put_contents($path, $lines);

        return $path;
    }

    /** Replace the middle character with a digit, which no pool word contains. */
    private function typo(string $word): string
    {
        $chars = mb_str_split($word);
        $chars[intdiv(count($chars), 2)] = '7';

        return implode('', $chars);
    }
}
