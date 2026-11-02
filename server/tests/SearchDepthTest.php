<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * The OpenCart storefront asks /search with limit=300 and shows that list. The hybrid blend normalizes and
 * ranks only the keyword candidates that fit in `limit`, so the top of a limit-10 answer is not the top of the
 * limit-300 answer shoppers see. This pins the effect (the reason the admin tester and the evaluation tool ask
 * for the storefront depth, see docs/EVALUATION.md); it is not a promise that the API behaves this way forever.
 */
final class SearchDepthTest extends DatabaseTestCase
{
    private const CONSOLE = 3393;
    private const GAME = 5000;

    private string $dataDir = '';
    private MockVpsServer $vps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = TempTree::path('depth_data');
        mkdir($this->dataDir, 0777, true);
        Synonyms::clearCache();

        // 60 "ps4" titles that tie on keyword score (popularity breaks the tie) and one that sits last in that order
        // but is by far the nearest neighbour for the query.
        $rows = [['product_id' => self::CONSOLE, 'title' => 'PS4 Console', 'stock' => 5, 'popularity' => 1000]];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['product_id' => $i, 'title' => "PS4 Game {$i}", 'stock' => 5, 'popularity' => 500 - $i];
        }
        $rows[] = ['product_id' => self::GAME, 'title' => 'PS4 Game Just Cause', 'stock' => 5, 'popularity' => 1];
        (new ProductLoader($this->pdo, 'products'))->load($rows);

        $vector = static fn (float $cosine): array => [$cosine, sqrt(1.0 - $cosine ** 2)];
        $products = [self::GAME => $vector(0.95), self::CONSOLE => $vector(0.5)];
        for ($i = 1; $i <= 60; $i++) {
            $products[$i] = $vector(0.5);
        }
        $this->vps = new MockVpsServer(['products' => $products, 'queries' => ['*' => [1.0, 0.0]]]);
    }

    protected function tearDown(): void
    {
        unset($this->vps);
        TempTree::removeIfAssigned($this->dataDir);
        Synonyms::clearCache();
    }

    /** @return list<int> */
    private function ids(int $limit, bool $hybrid = true): array
    {
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;
        $config['vps']['url'] = $hybrid ? $this->vps->url : '';
        $config['vps']['token'] = FakeVps::TOKEN;
        $config['vps']['timeout_ms'] = 3000;

        return SearchController::fromConfig($this->pdo, $config, null, null, false)
            ->search(['q' => 'ps4', 'limit' => $limit])['product_ids'];
    }

    public function testTheTopTenDependsOnTheRequestedLimit(): void
    {
        $shallow = array_slice($this->ids(10), 0, 10);
        $storefront = array_slice($this->ids(300), 0, 10);

        self::assertNotSame($storefront, $shallow, 'limit 10 and limit 300 give the same top 10');
        self::assertSame(self::GAME, $storefront[0], 'at the storefront depth the nearest neighbour leads');
        self::assertNotContains(self::GAME, $shallow, 'at limit 10 it never reached the candidate pool');
    }

    public function testKeywordOnlyTopTenDoesNotDependOnTheLimitHere(): void
    {
        // The depth effect lives in the hybrid blend (keyword scores exist only for candidates inside `limit`);
        // the plain keyword order is a prefix of the deeper one on this fixture.
        self::assertSame(
            array_slice($this->ids(300, false), 0, 10),
            $this->ids(10, false)
        );
    }
}
