<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminStrings;
use App\Config;
use App\SettingRules;
use App\SettingsValidator;
use PHPUnit\Framework\TestCase;

/** M46: the settings.json layer of App\Config, the editability table and the rule / help coverage. */
final class SettingsLayerTest extends TestCase
{
    private string $dir = '';
    private string $settings = '';
    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('SEARCH_SETTINGS_FILE');
        putenv('SEARCH_SETTINGS_FILE');
        $this->dir = TempTree::make('settings_layer');
        $this->settings = $this->dir . '/storage/settings.json';
        mkdir($this->dir . '/storage');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'SEARCH_SETTINGS_FILE' : 'SEARCH_SETTINGS_FILE=' . $this->previousEnv);
        TempTree::removeIfAssigned($this->dir);
    }

    /** @param array<string, mixed> $overrides */
    private function load(array $overrides = []): array
    {
        return Config::load(TempConfig::write($overrides + ['paths' => ['storage' => $this->dir . '/storage']]));
    }

    private function panel(string $json): void
    {
        file_put_contents($this->settings, $json);
    }

    public function testPrecedenceIsDefaultsThenConfigPhpThenThePanel(): void
    {
        $default = Config::defaults()['search']['title_weight'];
        self::assertSame($default, $this->load()['search']['title_weight']);

        self::assertSame(12.0, $this->load(['search' => ['title_weight' => 12]])['search']['title_weight']);

        $this->panel('{"search.title_weight": 15, "search.tail_cap": 7}');
        $config = $this->load(['search' => ['title_weight' => 12]]);
        self::assertSame(15.0, $config['search']['title_weight']);
        self::assertSame(7, $config['search']['tail_cap']);
        // untouched keys keep config.php / default
        self::assertSame(Config::defaults()['search']['desc_weight'], $config['search']['desc_weight']);
    }

    public function testEachTypeIsCastAndInvalidValuesAreIgnored(): void
    {
        $this->panel((string) json_encode([
            'search.tail_cap' => '9',          // int from a string
            'search.title_weight' => '2.5',    // float from a string
            'search.require_all_terms' => false,
            'search.suggest_blocklist' => 'a,b',
            'search.default_limit' => 'abc',   // not an int
            'search.min_token_size' => 1.5,    // not an int
            'search.semantic_top_k' => null,
            'search.stopwords' => ['x'],
        ]));
        $config = $this->load();
        self::assertSame(9, $config['search']['tail_cap']);
        self::assertSame(2.5, $config['search']['title_weight']);
        self::assertFalse($config['search']['require_all_terms']);
        self::assertSame('a,b', $config['search']['suggest_blocklist']);
        $defaults = Config::defaults()['search'];
        self::assertSame($defaults['default_limit'], $config['search']['default_limit']);
        self::assertSame($defaults['min_token_size'], $config['search']['min_token_size']);
        self::assertSame($defaults['semantic_top_k'], $config['search']['semantic_top_k']);
        self::assertSame($defaults['stopwords'], $config['search']['stopwords']);

        $layer = Config::panelLayer($this->settings);
        self::assertEqualsCanonicalizing(
            ['search.default_limit', 'search.min_token_size', 'search.semantic_top_k', 'search.stopwords'],
            $layer['invalid']
        );
    }

    public function testAMissingFileIsNoOverrides(): void
    {
        $layer = Config::panelLayer($this->settings);
        self::assertFalse($layer['exists']);
        self::assertNull($layer['problem']);
        self::assertSame([], $layer['values']);
        self::assertSame(Config::defaults()['search'], $this->load()['search']);
    }

    /** @return array<string, array{string, string}> */
    public static function brokenFiles(): array
    {
        return [
            'corrupt json' => ['{"search.tail_cap": 7', 'corrupt'],
            'a list, not an object' => ['[1,2,3]', 'corrupt'],
            'a scalar' => ['5', 'corrupt'],
            'too deep' => [str_repeat('[', 50) . str_repeat(']', 50), 'corrupt'],
            'oversized' => [
                '{"search.tail_cap": 7, "pad": "' . str_repeat('x', Config::SETTINGS_MAX_BYTES) . '"}',
                'oversized',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function testAnUnusableFileNeverBreaksTheSite(string $content, string $problem): void
    {
        $this->panel($content);
        $layer = Config::panelLayer($this->settings);
        self::assertSame($problem, $layer['problem']);
        self::assertSame([], $layer['values']);
        // config.php and the defaults keep serving
        $config = $this->load(['search' => ['tail_cap' => 11]]);
        self::assertSame(11, $config['search']['tail_cap']);
    }

    public function testAnUnreadableFileIsReportedNotFatal(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads any file');
        }
        $this->panel('{}');
        chmod($this->settings, 0000);
        self::assertSame('unreadable', Config::panelLayer($this->settings)['problem']);
        chmod($this->settings, 0644);
    }

    public function testKeysThatAreNotEditableAreIgnoredAndReported(): void
    {
        $this->panel((string) json_encode([
            'vps.token' => 'leaked-secret',
            'db.dsn' => 'mysql:host=evil',
            'admin.username' => 'mallory',
            'paths.storage' => '/tmp/elsewhere',
            'model.normalization_version' => 99,
            'reload.token' => 'x',
            'no.such.key' => 1,
            'search.tail_cap' => 5,
        ]));
        $layer = Config::panelLayer($this->settings);
        self::assertSame(['search.tail_cap' => 5], $layer['values']);
        self::assertEqualsCanonicalizing(
            ['vps.token', 'db.dsn', 'admin.username', 'paths.storage', 'model.normalization_version', 'reload.token',
                'no.such.key'],
            $layer['ignored']
        );
        $config = $this->load(['vps' => ['token' => 'real'], 'db' => ['dsn' => 'mysql:host=h']]);
        self::assertSame('real', $config['vps']['token']);
        self::assertSame('mysql:host=h', $config['db']['dsn']);
        self::assertSame('', $config['admin']['username']);
        self::assertSame($this->dir . '/storage', $config['paths']['storage']);
    }

    public function testTheEnvironmentOverridesTheSettingsPath(): void
    {
        $other = $this->dir . '/elsewhere.json';
        file_put_contents($other, '{"search.tail_cap": 3}');
        $this->panel('{"search.tail_cap": 99}');
        putenv('SEARCH_SETTINGS_FILE=' . $other);

        self::assertSame(3, $this->load()['search']['tail_cap']);
        self::assertSame($other, Config::settingsFile('/anything'));
    }

    public function testTheDefaultPathFollowsConfigPhpsStorageDirectory(): void
    {
        self::assertSame('/x/y/settings.json', Config::settingsFile('/x/y/'));
        self::assertSame('/a/b', Config::storageDir(['paths' => ['storage' => '/a/b']]));
        self::assertSame('/srv/s/storage', Config::storageDir([], '/srv/s/'));
    }

    public function testTheInstallerPathIgnoresThePanelLayer(): void
    {
        $this->panel('{"search.tail_cap": 5}');
        $file = TempConfig::write(['paths' => ['storage' => $this->dir . '/storage']]);
        self::assertSame(5, Config::load($file)['search']['tail_cap']);
        self::assertSame(40, Config::load($file, null, false)['search']['tail_cap']);
    }

    public function testConfigPhpOnlySetupsAreByteIdenticalToBefore(): void
    {
        $overrides = ['search' => ['tail_cap' => 12], 'weird' => ['unknown' => 1]];
        $layers = Config::layers(TempConfig::write($overrides + ['paths' => ['storage' => $this->dir . '/storage']]));
        self::assertSame($layers['file'], $layers['merged']);
    }

    /** @return array<string, array{string, string}> */
    public static function classes(): array
    {
        return [
            // hidden: every credential, whatever the section
            'db.dsn' => ['db.dsn', Config::HIDDEN],
            'db.user' => ['db.user', Config::HIDDEN],
            'db.password' => ['db.password', Config::HIDDEN],
            'vps.token' => ['vps.token', Config::HIDDEN],
            'redis.auth' => ['redis.auth', Config::HIDDEN],
            'debug.token' => ['debug.token', Config::HIDDEN],
            'reload.token' => ['reload.token', Config::HIDDEN],
            'admin.password_hash' => ['admin.password_hash', Config::HIDDEN],
            // read-only
            'db.products_table' => ['db.products_table', Config::READONLY],
            'db.admin_audit_table' => ['db.admin_audit_table', Config::READONLY],
            'paths.data' => ['paths.data', Config::READONLY],
            'paths.storage' => ['paths.storage', Config::READONLY],
            'model.normalization_version' => ['model.normalization_version', Config::READONLY],
            'admin.username' => ['admin.username', Config::READONLY],
            'admin.allowed_ips' => ['admin.allowed_ips', Config::READONLY],
            'admin.settings_backups' => ['admin.settings_backups', Config::READONLY],
            // editable
            'search.tail_cap' => ['search.tail_cap', Config::EDITABLE],
            'vps.url' => ['vps.url', Config::EDITABLE],
            'vps.timeout_ms' => ['vps.timeout_ms', Config::EDITABLE],
            'redis.host' => ['redis.host', Config::EDITABLE],
            'redis.prefix' => ['redis.prefix', Config::EDITABLE],
            'storefront.store_base' => ['storefront.store_base', Config::EDITABLE],
            'events.allowed_origin' => ['events.allowed_origin', Config::EDITABLE],
            'log.duplicate_seconds' => ['log.duplicate_seconds', Config::EDITABLE],
            'logs.page_size' => ['logs.page_size', Config::EDITABLE],
            // unknown
            'logs.token (removed)' => ['logs.token', Config::UNKNOWN],
            'typo' => ['search.nope', Config::UNKNOWN],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classes')]
    public function testEditability(string $path, string $expected): void
    {
        self::assertSame($expected, Config::editability($path));
    }

    public function testEverySchemaKeyHasExactlyOneClassAndNoSecretIsEverEditable(): void
    {
        foreach (array_keys(Config::schema()) as $path) {
            $class = Config::editability($path);
            self::assertContains($class, [Config::EDITABLE, Config::READONLY, Config::HIDDEN], $path);
            if (Config::isSecret($path)) {
                self::assertSame(Config::HIDDEN, $class, $path);
            }
        }
    }

    public function testEveryEditableKeyHasARuleAndAHelpLineAndNothingElseDoes(): void
    {
        $editable = array_values(array_filter(
            array_keys(Config::schema()),
            static fn (string $path): bool => Config::editability($path) === Config::EDITABLE
        ));
        $rules = array_keys(SettingRules::table());
        $help = array_keys(AdminStrings::fa()['settings']['help_keys']);
        sort($editable);
        sort($rules);
        sort($help);

        self::assertSame($editable, $rules, 'every editable key needs a rule, and only editable keys have one');
        self::assertSame($editable, $help, 'every editable key needs a help line, and only editable keys have one');
        foreach (AdminStrings::fa()['settings']['help_keys'] as $path => $line) {
            self::assertNotSame('', trim($line), $path);
        }
        $units = AdminStrings::fa()['settings']['units'];
        foreach (SettingRules::table() as $path => $rule) {
            if (isset($rule['unit'])) {
                self::assertArrayHasKey($rule['unit'], $units, $path);
            }
        }
    }

    public function testEveryDefaultSatisfiesItsOwnRule(): void
    {
        $defaults = Config::flatten(Config::defaults());
        $schema = Config::schema();
        foreach (SettingRules::table() as $path => $rule) {
            $text = SettingsValidator::text($schema[$path], $defaults[$path]);
            $parsed = SettingsValidator::parse($path, $schema[$path], $rule, $text);
            self::assertSame([], $parsed['errors'], "the default of {$path} breaks its own rule");
            self::assertSame([], $parsed['warnings'], "the default of {$path} is not in normalized form");
            self::assertEquals($defaults[$path], $parsed['value'], $path);
        }
    }

    public function testRuleBoundsAreSane(): void
    {
        foreach (SettingRules::table() as $path => $rule) {
            if (isset($rule['min'], $rule['max'])) {
                self::assertLessThanOrEqual($rule['max'], $rule['min'], $path);
            }
        }
    }

    public function testResultAffectingKeys(): void
    {
        self::assertTrue(SettingRules::affectsResults('search.tail_cap'));
        self::assertTrue(SettingRules::affectsResults('vps.url'));
        self::assertTrue(SettingRules::affectsResults('vps.timeout_ms'));
        self::assertFalse(SettingRules::affectsResults('logs.page_size'));
        self::assertFalse(SettingRules::affectsResults('redis.ttl'));
    }
}
