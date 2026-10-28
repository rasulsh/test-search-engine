<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- long literals and assertion messages in an HTTP / fixture test

namespace App\Tests;

use App\AdminViews;
use App\Config;
use App\ConfigReport;
use PHPUnit\Framework\TestCase;

/** The status page's effective-configuration table: value, source, doctor notes, no secrets. */
final class ConfigReportTest extends TestCase
{
    private const SECRETS = [
        'pw-secret-value', 'reload-secret-value', 'vps-secret-value', 'debug-secret-value', 'redis-secret-value',
        'cpuser_secret', 'mysql:host=secret-host;dbname=secret_db', 'typo-secret-value',
    ];

    /** @return array<string, mixed> */
    private static function overrides(): array
    {
        return [
            'db' => ['dsn' => 'mysql:host=secret-host;dbname=secret_db', 'user' => 'cpuser_secret', 'password' => 'pw-secret-value'],
            'reload' => ['token' => 'reload-secret-value'],
            'vps' => ['url' => 'https://vps.example.com', 'token' => 'vps-secret-value'],
            'debug' => ['token' => 'debug-secret-value'],
            'redis' => ['enabled' => true, 'auth' => 'redis-secret-value'],
            'admin' => TempConfig::admin(),
            'search' => ['title_weight' => 12.5, 'titel_weight' => 'typo-secret-value', 'default_limit' => 'many'],
            'logs' => ['token' => 'typo-secret-value'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function byKey(array $overrides): array
    {
        $rows = [];
        foreach (ConfigReport::rows($overrides) as $row) {
            $rows[$row['key']] = $row;
        }

        return $rows;
    }

    public function testTheSynonymBlocklistShowsOnTheStatusPageList(): void
    {
        $rows = self::byKey(['search' => ['synonym_blocklist' => 'mouse']]);

        self::assertArrayHasKey('search.synonym_blocklist', $rows);
        self::assertSame('mouse', $rows['search.synonym_blocklist']['value']);
    }

    public function testEveryKnownKeyHasARowWithItsEffectiveValueAndSource(): void
    {
        $rows = self::byKey(self::overrides());

        foreach (array_keys(Config::schema()) as $key) {
            self::assertArrayHasKey($key, $rows, $key);
        }
        self::assertSame(['12.5', 'value', ConfigReport::SOURCE_FILE], [
            $rows['search.title_weight']['value'], $rows['search.title_weight']['state'], $rows['search.title_weight']['source'],
        ]);
        self::assertSame(ConfigReport::SOURCE_DEFAULT, $rows['search.desc_weight']['source']);
        self::assertSame('1', $rows['search.desc_weight']['value']);
        self::assertSame('true', $rows['redis.enabled']['value']);
        self::assertSame('true', $rows['search.require_all_terms']['value'], 'a default bool');
        self::assertSame('search:cache:', $rows['redis.prefix']['value']);
        self::assertSame('0.4', $rows['search.keyword_weight']['value']);
    }

    public function testCredentialsAreNeverReturnedOnlyWhetherTheyAreSet(): void
    {
        $rows = self::byKey(self::overrides());

        foreach (['db.password', 'db.dsn', 'db.user', 'reload.token', 'vps.token', 'debug.token', 'redis.auth', 'admin.password_hash'] as $key) {
            self::assertSame(['set', null], [$rows[$key]['state'], $rows[$key]['value']], $key);
        }
        $unset = self::byKey(['redis' => ['auth' => '']]);
        self::assertSame('empty', $unset['redis.auth']['state']);
        self::assertSame('value', $rows['admin.username']['state'], 'a username is not a secret');
        $json = (string) json_encode(ConfigReport::rows(self::overrides()));
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertStringNotContainsString(TempConfig::ADMIN_HASH, $json);
    }

    public function testUnknownKeysAreListedWithoutTheirValue(): void
    {
        $rows = self::byKey(self::overrides());

        self::assertSame(['hidden', null, ConfigReport::SOURCE_FILE], [
            $rows['search.titel_weight']['state'], $rows['search.titel_weight']['value'], $rows['search.titel_weight']['source'],
        ]);
        self::assertSame('hidden', $rows['logs.token']['state']);
    }

    public function testDoctorResultsSitNextToTheirKeys(): void
    {
        $rows = self::byKey(self::overrides());

        self::assertSame(
            [['level' => 'error', 'message' => 'not a valid int']],
            $rows['search.default_limit']['issues']
        );
        self::assertStringContainsString('unknown key', $rows['search.titel_weight']['issues'][0]['message']);
        self::assertSame('warning', $rows['logs.token']['issues'][0]['level']);
        self::assertStringContainsString('removed in M39', $rows['logs.token']['issues'][0]['message']);
        self::assertSame([], $rows['search.desc_weight']['issues']);
    }

    public function testAMessageNamingTwoKeysLandsOnBoth(): void
    {
        $overrides = ['db' => ['dsn' => 'x', 'user' => 'u']];
        $rows = self::byKey($overrides);

        foreach (['admin.username', 'admin.password_hash'] as $key) {
            self::assertStringContainsString('not set', $rows[$key]['issues'][0]['message'], $key);
        }
    }

    public function testARenamedKeysNewNameCountsAsComingFromConfigPhp(): void
    {
        $map = ['search.old_weight' => ['since' => 'M40', 'until' => 'M42', 'to' => 'search.title_weight']];
        $rows = [];
        foreach (ConfigReport::rows(['search' => ['old_weight' => 15]], $map) as $row) {
            $rows[$row['key']] = $row;
        }

        self::assertSame(['15', ConfigReport::SOURCE_FILE], [$rows['search.title_weight']['value'], $rows['search.title_weight']['source']]);
        self::assertStringContainsString('renamed to search.title_weight', $rows['search.old_weight']['issues'][0]['message']);
    }

    public function testTheTableEscapesAndMarksChangedRowsForTheFilter(): void
    {
        $html = AdminViews::status([
            'database' => ['ok' => true, 'products' => 1],
            'bundle' => ['present' => false],
            'groups' => ['aliases' => 0, 'synonyms' => 0],
            'vps' => ['state' => 'off'],
            'redis' => ['state' => 'off'],
            'doctor' => ['errors' => [], 'warnings' => [], 'info' => []],
            'last_reload' => null,
            'config' => ConfigReport::rows(['search' => ['<b>x</b>' => 1, 'title_weight' => 11]] + self::overrides()),
        ]);

        self::assertStringContainsString('id="cfg-table"', $html);
        self::assertStringContainsString('data-filter="cfg-table"', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertGreaterThan(0, substr_count($html, 'data-changed="1"'));
        self::assertGreaterThan(50, substr_count($html, 'data-changed="0"'));
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }
}
