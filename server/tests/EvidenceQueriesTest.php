<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\IntentMap;
use App\Normalizer;
use App\ProductLoader;
use App\QueryEntities;
use App\SearchController;
use App\Synonyms;
use App\Tokenizer;
use App\TokenStats;

/**
 * M44: the seven queries of the owner's live "test page" (keyword-only, a VPS needs the real model), run through
 * the production wiring over EvidenceCatalog with the bundle files the build would write (token_df.json,
 * entities.json) and the owner's aliases. Before M44 they listed the routers, two consoles, a dropped digit...
 */
final class EvidenceQueriesTest extends DatabaseTestCase
{
    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load(EvidenceCatalog::rows());
        $this->dataDir = TempTree::path('m44_evidence');
        mkdir($this->dataDir, 0777, true);
        $this->writeBundleFiles();
        $this->writeAliases(EvidenceCatalog::aliases());
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dataDir);
        Synonyms::clearCache();
        QueryEntities::clearCache();
        TokenStats::clearCache();
        IntentMap::clearCache();
    }

    /** @param list<list<string>> $groups */
    private function writeAliases(array $groups): void
    {
        file_put_contents($this->dataDir . '/aliases.json', json_encode($groups, JSON_UNESCAPED_UNICODE));
        Synonyms::clearCache();
    }

    /** token_df.json and entities.json as pipeline/keyword.py writes them. */
    private function writeBundleFiles(): void
    {
        $df = [];
        $brands = [];
        foreach (EvidenceCatalog::rows() as $row) {
            $tokens = Tokenizer::split(Normalizer::normalize($row['title'] . ' ' . $row['tags']));
            foreach (array_unique($tokens) as $token) {
                $df[$token] = ($df[$token] ?? 0) + 1;
            }
            $brand = implode(' ', Tokenizer::split(Normalizer::normalize($row['brand'])));
            if (mb_strlen($brand) >= 2) {
                $brands[$brand] = ($brands[$brand] ?? 0) + 1;
            }
        }
        file_put_contents($this->dataDir . '/' . TokenStats::FILE, json_encode([
            'version' => 1,
            'n'       => count(EvidenceCatalog::rows()),
            'df'      => array_filter($df, static fn (int $n): bool => $n >= 2),
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($this->dataDir . '/' . QueryEntities::FILE, json_encode([
            'version'   => 1,
            'brands'    => array_map(
                static fn (string $name, int $count): array => ['name' => $name, 'count' => $count],
                array_keys($brands),
                $brands
            ),
            'platforms' => [
                'ps4' => ['ps4', 'playstation 4', 'پلی استیشن 4'],
                'xbox_one' => ['xbox one', 'ایکس باکس وان'],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;

        return SearchController::fromConfig($this->pdo, $config, null, null, false)
            ->search(['q' => $query, 'limit' => 10], true);
    }

    /** @param array<string, mixed> $response @return array<int, array<string, mixed>> keyword hit by id */
    private static function hits(array $response): array
    {
        $hits = [];
        foreach ($response['debug']['results'] as $row) {
            $hits[$row['product_id']] = $row['keyword_hit'];
        }

        return $hits;
    }

    public function testGiftCardAppleNoLongerListsNintendoCards(): void
    {
        $ids = $this->search('گیفت کارت Apple')['product_ids'];

        self::assertSame([], array_values(array_intersect($ids, [
            EvidenceCatalog::NINTENDO_10, EvidenceCatalog::NINTENDO_20, EvidenceCatalog::NINTENDO_50,
        ])));
    }

    public function testGiftCardAppleFindsTheITunesCardsThroughTheOwnersAlias(): void
    {
        $this->writeAliases([...EvidenceCatalog::aliases(), ['apple', 'اپل', 'آیتونز']]);

        $ids = $this->search('گیفت کارت Apple')['product_ids'];

        self::assertSame([EvidenceCatalog::ITUNES_10, EvidenceCatalog::ITUNES_25], array_slice($ids, 0, 2));
    }

    public function testGtaWithACapacityNumberDoesNotListTheRouters(): void
    {
        $out = $this->search('بازی ظرفیت Gta ۲');

        self::assertSame(EvidenceCatalog::GTA_CAP_2, $out['product_ids'][0]);
        self::assertSame([], array_values(array_intersect($out['product_ids'], [
            EvidenceCatalog::ROUTER_AX6000, EvidenceCatalog::ROUTER_AXE11000,
        ])));
        self::assertNotContains('like', array_column(self::hits($out), 'match_type'));
    }

    public function testSpacedPlayStationListsEveryUsedPs4AndNotTheXbox(): void
    {
        $out = $this->search('play station 4 کارکرده');

        $ids = $out['product_ids'];
        self::assertEqualsCanonicalizing(
            [EvidenceCatalog::CONSOLE_PS4_USED, EvidenceCatalog::CONSOLE_PS4_USED_SLIM],
            array_slice($ids, 0, 2)
        );
        self::assertNotContains(EvidenceCatalog::CONSOLE_XBOX_USED, $ids);
        self::assertNotContains('like', array_column(self::hits($out), 'match_type'));
        self::assertSame(['ps4'], $out['debug']['entities']['platforms']);
    }

    public function testTheDigitOfAMisspelledModelNameIsKept(): void
    {
        $out = $this->search('بازی Ps4 Red dead redeption2');

        self::assertSame('بازی ps4 red dead redemption2', $out['did_you_mean']);
        self::assertTrue($out['did_you_mean_applied']);
        self::assertSame(EvidenceCatalog::GAME_RDR2_PS4, $out['product_ids'][0]);
    }

    public function testGenericWordsAloneDoNotRankTheConsolesFirst(): void
    {
        $out = $this->search('بازی یک راه خروج');

        self::assertSame(EvidenceCatalog::GAME_A_WAY_OUT, $out['product_ids'][0]);
        $hits = self::hits($out);
        self::assertTrue($hits[EvidenceCatalog::GAME_A_WAY_OUT]['title_match']);
        // The consoles hold "راهنما" / "خروجی" in the description (prefix words) and are only a description match.
        foreach ([EvidenceCatalog::CONSOLE_PS4_SLIM, EvidenceCatalog::CONSOLE_PS4_PRO] as $console) {
            self::assertFalse($hits[$console]['title_match'] ?? false);
        }
        // The weighted coverage: the stopword "یک" the game lacks costs it a little, nothing else.
        self::assertLessThan(1.0, $hits[EvidenceCatalog::GAME_A_WAY_OUT]['coverage']);
        self::assertGreaterThan(0.9, $hits[EvidenceCatalog::GAME_A_WAY_OUT]['coverage']);
    }

    public function testAMisspelledBrandKeepsTheMonitorsAndReportsTheBrandAsUnmatched(): void
    {
        $out = $this->search('مانیتور شیامی');

        self::assertSame(
            [EvidenceCatalog::MONITOR_ASUS, EvidenceCatalog::MONITOR_LG, EvidenceCatalog::MONITOR_SAMSUNG],
            array_slice($out['product_ids'], 0, 3)
        );
        self::assertSame(['xiaomi'], $out['debug']['entities']['brands']);
        self::assertSame(['xiaomi'], $out['debug']['entities']['brand_unmatched']);
    }

    public function testAnOffTopicShooterQueryStaysEmpty(): void
    {
        self::assertSame([], $this->search('بازی تفنگی')['product_ids']);
        self::assertSame([], $this->search('لباس زنانه')['product_ids']);
    }
}
