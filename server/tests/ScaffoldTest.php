<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * M0 scaffold checks: the example config loads, exposes the sections later
 * milestones depend on, and ships no secrets. Replaced/extended by real unit
 * tests as logic lands in later milestones.
 */
final class ScaffoldTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadExampleConfig(): array
    {
        $config = require __DIR__ . '/../config.example.php';
        $this->assertIsArray($config);

        return $config;
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

        foreach (['name', 'revision', 'dim', 'normalization_version'] as $key) {
            $this->assertArrayHasKey($key, $model, "Missing model.{$key}");
        }

        $this->assertIsInt($model['dim']);
        $this->assertGreaterThan(0, $model['dim']);
        $this->assertIsInt($model['normalization_version']);
    }

    public function testExampleConfigContainsNoSecrets(): void
    {
        $config = $this->loadExampleConfig();

        $this->assertSame('', $config['db']['password'], 'config.example must not ship a DB password');
        $this->assertSame('', $config['reload']['token'], 'config.example must not ship a reload token');
        $this->assertSame('', $config['vps']['token'], 'config.example must not ship a VPS token');
        // M21 tooling is off until an operator sets a token.
        $this->assertSame('', $config['debug']['token'], 'config.example must not ship a debug token');
        $this->assertSame('', $config['logs']['token'], 'config.example must not ship a logs token');
    }

    public function testM21KnobsAreConfigDrivenAndDocumentedInTheEnvExample(): void
    {
        $search = $this->loadExampleConfig()['search'];
        $env = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');

        $knobs = [
            'tag_weight' => 'SEARCH_TAG_WEIGHT', 'brand_weight' => 'SEARCH_BRAND_WEIGHT',
            'category_weight' => 'SEARCH_CATEGORY_WEIGHT', 'brand_match_boost' => 'SEARCH_BRAND_MATCH_BOOST',
            'category_match_boost' => 'SEARCH_CATEGORY_MATCH_BOOST', 'tag_match_boost' => 'SEARCH_TAG_MATCH_BOOST',
            'tag_match_min_tokens' => 'SEARCH_TAG_MATCH_MIN_TOKENS',
        ];
        foreach ($knobs as $key => $variable) {
            $this->assertArrayHasKey($key, $search, "Missing search.{$key}");
            $this->assertStringContainsString($variable . '=', $env, "{$variable} is not in .env.example");
        }
        // Tags just below the title, then brand, category, specs; boosts small next to the relevance range.
        $this->assertLessThan($search['title_weight'], $search['tag_weight']);
        $this->assertGreaterThan($search['spec_weight'], $search['tag_weight']);
        foreach (['brand_match_boost', 'category_match_boost', 'tag_match_boost'] as $boost) {
            $this->assertGreaterThan(0.0, $search[$boost]);
            $this->assertLessThanOrEqual(0.2, $search[$boost]);
        }
        $this->assertStringContainsString('SEARCH_DEBUG_TOKEN=', $env);
        $this->assertStringContainsString('SEARCH_LOGS_TOKEN=', $env);
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
        $this->assertSame(2, $search['suggest_max_distance']);
    }

    public function testVpsIsConfigDrivenAndOffByDefault(): void
    {
        $this->assertSame(['url' => '', 'token' => '', 'timeout_ms' => 300], $this->loadExampleConfig()['vps']);

        putenv('SEARCH_VPS_URL=https://vps.example.com:8600');
        putenv('SEARCH_VPS_TOKEN=secret-from-env-0123');
        putenv('SEARCH_VPS_TIMEOUT_MS=150');
        try {
            $vps = $this->loadExampleConfig()['vps'];
        } finally {
            putenv('SEARCH_VPS_URL');
            putenv('SEARCH_VPS_TOKEN');
            putenv('SEARCH_VPS_TIMEOUT_MS');
        }

        $this->assertSame(
            ['url' => 'https://vps.example.com:8600', 'token' => 'secret-from-env-0123', 'timeout_ms' => 150],
            $vps
        );
    }

    public function testExplicitZeroFromTheEnvironmentIsHonoured(): void
    {
        // `getenv() ?: default` would silently replace "0" with the default.
        putenv('SEARCH_SEMANTIC_MIN_SCORE=0');
        putenv('SEARCH_STOCK_BOOST=0');
        putenv('SEARCH_SEMANTIC_WEIGHT=0.5');
        try {
            $search = $this->loadExampleConfig()['search'];
        } finally {
            putenv('SEARCH_SEMANTIC_MIN_SCORE');
            putenv('SEARCH_STOCK_BOOST');
            putenv('SEARCH_SEMANTIC_WEIGHT');
        }

        $this->assertSame(0.0, $search['semantic_min_score']);
        $this->assertSame(0.0, $search['stock_boost']);
        $this->assertSame(0.5, $search['semantic_weight']);
    }
}
