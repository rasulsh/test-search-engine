<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** M46: per-field rules, cross-key conflicts, normalization and the error / warning split. */
final class SettingsValidatorTest extends TestCase
{
    /**
     * @param array<string, string> $posted
     * @param array<string, mixed> $file config.php overrides
     * @param array<string, mixed> $current stored panel values
     * @param array<string, bool> $reset
     * @param array<string, bool> $empty
     * @return array<string, mixed>
     */
    private function validate(
        array $posted,
        array $file = [],
        array $current = [],
        array $reset = [],
        array $empty = []
    ): array {
        $inherited = Config::flatten(Config::merge($file));

        return SettingsValidator::validate($posted, $reset, $empty, $inherited, $current, $file);
    }

    /** @param array<string, mixed> $result */
    private static function codes(array $result, string $list): array
    {
        return array_map(static fn (array $e): string => ($e['key'] ?? '') . '#' . $e['code'], $result[$list]);
    }

    public function testAValidChangeIsStoredAndAnUnchangedValueIsNot(): void
    {
        $result = $this->validate(['search.tail_cap' => '55', 'search.title_weight' => '10']);
        self::assertSame([], $result['errors']);
        self::assertSame(['search.tail_cap' => 55], $result['values']);
        self::assertSame(
            [['key' => 'search.tail_cap', 'from' => '40', 'to' => '55', 'reset' => false]],
            $result['changes']
        );
    }

    public function testBlankAndTheResetBoxReturnToTheInheritedValue(): void
    {
        $file = ['search' => ['tail_cap' => 12]];
        $current = ['search.tail_cap' => 50, 'search.title_weight' => 11.0];

        $blank = $this->validate(['search.tail_cap' => '  ', 'search.title_weight' => '11'], $file, $current);
        self::assertSame(['search.title_weight' => 11.0], $blank['values']);
        self::assertSame([], $blank['errors']);
        self::assertSame('12', $blank['form']['search.tail_cap']);
        self::assertTrue($blank['changes'][0]['reset']);

        $reset = $this->validate([], $file, $current, ['search.title_weight' => true]);
        self::assertSame(['search.tail_cap' => 50], $reset['values']);

        // a value equal to what config.php says is not stored either
        $same = $this->validate(['search.tail_cap' => '12'], $file, $current);
        self::assertSame(['search.title_weight' => 11.0], $same['values']);
    }

    public function testAKeyThatIsNotPostedIsUnchanged(): void
    {
        $result = $this->validate([], [], ['search.tail_cap' => 50]);
        self::assertSame(['search.tail_cap' => 50], $result['values']);
        self::assertSame([], $result['changes']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function badValues(): array
    {
        return [
            'int with a fraction' => ['search.tail_cap', '1.5', 'int'],
            'int text' => ['search.tail_cap', 'abc', 'int'],
            'int exponent' => ['search.tail_cap', '1e3', 'int'],
            'float text' => ['search.title_weight', 'x', 'float'],
            'float infinity' => ['search.title_weight', 'INF', 'float'],
            'float nan' => ['search.title_weight', 'NAN', 'float'],
            'int below min' => ['search.default_limit', '0', 'range'],
            'int above max' => ['search.default_limit', '1001', 'range'],
            'negative weight' => ['search.title_weight', '-1', 'range'],
            'ratio above one' => ['search.entity_demote', '1.01', 'range'],
            'score below -1' => ['search.semantic_min_score', '-1.5', 'range'],
            'coverage above one' => ['search.soft_and_min_coverage', '2', 'range'],
            'df ratio above one' => ['search.generic_df_ratio', '1.5', 'range'],
            'timeout zero' => ['vps.timeout_ms', '0', 'range'],
            'port too big' => ['redis.port', '70000', 'range'],
            'enum' => ['search.suggest_max_distance', '3', 'enum'],
            'bool' => ['search.entities_enabled', 'maybe', 'bool'],
            'url without scheme' => ['vps.url', 'vps.example.com', 'url'],
            'url with ftp' => ['vps.url', 'ftp://vps.example.com', 'url'],
            'url with credentials' => ['vps.url', 'https://user:pw@vps.example.com', 'url'],
            'url with a space' => ['vps.url', 'https://vps example.com', 'url'],
            'origin with a path' => ['events.allowed_origin', 'https://shop.example.com/x', 'origin'],
            'origin with a slash' => ['events.allowed_origin', 'https://shop.example.com/', 'origin'],
            'host with a scheme' => ['redis.host', 'tcp://localhost', 'host'],
            'prefix with a space' => ['redis.prefix', 'a b:', 'prefix'],
            'prefix with a star' => ['redis.prefix', 'search:*', 'prefix'],
            'control character' => ['storefront.store_base', "https://a.example\x00", 'control'],
            'newline in a url' => ['storefront.store_base', "https://a.example\nx", 'control'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function testEachRuleKind(string $path, string $raw, string $code): void
    {
        $result = $this->validate([$path => $raw]);
        self::assertSame([$path . '#' . $code], self::codes($result, 'errors'));
        self::assertSame([], $result['values'], 'nothing is stored for a bad value');
        self::assertSame($raw, $result['form'][$path], 'the typed text is kept');
    }

    public function testRangeBoundsAreInclusive(): void
    {
        $result = $this->validate([
            'search.entity_demote' => '1', 'search.semantic_min_score' => '-1', 'search.generic_df_ratio' => '0',
            'search.default_limit' => '1000', 'redis.port' => '65535',
        ]);
        self::assertSame([], $result['errors']);
    }

    public function testTextTooLongIsRejected(): void
    {
        $result = $this->validate(['storefront.store_base' => 'https://a.example/' . str_repeat('x', 2100)]);
        self::assertSame(['storefront.store_base#too_long'], self::codes($result, 'errors'));
    }

    public function testPersianDigitsAreAccepted(): void
    {
        $result = $this->validate(['search.tail_cap' => '۵۵', 'search.title_weight' => '۲٫۵']);
        self::assertSame([], $result['errors']);
        self::assertSame(['search.tail_cap' => 55, 'search.title_weight' => 2.5], $result['values']);
    }

    public function testValidUrlsOriginsAndHostsPass(): void
    {
        $result = $this->validate([
            'vps.url' => 'https://vps.example.com:8443/base',
            'storefront.store_base' => 'http://shop.example.com',
            'events.allowed_origin' => 'https://shop.example.com:8080',
            'redis.host' => 'redis.internal',
            'redis.prefix' => 'shop:cache:',
        ]);
        self::assertSame([], $result['errors']);
        self::assertCount(5, $result['values']);
    }

    public function testListsAreNormalizedShownBackAndFlagged(): void
    {
        $result = $this->validate(['search.synonym_blocklist' => "گیمینگ ،  ي\n\nگیمینگ, ,,لپ‌تاپ"]);
        self::assertSame([], $result['errors']);
        $normalized = $result['values']['search.synonym_blocklist'];
        self::assertSame($normalized, $result['form']['search.synonym_blocklist']);
        self::assertStringNotContainsString('ي', $normalized);
        $items = explode(',', $normalized);
        self::assertSame($items, array_unique($items), 'no repeated item');
        self::assertNotContains('', $items);
        self::assertSame(['search.synonym_blocklist#csv_dropped'], self::codes($result, 'warnings'));
    }

    public function testAnItemThatNormalizesToNothingIsFlagged(): void
    {
        $result = $this->validate(['search.brand_blocklist' => "samsung, \u{200C}, \u{200E}"]);
        self::assertSame('samsung', $result['values']['search.brand_blocklist']);
        self::assertSame(['search.brand_blocklist#csv_dropped'], self::codes($result, 'warnings'));
        self::assertSame(['2'], $result['warnings'][0]['args']);
    }

    public function testAnEmptyListNeedsTheExplicitEmptyBox(): void
    {
        // blank = inherit, so a default list cannot be blanked by leaving the field empty
        $blank = $this->validate(['search.suggest_blocklist' => '']);
        self::assertSame([], $blank['values']);

        $empty = $this->validate(['search.suggest_blocklist' => 'x'], [], [], [], ['search.suggest_blocklist' => true]);
        self::assertSame(['search.suggest_blocklist' => ''], $empty['values']);
        self::assertSame('', $empty['form']['search.suggest_blocklist']);

        // a required key cannot be emptied
        $prefix = $this->validate([], [], [], [], ['redis.prefix' => true]);
        self::assertSame(['redis.prefix#required'], self::codes($prefix, 'errors'));
    }

    public function testVpsUrlWithoutATokenWarns(): void
    {
        $result = $this->validate(['vps.url' => 'https://vps.example.com']);
        self::assertSame([], $result['errors']);
        self::assertSame(['vps.url#vps_token_missing'], self::codes($result, 'warnings'));
        // the warning never names the credential
        self::assertStringNotContainsString('vps.token', (string) json_encode($result));

        $withToken = $this->validate(['vps.url' => 'https://vps.example.com'], ['vps' => ['token' => 'abc']]);
        self::assertSame([], $withToken['warnings']);
    }

    public function testRedisOnWithAnEmptyHostWarns(): void
    {
        $file = ['redis' => ['host' => '']];
        $result = $this->validate(['redis.enabled' => '1'], $file);
        self::assertSame(['redis.host#redis_host_empty'], self::codes($result, 'warnings'));

        self::assertSame([], $this->validate(['redis.enabled' => '1'])['warnings']);
    }

    public function testVpsTimeoutNotSmallerThanTheLatencyBudgetWarns(): void
    {
        $result = $this->validate(['vps.timeout_ms' => '800']);
        self::assertSame(['vps.timeout_ms#timeout_over_budget'], self::codes($result, 'warnings'));
        self::assertSame(['800', '800'], $result['warnings'][0]['args']);

        self::assertSame([], $this->validate(['vps.timeout_ms' => '799'])['warnings']);
        // raising the budget clears it
        $raised = $this->validate(['vps.timeout_ms' => '900', 'search.latency_budget_ms' => '1000']);
        self::assertSame([], $raised['warnings']);
        // lowering the budget below the (default 600 ms) timeout trips it as well
        $budget = $this->validate(['search.latency_budget_ms' => '500']);
        self::assertSame(['vps.timeout_ms#timeout_over_budget'], self::codes($budget, 'warnings'));
    }

    public function testTailCapTopKAndResultCapOrdering(): void
    {
        $tail = $this->validate(['search.tail_cap' => '150']);
        self::assertSame(['search.tail_cap#tail_over_top_k'], self::codes($tail, 'warnings'));

        // 0 = no cap: never a conflict
        self::assertSame([], $this->validate(['search.tail_cap' => '0'])['warnings']);

        $cap = $this->validate(['search.result_cap' => '50']);
        self::assertSame(['search.result_cap#cap_under_top_k'], self::codes($cap, 'warnings'));
        self::assertSame([], $this->validate(['search.result_cap' => '0'])['warnings']);

        $topK = $this->validate(['search.semantic_top_k' => '500']);
        self::assertSame(['search.result_cap#cap_under_top_k'], array_values(array_diff(
            self::codes($topK, 'warnings'),
            ['search.tail_cap#tail_over_top_k']
        )));
    }

    public function testEntityBoostAndDemoteRules(): void
    {
        $both = $this->validate(['search.entity_boost' => '0', 'search.entity_demote' => '0']);
        self::assertSame(['search.entity_boost#entities_both_zero'], self::codes($both, 'warnings'));

        // off by switch: no warning about values
        $off = $this->validate([
            'search.entities_enabled' => '0', 'search.entity_boost' => '0', 'search.entity_demote' => '0',
        ]);
        self::assertSame([], $off['warnings']);

        $one = $this->validate(['search.entity_boost' => '0']);
        self::assertSame([], $one['warnings']);

        $range = $this->validate(['search.entity_demote' => '1.5', 'search.entity_boost' => '-0.1']);
        self::assertEqualsCanonicalizing(
            ['search.entity_demote#range', 'search.entity_boost#range'],
            self::codes($range, 'errors')
        );
    }

    public function testBothBlendWeightsZeroWarns(): void
    {
        $result = $this->validate(['search.keyword_weight' => '0', 'search.semantic_weight' => '0']);
        self::assertSame(['search.keyword_weight#weights_zero'], self::codes($result, 'warnings'));
        self::assertSame([], $this->validate(['search.keyword_weight' => '0'])['warnings']);
    }

    public function testRedisTtlRelations(): void
    {
        $zero = $this->validate(['redis.ttl_zero' => '90000']);
        self::assertSame(['redis.ttl_zero#ttl_zero_over_ttl'], self::codes($zero, 'warnings'));
        $hot = $this->validate(['redis.ttl_hot' => '100']);
        self::assertSame(['redis.ttl_hot#ttl_hot_under_ttl'], self::codes($hot, 'warnings'));
    }

    public function testAConflictIsNotRepeatedOnAnUnrelatedSave(): void
    {
        // the stored settings already carry the odd pair; touching another key does not nag
        $current = ['search.tail_cap' => 150];
        $result = $this->validate(['search.title_weight' => '11'], [], $current);
        self::assertSame([], $result['warnings']);
    }

    public function testAKeyConfigPhpAlsoSetsIsAnInfoRowAndThePanelWins(): void
    {
        $file = ['search' => ['tail_cap' => 12]];
        $result = $this->validate(['search.tail_cap' => '20'], $file);
        self::assertSame([], $result['errors']);
        self::assertSame(['search.tail_cap#shadows_file'], self::codes($result, 'info'));
        self::assertSame(20, $result['values']['search.tail_cap']);
    }

    public function testErrorsAndWarningsAreClassifiedApart(): void
    {
        $result = $this->validate(['search.tail_cap' => '150', 'search.default_limit' => 'x']);
        self::assertSame(['search.default_limit#int'], self::codes($result, 'errors'));
        self::assertSame(['search.tail_cap#tail_over_top_k'], self::codes($result, 'warnings'));
    }

    public function testMessagesNeverNameACredential(): void
    {
        $result = $this->validate([
            'vps.url' => 'https://vps.example.com', 'redis.enabled' => '1', 'search.tail_cap' => 'x',
        ], ['redis' => ['host' => '', 'auth' => 'pw']]);
        $json = (string) json_encode($result);
        $names = ['vps.token', 'redis.auth', 'db.password', 'db.dsn', 'reload.token', 'debug.token', 'password_hash'];
        foreach ($names as $name) {
            self::assertStringNotContainsString($name, $json);
        }
    }
}
