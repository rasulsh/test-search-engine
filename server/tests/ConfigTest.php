<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreTheFullNestedTreeAndMatchTheSchema(): void
    {
        $defaults = Config::defaults();

        self::assertSame(
            array_keys(Config::schema()),
            array_keys(Config::flatten($defaults)),
            'every schema key has a default and vice versa'
        );
        self::assertSame(0.4, $defaults['search']['semantic_min_score']);
        self::assertTrue($defaults['search']['require_all_terms']);
        self::assertFalse($defaults['redis']['enabled']);
        self::assertSame(Normalizer::VERSION, $defaults['model']['normalization_version']);
        self::assertSame(dirname(__DIR__) . '/data', $defaults['paths']['data']);
    }

    public function testEveryDefaultHasItsSchemaType(): void
    {
        $flat = Config::flatten(Config::defaults());
        foreach (Config::schema() as $path => $type) {
            self::assertSame($type, get_debug_type($flat[$path]), "default of {$path}");
        }
    }

    public function testRequiredKeysAreOnlyTheEnvironmentOnes(): void
    {
        self::assertSame(['db.dsn', 'db.user'], Config::required());
        foreach (Config::required() as $path) {
            self::assertArrayHasKey($path, Config::schema());
        }
    }

    public function testOverridesWinAndTheRestIsFilledFromDefaults(): void
    {
        $config = Config::merge([
            'db' => ['dsn' => 'mysql:host=h;dbname=d', 'user' => 'u'],
            'search' => ['title_weight' => 12],
            'vps' => ['url' => 'https://vps.example.com'],
        ]);

        self::assertSame('mysql:host=h;dbname=d', $config['db']['dsn']);
        self::assertSame('products', $config['db']['products_table'], 'a sibling of an override keeps its default');
        self::assertSame(12.0, $config['search']['title_weight'], 'cast to float');
        self::assertSame(1.0, $config['search']['desc_weight']);
        self::assertSame('https://vps.example.com', $config['vps']['url']);
        self::assertSame(300, $config['vps']['timeout_ms']);
        self::assertSame(Config::defaults()['redis'], $config['redis']);
    }

    /** @return array<string, array{string, mixed, mixed}> */
    public static function castCases(): array
    {
        return [
            'zero int from a string' => ['int', '0', 0],
            'zero float from a string' => ['float', '0.0', 0.0],
            'zero float from "0"' => ['float', '0', 0.0],
            'false from "false"' => ['bool', 'false', false],
            'false from "0"' => ['bool', '0', false],
            'true from "1"' => ['bool', '1', true],
            'true from "on"' => ['bool', 'on', true],
            'false from a real false' => ['bool', false, false],
            'int from a numeric string' => ['int', ' 42 ', 42],
            'negative float' => ['float', '-0.5', -0.5],
            'float from an int' => ['float', 3, 3.0],
            'string stays as given' => ['string', ' x ', ' x '],
            'empty string stays a string' => ['string', '', ''],
            'string "0" stays a string' => ['string', '0', '0'],
            'not an int' => ['int', '3.5', null],
            'text is not a float' => ['float', 'abc', null],
            'maybe is not a bool' => ['bool', 'maybe', null],
            'blank int is unset' => ['int', '', null],
            'array is not a string' => ['string', ['x'], null],
        ];
    }

    #[DataProvider('castCases')]
    public function testCast(string $type, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, Config::cast($type, $value));
    }

    public function testExplicitZeroAndFalseSurviveTheMerge(): void
    {
        $config = Config::merge([
            'search' => [
                'semantic_min_score' => '0', 'stock_boost' => '0.0', 'require_all_terms' => 'false',
                'soft_and_min_results' => '0',
            ],
            'redis' => ['enabled' => 'false', 'db' => '0'],
        ]);

        self::assertSame(0.0, $config['search']['semantic_min_score']);
        self::assertSame(0.0, $config['search']['stock_boost']);
        self::assertFalse($config['search']['require_all_terms']);
        self::assertSame(0, $config['search']['soft_and_min_results']);
        self::assertFalse($config['redis']['enabled']);
        self::assertSame(0, $config['redis']['db']);
    }

    public function testABlankOrInvalidTunableKeepsItsDefault(): void
    {
        $config = Config::merge([
            'search' => ['title_weight' => '', 'desc_weight' => 'abc'],
            'redis' => ['port' => ''],
        ]);

        self::assertSame(10.0, $config['search']['title_weight']);
        self::assertSame(1.0, $config['search']['desc_weight']);
        self::assertSame(6379, $config['redis']['port']);
    }

    public function testLoadWithoutAFileGivesTheDefaults(): void
    {
        self::assertSame(Config::defaults(), Config::load(null));
        self::assertSame(Config::defaults(), Config::load(sys_get_temp_dir() . '/no-such-config.php'));
    }

    public function testLoadReadsTheOverridesFileAndTheRootMovesThePaths(): void
    {
        $file = TempConfig::write(['search' => ['min_relevance' => 0.3]]);
        $config = Config::load($file, '/srv/search/');

        self::assertSame(0.3, $config['search']['min_relevance']);
        self::assertSame('/srv/search/data', $config['paths']['data']);
        self::assertSame('/srv/search/data_incoming', $config['paths']['data_incoming']);
    }

    public function testAPathOverrideBeatsTheRoot(): void
    {
        $config = Config::merge(['paths' => ['data' => '/var/search-data']], '/srv/search');

        self::assertSame('/var/search-data', $config['paths']['data']);
        self::assertSame('/srv/search/data_incoming', $config['paths']['data_incoming']);
    }

    public function testTheOverridesOnlyExampleMergesCleanly(): void
    {
        $example = __DIR__ . '/../config.example.php';
        $overrides = Config::overrides($example);

        foreach (array_keys(Config::flatten($overrides)) as $path) {
            self::assertArrayHasKey($path, Config::schema(), "{$path} in config.example.php is not a known key");
        }
        $merged = Config::load($example);
        self::assertSame(array_keys(Config::flatten(Config::defaults())), array_keys(Config::flatten($merged)));
        self::assertSame(Config::defaults()['search'], $merged['search'], 'the example repeats no tunable');
    }

    public function testAnUnknownKeyIsKeptButNotInTheSchema(): void
    {
        $config = Config::merge(['search' => ['titel_weight' => 3]]);

        self::assertSame(3, $config['search']['titel_weight']);
        self::assertArrayNotHasKey('search.titel_weight', Config::schema());
    }
}
