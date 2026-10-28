<?php

declare(strict_types=1);

namespace App\Tests;

use App\AliasCounts;
use App\AliasStore;
use App\Cache;
use App\Config;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * M41: the owner's aliases live in storage/aliases.json. Search reads that file (the bundle's own
 * until the storage file exists), the panel's tester runs without a log row or a result cache, and
 * the alias manager counts products on demand.
 */
final class SearchControllerStorageAliasTest extends DatabaseTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TempTree::make('m41_storage');
        mkdir($this->root . '/data');
        Synonyms::clearCache();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => 1, 'title' => 'PS5 Console Slim 1TB', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 2, 'title' => 'کنسول پلی استیشن ۵ اسلیم', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 3, 'title' => 'Xbox Series X', 'stock' => 5, 'popularity' => 10],
            ['product_id' => 4, 'title' => 'PS5 Controller', 'stock' => 5, 'popularity' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->root);
        Synonyms::clearCache();
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = Config::defaults($this->root);
        $config['paths']['data'] = $this->root . '/data';
        $config['paths']['storage'] = $this->root . '/storage';

        return $config;
    }

    /** @return list<int> */
    private function ids(string $query, bool $record = true, ?Cache $cache = null): array
    {
        return SearchController::fromConfig($this->pdo, $this->config(), null, $cache, $record)
            ->search(['q' => $query])['product_ids'];
    }

    private function logRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn();
    }

    public function testSearchReadsTheBundleFileUntilTheStorageFileExists(): void
    {
        file_put_contents($this->root . '/data/aliases.json', '[["sony fan", "xbox"]]');
        self::assertContains(3, $this->ids('sony fan'));

        // The first panel visit seeds storage; from then on only the storage file counts.
        $store = AliasStore::fromConfig($this->config());
        $store->seedIfMissing();
        $store->save([['sony fan', 'playstation']], $store->read()['version']);
        Synonyms::clearCache();
        $ids = $this->ids('sony fan');
        self::assertNotContains(3, $ids, 'the bundle alias is no longer read');
    }

    public function testASavedAliasIsLiveOnTheNextSearchWithoutAReload(): void
    {
        $store = AliasStore::fromConfig($this->config());
        self::assertNotContains(1, $this->ids('پی اس فایو'));

        $store->save([['ps5', 'پی اس فایو']], '');
        $ids = $this->ids('پی اس فایو');
        self::assertContains(1, $ids);
        self::assertContains(4, $ids);

        // Deleting the group takes the words away again at once.
        $store->save([], $store->read()['version']);
        self::assertNotContains(1, $this->ids('پی اس فایو'));
        self::assertNotContains(4, $this->ids('پی اس فایو'));
    }

    public function testTheTesterRecordsNothing(): void
    {
        $redis = new FakeRedis();
        $cache = new Cache($redis, 'search:cache:', 60);

        $this->ids('ps5', false, $cache);
        self::assertSame(0, $this->logRows(), 'no search_logs row');
        self::assertSame([], $redis->data, 'nothing read from or written to the result cache');
        self::assertSame([], $redis->log, 'the cache was not even asked');

        $this->ids('ps5', true, $cache);
        self::assertSame(1, $this->logRows());
        self::assertNotSame([], $redis->data);
    }

    public function testTheTesterReturnsTheDebugBreakdownWithMatchTypes(): void
    {
        $response = SearchController::fromConfig($this->pdo, $this->config(), null, null, false)
            ->search(['q' => 'ps5', 'limit' => 10], true);

        self::assertSame(\App\Logger::TIER_KEYWORD_ONLY, $response['debug']['tier']);
        self::assertNotSame([], $response['debug']['results']);
        self::assertArrayHasKey('match_type', $response['debug']['results'][0]['keyword_hit']);
    }

    public function testCountsAreLiteralPerTermAndCapped(): void
    {
        $config = $this->config();
        $config['admin']['alias_warn_matches'] = 1;
        $counts = AliasCounts::fromConfig($this->pdo, $config);

        self::assertSame(1, $counts->count('xbox'));
        self::assertSame(0, $counts->count('nintendo'));
        // "ps5" matches two products; the count stops at cap + 1.
        self::assertSame(2, $counts->count('ps5'));
        // No expansion: an alias group does not widen the count.
        AliasStore::fromConfig($config)->save([['xbox', 'ps5']], '');
        Synonyms::clearCache();
        self::assertSame(1, $counts->count('xbox'));
        self::assertSame(1, $counts->cap());
    }

    public function testTheSynonymBlocklistReachesTheRuntimeAndChangesTheCacheVersion(): void
    {
        file_put_contents($this->root . '/data/synonyms.json', '[["mouse", "موس"]]');
        file_put_contents($this->root . '/data/aliases.json', '[]');
        $config = $this->config();
        $before = SearchController::synonymsFromConfig($config);

        $config['search']['synonym_blocklist'] = 'mouse';
        $after = SearchController::synonymsFromConfig($config);

        self::assertSame([['mouse'], ['موس']], $before->variants(['mouse'], 5));
        self::assertSame([['mouse']], $after->variants(['mouse'], 5));
        self::assertNotSame($before->version(), $after->version());
    }

    public function testSynonymsFromConfigUsesTheStorageFileWhenItExists(): void
    {
        file_put_contents($this->root . '/data/aliases.json', '[["a", "b"]]');
        $config = $this->config();
        self::assertEqualsCanonicalizing(['a', 'b'], SearchController::synonymsFromConfig($config)->aliasTokens());

        AliasStore::fromConfig($config)->save([['c', 'd']], '');
        self::assertEqualsCanonicalizing(['c', 'd'], SearchController::synonymsFromConfig($config)->aliasTokens());
    }
}
