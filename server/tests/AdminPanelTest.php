<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M39 over HTTP (PHP's built-in server, the front controller): login, session rules, throttling,
 * CSRF, HTTPS only, address list, the pages behind the login, and the redirects of the old
 * token-protected pages.
 */
final class AdminPanelTest extends TestCase
{
    private const WRONG = 'definitely-not-the-password';

    /** @var array<string, AdminServer> */
    private static array $servers = [];
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('SEARCH_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('CI')) {
                self::fail('A test database is required under CI: SEARCH_TEST_DB_DSN is not set');
            }
            self::markTestSkipped('SEARCH_TEST_DB_DSN is not set');
        }
        $user = getenv('SEARCH_TEST_DB_USER') ?: '';
        $password = getenv('SEARCH_TEST_DB_PASSWORD') ?: '';
        $root = dirname(__DIR__, 2);

        try {
            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events, admin_audit');
            self::$pdo->exec((string) file_get_contents($root . '/db/schema.sql'));
            self::$pdo->exec((string) file_get_contents($root . '/fixtures/products.sample.sql'));
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        $base = [
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => sys_get_temp_dir() . '/admin_panel_no_bundle'],
            'logs' => ['page_size' => 3, 'refresh_seconds' => 30],
            'admin' => ['ip_header' => 'HTTP_X_TEST_IP'],
        ];
        self::$servers['main'] = new AdminServer($base);
        self::$servers['global'] = new AdminServer(array_replace_recursive($base, [
            'admin' => ['login_max_failures' => 50, 'login_max_failures_global' => 2],
        ]));
        self::$servers['off'] = new AdminServer(array_replace_recursive($base, [
            'admin' => ['username' => '', 'password_hash' => ''],
        ]));
        self::$servers['locked'] = new AdminServer(array_replace_recursive($base, [
            'admin' => ['allowed_ips' => '10.0.0.0/8'],
        ]));
        self::$servers['dead'] = new AdminServer(array_replace_recursive($base, [
            'vps' => ['url' => 'http://127.0.0.1:1', 'token' => 't'],
            'redis' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 1],
            'db' => ['password' => $password],
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server->stop();
        }
        self::$servers = [];
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM admin_audit');
        self::$pdo->exec('DELETE FROM search_logs');
    }

    private static function client(string $server = 'main', bool $https = true): AdminClient
    {
        return self::$servers[$server]->client($https);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function loggedIn(AdminClient $client): array
    {
        $response = $client->login();
        self::assertSame(303, $response['status'], $response['body']);

        return $response;
    }

    /** @return list<array<string, string>> */
    private static function audit(): array
    {
        return self::$pdo->query('SELECT ip, action, detail FROM admin_audit ORDER BY id')->fetchAll();
    }

    public function testWithoutAdminConfigThePanelAnswers503AndShowsNoForm(): void
    {
        $response = self::client('off')->request('GET', '/admin.php');

        self::assertSame(503, $response['status']);
        self::assertStringNotContainsString('name="password"', $response['body']);
        self::assertStringContainsString('admin.password_hash', $response['body']);
    }

    public function testPlainHttpIsRefusedBeforeAnythingElse(): void
    {
        $plain = self::client('main', false);

        $get = $plain->request('GET', '/admin.php?p=login');
        $post = $plain->request(
            'POST',
            '/admin.php?p=login',
            ['username' => 'admin', 'password' => TempConfig::ADMIN_PASSWORD]
        );

        self::assertSame([403, 403], [$get['status'], $post['status']]);
        self::assertStringNotContainsString('name="password"', $get['body']);
        self::assertSame([], $plain->setCookies, 'no session cookie over plain HTTP');
        self::assertSame([], self::audit());
    }

    public function testAnAddressOutsideTheListNeverSeesTheForm(): void
    {
        $response = self::client('locked')->request('GET', '/admin.php?p=login');

        self::assertSame(403, $response['status']);
        self::assertStringNotContainsString('name="password"', $response['body']);
    }

    public function testEveryPageRedirectsToTheLoginWhenLoggedOut(): void
    {
        foreach (['status', 'analytics', 'logs', 'audit', 'help'] as $page) {
            $response = self::client()->request('GET', '/admin.php?p=' . $page);

            self::assertSame(303, $response['status'], $page);
            self::assertStringStartsWith('?p=login', $response['headers']['location'], $page);
            self::assertStringNotContainsString('<table', $response['body']);
        }
    }

    public function testTheLoginPageIsPersianRtlWithSecurityHeaders(): void
    {
        $response = self::client()->request('GET', '/admin.php?p=login');
        self::assertSame(200, $response['status']);
        foreach (['<html lang="fa" dir="rtl">', 'ورود به پنل مدیریت', 'name="csrf"', 'type="password"'] as $text) {
            self::assertStringContainsString($text, $response['body']);
        }
        self::assertStringContainsString('no-store', $response['headers']['cache-control']);
        self::assertStringContainsString('noindex', $response['headers']['x-robots-tag']);
        self::assertSame('DENY', $response['headers']['x-frame-options']);
        $csp = $response['headers']['content-security-policy'];
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        preg_match("/script-src 'nonce-([^']+)'/", $csp, $nonce);
        self::assertStringContainsString('nonce="' . $nonce[1] . '"', $response['body']);
        self::assertStringNotContainsString('http', $response['body'], 'no external request');
    }

    public function testTheSessionCookieHasTheRightFlagsAndIsRegeneratedOnLogin(): void
    {
        $client = self::client();
        $client->request('GET', '/admin.php?p=login');
        $before = $client->cookies['search_admin'] ?? '';

        $response = $this->loggedIn($client);

        self::assertNotSame('', $before);
        self::assertNotSame($before, $client->cookies['search_admin'], 'session id regenerated on login');
        $cookie = implode("\n", array_filter(
            $client->setCookies,
            static fn (string $c): bool => str_starts_with($c, 'search_admin=')
        ));
        foreach (['HttpOnly', 'SameSite=Strict', 'Secure', 'path=/'] as $flag) {
            self::assertStringContainsStringIgnoringCase($flag, $cookie);
        }
        self::assertSame('?p=status', $response['headers']['location']);
        self::assertSame(['login_ok'], array_column(self::audit(), 'action'));
    }

    public function testWrongPasswordAndWrongUsernameGetTheSameGenericAnswer(): void
    {
        $badPassword = self::client();
        $badUser = self::client();

        $a = $badPassword->login('admin', self::WRONG);
        $b = $badUser->login('nobody', TempConfig::ADMIN_PASSWORD);

        self::assertSame([401, 401], [$a['status'], $b['status']]);
        $strip = static fn (string $html): string => (string) preg_replace('/(nonce|value)="[^"]+"/', '', $html);
        self::assertSame($strip($a['body']), $strip($b['body']), 'nothing tells which half was wrong');
        self::assertStringContainsString('نام کاربری یا گذرواژه درست نیست', $a['body']);
        self::assertSame(303, self::client()->request('GET', '/admin.php?p=status')['status']);
    }

    public function testFailuresAreAuditedWithoutThePasswordAndThrottledPerAddress(): void
    {
        $attacker = ['X-Test-Ip' => '198.51.100.1'];
        $client = self::client();
        $form = $client->request('GET', '/admin.php?p=login', [], $attacker);
        $csrf = AdminClient::csrfIn($form['body']);
        $post = static fn (string $password): array => $client->request(
            'POST',
            '/admin.php?p=login',
            ['csrf' => $csrf, 'username' => 'admin', 'password' => $password],
            $attacker
        );

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $post(self::WRONG . $i)['status']);
        }
        $blocked = $post(TempConfig::ADMIN_PASSWORD);

        self::assertSame(429, $blocked['status'], 'even the right password is refused while throttled');
        self::assertStringContainsString('تعداد تلاش‌های ناموفق زیاد', $blocked['body']);
        self::assertSame(
            ['login_fail', 'login_fail', 'login_fail', 'login_fail', 'login_fail', 'login_throttled'],
            array_column(self::audit(), 'action')
        );
        $dump = (string) json_encode(self::audit());
        self::assertStringNotContainsString(self::WRONG, $dump, 'the attempted password is never stored');
        self::assertStringNotContainsString(TempConfig::ADMIN_PASSWORD, $dump);
        self::assertSame(['198.51.100.1'], array_values(array_unique(array_column(self::audit(), 'ip'))));

        // Another address is not affected by this one's failures.
        $other = self::client();
        $form = $other->request('GET', '/admin.php?p=login', [], ['X-Test-Ip' => '198.51.100.2']);
        $ok = $other->request('POST', '/admin.php?p=login', [
            'csrf' => AdminClient::csrfIn($form['body']),
            'username' => 'admin',
            'password' => TempConfig::ADMIN_PASSWORD,
        ], ['X-Test-Ip' => '198.51.100.2']);
        self::assertSame(303, $ok['status']);
    }

    public function testTheGlobalLimitThrottlesEveryAddress(): void
    {
        $server = 'global';
        foreach (['203.0.113.1', '203.0.113.2'] as $ip) {
            $c = self::client($server);
            $form = $c->request('GET', '/admin.php?p=login', [], ['X-Test-Ip' => $ip]);
            $c->request('POST', '/admin.php?p=login', [
                'csrf' => AdminClient::csrfIn($form['body']), 'username' => 'admin', 'password' => self::WRONG,
            ], ['X-Test-Ip' => $ip]);
        }

        $third = self::client($server);
        $form = $third->request('GET', '/admin.php?p=login', [], ['X-Test-Ip' => '203.0.113.3']);
        $response = $third->request('POST', '/admin.php?p=login', [
            'csrf' => AdminClient::csrfIn($form['body']),
            'username' => 'admin',
            'password' => TempConfig::ADMIN_PASSWORD,
        ], ['X-Test-Ip' => '203.0.113.3']);

        self::assertSame(429, $response['status']);
        self::assertContains('login_throttled', array_column(self::audit(), 'action'));
    }

    public function testLoginNeedsAValidCsrfToken(): void
    {
        $client = self::client();
        $client->request('GET', '/admin.php?p=login');

        $credentials = ['username' => 'admin', 'password' => TempConfig::ADMIN_PASSWORD];
        $missing = $client->request('POST', '/admin.php?p=login', $credentials);
        $wrong = $client->request('POST', '/admin.php?p=login', ['csrf' => str_repeat('a', 64)] + $credentials);

        self::assertSame([403, 403], [$missing['status'], $wrong['status']]);
        self::assertStringContainsString('نشانهٔ امنیتی', $wrong['body']);
        self::assertSame([], self::audit(), 'a forged request never reaches the password check');
        self::assertSame(303, $client->request('GET', '/admin.php?p=status')['status']);
    }

    public function testLogoutIsPostOnlyNeedsCsrfAndEndsTheSession(): void
    {
        $client = self::client();
        $this->loggedIn($client);
        $csrf = $client->csrf();

        $get = $client->request('GET', '/admin.php?p=logout');
        $forged = $client->request('POST', '/admin.php?p=logout');
        self::assertSame([405, 403], [$get['status'], $forged['status']]);
        self::assertSame(200, $client->request('GET', '/admin.php?p=status')['status'], 'still logged in');

        $out = $client->request('POST', '/admin.php?p=logout', ['csrf' => $csrf]);

        self::assertSame(303, $out['status']);
        self::assertSame('?p=login&r=out', $out['headers']['location']);
        self::assertSame(303, $client->request('GET', '/admin.php?p=status')['status'], 'logged out');
        self::assertContains('logout', array_column(self::audit(), 'action'));
    }

    public function testASessionExpiresAfterTheIdleTimeout(): void
    {
        $client = self::client();
        $this->loggedIn($client);
        self::assertSame(200, $client->request('GET', '/admin.php?p=help')['status']);

        $this->ageSession($client, 'last', 31 * 60);
        $response = $client->request('GET', '/admin.php?p=help');

        self::assertSame(303, $response['status']);
        self::assertSame('?p=login&r=idle&next=help', $response['headers']['location']);
        $login = $client->request('GET', '/admin.php?p=login&r=idle');
        self::assertStringContainsString('بی‌فعالیتی', $login['body']);
        self::assertSame(303, $client->request('GET', '/admin.php?p=help')['status'], 'the old session is dead');
    }

    public function testASessionExpiresAfterTheAbsoluteLifetimeEvenWhenActive(): void
    {
        $client = self::client();
        $this->loggedIn($client);

        $this->ageSession($client, 'created', 8 * 3600 + 60);
        $response = $client->request('GET', '/admin.php?p=help');

        self::assertSame(303, $response['status']);
        self::assertStringContainsString('r=expired', $response['headers']['location']);
    }

    public function testABackgroundPollDoesNotKeepTheSessionAlive(): void
    {
        $client = self::client();
        $this->loggedIn($client);
        $this->ageSession($client, 'last', 10 * 60);
        $stamp = $this->sessionValue($client, 'last');

        $poll = $client->request('GET', '/admin.php?p=analytics&format=json&window=24h');
        self::assertSame(200, $poll['status']);
        self::assertSame($stamp, $this->sessionValue($client, 'last'), 'a JSON poll leaves the idle clock alone');

        $client->request('GET', '/admin.php?p=help');
        self::assertGreaterThan($stamp, $this->sessionValue($client, 'last'), 'a page view refreshes it');

        $this->ageSession($client, 'last', 31 * 60);
        $dead = $client->request('GET', '/admin.php?p=analytics&format=json');
        self::assertSame(401, $dead['status']);
        self::assertSame(['error' => 'unauthorized'], json_decode($dead['body'], true));
    }

    public function testEveryPostNeedsTheCsrfTokenWhenLoggedIn(): void
    {
        $client = self::client();
        $this->loggedIn($client);

        foreach (['status', 'logs', 'audit'] as $page) {
            $post = $client->request('POST', '/admin.php?p=' . $page, ['x' => '1']);
            self::assertSame(403, $post['status'], $page);
        }
        $withToken = $client->request('POST', '/admin.php?p=status', ['csrf' => $client->csrf()]);
        self::assertSame(405, $withToken['status'], 'the panel has no write action yet');
    }

    public function testPagesRenderBehindTheLoginWithTheShell(): void
    {
        $client = self::client();
        $this->loggedIn($client);

        foreach (['status', 'logs', 'audit', 'help'] as $page) {
            $response = $client->request('GET', '/admin.php?p=' . $page);
            self::assertSame(200, $response['status'], $page);
            $needles = ['lang="fa" dir="rtl"', 'aria-current="page"', 'id="theme"', 'راهنمای این صفحه',
                'action="?p=logout"'];
            foreach ($needles as $needle) {
                if ($page === 'help' && $needle === 'راهنمای این صفحه') {
                    continue;
                }
                self::assertStringContainsString($needle, $response['body'], "{$page}: {$needle}");
            }
            self::assertStringContainsString('prefers-color-scheme:dark', $response['body']);
            self::assertStringContainsString('admin-theme', $response['body']);
        }
        $status = $client->request('GET', '/admin.php?p=status')['body'];
        self::assertStringContainsString('تعداد محصولات', $status);
        self::assertStringNotContainsString(TempConfig::ADMIN_HASH, $status, 'no config value on the page');
        self::assertStringNotContainsString('127.0.0.1:', $status);
    }

    public function testTheStatusPageNeverHangsWhenTheVpsAndRedisAreDown(): void
    {
        $client = self::client('dead');
        $this->loggedIn($client);

        $started = microtime(true);
        $response = $client->request('GET', '/admin.php?p=status');
        $elapsed = microtime(true) - $started;

        self::assertSame(200, $response['status']);
        self::assertLessThan(4.0, $elapsed);
        self::assertSame(2, substr_count($response['body'], 'در دسترس نیست</span>'), 'VPS and Redis both unreachable');
    }

    public function testTheAuditPageListsLoginsAndLastReloadComesFromTheTrail(): void
    {
        $client = self::client();
        $this->loggedIn($client);
        self::$pdo->exec(
            "INSERT INTO admin_audit (ip, action, detail) VALUES ('203.0.113.9', 'reload_api', 'count=5')"
        );

        $audit = $client->request('GET', '/admin.php?p=audit')['body'];
        $status = $client->request('GET', '/admin.php?p=status')['body'];

        self::assertStringContainsString('ورود موفق', $audit);
        self::assertStringContainsString('بارگذاری بسته (از راه API)', $audit);
        self::assertStringContainsString('203.0.113.9', $audit);
        self::assertStringNotContainsString('ثبت نشده است', $status, 'last reload found');
    }

    public function testLogsAreEscapedPagedAndFilteredBehindTheLogin(): void
    {
        $this->seedLogs([
            ['raw_q' => 'gap-1', 'result_count' => 0],
            ['raw_q' => 'found'],
            ['raw_q' => '<script>alert(1)</script>', 'latency_ms' => 900],
            ['raw_q' => 'q4'],
        ]);
        $client = self::client();
        $this->loggedIn($client);

        $recent = $client->request('GET', '/admin.php?p=logs')['body'];
        $zero = $client->request('GET', '/admin.php?p=logs&view=zero')['body'];
        $slow = $client->request('GET', '/admin.php?p=logs&view=slowest')['body'];
        $page2 = $client->request('GET', '/admin.php?p=logs&page=2')['body'];

        self::assertSame(['q4', '&lt;script&gt;alert(1)&lt;/script&gt;', 'found'], self::cells($recent, 2));
        self::assertStringNotContainsString('<script>alert(1)', $recent);
        self::assertSame(['gap-1'], self::cells($zero, 2));
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', self::cells($slow, 2)[0]);
        self::assertSame(['gap-1'], self::cells($page2, 2));
        self::assertStringContainsString('عبارت', $recent);
        self::assertStringContainsString('page=2', $recent);
    }

    /** @return list<string> the n-th cell (0-based) of every body row */
    private static function cells(string $html, int $n): array
    {
        preg_match_all('#<tbody>(.*?)</tbody>#su', $html, $body);
        preg_match_all('#<tr>(.*?)</tr>#su', $body[1][0] ?? '', $rows);
        $out = [];
        foreach ($rows[1] as $row) {
            preg_match_all('#<td[^>]*>(.*?)</td>#su', $row, $cells);
            $out[] = $cells[1][$n] ?? '';
        }

        return $out;
    }

    public function testAnalyticsRunInsideThePanelAndItsJsonIsBehindTheSession(): void
    {
        $this->seedLogs([
            ['raw_q' => 'far cry', 'result_count' => 4],
            ['raw_q' => '<b>nothing</b>', 'result_count' => 0],
        ]);
        self::$pdo->exec('UPDATE search_logs SET ts = NOW()');
        $client = self::client();
        $anonymousJson = $client->request('GET', '/admin.php?p=analytics&format=json');
        $this->loggedIn($client);

        $page = $client->request('GET', '/admin.php?p=analytics');
        $json = $client->request('GET', '/admin.php?p=analytics&format=json&window=24h');

        self::assertSame(401, $anonymousJson['status']);
        self::assertStringNotContainsString('far cry', $anonymousJson['body']);
        self::assertSame(200, $page['status']);
        $needles = ['پرجستجوترین عبارت‌ها', 'id="meta"', '"embedded":true', 'id="app"', 'aria-current="page"'];
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $page['body']);
        }
        self::assertStringContainsString('\\u003Cb\\u003Enothing', $page['body']);
        self::assertStringNotContainsString('<b>nothing</b>', $page['body']);
        preg_match("/script-src 'nonce-([^']+)'/", $page['headers']['content-security-policy'], $csp);
        self::assertSame(
            3,
            substr_count($page['body'], 'nonce="' . $csp[1] . '"'),
            'theme bootstrap, panel script, dashboard'
        );
        self::assertSame('24h', json_decode($json['body'], true)['window']);
        self::assertSame(2, json_decode($json['body'], true)['summary']['total']);
        self::assertStringContainsString('application/json', $json['headers']['content-type']);
    }

    public function testTheOldPagesRedirectIntoThePanelWithoutAnyToken(): void
    {
        $client = self::client();

        $analytics = $client->request('GET', '/analytics.php?window=24h&format=json&token=secret&evil=1');
        $logs = $client->request('GET', '/logs.php?view=zero&page=2&token=secret');
        $viaFront = $client->request('GET', '/analytics');

        self::assertSame(302, $analytics['status']);
        self::assertSame('admin.php?p=analytics&window=24h&format=json', $analytics['headers']['location']);
        self::assertSame('admin.php?p=logs&view=zero&page=2', $logs['headers']['location']);
        self::assertSame('admin.php?p=analytics', $viaFront['headers']['location']);
        self::assertStringNotContainsString('secret', $analytics['headers']['location'] . $logs['headers']['location']);
        self::assertSame('', $analytics['body']);
    }

    public function testTheCleanUrlRoutesTheFrontController(): void
    {
        $response = self::client()->request('GET', '/admin');

        self::assertSame(303, $response['status']);
        self::assertStringStartsWith('?p=login', $response['headers']['location']);
    }

    public function testUnknownPagesAndMethods(): void
    {
        $client = self::client();
        $this->loggedIn($client);

        self::assertSame(404, $client->request('GET', '/admin.php?p=nope')['status']);
        self::assertSame(405, $client->request('DELETE', '/admin.php?p=status')['status']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedLogs(array $rows): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO search_logs (ts, raw_q, normalized_q, result_count, latency_ms)
             VALUES (:ts, :raw_q, :normalized_q, :result_count, :latency_ms)'
        );
        foreach ($rows as $i => $row) {
            $stmt->execute($row + [
                'ts' => sprintf('2026-01-01 10:%02d:00', $i),
                'normalized_q' => strtolower((string) $row['raw_q']),
                'result_count' => 2,
                'latency_ms' => 10,
            ]);
        }
    }

    private static function sessionFile(AdminClient $client): string
    {
        return self::$servers['main']->sessionDir . '/sess_' . ($client->cookies['search_admin'] ?? 'none');
    }

    /** Moves a session timestamp into the past by editing the server's session file. */
    private function ageSession(AdminClient $client, string $key, int $seconds): void
    {
        $file = self::sessionFile($client);
        self::assertFileExists($file);
        file_put_contents($file, (string) preg_replace_callback(
            '/' . $key . '\|i:(\d+);/',
            static fn (array $m): string => $key . '|i:' . ((int) $m[1] - $seconds) . ';',
            (string) file_get_contents($file)
        ));
    }

    private function sessionValue(AdminClient $client, string $key): int
    {
        preg_match('/' . $key . '\|i:(\d+);/', (string) file_get_contents(self::sessionFile($client)), $m);

        return (int) ($m[1] ?? 0);
    }
}
