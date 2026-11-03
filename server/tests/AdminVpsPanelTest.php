<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- long literals and assertion messages in an HTTP / fixture test

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** The admin panel's VPS page over HTTP: auth, empty state, incidents, filters, export, secrets, "Probe now". */
final class AdminVpsPanelTest extends TestCase
{
    private static PDO $pdo;
    private static string $schema;
    private static AdminServer $server;
    private static MockVpsServer $vps;
    private AdminClient $client;

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
        try {
            self::$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            self::$pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events, admin_audit, vps_calls');
            self::$schema = (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql');
            self::$pdo->exec(self::$schema);
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }
        self::$vps = new MockVpsServer(['token' => FakeVps::TOKEN]);
        self::$server = new AdminServer([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'vps' => ['url' => self::$vps->url, 'token' => FakeVps::TOKEN],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$server)) {
            return;
        }
        self::$server->stop();
        self::$vps->stop();
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS vps_calls');
        self::$pdo->exec(self::$schema);
        self::$pdo->exec('DELETE FROM admin_audit');
        self::$vps->setScenario(['token' => FakeVps::TOKEN]);
        $this->client = self::$server->client();
        self::assertSame(303, $this->client->login()['status']);
    }

    /** @param array<string, mixed> $extra */
    private function call(int $secondsAgo, string $outcome, string $kind = 'search', int $ms = 100, array $extra = []): void
    {
        self::$pdo->prepare(
            'INSERT INTO vps_calls (ts, kind, outcome, duration_ms, timeout_ms, error, normalized_q, http_status, connect_ms)'
            . ' VALUES (UTC_TIMESTAMP() - INTERVAL ? SECOND, ?, ?, ?, 600, ?, ?, ?, ?)'
        )->execute([$secondsAgo, $kind, $outcome, $ms, $extra['error'] ?? null, $extra['q'] ?? null, $extra['status'] ?? null, $extra['connect'] ?? 3]);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function page(string $query = ''): array
    {
        return $this->client->request('GET', '/admin.php?p=vps' . $query);
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return self::$pdo->query("SELECT action FROM admin_audit WHERE action NOT LIKE 'login%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testItNeedsALogin(): void
    {
        $anonymous = self::$server->client()->request('GET', '/admin.php?p=vps');

        self::assertSame(303, $anonymous['status']);
        self::assertStringContainsString('p=login', $anonymous['headers']['location']);
        self::assertStringContainsString('next=vps', $anonymous['headers']['location']);
        self::assertSame(303, self::$server->client()->request('POST', '/admin.php?p=vps', ['action' => 'vps_probe'])['status']);
        self::assertSame(303, self::$server->client()->request('GET', '/admin.php?p=vps&export=csv')['status']);
        self::assertSame([], $this->auditActions());
    }

    public function testAnEmptyTableShowsTheEmptyStatesAndTheHelp(): void
    {
        $page = $this->page();

        self::assertSame(200, $page['status']);
        foreach (
            [
            'در 7 روز گذشته خطایی ثبت نشده است.', 'در این بازه تماسی ثبت نشده است.', 'تماسی با این فیلترها پیدا نشد.',
            'تماسی در این 7 روز ثبت نشده است.', 'پایان مهلت', 'در دسترس نبود', 'خطای HTTP', 'پاسخ نامعتبر',
            'name="action" value="vps_probe"', 'تماس‌های VPS', 'href="?p=vps"', 'راهنمای این صفحه',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $page['body'], $needle);
        }
    }

    public function testAMissingTableSaysWhichMigrationToRunAndStillRenders(): void
    {
        self::$pdo->exec('DROP TABLE vps_calls');

        $page = $this->page();

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('2026-11-03_vps_calls.sql', $page['body']);
        self::assertStringNotContainsString('value="vps_probe"', $page['body']);
        self::assertSame(200, $this->page('&export=csv')['status'], 'the export falls back to the page');
    }

    public function testSummaryIncidentsAndTheListShowTheSeededCalls(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->call(3000 - $i * 10, 'ok', 'search', 40);
        }
        $this->call(1000, 'timeout', 'search', 600, ['error' => 'timeout [curl 28]', 'q' => 'ps5 pro']);
        $this->call(990, 'timeout', 'search', 601, ['q' => 'macbook']);
        $this->call(960, 'unreachable', 'probe', 5, ['error' => 'unreachable: Failed to connect to [vps] port 1', 'connect' => null]);
        $this->call(100, 'http_error', 'search', 30, ['status' => 503, 'error' => 'http_503']);
        $this->call(50, 'skipped', 'search', 0, ['error' => 'not_configured']);
        for ($i = 0; $i < 3; $i++) {
            $this->call(900 - $i * 10, 'ok', 'search', 80);
        }

        $page = $this->page();
        $body = $page['body'];

        self::assertSame(200, $page['status']);
        // two incidents: the 3-failure burst (990-1000 s ago, 40 s long) and the lone 503
        preg_match('#<h2>رویدادهای خطا</h2>(.*?)</section>#su', $body, $section);
        self::assertSame(2, substr_count($section[1], '<tr><td class="c nw">'), 'the burst is one incident, the 503 another');
        self::assertMatchesRegularExpression('#<tr><td class="c nw"><bdi class="ltr">[\d-]+ [\d:]+</bdi></td><td class="c nw"><bdi class="ltr">[\d-]+ [\d:]+</bdi></td><td class="c">40</td><td class="c">3</td>#u', $body);
        self::assertStringContainsString('ps5 pro', $body);
        self::assertStringContainsString('timeout [curl 28]', $body);
        // the burst's baseline: 40 ms before (the 10 newest ok calls), 80 after
        self::assertMatchesRegularExpression('#<td class="c">40</td><td class="c">80</td>#u', $body);
        // strips: search (ok, timeout, http_error, skipped) and probe (unreachable)
        self::assertSame(5, preg_match_all('#<svg class="strip"#', $body), 'one strip per outcome that occurred, per kind');
        self::assertStringContainsString('class="o-timeout"', $body);
        self::assertStringContainsString('<title>', $body);
    }

    public function testTheListFiltersAndPagesFromTheQueryString(): void
    {
        $this->call(500, 'ok', 'search', 111, ['q' => 'fast-one']);
        $this->call(400, 'ok', 'search', 999, ['q' => 'slow-one']);
        $this->call(300, 'timeout', 'search', 600, ['q' => 'timed-out']);
        $this->call(200, 'http_error', 'probe', 20, ['q' => null, 'status' => 503]);

        $failures = $this->page()['body'];
        $slowest = $this->page('&view=slowest')['body'];
        $timeouts = $this->page('&view=all&outcome=timeout')['body'];
        $probes = $this->page('&view=all&kind=probe')['body'];
        $junk = $this->page('&view=%27%3Bdrop&outcome=nope&kind[]=x&from=tomorrow&per=7&page=-4');

        self::assertStringContainsString('timed-out', $failures);
        self::assertStringNotContainsString('fast-one', $failures);
        self::assertSame(1, preg_match('#slow-one.*fast-one#su', $slowest), 'slowest ok call first');
        self::assertStringNotContainsString('timed-out', $slowest);
        self::assertStringContainsString('timed-out', $timeouts);
        self::assertStringNotContainsString('slow-one', $timeouts);
        self::assertStringNotContainsString('timed-out', $probes);
        self::assertStringContainsString('>503<', $probes);
        self::assertSame(200, $junk['status'], 'unknown filter values are ignored, not an error');
        self::assertStringContainsString('timed-out', $junk['body']);
        self::assertSame(200, $this->page('&view=all&from=2026-01-01&to=2026-01-02')['status']);
        self::assertStringContainsString('تماسی با این فیلترها پیدا نشد.', $this->page('&view=all&from=2026-01-01&to=2026-01-02')['body']);
    }

    public function testPagingFollowsTheRowsPerPageChoice(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->call($i * 10, 'timeout', 'search', 600, ['q' => sprintf('q%02d', $i)]);
        }

        $first = $this->page('&per=25')['body'];
        $second = $this->page('&per=25&page=2')['body'];

        self::assertTrue(str_contains($first, 'title="q30"') && !str_contains($first, 'title="q01"'), 'newest (highest id) first');
        self::assertTrue(str_contains($second, 'title="q01"') && !str_contains($second, 'title="q30"'));
        self::assertSame(25, substr_count($first, 'class="trunc" title="q'));
        self::assertSame(5, substr_count($second, 'class="trunc" title="q'));
        self::assertStringContainsString('rel="next"', $first);
        self::assertStringContainsString('rel="prev"', $second);
    }

    public function testNoSecretAndNoUnnonceDScriptInThePage(): void
    {
        $this->call(100, 'unreachable', 'search', 5, ['error' => 'unreachable: Failed to connect to [vps] port 1']);
        $page = $this->page();

        foreach ([FakeVps::TOKEN, self::$vps->url, parse_url(self::$vps->url, PHP_URL_HOST) . ':' . parse_url(self::$vps->url, PHP_URL_PORT), 'password_hash', 'vps.token'] as $secret) {
            self::assertStringNotContainsString($secret, $page['body'], $secret);
        }
        preg_match('/nonce-([A-Za-z0-9+\/=]+)/', $page['headers']['content-security-policy'], $nonce);
        self::assertNotEmpty($nonce);
        self::assertSame(
            substr_count($page['body'], '<script'),
            substr_count($page['body'], '<script nonce="' . $nonce[1] . '"'),
            'every script carries the page nonce'
        );
        self::assertStringNotContainsString('http://', str_replace('http://www.w3.org', '', $page['body']), 'no external request');
        self::assertStringNotContainsString('https://', $page['body']);
    }

    public function testTheCsvExportIsUtf8HasTheColumnsAndGuardsFormulas(): void
    {
        $this->call(100, 'timeout', 'search', 600, ['q' => '=HYPERLINK("x")', 'error' => 'timeout [curl 28]']);
        $this->call(90, 'ok', 'search', 50, ['q' => 'پلی استیشن']);

        $failures = $this->page('&export=csv');
        $all = $this->page('&export=csv&view=all');

        self::assertSame(200, $failures['status']);
        self::assertStringStartsWith('text/csv; charset=utf-8', $failures['headers']['content-type']);
        self::assertStringContainsString('attachment; filename="vps-calls.csv"', $failures['headers']['content-disposition']);
        $lines = array_values(array_filter(explode("\n", $failures['body'])));
        self::assertSame('id,ts,kind,outcome,source,http_status,duration_ms,namelookup_ms,connect_ms,appconnect_ms,ttfb_ms,timeout_ms,error,response_bytes,neighbours,request_id,vps_queue_ms,vps_embed_ms,vps_search_ms,vps_took_ms,vps_inflight,normalized_q,sent_differs,sent_hash', $lines[0]);
        self::assertCount(2, $lines, 'the failures view: the header and the one failure');
        self::assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $failures['body']);
        self::assertStringContainsString('پلی استیشن', $all['body']);
        foreach ([FakeVps::TOKEN, self::$vps->url, 'customer'] as $secret) {
            self::assertStringNotContainsString($secret, $all['body']);
        }
    }

    public function testProbeNowIsRecordedAuditedAndShown(): void
    {
        $post = $this->client->request('POST', '/admin.php?p=vps', ['csrf' => $this->client->csrf(), 'action' => 'vps_probe']);

        self::assertSame(303, $post['status'], $post['body']);
        self::assertMatchesRegularExpression('/^\?p=vps&probed=ok&ms=\d+$/', $post['headers']['location']);
        self::assertSame('/health', self::$vps->lastRequest()['path']);
        self::assertSame('', self::$vps->lastRequest()['authorization'], 'health is open: no token is sent');
        $row = self::$pdo->query('SELECT * FROM vps_calls')->fetch();
        self::assertSame(['probe', 'ok', 200], [$row['kind'], $row['outcome'], (int) $row['http_status']]);
        self::assertSame($row['request_id'], self::$vps->lastRequest()['request_id']);
        self::assertSame(['vps_probe'], $this->auditActions());
        self::assertMatchesRegularExpression('/^ok \d+ ms$/', (string) self::$pdo->query("SELECT detail FROM admin_audit WHERE action = 'vps_probe'")->fetchColumn());
        $page = $this->client->request('GET', '/admin.php' . $post['headers']['location']);
        self::assertStringContainsString('آزمایش انجام شد:', $page['body']);
        self::assertStringContainsString('موفق', $page['body']);
    }

    public function testProbeNowAgainstAFailingAndAnUnconfiguredVps(): void
    {
        self::$vps->setScenario(['token' => FakeVps::TOKEN, 'health' => ['status' => 503, 'body' => ['ok' => false]]]);
        $failing = $this->client->request('POST', '/admin.php?p=vps', ['csrf' => $this->client->csrf(), 'action' => 'vps_probe']);

        self::assertStringContainsString('probed=http_error', $failing['headers']['location']);
        self::assertSame('http_error', self::$pdo->query('SELECT outcome FROM vps_calls')->fetchColumn());
        self::assertStringContainsString('class="msg bad"', $this->client->request('GET', '/admin.php' . $failing['headers']['location'])['body']);

        $off = new AdminServer(['db' => ['dsn' => (string) getenv('SEARCH_TEST_DB_DSN'), 'user' => getenv('SEARCH_TEST_DB_USER') ?: '', 'password' => getenv('SEARCH_TEST_DB_PASSWORD') ?: ''], 'vps' => ['url' => '']]);
        try {
            $client = $off->client();
            $client->login();
            $none = $client->request('POST', '/admin.php?p=vps', ['csrf' => $client->csrf(), 'action' => 'vps_probe']);
            self::assertStringContainsString('probed=not_configured', $none['headers']['location']);
            self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM vps_calls WHERE outcome = 'not_configured'")->fetchColumn());
        } finally {
            $off->stop();
        }
    }

    public function testWritesNeedTheCsrfTokenAndAKnownAction(): void
    {
        $forged = $this->client->request('POST', '/admin.php?p=vps', ['action' => 'vps_probe']);
        $unknown = $this->client->request('POST', '/admin.php?p=vps', ['csrf' => $this->client->csrf(), 'action' => 'delete_all']);
        $other = $this->client->request('POST', '/admin.php?p=logs', ['csrf' => $this->client->csrf(), 'action' => 'vps_probe']);

        self::assertSame([403, 400, 405], [$forged['status'], $unknown['status'], $other['status']]);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM vps_calls')->fetchColumn());
        self::assertSame([], $this->auditActions());
    }

    public function testAProbeLabelAppearsInTheAuditPage(): void
    {
        $this->client->request('POST', '/admin.php?p=vps', ['csrf' => $this->client->csrf(), 'action' => 'vps_probe']);

        $audit = $this->client->request('GET', '/admin.php?p=audit');

        self::assertStringContainsString('آزمایش اتصال VPS', $audit['body']);
    }
}
