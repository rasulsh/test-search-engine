<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\ConfigDoctor;
use PHPUnit\Framework\TestCase;

final class ConfigDoctorTest extends TestCase
{
    /** @return array<string, mixed> a config.php with everything a clean deployment sets */
    private static function clean(): array
    {
        return [
            'db' => ['dsn' => 'mysql:host=h;dbname=d', 'user' => 'u', 'password' => 'pw'],
            'reload' => ['token' => 'a-long-random-reload-token'],
            'admin' => TempConfig::admin(),
        ];
    }

    public function testAnUnsetAdminIsAWarningAndAHalfSetOneIsAnError(): void
    {
        $unset = self::clean();
        unset($unset['admin']);
        $report = ConfigDoctor::check($unset);
        self::assertSame([], $report['errors']);
        self::assertSame(['admin.username / admin.password_hash: not set, admin.php answers 503'], $report['warnings']);

        $plain = self::clean();
        $plain['admin']['password_hash'] = 'my-plaintext-password';
        $report = ConfigDoctor::check($plain);
        self::assertCount(1, $report['errors']);
        self::assertStringStartsWith('admin.password_hash: not a password_hash() value', $report['errors'][0]);
        self::assertStringNotContainsString('my-plaintext-password', (string) json_encode($report));

        $noUser = self::clean();
        $noUser['admin']['username'] = '';
        self::assertSame(
            ['admin.username: empty while admin.password_hash is set'],
            ConfigDoctor::check($noUser)['errors']
        );
        $noHash = self::clean();
        $noHash['admin']['password_hash'] = '';
        self::assertSame(
            ['admin.password_hash: empty while admin.username is set'],
            ConfigDoctor::check($noHash)['errors']
        );
    }

    public function testRemovedKeysGetTheirReasonNotABareUnknownKey(): void
    {
        $overrides = self::clean();
        $overrides['logs'] = ['token' => 'old-token-value'];
        $overrides['admin']['ip_header'] = 'HTTP_X_FORWARDED_FOR';
        $report = ConfigDoctor::check($overrides);

        self::assertSame([], $report['errors']);
        self::assertCount(2, $report['warnings']);
        $byKey = [];
        foreach ($report['warnings'] as $warning) {
            $byKey[strstr($warning, ':', true)] = $warning;
        }
        self::assertStringStartsWith('logs.token: removed in M39 (the logs and analytics pages', $byKey['logs.token']);
        self::assertStringEndsWith('has no effect and can be deleted', $byKey['logs.token']);
        self::assertStringStartsWith('admin.ip_header: removed in M39 (X-Forwarded-For', $byKey['admin.ip_header']);
        self::assertStringNotContainsString('unknown key', implode("\n", $report['warnings']));
        self::assertStringNotContainsString('old-token-value', (string) json_encode($report));
    }

    public function testEveryDeprecatedKeyIsGoneFromTheSchemaAndWellFormed(): void
    {
        $schema = Config::schema();
        foreach (Config::deprecated() as $old => $rule) {
            self::assertArrayNotHasKey($old, $schema, "{$old} must not live on in the schema");
            self::assertMatchesRegularExpression('/^M\d+$/', $rule['since'], $old);
            if (isset($rule['to'])) {
                self::assertArrayHasKey($rule['to'], $schema, "{$old} renamed to a real key");
                self::assertArrayHasKey('until', $rule, $old);
            } else {
                self::assertNotSame('', trim($rule['removed'] ?? ''), "{$old} says why");
            }
        }
    }

    public function testARenamedKeyKeepsWorkingAndTheDoctorNamesTheNewKey(): void
    {
        $map = ['search.old_weight' => ['since' => 'M40', 'until' => 'M42', 'to' => 'search.title_weight']];
        $overrides = self::clean();
        $overrides['search'] = ['old_weight' => 15];

        $config = Config::merge($overrides, null, $map);
        $report = ConfigDoctor::check($overrides, $map);

        self::assertSame(15.0, $config['search']['title_weight'], 'the old value feeds the new key');
        self::assertSame([], $report['errors']);
        self::assertSame(
            ['search.old_weight: renamed to search.title_weight in M40; the old value is still used until M42, '
                . 'rename it in config.php'],
            $report['warnings']
        );
    }

    public function testWhenBothNamesAreSetTheNewOneWinsAndTheOldIsFlaggedAsIgnored(): void
    {
        $map = ['search.old_weight' => ['since' => 'M40', 'until' => 'M42', 'to' => 'search.title_weight']];
        $overrides = self::clean();
        $overrides['search'] = ['old_weight' => 15, 'title_weight' => 20];

        $config = Config::merge($overrides, null, $map);
        $report = ConfigDoctor::check($overrides, $map);

        self::assertSame(20.0, $config['search']['title_weight']);
        self::assertStringContainsString('ignored because search.title_weight is set', $report['warnings'][0]);
    }

    public function testASecretIsRecognizedByItsKeyName(): void
    {
        $secrets = ['db.password', 'db.dsn', 'db.user', 'vps.token', 'reload.token', 'debug.token', 'redis.auth',
            'admin.password_hash'];
        foreach ($secrets as $path) {
            self::assertTrue(Config::isSecret($path), $path);
        }
        $plain = ['search.title_weight', 'admin.username', 'admin.session_hours', 'redis.host', 'paths.data'];
        foreach ($plain as $path) {
            self::assertFalse(Config::isSecret($path), $path);
        }
        foreach (array_keys(Config::schema()) as $path) {
            if (preg_match('/\\.(token|password|password_hash|auth|secret)$/', $path) === 1) {
                self::assertTrue(Config::isSecret($path), "{$path} looks like a secret");
            }
        }
    }

    public function testAMissingRequiredKeyIsAnError(): void
    {
        $report = ConfigDoctor::check(['reload' => ['token' => 't']]);

        self::assertSame(
            ['db.dsn: required, missing or empty', 'db.user: required, missing or empty'],
            $report['errors']
        );
    }

    public function testAnEmptyRequiredKeyIsAnErrorButAnEmptyPasswordIsFine(): void
    {
        $overrides = self::clean();
        $overrides['db']['user'] = '  ';
        $overrides['db']['password'] = '';
        $report = ConfigDoctor::check($overrides);

        self::assertSame(['db.user: required, missing or empty'], $report['errors']);
    }

    public function testAValueThatDoesNotCastIsAnError(): void
    {
        $overrides = self::clean();
        $overrides['search'] = ['title_weight' => 'heavy', 'default_limit' => '3.5', 'require_all_terms' => 'maybe'];
        $overrides['vps'] = ['timeout_ms' => 'fast'];
        $report = ConfigDoctor::check($overrides);

        self::assertEqualsCanonicalizing([
            'search.title_weight: not a valid float',
            'search.default_limit: not a valid int',
            'search.require_all_terms: not a valid bool',
            'vps.timeout_ms: not a valid int',
        ], $report['errors']);
    }

    public function testExplicitZerosAndFalsesAreValid(): void
    {
        $overrides = self::clean();
        $overrides['search'] = ['semantic_min_score' => '0', 'require_all_terms' => 'false', 'stock_boost' => 0];
        $report = ConfigDoctor::check($overrides);

        self::assertSame([], $report['errors']);
    }

    public function testAnUnknownKeyIsAWarning(): void
    {
        $overrides = self::clean();
        $overrides['search'] = ['titel_weight' => 3];
        $overrides['nonsense'] = 1;
        $report = ConfigDoctor::check($overrides);

        self::assertSame([], $report['errors']);
        self::assertEqualsCanonicalizing([
            'search.titel_weight: unknown key (typo or removed option)',
            'nonsense: unknown key (typo or removed option)',
        ], $report['warnings']);
    }

    public function testHalfConfiguredFeaturesAreWarnings(): void
    {
        $overrides = self::clean();
        $overrides['redis'] = ['enabled' => true, 'host' => ''];
        $overrides['vps'] = ['url' => 'https://vps.example.com'];
        $overrides['reload'] = ['token' => ''];
        $report = ConfigDoctor::check($overrides);

        self::assertSame([], $report['errors']);
        self::assertEqualsCanonicalizing([
            'redis.host: empty while redis.enabled is on (cache stays off)',
            'vps.token: empty while vps.url is set (the VPS will refuse the calls)',
            'reload.token: empty, POST /reload is disabled',
        ], $report['warnings']);
    }

    public function testRedisOffOrVpsUnsetIsNotHalfConfigured(): void
    {
        $overrides = self::clean();
        $overrides['redis'] = ['enabled' => false, 'host' => ''];
        $report = ConfigDoctor::check($overrides);

        self::assertSame([], $report['warnings']);
    }

    public function testInfoListsTunablesAtTheirCodeDefault(): void
    {
        $overrides = self::clean();
        $overrides['search'] = ['title_weight' => 12.0];
        $info = ConfigDoctor::check($overrides)['info'];

        self::assertContains('search.desc_weight: code default', $info);
        self::assertNotContains('search.title_weight: code default', $info);
        self::assertNotContains('db.dsn: code default', $info);
        self::assertCount(count(Config::schema()) - count(Config::flatten($overrides)), $info);
    }

    public function testReportsNeverCarryValues(): void
    {
        $overrides = self::clean();
        $overrides['search'] = ['title_weight' => 'SECRET-LOOKING-VALUE'];
        $overrides['vps'] = ['token' => 'vps-secret-token-value', 'url' => 'https://secret-host.example.com'];
        $overrides['mystery'] = ['password' => 'hunter2-hunter2'];
        $json = (string) json_encode(ConfigDoctor::check($overrides));

        $values = ['SECRET-LOOKING-VALUE', 'vps-secret-token-value', 'secret-host', 'hunter2', 'a-long-random'];
        foreach ($values as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
