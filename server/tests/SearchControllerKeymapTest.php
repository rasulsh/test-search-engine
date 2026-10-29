<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Keymap;
use App\ProductLoader;
use App\SearchController;
use App\Synonyms;

/**
 * Keyboard-layout recovery through the production wiring: punctuation keys, digits kept,
 * layout then spelling, and the corrected text reaching the VPS. The VPS is FakeVps.
 */
final class SearchControllerKeymapTest extends DatabaseTestCase
{
    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = TempTree::path('keymap_data');
        mkdir($this->dataDir, 0777, true);
        Synonyms::clearCache();
        file_put_contents($this->dataDir . '/' . Synonyms::ALIASES_FILE, json_encode(
            [['mortal kombat', 'mortal combat']],
            JSON_UNESCAPED_UNICODE
        ));
        $rows = [
            1 => 'Mortal Kombat 1 Standard Edition',
            2 => 'GTA 6 Standard Edition',
            3 => 'کنسول بازی ایکس باکس',
            4 => 'Sony WH Headphones',
            5 => 'HF-1502 Headset',
            6 => 'PS5 Slim Console',
            7 => 'Sony Camera A7',
        ];
        $loader = [];
        foreach ($rows as $id => $title) {
            $loader[] = ['product_id' => $id, 'title' => $title, 'stock' => 5, 'popularity' => 10];
        }
        (new ProductLoader($this->pdo, 'products'))->load($loader);
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dataDir);
        Synonyms::clearCache();
    }

    /** @param list<array<string, mixed>> $calls */
    private function controller(array &$calls = [], bool $withVps = true): SearchController
    {
        $config = Config::defaults();
        $config['paths']['data'] = $this->dataDir;
        $scenario = ['products' => [], 'queries' => ['*' => [1.0, 0.0]]];
        $vps = $withVps ? FakeVps::client($scenario, $calls) : null;

        return SearchController::fromConfig($this->pdo, $config, $vps);
    }

    public function testLayoutGibberishOfAnAliasedNameRecovers(): void
    {
        $result = $this->controller()->search(['q' => 'ئخقفشم زخئذشف']);

        self::assertContains(1, $result['product_ids']);
        self::assertTrue($result['did_you_mean_applied']);
        self::assertSame('mortal combat', $result['did_you_mean']);
    }

    public function testDigitIsKeptWhileTheLettersAreRemapped(): void
    {
        $result = $this->controller()->search(['q' => 'لفش ۶']);

        self::assertSame('gta 6', $result['did_you_mean']);
        self::assertSame(2, $result['product_ids'][0]);
    }

    public function testLetterAndDigitWordIsRemapped(): void
    {
        $result = $this->controller()->search(['q' => 'حس۵']);

        self::assertSame('ps5', $result['did_you_mean']);
        self::assertContains(6, $result['product_ids']);
    }

    public function testPunctuationKeysRecoverAPersianWord(): void
    {
        // "کنسول" typed on a US layout: ک=; ن=k س=s و=, ل=g.
        $result = $this->controller()->search(['q' => ';ks,g']);

        self::assertSame('کنسول', $result['did_you_mean']);
        self::assertContains(3, $result['product_ids']);
    }

    public function testPersianLettersTypedForALatinWordRecover(): void
    {
        $result = $this->controller()->search(['q' => 'سخدغ']);

        self::assertSame('sony', $result['did_you_mean']);
        self::assertEqualsCanonicalizing([4, 7], $result['product_ids']);
    }

    public function testAModelCodeIsNotRemapped(): void
    {
        $result = $this->controller()->search(['q' => 'HF-1502']);

        self::assertNull($result['did_you_mean']);
        self::assertSame([5], $result['product_ids']);
    }

    public function testASpellingErrorOnTheRemappedTextIsCorrected(): void
    {
        // "sohy" (a typo of "sony") typed on a Persian layout; the layout fix alone finds nothing.
        $result = $this->controller()->search(['q' => Keymap::enToFa('sohy')]);

        self::assertSame('sony', $result['did_you_mean']);
        self::assertContains(4, $result['product_ids']);
    }

    public function testTheAppliedTextReachesTheVps(): void
    {
        $calls = [];
        $this->controller($calls)->search(['q' => 'سخدغ']);

        self::assertCount(1, $calls);
        self::assertSame('sony', $calls[0]['q']);
    }

    public function testASuggestionThatIsOnlyOfferedDoesNotChangeTheVpsQuery(): void
    {
        $calls = [];
        // "sony" matches 2 products literally (below the suggestion threshold): kept, not replaced.
        $this->controller($calls)->search(['q' => 'sony']);

        self::assertSame('sony', $calls[0]['q']);
    }
}
