<?php

declare(strict_types=1);

namespace App\Tests;

use App\IntentMap;
use PHPUnit\Framework\TestCase;

final class IntentMapTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = TempTree::path('intent');
        mkdir($this->dir, 0777, true);
        IntentMap::clearCache();
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dir);
        IntentMap::clearCache();
    }

    private function write(string $json): void
    {
        file_put_contents($this->dir . '/' . IntentMap::FILE, $json);
    }

    public function testLoadsTheBundleFileAndResolvesAKeyFoundWhole(): void
    {
        $this->write(json_encode([
            'فرمون' => ['category' => ['لوازم جانبی گیمینگ'], 'tags' => ['فرمان بازی']],
            'شوتر' => ['tags' => ['شوتر']],
        ], JSON_UNESCAPED_UNICODE));

        $map = IntentMap::fromDirectory($this->dir);

        self::assertSame(
            ['categories' => ['لوازم جانبی گیمینگ'], 'tags' => ['فرمان بازی']],
            $map->resolve('فرمون', 4)
        );
        self::assertSame(
            ['categories' => ['لوازم جانبی گیمینگ'], 'tags' => ['فرمان بازی', 'شوتر']],
            $map->resolve('بهترین فرمون شوتر', 4)
        );
        self::assertSame(['categories' => [], 'tags' => []], $map->resolve('موس', 4));
    }

    public function testKeysAndNamesAreNormalizedOnLoadAndMatchOnWholeTokensOnly(): void
    {
        // A hand-edited server file with a Latin-case key, Arabic ye / kaf and a phrase key.
        $this->write(json_encode([
            'Racing Wheel' => ['category' => ['كنسول  بازي']],
        ], JSON_UNESCAPED_UNICODE));
        $map = IntentMap::fromDirectory($this->dir);

        self::assertSame(['categories' => ['کنسول بازی'], 'tags' => []], $map->resolve('racing wheel', 4));
        self::assertSame(['categories' => ['کنسول بازی'], 'tags' => []], $map->resolve('best racing wheel xl', 4));
        self::assertSame(['categories' => [], 'tags' => []], $map->resolve('racing', 4)); // part of the phrase
        self::assertSame(['categories' => [], 'tags' => []], $map->resolve('racing wheels', 4)); // not a substring
    }

    public function testTheLongestPhraseWinsAndTermsAreBounded(): void
    {
        $this->write(json_encode([
            'a' => ['tags' => ['ta']],
            'a b' => ['tags' => ['tab']],
            'c' => ['tags' => ['tc']],
            'd' => ['tags' => ['td']],
        ]));
        $map = IntentMap::fromDirectory($this->dir);

        self::assertSame(['tab'], $map->resolve('a b', 4)['tags']);
        self::assertSame(['ta', 'tc', 'td'], $map->resolve('a c d', 4)['tags']);
        self::assertSame(['ta', 'tc'], $map->resolve('a c d', 2)['tags']);
        self::assertSame(['categories' => [], 'tags' => []], $map->resolve('a', 0));
    }

    public function testMissingFileIsAnEmptyMapWithoutError(): void
    {
        $map = IntentMap::fromDirectory($this->dir);

        self::assertSame(['categories' => [], 'tags' => []], $map->resolve('فرمون', 4));
    }

    public function testUnreadableOrInvalidFileIsAnEmptyMapAtSearchTime(): void
    {
        $this->write('{"a": ["not an object"]}');

        self::assertSame(['categories' => [], 'tags' => []], IntentMap::fromDirectory($this->dir)->resolve('a', 4));
    }

    public function testParseValidatesTheShape(): void
    {
        self::assertSame([], IntentMap::parse('{}'));
        self::assertNotNull(IntentMap::parse('{"a": {"category": ["x"]}}'));
        self::assertNotNull(IntentMap::parse('{"a": {"tags": ["x"], "category": []}}'));
        foreach (
            ['', '[]x', '["a"]', '{"a": "x"}', '{"a": {}}', '{"a": {"colour": ["x"]}}',
            '{"a": {"tags": "x"}}', '{"a": {"tags": [1]}}', '{"a": {"tags": {"k": "x"}}}'] as $bad
        ) {
            self::assertNull(IntentMap::parse($bad), $bad);
        }
    }

    public function testACachedMapFollowsTheFileWhenItChanges(): void
    {
        $this->write('{"a": {"tags": ["one"]}}');
        self::assertSame(['one'], IntentMap::fromDirectory($this->dir)->resolve('a', 4)['tags']);

        $this->write('{"a": {"tags": ["two", "extra"]}}');

        self::assertSame(['two', 'extra'], IntentMap::fromDirectory($this->dir)->resolve('a', 4)['tags']);
    }
}
