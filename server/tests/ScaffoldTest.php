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
    }

    public function testRelevanceKnobsAreConfigDrivenWithDefaults(): void
    {
        $search = $this->loadExampleConfig()['search'];

        $this->assertSame(0.82, $search['semantic_min_score']);
        $this->assertSame(1.0, $search['keyword_weight']);
        $this->assertSame(1.0, $search['semantic_weight']);
        $this->assertSame(3, $search['suggest_min_results']);
        $this->assertSame(2, $search['suggest_min_frequency']);
        $this->assertSame(2, $search['suggest_max_distance']);
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
