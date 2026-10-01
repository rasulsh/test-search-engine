<?php

declare(strict_types=1);

namespace App\Tests;

use App\Logger;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M21 through the production wiring (SearchController::fromConfig): the brand
 * match boost (with an alias variant bridging Persian and Latin), the tag
 * phrase boost, the debug breakdown, and what every search writes to
 * search_logs (suggestion, tier). The VPS is FakeVps, answered in-process.
 */
final class SearchControllerStructuredTest extends DatabaseTestCase
{
    private const SSD_QUERY = 'اس اس دی سامسونگ';

    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/m21_data_' . uniqid('', true);
        mkdir($this->dataDir, 0777, true);
        file_put_contents($this->dataDir . '/aliases.json', '[["سامسونگ", "samsung"]]');
        Synonyms::clearCache();

        // The queried brand has the lowest popularity and the highest id: it can only lead through the boost.
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'حافظه اس اس دی وسترن ۱ ترابایت', 'brand' => 'WD',
                'category' => 'SSD', 'stock' => 5, 'popularity' => 100],
            ['product_id' => 2, 'title' => 'حافظه اس اس دی کینگستون ۱ ترابایت', 'brand' => 'Kingston',
                'category' => 'SSD', 'stock' => 5, 'popularity' => 90],
            ['product_id' => 3, 'title' => 'حافظه اس اس دی ۹۷۰ ایوو ۱ ترابایت', 'brand' => 'Samsung',
                'category' => 'SSD', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 4, 'title' => 'کرید اوریجینز', 'tags' => 'Assassins Creed Origins No Mans Sky',
                'brand' => 'Ubisoft', 'category' => 'Games', 'stock' => 5, 'popularity' => 1],
            ['product_id' => 5, 'title' => 'بازی دیگر', 'specs' => 'Assassins Creed Origins',
                'brand' => 'Ubisoft', 'category' => 'Games', 'stock' => 5, 'popularity' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataDir . '/aliases.json');
        @rmdir($this->dataDir);
        Synonyms::clearCache();
    }

    /** @param array<string, float> $searchOverrides cosine of the games to the query: $gameCosine */
    private function controller(
        array $searchOverrides = [],
        bool $withVps = true,
        float $gameCosine = 0.3
    ): SearchController {
        $config = require self::repoRoot() . '/server/config.example.php';
        $config['paths']['data'] = $this->dataDir;
        $config['search'] = $searchOverrides + $config['search'];
        $unit = [1.0, 0.0, 0.0];
        $vps = FakeVps::client(['products' => [
            1 => $unit, 2 => $unit, 3 => [0.99, 0.14, 0.0],
            // The games sit below the cosine floor unless a test lifts them.
            4 => [$gameCosine, sqrt(1 - $gameCosine ** 2), 0.0], 5 => [$gameCosine, sqrt(1 - $gameCosine ** 2), 0.0],
        ], 'queries' => ['*' => $unit]]);

        return SearchController::fromConfig($this->pdo, $config, $withVps ? $vps : null);
    }

    /** @return array<string, mixed> */
    private function lastLog(): array
    {
        return (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testBrandMatchBoostLiftsTheQueriedBrandThroughAnAlias(): void
    {
        // Nothing holds the Persian brand word, so the keyword tier serves the
        // SSDs by popularity (the drift); the alias variant names Samsung.
        $off = $this->controller(['brand_match_boost' => 0.0])->search(['q' => self::SSD_QUERY]);
        $on = $this->controller()->search(['q' => self::SSD_QUERY]);

        self::assertSame([1, 2, 3], array_slice($off['product_ids'], 0, 3));
        self::assertSame(3, $on['product_ids'][0]);
        // One brand cannot flood: the other SSDs still follow, and unrelated products stay out.
        self::assertSame([3, 1, 2], $on['product_ids']);
    }

    public function testCategoryMatchBoostRidesTheSameWiring(): void
    {
        $config = ['brand_match_boost' => 0.0, 'category_match_boost' => 0.5];
        $out = $this->controller($config, true, 0.9)->search(['q' => 'اس اس دی games'], true);

        // "games" is the category of 4 and 5: both carry the category boost, the SSDs do not.
        $seen = [];
        foreach ($out['debug']['results'] as $row) {
            $seen[$row['product_id']] = $row['blend']['boosts']['category'] ?? null;
        }
        self::assertSame(0.5, $seen[4] ?? null);
        self::assertSame(0.5, $seen[5] ?? null);
        self::assertSame(0.0, $seen[1] ?? null);
    }

    public function testDebugBreakdownShape(): void
    {
        $out = $this->controller()->search(['q' => self::SSD_QUERY], true);

        $debug = $out['debug'];
        self::assertSame(['tier', 'settings', 'results'], array_keys($debug));
        self::assertSame('hybrid', $debug['tier']);
        self::assertSame(0.15, $debug['settings']['brand_match_boost']);
        self::assertCount(count($out['product_ids']), $debug['results']);

        $first = $debug['results'][0];
        self::assertSame(3, $first['product_id']);
        self::assertSame(1, $first['rank']);
        self::assertSame(['product_id', 'rank', 'keyword_hit', 'pinned', 'blend'], array_keys($first));
        self::assertSame(
            ['match_type', 'score', 'title_match', 'field_match', 'name_all'],
            array_keys($first['keyword_hit'])
        );
        self::assertFalse($first['pinned']);
        self::assertSame(['keyword', 'semantic', 'blended', 'boosts', 'score'], array_keys($first['blend']));
        self::assertSame(['score', 'normalized'], array_keys($first['blend']['keyword']));
        self::assertSame(['cosine', 'normalized', 'assumed'], array_keys($first['blend']['semantic']));
        self::assertSame(
            ['stock', 'popularity', 'brand', 'category', 'tag'],
            array_keys($first['blend']['boosts'])
        );
        self::assertSame(0.15, $first['blend']['boosts']['brand']);
        self::assertSame(0.1, $first['blend']['boosts']['stock']);
    }

    public function testDebugIsOffByDefaultAndNeverLogged(): void
    {
        $plain = $this->controller()->search(['q' => self::SSD_QUERY]);

        self::assertArrayNotHasKey('debug', $plain);
        self::assertArrayNotHasKey('debug', $this->controller()->search(['q' => 'sony'], false));

        $this->controller()->search(['q' => self::SSD_QUERY], true);
        $columns = array_keys($this->lastLog());
        self::assertNotContains('debug', $columns);
        self::assertStringNotContainsString('blend', implode(' ', array_map('strval', $this->lastLog())));
    }

    public function testDebugOnAKeywordOnlySearch(): void
    {
        $out = $this->controller([], false)->search(['q' => 'creed origins'], true);

        self::assertSame('keyword_only', $out['debug']['tier']);
        foreach ($out['debug']['results'] as $row) {
            self::assertNull($row['blend']); // keyword order, no blend
            self::assertIsArray($row['keyword_hit']);
        }
        self::assertSame([4, 5], array_column($out['debug']['results'], 'product_id')); // tag, then specs
    }

    public function testTagPhraseBoostAppliesToTheTaggedProductOnly(): void
    {
        $out = $this->controller([], true, 0.9)->search(['q' => 'assassins creed origins'], true);

        $blend = array_column($out['debug']['results'], 'blend', 'product_id');
        self::assertSame([4, 5], array_keys($blend));
        self::assertSame(0.1, $blend[4]['boosts']['tag']);
        self::assertSame(0.0, $blend[5]['boosts']['tag']);

        // One word is the keyword tier's business: no phrase boost.
        $single = $this->controller([], true, 0.9)->search(['q' => 'assassins'], true);
        foreach (array_column($single['debug']['results'], 'blend') as $blendRow) {
            self::assertSame(0.0, $blendRow['boosts']['tag']);
        }
    }

    public function testTagOnlyMatchSurvivesTheHybridFloorAsASolidHit(): void
    {
        // Product 4 matches only through its tags; the VPS knows nothing of it
        // (keyword-weight alone would sit under the floor). A name match is solid.
        $unit = [1.0, 0.0, 0.0];
        $config = require self::repoRoot() . '/server/config.example.php';
        $config['paths']['data'] = $this->dataDir;
        $vps = FakeVps::client(['products' => [1 => $unit], 'queries' => ['*' => $unit]]);

        $out = SearchController::fromConfig($this->pdo, $config, $vps)->search(['q' => 'assassins creed origins']);

        self::assertContains(4, $out['product_ids']);
        self::assertNotContains(5, $out['product_ids']); // specs-only: weak, no cosine, dropped
    }

    public function testEverySearchLogsTierAndSuggestionInOneRow(): void
    {
        $hybrid = $this->controller();
        $hybrid->search(['q' => 'sony']);
        self::assertSame('hybrid', $this->lastLog()['tier']);
        self::assertSame(1, (int) $this->lastLog()['had_vector']);
        self::assertNull($this->lastLog()['did_you_mean']);

        $this->controller([], false)->search(['q' => 'اس اس دی سامسونگ']);
        $log = $this->lastLog();
        self::assertSame('keyword_only', $log['tier']);
        self::assertSame(0, (int) $log['had_vector']);
        self::assertSame(self::SSD_QUERY, $log['raw_q']);
        // Keyword-only: no blend, so no brand boost: popularity order.
        self::assertStringStartsWith('1,2,3', (string) $log['top_ids']);

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }

    public function testSuggestionIsLoggedWhenOffered(): void
    {
        // "Ubisoft" typo: the suggestion is offered (and applied: no literal hit).
        $out = $this->controller([], false)->search(['q' => 'ubisof']);

        $log = $this->lastLog();
        self::assertSame($out['did_you_mean'], $log['did_you_mean']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());
    }

    public function testLoggerAddsTheNewColumnsToAnOlderTable(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec(
            'CREATE TABLE search_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                raw_q VARCHAR(512) NOT NULL, normalized_q VARCHAR(512) NOT NULL DEFAULT \'\',
                had_vector TINYINT(1) NOT NULL DEFAULT 0, result_count INT UNSIGNED NOT NULL DEFAULT 0,
                top_ids VARCHAR(1024) NOT NULL DEFAULT \'\', customer_id VARCHAR(64) DEFAULT NULL,
                latency_ms INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (id)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4'
        );
        $logger = new Logger($this->pdo, 'search_logs');

        $logger->log(['raw_q' => 'a', 'did_you_mean' => 'b', 'tier' => Logger::TIER_HYBRID, 'had_vector' => true]);
        $logger->log(['raw_q' => 'c']);

        $rows = $this->pdo->query('SELECT raw_q, did_you_mean, tier FROM search_logs ORDER BY id')->fetchAll();
        self::assertSame(
            [
                ['raw_q' => 'a', 'did_you_mean' => 'b', 'tier' => 'hybrid'],
                ['raw_q' => 'c', 'did_you_mean' => null, 'tier' => 'keyword_only'],
            ],
            $rows
        );
    }

    public function testLoggerFallsBackToTheOldColumnsWhenTheTableCannotBeAltered(): void
    {
        // A view over an older table accepts inserts but refuses ALTER TABLE.
        $this->pdo->exec('DROP TABLE search_logs');
        $this->pdo->exec(
            'CREATE TABLE search_logs_base (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, raw_q VARCHAR(512) NOT NULL,
                normalized_q VARCHAR(512) NOT NULL DEFAULT \'\', had_vector TINYINT(1) NOT NULL DEFAULT 0,
                result_count INT UNSIGNED NOT NULL DEFAULT 0, top_ids VARCHAR(1024) NOT NULL DEFAULT \'\',
                customer_id VARCHAR(64) DEFAULT NULL, latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB'
        );
        $this->pdo->exec('CREATE VIEW search_logs AS SELECT * FROM search_logs_base');

        try {
            (new Logger($this->pdo, 'search_logs'))->log(['raw_q' => 'kept', 'tier' => Logger::TIER_HYBRID]);

            self::assertSame('kept', $this->pdo->query('SELECT raw_q FROM search_logs_base')->fetchColumn());
        } finally {
            $this->pdo->exec('DROP VIEW search_logs');
            $this->pdo->exec('DROP TABLE search_logs_base');
            $this->pdo->exec((string) file_get_contents(self::repoRoot() . '/db/schema.sql'));
        }
    }

    public function testOverLongQueryIsTruncatedNotDropped(): void
    {
        $long = str_repeat('گ', 600);

        $this->controller([], false)->search(['q' => $long]);

        self::assertSame(512, mb_strlen((string) $this->lastLog()['raw_q']));
    }

    public function testALogFailureNeverFailsTheSearch(): void
    {
        $this->pdo->exec('DROP TABLE search_logs');
        $errorLog = (string) tempnam(sys_get_temp_dir(), 'm21log_');
        $previous = ini_set('error_log', $errorLog);
        try {
            $out = $this->controller([], false)->search(['q' => 'کرید']);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame([4], $out['product_ids']);
        self::assertStringContainsString('log write failed', (string) file_get_contents($errorLog));
        @unlink($errorLog);
        $this->pdo->exec((string) file_get_contents(self::repoRoot() . '/db/schema.sql'));
    }
}
