<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * M0 scaffold checks: the example config loads, exposes the sections later
 * milestones depend on, and ships no secrets. Replaced/extended by real unit
 * tests as logic lands in later milestones.
 */
final class ScaffoldTest extends TestCase
{
    /** @return array<string, mixed> the example's overrides merged over the code defaults */
    private function loadExampleConfig(): array
    {
        return Config::load(__DIR__ . '/../config.example.php');
    }

    public function testExampleConfigIsOverridesOnly(): void
    {
        $overrides = Config::overrides(__DIR__ . '/../config.example.php');
        $tunables = ['search', 'model', 'paths'];
        foreach ($tunables as $section) {
            $this->assertArrayNotHasKey($section, $overrides, "config.example must not repeat {$section} defaults");
        }
        // Every key it does list is one the schema knows.
        foreach (array_keys(Config::flatten($overrides)) as $path) {
            $this->assertArrayHasKey($path, Config::schema(), "config.example lists unknown key {$path}");
        }
    }

    public function testExampleConfigExposesRequiredSections(): void
    {
        $config = $this->loadExampleConfig();

        foreach (['db', 'model', 'search', 'paths', 'reload'] as $section) {
            $this->assertArrayHasKey($section, $config, "Missing config section: {$section}");
            $this->assertIsArray($config[$section]);
        }
    }

    public function testModelSectionDrivesParityContract(): void
    {
        $model = $this->loadExampleConfig()['model'];

        // M42: cPanel holds no model; the normalization version is the only compatibility key left.
        $this->assertSame(Normalizer::VERSION, $model['normalization_version']);
        foreach (['name', 'revision', 'dim'] as $key) {
            $this->assertArrayNotHasKey($key, $model, "model.{$key} left the config in M42");
        }
    }

    public function testExampleConfigContainsNoSecrets(): void
    {
        $config = $this->loadExampleConfig();

        $this->assertSame('', $config['db']['password'], 'config.example must not ship a DB password');
        $this->assertSame('', $config['reload']['token'], 'config.example must not ship a reload token');
        $this->assertSame('', $config['vps']['token'], 'config.example must not ship a VPS token');
        // M21 tooling is off until an operator sets a token.
        $this->assertSame('', $config['debug']['token'], 'config.example must not ship a debug token');
        $this->assertSame('', $config['admin']['password_hash'], 'config.example must not ship an admin password hash');
        $this->assertSame('', $config['admin']['username'], 'config.example must not ship an admin user');
        $this->assertSame('', $config['redis']['auth'], 'config.example must not ship a Redis password');
    }

    public function testM21KnobsHaveSaneDefaults(): void
    {
        $search = $this->loadExampleConfig()['search'];

        // Tags just below the title, then brand, category, specs; boosts small next to the relevance range.
        $this->assertLessThan($search['title_weight'], $search['tag_weight']);
        $this->assertGreaterThan($search['spec_weight'], $search['tag_weight']);
        foreach (['brand_match_boost', 'category_match_boost', 'tag_match_boost'] as $boost) {
            $this->assertGreaterThan(0.0, $search[$boost]);
            $this->assertLessThanOrEqual(0.2, $search[$boost]);
        }
    }

    public function testEnvExampleListsOnlyWhatTheServerStillReads(): void
    {
        $env = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');

        $names = ['SEARCH_DB_DSN', 'SEARCH_VPS_URL', 'SEARCH_RELOAD_TOKEN', 'SEARCH_DEBUG_TOKEN',
            'SEARCH_ADMIN_USERNAME', 'SEARCH_ADMIN_PASSWORD_HASH'];
        foreach ($names as $name) {
            $this->assertStringContainsString($name . '=', $env, "{$name} is not in .env.example");
        }
        // A removed key's variable must not linger (Config::deprecated()): nothing reads it any more.
        $this->assertStringNotContainsString('SEARCH_LOGS_TOKEN', $env);
        // Tunables are no longer read from the environment: listing them would mislead.
        $this->assertStringNotContainsString('SEARCH_TITLE_WEIGHT', $env);
    }

    public function testRelevanceKnobsAreConfigDrivenWithDefaults(): void
    {
        $search = $this->loadExampleConfig()['search'];

        // bge-m3 scale (M18); e5's 0.82 would drop nearly every neighbour.
        $this->assertSame(0.4, $search['semantic_min_score']);
        $this->assertSame(0.4, $search['keyword_weight']);
        $this->assertSame(0.6, $search['semantic_weight']);
        $this->assertSame(0.45, $search['min_relevance']);
        $this->assertSame(3, $search['suggest_min_results']);
        $this->assertSame(2, $search['suggest_min_frequency']);
        $this->assertSame(1, $search['suggest_max_distance']);
    }

    public function testVpsIsConfigDrivenAndOffByDefault(): void
    {
        $this->assertSame(
            ['url' => '', 'token' => '', 'timeout_ms' => 600, 'reload_timeout_ms' => 60000],
            $this->loadExampleConfig()['vps']
        );

        putenv('SEARCH_VPS_URL=https://vps.example.com:8600');
        putenv('SEARCH_VPS_TOKEN=secret-from-env-0123');
        try {
            $vps = $this->loadExampleConfig()['vps'];
        } finally {
            putenv('SEARCH_VPS_URL');
            putenv('SEARCH_VPS_TOKEN');
        }

        $this->assertSame(
            ['url' => 'https://vps.example.com:8600', 'token' => 'secret-from-env-0123', 'timeout_ms' => 600,
                'reload_timeout_ms' => 60000],
            $vps
        );
    }

    public function testRedisCacheIsConfigDrivenAndOffByDefault(): void
    {
        $defaults = $this->loadExampleConfig()['redis'];
        $this->assertFalse($defaults['enabled']);
        $this->assertSame(
            [
                'host' => '127.0.0.1', 'port' => 6379, 'auth' => '', 'db' => 0, 'ttl' => 86400,
                'ttl_zero' => 300, 'ttl_hot' => 604800, 'ttl_hot_threshold' => 20, 'freq_window' => 604800,
                'timeout_ms' => 100,
            ],
            array_diff_key($defaults, ['enabled' => 0, 'prefix' => 0])
        );

        $env = [
            'SEARCH_REDIS_ENABLED' => '1', 'SEARCH_REDIS_HOST' => '10.0.0.5', 'SEARCH_REDIS_PORT' => '6400',
            'SEARCH_REDIS_AUTH' => 'pw-from-env',
        ];
        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
        }
        try {
            $redis = $this->loadExampleConfig()['redis'];
        } finally {
            foreach (array_keys($env) as $name) {
                putenv($name);
            }
        }

        $this->assertTrue($redis['enabled']);
        $this->assertSame(['10.0.0.5', 6400, 'pw-from-env'], [$redis['host'], $redis['port'], $redis['auth']]);
    }
}
