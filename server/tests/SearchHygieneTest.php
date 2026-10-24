<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cache;
use App\Config;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;
use App\VpsClient;

/**
 * The search-fixes round: aliases reach the answer without a restart and never
 * hide behind a cached answer, query hygiene (entities, caps, probes), the v2
 * log row, and the hybrid tier never losing an exact title match.
 */
final class SearchHygieneTest extends DatabaseTestCase
{
    private const MAFIA = 1;
    private const WOLVERINE = 2;
    private const DISPATCH = 3;
    private const ASSASSIN = 4;
    private const RAZER = 5;

    private string $dataDir;
    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            $this->row(self::MAFIA, 'Mafia Definitive Edition', 50),
            $this->row(self::WOLVERINE, 'Marvel Wolverine Collector', 40),
            $this->row(self::DISPATCH, 'Dispatch', 30),
            $this->row(self::ASSASSIN, "Assassin's Creed Mirage", 20),
            $this->row(self::RAZER, 'Razer Kaira X PS5 Headset', 10),
            $this->row(6, 'Atari Flashback Console', 5),
            $this->row(7, 'Battle Royale Poster', 5),
            $this->row(8, 'Battery Pack 5000', 5),
        ]);
        $this->dataDir = sys_get_temp_dir() . '/hygiene_' . uniqid('', true);
        mkdir($this->dataDir);
        $this->writeAliases([['mafia', 'مافیا']]);
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'hyglog_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        array_map('unlink', glob($this->dataDir . '/*') ?: []);
        rmdir($this->dataDir);
        Synonyms::clearCache();
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $title, int $popularity): array
    {
        return ['product_id' => $id, 'title' => $title, 'description' => 'store item', 'stock' => 1,
            'popularity' => $popularity];
    }

    /** @param list<list<string>> $groups */
    private function writeAliases(array $groups): void
    {
        file_put_contents(
            $this->dataDir . '/aliases.json',
            (string) json_encode($groups, JSON_UNESCAPED_UNICODE)
        );
    }

    private function controller(?Cache $cache = null, ?VpsClient $vps = null): SearchController
    {
        /** @var array<string, mixed> $config */
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;
        // A tiny catalog: the soft-AND top-up would answer every query.
        $config['search']['soft_and_min_results'] = 0;
        // Every word of this catalog occurs in one product.
        $config['search']['suggest_min_frequency'] = 1;

        return SearchController::fromConfig($this->pdo, $config, $vps, $cache);
    }

    /** @return array<string, mixed> */
    private function lastLog(): array
    {
        return (array) $this->pdo->query('SELECT * FROM search_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testEditingTheAliasFileReachesTheNextSearchWithoutARestart(): void
    {
        // The reported case: an alias added while the service is running.
        $this->writeAliases([]);
        self::assertSame([], $this->controller()->search(['q' => 'مافیا'])['product_ids']);

        $this->writeAliases([['mafia', 'مافیا']]);

        self::assertSame(
            $this->controller()->search(['q' => 'mafia'])['product_ids'],
            $this->controller()->search(['q' => 'مافیا'])['product_ids']
        );
        self::assertSame([self::MAFIA], $this->controller()->search(['q' => 'مافیا'])['product_ids']);
    }

    public function testAliasKeysAreNormalizedLikeQueries(): void
    {
        // Arabic yeh / kaf, a ZWNJ and capitals in the file still meet a normalized query.
        $this->writeAliases([['Wolverine', "وُلورين‌گ"]]);

        self::assertSame([self::WOLVERINE], $this->controller()->search(['q' => 'ولورینگ'])['product_ids']);
        self::assertSame([self::WOLVERINE], $this->controller()->search(['q' => 'ولورین گ'])['product_ids']);
    }

    public function testAZeroResultAnswerDoesNotOutliveAnAliasEdit(): void
    {
        $redis = new FakeRedis();
        $cache = new Cache($redis, 'c:', 86400, 0, 0, 0, 300);
        $this->writeAliases([]);

        $first = $this->controller($cache)->search(['q' => 'مافیا']);
        $again = $this->controller($cache)->search(['q' => 'مافیا']);
        self::assertSame([], $first['product_ids']);
        self::assertSame(1, (int) $this->lastLog()['cache_hit'], 'the empty answer is cached');
        self::assertSame([], $again['product_ids']);

        // The alias lands; the very next request must not see the cached empty answer.
        $this->writeAliases([['mafia', 'مافیا']]);
        $fixed = $this->controller($cache)->search(['q' => 'مافیا']);

        self::assertSame([self::MAFIA], $fixed['product_ids']);
        self::assertSame(0, (int) $this->lastLog()['cache_hit']);
        // And it is cached now, under the new alias version.
        $this->controller($cache)->search(['q' => 'مافیا']);
        self::assertSame(1, (int) $this->lastLog()['cache_hit']);
    }

    public function testEmptyAnswersUseTheShortTtlAndOthersTheNormalOne(): void
    {
        $redis = new FakeRedis();
        $cache = new Cache($redis, 'c:', 86400, 0, 0, 0, 300);

        $this->controller($cache)->search(['q' => 'zzzzzz']);
        $this->controller($cache)->search(['q' => 'mafia']);

        self::assertSame([300, 86400], array_values($redis->ttls));
    }

    public function testHtmlEntitiesAreDecodedBeforeSearching(): void
    {
        foreach (['Assassin&#039;s Creed', 'Assassin&amp;#039;s Creed', 'Assassin#039s Creed'] as $raw) {
            $result = $this->controller()->search(['q' => $raw]);

            self::assertSame([self::ASSASSIN], $result['product_ids'], $raw);
            self::assertSame($raw, $result['query']['raw']);
        }
    }

    public function testASingleXInAQueryIsNotATenAndStillMatches(): void
    {
        $result = $this->controller()->search(['q' => 'RAZER KAIRA X PS5']);

        self::assertSame('razer kaira x ps5', $result['query']['normalized']);
        self::assertSame([self::RAZER], $result['product_ids']);
    }

    public function testALongQueryIsCappedBeforeAnyStageAndLogged(): void
    {
        $query = 'razer kaira ' . str_repeat('word ', 30);
        $result = $this->controller()->search(['q' => $query]);

        // 8 tokens at most reach the normalizer: "razer kaira" + 6 "word".
        self::assertSame('razer kaira' . str_repeat(' word', 6), $result['query']['normalized']);
        self::assertSame($query, $result['query']['raw']);
        self::assertLessThanOrEqual(512, strlen((string) $this->lastLog()['raw_q']));
    }

    public function testASqlProbeGetsNoSearchAndAFlaggedLogRow(): void
    {
        $probe = "mafia' AND extractvalue(1,concat(0x7e,(select version()))) -- ";
        $result = $this->controller()->search(['q' => $probe]);

        self::assertSame([], $result['product_ids']);
        self::assertSame(0, $result['count']);
        $log = $this->lastLog();
        self::assertSame(1, (int) $log['is_suspicious']);
        self::assertSame(2, (int) $log['log_version']);
        self::assertSame($result['search_uuid'], $log['search_uuid']);

        foreach (['x union select 1', 'a sleep(5)', "a' -- b", 'x concat(0x41)'] as $other) {
            self::assertSame(0, $this->controller()->search(['q' => $other])['count'], $other);
            self::assertSame(1, (int) $this->lastLog()['is_suspicious']);
        }
    }

    public function testTheLogRowCarriesTheV2Fields(): void
    {
        $result = $this->controller()->search([
            'q' => 'مافیا', 'session_id' => 'abc123', 'source' => 'autocomplete', 'page' => 2,
        ]);
        $log = $this->lastLog();

        self::assertSame(2, (int) $log['log_version']);
        self::assertSame($result['search_uuid'], $log['search_uuid']);
        self::assertSame('abc123', $log['session_id']);
        self::assertSame('autocomplete', $log['source']);
        self::assertSame(2, (int) $log['page']);
        self::assertSame('mafia', $log['expanded_q']);
        self::assertSame(1, (int) $log['alias_hit']);
        self::assertSame(0, (int) $log['dym_shown']);
        self::assertSame(0, (int) $log['is_capped']);
        self::assertNotNull($log['lat_keyword_ms']);
        self::assertNull($log['lat_vector_ms'], 'no VPS configured');
        self::assertSame(0, (int) $log['is_suspicious']);
    }

    public function testTheFutureStorefrontRequestIsLoggedAsSent(): void
    {
        $session = md5('php-session-id');
        $this->controller()->search([
            'q' => "Assassin's Creed", 'limit' => 300, 'customer_id' => '7',
            'session_id' => $session, 'source' => 'paging', 'page' => 3,
        ]);
        $log = $this->lastLog();

        self::assertSame([$session, 'paging', 3], [$log['session_id'], $log['source'], (int) $log['page']]);
    }

    public function testBadSessionSourceAndPageFallBackToDefaults(): void
    {
        $this->controller()->search(['q' => 'mafia', 'session_id' => 'bad id!', 'source' => 'x', 'page' => 9999]);
        $log = $this->lastLog();

        self::assertNull($log['session_id']);
        self::assertSame('page', $log['source']);
        self::assertSame(255, (int) $log['page']);
    }

    public function testTheSameSessionRepeatingAQueryIsFlaggedDuplicate(): void
    {
        $request = ['q' => 'mafia', 'session_id' => 's1'];
        $this->controller()->search($request);
        self::assertSame(0, (int) $this->lastLog()['is_duplicate']);

        $this->controller()->search($request);
        self::assertSame(1, (int) $this->lastLog()['is_duplicate']);

        // Another page, another query, another session are all distinct.
        $this->controller()->search($request + ['page' => 2]);
        self::assertSame(0, (int) $this->lastLog()['is_duplicate']);
        $this->controller()->search(['q' => 'mafia', 'session_id' => 's2']);
        self::assertSame(0, (int) $this->lastLog()['is_duplicate']);
        $this->controller()->search(['q' => 'wolverine', 'session_id' => 's1']);
        self::assertSame(0, (int) $this->lastLog()['is_duplicate']);
    }

    public function testASuggestionIsLoggedAsShown(): void
    {
        $result = $this->controller()->search(['q' => 'wolvarine']);

        self::assertSame('wolverine', $result['did_you_mean']);
        self::assertSame(1, (int) $this->lastLog()['dym_shown']);
        self::assertNotNull($this->lastLog()['lat_spell_ms']);
    }

    public function testACachedAnswerKeepsItsExpansionInTheLog(): void
    {
        $cache = new Cache(new FakeRedis(), 'c:', 300);
        $this->controller($cache)->search(['q' => 'مافیا']);
        $this->controller($cache)->search(['q' => 'مافیا']);
        $log = $this->lastLog();

        self::assertSame(1, (int) $log['cache_hit']);
        self::assertSame('mafia', $log['expanded_q']);
        self::assertSame(1, (int) $log['alias_hit']);
        self::assertNull($log['lat_keyword_ms'], 'a cache hit has no stage timings');
    }

    public function testGarbledLayoutIsNotAppliedToModelCodes(): void
    {
        foreach (['HF-1502', 'DX-130', 'GM 2025'] as $code) {
            $result = $this->controller()->search(['q' => $code]);

            self::assertNull($result['did_you_mean'], $code);
            self::assertSame([], $result['product_ids'], $code);
        }
    }

    public function testALayoutFixNeedsATitleMatchToBelieveIt(): void
    {
        // "ئخیثقد" is "modern" typed on a Persian layout; with a title match it is applied.
        (new ProductLoader($this->pdo, 'products'))->load([$this->row(9, 'Modern Warfare', 1)]);

        $result = $this->controller()->search(['q' => 'ئخیثقد']);

        self::assertSame('modern', $result['did_you_mean']);
        self::assertSame([9], $result['product_ids']);
    }

    public function testSuggestionsAreSensibleWords(): void
    {
        // "gtavi" is no "atari" (a different first letter), "battel" is the swapped "battle".
        $gta = $this->controller()->search(['q' => 'gtavi']);
        self::assertNull($gta['did_you_mean']);
        self::assertSame([], $gta['product_ids']);

        self::assertSame('battle', $this->controller()->search(['q' => 'battel'])['did_you_mean']);
    }

    public function testASpacingVariantOfAnAliasBeatsEditDistance(): void
    {
        $this->writeAliases([['resident evil', 'رزیدنت اویل']]);
        (new ProductLoader($this->pdo, 'products'))->load([$this->row(10, 'Resident Evil 4', 1)]);

        $result = $this->controller()->search(['q' => 'رزیدنتاویل']);

        self::assertSame('رزیدنت اویل', $result['did_you_mean']);
        self::assertSame([10], $result['product_ids']);
    }

    public function testAHybridAnswerNeverDropsAnExactTitleMatch(): void
    {
        // The VPS likes everything but "Dispatch" (cosine 0 for it, 0.9 for the rest): the
        // exact title match must survive the relevance floor whatever the neighbours are.
        $products = [];
        foreach ([self::MAFIA, self::WOLVERINE, self::ASSASSIN, self::RAZER, 6, 7, 8] as $id) {
            $products[$id] = [1.0, 0.0, 0.0, 0.0];
        }
        $products[self::DISPATCH] = [0.0, 1.0, 0.0, 0.0];
        $vps = FakeVps::client(['products' => $products, 'queries' => ['*' => [1.0, 0.0, 0.0, 0.0]]]);

        foreach (['Dispatch', 'dispatch', 'DISPATCH '] as $query) {
            $result = $this->controller(null, $vps)->search(['q' => $query]);

            self::assertContains(self::DISPATCH, $result['product_ids'], $query);
            self::assertSame(self::DISPATCH, $result['product_ids'][0], $query);
            self::assertSame('hybrid', $this->lastLog()['tier']);
        }
    }

    public function testEveryAnswerHasItsOwnSearchUuid(): void
    {
        $first = $this->controller()->search(['q' => 'mafia']);
        $second = $this->controller()->search(['q' => 'mafia']);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first['search_uuid']);
        self::assertNotSame($first['search_uuid'], $second['search_uuid']);
    }
}
