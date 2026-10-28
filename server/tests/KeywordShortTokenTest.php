<?php

declare(strict_types=1);

namespace App\Tests;

use App\Keyword;
use App\ProductLoader;
use App\Synonyms;

/**
 * M44: a token shorter than the FULLTEXT minimum is no longer a substring scan. A short number
 * joins the word before it as a model number ("gta 2", "ps 4", "fc 26"); any other short token is
 * a whole word of the title or the tags. The owner's queries: "gta 2" returned two routers
 * ("GT-AX6000"), "play station 4" fell back to the LIKE scan.
 */
final class KeywordShortTokenTest extends DatabaseTestCase
{
    private const GTA_2 = 1;
    private const GTA_V = 2;
    private const ROUTER = 3;
    private const PS4_CONSOLE = 4;
    private const PS4_SPACED = 5;
    private const FC_26 = 6;
    private const FC_25 = 7;
    private const BEARING = 8;

    protected function setUp(): void
    {
        parent::setUp();
        (new ProductLoader($this->pdo, 'products'))->load([
            ['product_id' => self::GTA_2, 'title' => 'بازی GTA 2 برای پلی استیشن', 'popularity' => 5],
            ['product_id' => self::GTA_V, 'title' => 'بازی Grand Theft Auto V', 'popularity' => 50],
            ['product_id' => self::ROUTER, 'title' => 'روتر ایسوس ROG Rapture GT-AX6000', 'popularity' => 900,
                'description' => 'پهنای باند ظرفیت 2 گیگابیت'],
            ['product_id' => self::PS4_CONSOLE, 'title' => 'کنسول PlayStation 4 کارکرده', 'popularity' => 70],
            ['product_id' => self::PS4_SPACED, 'title' => 'کنسول بازی PS 4 Slim', 'popularity' => 60],
            ['product_id' => self::FC_26, 'title' => 'بازی EA SPORTS FC26', 'popularity' => 40],
            ['product_id' => self::FC_25, 'title' => 'بازی EA SPORTS FC 25', 'popularity' => 80],
            ['product_id' => self::BEARING, 'title' => 'بلبرینگ 6202', 'description' => 'مناسب gta2 و fc 26', 'popularity' => 1],
        ]);
    }

    private function keyword(): Keyword
    {
        $groups = [
            ['gta', 'grand theft auto', 'جی تی ای'],
            ['ps4', 'playstation 4', 'پلی استیشن 4'],
        ];

        return new Keyword($this->pdo, 'products', 3, 20, synonyms: new Synonyms($groups));
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        return array_column($this->keyword()->search($query), 'product_id');
    }

    public function testASpacedModelNumberDoesNotReturnTheRouter(): void
    {
        $ids = $this->ids('gta 2');

        self::assertContains(self::GTA_2, $ids);
        self::assertNotContains(self::ROUTER, $ids);
        self::assertNotContains(self::BEARING, $ids);
    }

    public function testNoRelevanceResultComesFromASubstringScan(): void
    {
        foreach (['gta 2', 'بازی ظرفیت gta 2', 'play station 4 کارکرده', 'ps4 کارکرده', 'ps 4', 'fc 26', 'tv', 'لپ'] as $query) {
            $types = array_unique(array_column($this->keyword()->search($query), 'match_type'));

            self::assertNotContains('like', $types, $query);
        }
    }

    public function testASpacedModelNumberMeetsTheJoinedTitle(): void
    {
        self::assertContains(self::FC_26, $this->ids('fc 26'));
        self::assertContains(self::FC_25, $this->ids('fc25'), 'the joined query also meets the spaced title');
        self::assertContains(self::PS4_SPACED, $this->ids('ps4'), 'ps4 meets "PS 4" through the spaced spelling');
    }

    public function testSpacedLatinWordsMeetAnAliasTerm(): void
    {
        // "play station 4" reads like the alias term "playstation 4" once the spaces are ignored.
        $ids = $this->ids('play station 4');

        self::assertContains(self::PS4_CONSOLE, $ids);
    }

    public function testPs4WithAWordDoesNotNeedTheLikePath(): void
    {
        $results = $this->keyword()->search('ps4 کارکرده');

        self::assertSame(self::PS4_CONSOLE, $results[0]['product_id']);
        self::assertNotSame('like', $results[0]['match_type']);
    }

    public function testAShortTokenIsAWholeWordNeverASubstring(): void
    {
        // "26" is not the tail of "6202"/"AX6000"; "gt" is not the head of "gta".
        self::assertSame([], array_values(array_intersect([self::BEARING, self::ROUTER], $this->ids('26'))));
        self::assertSame([], $this->ids('gt'));
    }

    public function testAQueryOfShortTokensOnlyHasNoPartialMatches(): void
    {
        $types = array_unique(array_column($this->keyword()->search('ps 9'), 'match_type'));

        self::assertSame([], array_values(array_diff($types, ['title'])), 'no partial hit on "ps" alone');
        self::assertSame([], $this->ids('xx 9'));
    }
}
