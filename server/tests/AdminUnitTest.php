<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminAuth;
use App\AdminStatus;
use App\AdminStrings;
use App\AdminViews;
use App\Audit;
use App\Config;
use PHPUnit\Framework\Attributes\DataProvider;

/** M39 building blocks without HTTP: audit trail, session rules, address lists, status probes. */
final class AdminUnitTest extends DatabaseTestCase
{
    private function audit(int $retentionDays = 180): Audit
    {
        return new Audit($this->pdo, 'admin_audit', $retentionDays);
    }

    public function testAuditWritesAndCountsFailuresPerAddressAndGlobally(): void
    {
        $audit = $this->audit();
        foreach (['1.1.1.1', '1.1.1.1', '2.2.2.2'] as $ip) {
            $audit->write($ip, Audit::LOGIN_FAIL);
        }
        $audit->write('1.1.1.1', Audit::LOGIN_OK);

        self::assertSame(2, $audit->failures('1.1.1.1', 15));
        self::assertSame(1, $audit->failures('2.2.2.2', 15));
        self::assertSame(3, $audit->failures(null, 15));
        self::assertSame(0, $audit->failures('3.3.3.3', 15));
    }

    public function testFailuresOutsideTheWindowDoNotCount(): void
    {
        $audit = $this->audit();
        $audit->write('1.1.1.1', Audit::LOGIN_FAIL);
        $this->pdo->exec("UPDATE admin_audit SET ts = NOW() - INTERVAL 16 MINUTE");

        self::assertSame(0, $audit->failures('1.1.1.1', 15));
        self::assertSame(1, $audit->failures('1.1.1.1', 20));
    }

    public function testRowsPastTheRetentionArePurgedOnWrite(): void
    {
        $audit = $this->audit(180);
        $audit->write('1.1.1.1', 'old');
        $audit->write('1.1.1.1', 'recent');
        $this->pdo->exec("UPDATE admin_audit SET ts = NOW() - INTERVAL 181 DAY WHERE action = 'old'");
        $this->pdo->exec(
            "INSERT INTO admin_audit (ip, action, ts) VALUES ('9.9.9.9', 'edge', NOW() - INTERVAL 179 DAY)"
        );

        $audit->write('1.1.1.1', 'trigger');

        $actions = $this->pdo->query('SELECT action FROM admin_audit ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['recent', 'edge', 'trigger'], $actions);
    }

    public function testDetailIsCappedAndRecentPagesNewestFirst(): void
    {
        $audit = $this->audit();
        for ($i = 1; $i <= 5; $i++) {
            $audit->write('1.1.1.1', 'step', (string) $i);
        }
        $audit->write('1.1.1.1', 'long', str_repeat('x', 900));

        $page = $audit->recent(1, 4);
        self::assertSame(6, $page['total']);
        self::assertSame(2, $page['pages']);
        self::assertSame('long', $page['rows'][0]['action']);
        self::assertSame(512, strlen($page['rows'][0]['detail']));
        self::assertCount(2, $audit->recent(9, 4)['rows'], 'out of range clamps to the last page');
    }

    public function testLastOfFindsTheNewestMatchingRow(): void
    {
        $audit = $this->audit();
        self::assertNull($audit->lastOf([Audit::RELOAD_API]));
        $audit->write('1.1.1.1', Audit::RELOAD_API, 'count=3');

        self::assertNotNull($audit->lastOf([Audit::RELOAD_API, 'reload']));
        self::assertNull($audit->lastOf(['reload']));
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function sessions(): array
    {
        $now = 100_000;

        return [
            'fresh' => [['admin_login' => true, 'created' => $now - 60, 'last' => $now - 30], $now, 'ok'],
            'idle' => [['admin_login' => true, 'created' => $now - 3000, 'last' => $now - 1801], $now, 'idle'],
            'inside idle' => [['admin_login' => true, 'created' => $now - 3000, 'last' => $now - 1800], $now, 'ok'],
            'absolute' => [['admin_login' => true, 'created' => $now - 28801, 'last' => $now - 5], $now, 'expired'],
            'never logged in' => [['csrf' => 'x'], $now, 'anonymous'],
            'empty' => [[], $now, 'anonymous'],
            'half written' => [['admin_login' => true], $now, 'anonymous'],
        ];
    }

    /** @param array<string, mixed> $session */
    #[DataProvider('sessions')]
    public function testSessionState(array $session, int $now, string $expected): void
    {
        self::assertSame($expected, AdminAuth::sessionState($session, $now, 1800, 28800));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function addresses(): array
    {
        return [
            'exact v4' => ['203.0.113.7', '203.0.113.7', true],
            'other v4' => ['203.0.113.8', '203.0.113.7', false],
            'cidr v4 in' => ['10.1.2.3', '10.0.0.0/8', true],
            'cidr v4 out' => ['11.0.0.1', '10.0.0.0/8', false],
            'cidr /20 edge in' => ['192.168.15.255', '192.168.0.0/20', true],
            'cidr /20 edge out' => ['192.168.16.0', '192.168.0.0/20', false],
            'cidr /32' => ['1.2.3.4', '1.2.3.4/32', true],
            'cidr /0' => ['8.8.8.8', '0.0.0.0/0', true],
            'exact v6' => ['2001:db8::1', '2001:db8::1', true],
            'cidr v6 in' => ['2001:db8:ffff::1', '2001:db8::/32', true],
            'cidr v6 out' => ['2001:db9::1', '2001:db8::/32', false],
            'v4 vs v6 range' => ['10.0.0.1', '2001:db8::/32', false],
            'bad prefix length' => ['10.0.0.1', '10.0.0.0/33', false],
            'garbage ip' => ['not-an-ip', '10.0.0.0/8', false],
            'empty entry' => ['10.0.0.1', '', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIpMatches(string $ip, string $entry, bool $expected): void
    {
        self::assertSame($expected, AdminAuth::ipMatches($ip, $entry));
    }

    public function testAllowedIpsListAndEmptyMeansAnyone(): void
    {
        $open = new AdminAuth(Config::defaults()['admin']);
        $closed = new AdminAuth(['allowed_ips' => ' 10.0.0.0/8 , 203.0.113.7'] + Config::defaults()['admin']);

        self::assertTrue($open->ipAllowed('8.8.8.8'));
        self::assertTrue($closed->ipAllowed('10.9.9.9'));
        self::assertTrue($closed->ipAllowed('203.0.113.7'));
        self::assertFalse($closed->ipAllowed('8.8.8.8'));
    }

    public function testClientIpIsRemoteAddrUnlessTheProxyIsTrusted(): void
    {
        $admin = Config::defaults()['admin'];
        $plain = new AdminAuth($admin);
        $proxied = new AdminAuth(['trusted_proxies' => '10.0.0.1, 192.168.0.0/16'] + $admin);
        $forged = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6'];

        self::assertSame('198.51.100.9', $plain->clientIp($forged), 'no trusted proxy: the header is ignored');
        self::assertSame('198.51.100.9', $proxied->clientIp($forged), 'a stranger cannot vouch for an address');
        $viaProxy = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9'];
        self::assertSame('203.0.113.9', $proxied->clientIp($viaProxy), 'right to left: the proxy appended it');
        $chain = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 192.168.4.4'];
        self::assertSame('203.0.113.9', $proxied->clientIp($chain), 'inner trusted proxies are skipped');
        $junk = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'junk'];
        self::assertSame('10.0.0.1', $proxied->clientIp($junk));
        $partlyJunk = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'junk, 203.0.113.9'];
        self::assertSame('203.0.113.9', $proxied->clientIp($partlyJunk), 'only the entries to its right count');
        self::assertSame('10.0.0.1', $proxied->clientIp(['REMOTE_ADDR' => '10.0.0.1']));
        $allTrusted = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.1'];
        self::assertSame('10.0.0.1', $proxied->clientIp($allTrusted));
    }

    public function testHttpsDetection(): void
    {
        $admin = Config::defaults()['admin'];
        $auth = new AdminAuth($admin);
        $trusting = new AdminAuth(['trust_forwarded_proto' => true] + $admin);

        self::assertFalse($auth->isHttps([]));
        self::assertFalse($auth->isHttps(['HTTPS' => 'off']));
        self::assertTrue($auth->isHttps(['HTTPS' => 'on']));
        self::assertTrue($auth->isHttps(['SERVER_PORT' => '443']));
        self::assertFalse($auth->isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']), 'spoofable, so off by default');
        self::assertTrue($trusting->isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertFalse($trusting->isHttps(['HTTP_X_FORWARDED_PROTO' => 'http']));
    }

    public function testConfigProblemsNameKeysAndNeverValues(): void
    {
        $hash = TempConfig::ADMIN_HASH;
        $base = Config::defaults()['admin'];

        self::assertSame([], AdminAuth::configProblems($base), 'unset is not a problem, just disabled');
        self::assertFalse(AdminAuth::configured($base));
        self::assertTrue(AdminAuth::configured(['username' => 'a', 'password_hash' => $hash] + $base));
        self::assertFalse(AdminAuth::configured(['username' => 'a', 'password_hash' => 'plaintext'] + $base));
        $problems = AdminAuth::configProblems(['username' => 'a', 'password_hash' => 'plaintext-pw'] + $base);
        self::assertCount(1, $problems);
        self::assertStringNotContainsString('plaintext-pw', $problems[0]);
    }

    public function testStatusCollectsEverythingAndDegradesWhenProbesFail(): void
    {
        $this->loadSampleFixture();
        $data = TempTree::path('admin_status');
        mkdir($data);
        $meta = [
            'built_at' => '2026-10-01T10:00:00Z', 'normalization_version' => 4,
            'count' => 12,
        ];
        file_put_contents($data . '/meta.json', json_encode($meta));
        file_put_contents($data . '/aliases.json', '[["a","b"],["c","d"]]');
        file_put_contents($data . '/synonyms.json', '[["x","y"]]');
        $config = Config::merge([
            'paths' => ['data' => $data],
            'vps' => ['url' => 'http://vps.test'],
            'redis' => ['enabled' => true],
        ]);
        $fake = new FakeRedis();
        $fake->data = ['search:cache:1' => 'a', 'search:cache:2' => 'b', 'other' => 'c'];
        $health = ['ok' => true, 'count' => 7, 'model' => 'bge', 'built_at' => 'then'];
        $http = static fn (string $url, int $timeout): array => [
            'status' => 200,
            'body' => (string) json_encode($health),
        ];
        $status = new AdminStatus($config, $this->pdo, $this->audit(), [], $http, $fake);
        $this->audit()->write('1.1.1.1', Audit::RELOAD_API);

        $s = $status->collect();

        $products = (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        self::assertSame(['ok' => true, 'products' => $products], $s['database']);
        self::assertSame(['present' => true] + $meta, $s['bundle']);
        self::assertSame(['aliases' => 2, 'synonyms' => 1], $s['groups']);
        self::assertSame(
            ['state' => 'ok', 'http' => 200, 'count' => 7, 'model' => 'bge', 'built_at' => 'then'],
            $s['vps']
        );
        self::assertSame(['state' => 'ok', 'keys' => 2], $s['redis']);
        self::assertNotNull($s['last_reload']);

        // Both probes fail: the page still collects, with "unreachable".
        $fake->down = true;
        $s = (new AdminStatus($config, $this->pdo, $this->audit(), [], static fn (): ?array => null, $fake))->collect();
        self::assertSame('unreachable', $s['vps']['state']);
        self::assertSame('unreachable', $s['redis']['state']);

        // Not configured: reported as off, not as a failure.
        $bare = Config::merge(['paths' => ['data' => $data . '_none']]);
        $s = (new AdminStatus($bare, $this->pdo, $this->audit(), []))->collect();
        self::assertSame('off', $s['vps']['state']);
        self::assertSame('off', $s['redis']['state']);
        self::assertSame(['present' => false], $s['bundle']);
        self::assertSame(['aliases' => null, 'synonyms' => null], $s['groups']);
    }

    public function testStatusProbeUsesTheConfiguredShortTimeout(): void
    {
        $seen = [];
        $http = static function (string $url, int $timeout) use (&$seen): ?array {
            $seen = [$url, $timeout];

            return null;
        };
        $config = Config::merge(['vps' => ['url' => 'https://vps.test/'], 'admin' => ['probe_timeout_ms' => 750]]);

        (new AdminStatus($config, $this->pdo, $this->audit(), [], $http))->collect();

        self::assertSame(['https://vps.test/health', 750], $seen);
    }

    public function testStatusViewEscapesAndShowsPersianStates(): void
    {
        $html = AdminViews::status([
            'database' => ['ok' => true, 'products' => 1234],
            'bundle' => ['present' => true, 'built_at' => '<b>x</b>', 'normalization_version' => 4,
                'count' => 5],
            'groups' => ['aliases' => 2, 'synonyms' => null],
            'vps' => ['state' => 'unreachable'],
            'redis' => ['state' => 'off'],
            'doctor' => ['errors' => ['db.dsn: required, missing or empty'], 'warnings' => [], 'info' => []],
            'last_reload' => null,
            'config' => [],
        ]);

        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString(AdminStrings::fa()['errors']['unreachable'], $html);
        self::assertStringContainsString('1,234', $html, 'Latin digits, comma thousands');
        self::assertDoesNotMatchRegularExpression('/[\x{06F0}-\x{06F9}]/u', $html);
        self::assertStringContainsString('db.dsn: required, missing or empty', $html);
        self::assertStringContainsString(AdminStrings::fa()['status']['redis_off'], $html);
    }

    public function testEveryPageHelpHasAllFiveParts(): void
    {
        $fa = AdminStrings::fa();
        $pages = array_filter($fa, static fn (mixed $v): bool => is_array($v) && isset($v['help']['what']));

        self::assertGreaterThanOrEqual(4, count($pages));
        foreach ($pages as $name => $page) {
            foreach (array_keys($fa['help_labels']) as $part) {
                self::assertNotSame('', trim($page['help'][$part] ?? ''), "{$name}.help.{$part}");
            }
        }
        foreach (array_keys($fa['nav']) as $key) {
            self::assertNotSame('', $fa['nav'][$key]);
        }
    }

    public function testNoPersianInCodeAndNoEmDashesInStrings(): void
    {
        $src = dirname(__DIR__) . '/src';
        $files = ['AdminPage.php', 'AdminViews.php', 'AdminAuth.php', 'AdminStatus.php', 'Audit.php', 'AdminPage.js'];
        foreach ($files as $file) {
            self::assertSame(
                0,
                preg_match('/[\x{0600}-\x{06FF}]/u', (string) file_get_contents($src . '/' . $file)),
                "{$file} holds no Persian: it lives in AdminStrings"
            );
        }
        $json = (string) json_encode(AdminStrings::fa(), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('—', $json);
        self::assertStringNotContainsString('ي', $json, 'Arabic yeh');
        self::assertStringNotContainsString('ك', $json, 'Arabic kaf');
    }
}
