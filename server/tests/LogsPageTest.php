<?php

declare(strict_types=1);

namespace App\Tests;

use App\LogsPage;
use PDO;

/** The logs page's query (M42): sort whitelist, search escaping, paging edges, the scan bound. */
final class LogsPageTest extends DatabaseTestCase
{
    /** @param array<string, mixed> $row */
    private function log(array $row): void
    {
        static $n = 0;
        $row += ['ts' => sprintf('2026-01-01 10:%02d:00', $n++ % 60), 'normalized_q' => null, 'result_count' => 2,
            'latency_ms' => 10, 'tier' => 'keyword_only', 'cache_hit' => 0];
        $row['normalized_q'] ??= strtolower((string) $row['raw_q']);
        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_logs (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')'
        );
        $stmt->execute($row);
    }

    /** @return list<string> */
    private static function raw(array $page): array
    {
        return array_column($page['rows'], 'raw_q');
    }

    private function page(int $size = 50, int $scan = 0): LogsPage
    {
        return new LogsPage($this->pdo, 'search_logs', $size, $scan);
    }

    public function testAnEmptyTableIsOneEmptyPage(): void
    {
        $r = $this->page()->fetch('recent', 1);

        self::assertSame([[], 0, 1, 1, 0, 0], [$r['rows'], $r['total'], $r['page'], $r['pages'], $r['from'], $r['to']]);
        self::assertNull($r['scan_limit']);
    }

    public function testLastPageAndOutOfRangePages(): void
    {
        foreach (range(1, 7) as $i) {
            $this->log(['raw_q' => "q{$i}"]);
        }
        $logs = $this->page(3);

        $last = $logs->fetch('recent', 3);
        self::assertSame(['q1'], self::raw($last));
        self::assertSame([3, 3, 7, 7], [$last['page'], $last['pages'], $last['from'], $last['to']]);
        self::assertSame(['q7', 'q6', 'q5'], self::raw($logs->fetch('recent', 1)));

        self::assertSame(3, $logs->fetch('recent', 99)['page'], 'past the end shows the last page');
        self::assertSame(['q1'], self::raw($logs->fetch('recent', 99)));
        self::assertSame(1, $logs->fetch('recent', 0)['page']);
        self::assertSame(1, $logs->fetch('recent', -5)['page']);
    }

    public function testPageSizeMustBeOneOfTheOfferedOnes(): void
    {
        foreach (range(1, 30) as $i) {
            $this->log(['raw_q' => "q{$i}"]);
        }
        $logs = $this->page(50);

        self::assertCount(25, $logs->fetch('recent', 1, '', '', '', 25)['rows']);
        self::assertSame(25, $logs->fetch('recent', 1, '', '', '', 25)['per']);
        self::assertSame(50, $logs->fetch('recent', 1, '', '', '', 7)['per'], 'not offered: the configured size');
        self::assertSame(50, $logs->fetch('recent', 1, '', '', '', 100000)['per']);
        self::assertSame(2, $logs->fetch('recent', 1, '', '', '', 25)['pages']);
        self::assertSame(20, $this->page(20)->fetch('recent', 1, '', '', '', 20)['per'], 'a configured 20 is offered');
    }

    public function testSortingCoversTheWholeFilteredResultNotJustThePage(): void
    {
        foreach ([40, 10, 30, 20, 50] as $i => $ms) {
            $this->log(['raw_q' => "q{$i}", 'latency_ms' => $ms, 'result_count' => 5 - $i]);
        }
        $logs = $this->page(2);

        self::assertSame(['q4', 'q0'], self::raw($logs->fetch('recent', 1, 'latency_ms', 'desc')));
        self::assertSame(['q2', 'q3'], self::raw($logs->fetch('recent', 2, 'latency_ms', 'desc')));
        self::assertSame(['q1'], self::raw($logs->fetch('recent', 3, 'latency_ms', 'desc')));
        self::assertSame(['q1', 'q3'], self::raw($logs->fetch('recent', 1, 'latency_ms', 'asc')));
        self::assertSame(['q0', 'q1'], self::raw($logs->fetch('recent', 1, 'result_count', 'desc')));
        self::assertSame(['q0', 'q1'], self::raw($logs->fetch('recent', 1, 'raw_q', 'asc')));
        self::assertSame(['q4', 'q3'], self::raw($logs->fetch('recent', 1, 'raw_q', 'desc')));
        self::assertSame(['q4', 'q3'], self::raw($logs->fetch('recent', 1, 'ts', 'desc')));
        self::assertSame(['q0', 'q1'], self::raw($logs->fetch('recent', 1, 'ts', 'asc')));
    }

    public function testEveryShownColumnIsSortable(): void
    {
        $this->log(['raw_q' => 'a']);
        $columns = ['ts', 'raw_q', 'normalized_q', 'tier', 'had_vector', 'result_count', 'latency_ms', 'cache_hit'];
        foreach ($columns as $sort) {
            $r = $this->page()->fetch('recent', 1, $sort, 'asc');
            self::assertSame($sort, $r['sort'], $sort);
            self::assertCount(1, $r['rows']);
        }
    }

    public function testAnUnknownSortColumnFallsBackAndIsNeverPutIntoTheSql(): void
    {
        $this->log(['raw_q' => 'first']);
        $this->log(['raw_q' => 'second']);
        $recording = new class (
            (string) getenv('SEARCH_TEST_DB_DSN'),
            (string) getenv('SEARCH_TEST_DB_USER'),
            (string) getenv('SEARCH_TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        ) extends PDO {
            /** @var list<string> */
            public array $sql = [];

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->sql[] = $query;

                return parent::prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
            {
                $this->sql[] = $query;

                return parent::query($query, $fetchMode, ...$args);
            }
        };
        $logs = new LogsPage($recording, 'search_logs', 50);

        $hostile = [
            'nope', 'raw_q; DROP TABLE search_logs', 'id DESC, (SELECT SLEEP(5))', 'RAW_Q', '', 'password', '1',
            '../etc', 'raw_q`', 'customer_id) UNION SELECT 1', ' ts', 'ts ',
        ];
        foreach ($hostile as $sort) {
            $r = $logs->fetch('recent', 1, $sort, 'desc; DROP TABLE search_logs');
            self::assertSame(['second', 'first'], self::raw($r), "[{$sort}] falls back to the newest first");
            self::assertSame('', $r['sort'], "[{$sort}]");
            self::assertSame('desc', $r['dir']);
        }
        foreach ($recording->sql as $sql) {
            self::assertStringNotContainsString('DROP', $sql);
            self::assertStringNotContainsString('SLEEP', $sql);
            self::assertStringNotContainsString('UNION', $sql);
            self::assertStringNotContainsString('..', $sql);
            self::assertStringNotContainsString('password', $sql);
        }
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
        self::assertSame('latency_ms', $logs->fetch('slowest', 1, 'bogus')['sort'], 'slowest falls back to latency');
    }

    public function testADirectionOtherThanAscOrDescIsTheColumnsDefault(): void
    {
        $this->log(['raw_q' => 'b', 'latency_ms' => 5]);
        $this->log(['raw_q' => 'a', 'latency_ms' => 9]);

        self::assertSame(['a', 'b'], self::raw($this->page()->fetch('recent', 1, 'raw_q', 'sideways')), 'text: A to Z');
        $byLatency = $this->page()->fetch('recent', 1, 'latency_ms', '');
        self::assertSame(['a', 'b'], self::raw($byLatency), 'numbers: biggest first');
    }

    public function testViewsKeepTheirOwnFilterAndDefaultOrder(): void
    {
        $this->log(['raw_q' => 'gap', 'result_count' => 0, 'latency_ms' => 20]);
        $this->log(['raw_q' => 'slow', 'latency_ms' => 900]);
        $this->log(['raw_q' => 'fine', 'latency_ms' => 5]);

        self::assertSame(['gap'], self::raw($this->page()->fetch('zero', 1)));
        self::assertSame(['slow', 'gap', 'fine'], self::raw($this->page()->fetch('slowest', 1)));
        self::assertSame(['fine', 'slow', 'gap'], self::raw($this->page()->fetch('nonsense', 1)), 'unknown: recent');
        self::assertSame(['gap'], self::raw($this->page()->fetch('zero', 1, 'raw_q', 'asc')));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function searches(): array
    {
        return [
            'percent is literal' => ['%', ['50% off']],
            'percent in a phrase' => ['50%', ['50% off']],
            'underscore is literal' => ['_', ['a_b']],
            'underscore in a phrase' => ['a_b', ['a_b']],
            'single quote' => ["o'neil", ["o'neil"]],
            'double quote' => ['say "hi"', ['say "hi"']],
            'backslash' => ['back\\slash', ['back\\slash']],
            'the escape character itself' => ['x|y', ['x|y']],
            'a sql tail finds nothing' => ["' OR '1'='1", []],
            'a comment finds nothing' => ['x -- ', []],
            'plain substring' => ['off', ['50% off']],
            'case-insensitive' => ['SAMSUNG', ['Samsung S24']],
            'no hit' => ['zzz', []],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('searches')]
    public function testSearchEscapesWildcardsAndQuotes(string $term, array $expected): void
    {
        $rows = ['50% off', '50 percent', 'a_b', 'axb', "o'neil", 'say "hi"', 'back\\slash', 'x|y', 'xy',
            'Samsung S24'];
        foreach ($rows as $q) {
            $this->log(['raw_q' => $q]);
        }

        $r = $this->page()->fetch('recent', 1, 'raw_q', 'asc', $term);

        self::assertEqualsCanonicalizing($expected, self::raw($r), $term);
        self::assertSame(count($expected), $r['total']);
        self::assertSame(10, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }

    public function testLikePatternEscapesEveryWildcard(): void
    {
        self::assertSame('%50|%|_x||y%', LogsPage::likePattern('50%_x|y'));
        self::assertSame('%a\\b%', LogsPage::likePattern('a\\b'), 'a backslash is not special for this escape');
    }

    public function testSearchLooksAtTheRawAndTheNormalizedQuery(): void
    {
        // Typed with an Arabic yeh; the stored normalized form has the Persian one.
        $this->log(['raw_q' => "گوشي", 'normalized_q' => 'گوشی']);
        $this->log(['raw_q' => 'tablet', 'normalized_q' => 'tab let']);
        $logs = $this->page();

        self::assertSame(['گوشي'], self::raw($logs->fetch('recent', 1, '', '', 'گوشی')), 'by the normalized form');
        self::assertSame(['گوشي'], self::raw($logs->fetch('recent', 1, '', '', 'گوشي')), 'found by the raw form');
        self::assertSame(['tablet'], self::raw($logs->fetch('recent', 1, '', '', 'tab let')), 'normalized column only');
        self::assertSame([], self::raw($logs->fetch('recent', 1, '', '', 'laptop')));
    }

    public function testSearchCombinesWithViewSortAndPaging(): void
    {
        foreach (range(1, 9) as $i) {
            $this->log(['raw_q' => "phone {$i}", 'latency_ms' => $i * 10, 'result_count' => $i % 3 === 0 ? 0 : 4]);
        }
        $this->log(['raw_q' => 'laptop', 'result_count' => 0]);
        $logs = $this->page(2);

        $zero = $logs->fetch('zero', 1, 'latency_ms', 'asc', 'phone');
        self::assertSame(['phone 3', 'phone 6'], self::raw($zero));
        self::assertSame([3, 2], [$zero['total'], $zero['pages']]);
        self::assertSame(['phone 9'], self::raw($logs->fetch('zero', 2, 'latency_ms', 'asc', 'phone')));
        self::assertSame(['phone 9', 'phone 8'], self::raw($logs->fetch('slowest', 1, '', '', 'phone')));
        self::assertSame('phone', $logs->fetch('recent', 1, '', '', '  phone  ')['q'], 'the term is trimmed');
        $capped = $logs->fetch('recent', 1, '', '', str_repeat('x', 500));
        self::assertSame(100, mb_strlen($capped['q']), 'the term is capped');
    }

    public function testSearchAndUnindexedSortsReadOnlyTheNewestRowsOfABigTable(): void
    {
        foreach (range(1, 10) as $i) {
            $this->log(['raw_q' => "item {$i}", 'latency_ms' => $i]);
        }
        $logs = $this->page(50, 4);

        $found = $logs->fetch('recent', 1, '', '', 'item');
        self::assertSame(['item 10', 'item 9', 'item 8', 'item 7'], self::raw($found));
        self::assertSame(4, $found['scan_limit'], 'the page says older rows were left out');

        $sorted = $logs->fetch('recent', 1, 'latency_ms', 'asc');
        self::assertSame(['item 7', 'item 8', 'item 9', 'item 10'], self::raw($sorted));
        self::assertSame(4, $sorted['scan_limit']);

        $byTime = $logs->fetch('recent', 1, 'ts', 'asc');
        self::assertSame(10, $byTime['total'], 'an index-ordered view covers every row');
        self::assertNull($byTime['scan_limit']);
        self::assertNull($logs->fetch('recent', 1)['scan_limit']);
        self::assertSame(10, $logs->fetch('recent', 1)['total']);

        self::assertNull($this->page(50, 10)->fetch('recent', 1, '', '', 'item')['scan_limit'], 'nothing left out');
        self::assertSame(10, $this->page(50, 0)->fetch('recent', 1, 'raw_q', 'asc')['total'], '0 = no bound');
    }

    public function testATableFromBeforeM21CannotBeSortedByAColumnItLacks(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS old_logs');
        $this->pdo->exec(
            'CREATE TABLE old_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ts DATETIME NOT NULL
             DEFAULT CURRENT_TIMESTAMP, raw_q VARCHAR(512) NOT NULL, normalized_q VARCHAR(512) NOT NULL DEFAULT \'\',
             result_count INT NOT NULL DEFAULT 0, latency_ms INT NOT NULL DEFAULT 0)'
        );
        $this->pdo->exec("INSERT INTO old_logs (raw_q) VALUES ('a'), ('b')");
        try {
            $r = (new LogsPage($this->pdo, 'old_logs', 50))->fetch('recent', 1, 'tier', 'asc');
            self::assertSame(['b', 'a'], array_column($r['rows'], 'raw_q'), 'the view\'s own order');
            self::assertSame(['', 'desc'], [$r['sort'], $r['dir']]);
        } finally {
            $this->pdo->exec('DROP TABLE old_logs');
        }
    }
}
