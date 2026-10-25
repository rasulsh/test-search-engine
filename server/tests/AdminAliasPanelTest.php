<?php

declare(strict_types=1);

namespace App\Tests;

use App\AliasStore;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * M41 over HTTP: the alias manager (groups, history, advanced editor), the synonyms viewer, the
 * search tester, and the panel-wide fixes that shipped with them (dates, readable audit rows,
 * the theme switch on every page, analytics inside the shell).
 */
final class AdminAliasPanelTest extends TestCase
{
    private static AdminServer $server;
    private static PDO $pdo;
    private static string $root = '';
    private AdminClient $client;
    private string $csrf = '';

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
        $repo = dirname(__DIR__, 2);

        try {
            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('DROP TABLE IF EXISTS products, search_logs, search_events, admin_audit');
            self::$pdo->exec((string) file_get_contents($repo . '/db/schema.sql'));
            self::$pdo->exec((string) file_get_contents($repo . '/fixtures/products.sample.sql'));
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        self::$root = TempTree::make('alias_panel');
        mkdir(self::$root . '/data');
        self::$server = new AdminServer([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => [
                'data' => self::$root . '/data',
                'data_incoming' => self::$root . '/data_incoming',
                'storage' => self::$root . '/storage',
            ],
            'admin' => ['trusted_proxies' => '127.0.0.1'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$server)) {
            self::$server->stop();
        }
        TempTree::removeIfAssigned(self::$root);
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM admin_audit');
        self::$pdo->exec('DELETE FROM search_logs');
        TempTree::remove(self::$root . '/storage');
        file_put_contents(self::$root . '/data/aliases.json', '[["ps5","پلی استیشن 5"]]');
        file_put_contents(self::$root . '/data/synonyms.json', '[["laptop","لپ تاپ","notebook"]]');
        $this->client = self::$server->client();
        self::assertSame(303, $this->client->login()['status']);
        $this->csrf = $this->client->csrf();
    }

    private function get(string $query): array
    {
        return $this->client->request('GET', '/admin.php?' . $query);
    }

    /** @param array<string, string> $form */
    private function post(string $query, array $form): array
    {
        return $this->client->request('POST', '/admin.php?' . $query, ['csrf' => $this->csrf] + $form);
    }

    /** One group saved through the form, as the editor posts it. */
    private function saveGroup(string $index, string $version, string $terms, array $extra = []): array
    {
        return $this->post('p=aliases', [
            'action' => 'alias_save', 'index' => $index, 'version' => $version, 'terms' => $terms,
        ] + $extra);
    }

    private function stored(): array
    {
        return AliasStore::fromConfig([
            'paths' => ['storage' => self::$root . '/storage', 'data' => self::$root . '/data'],
            'admin' => ['alias_backups' => 20],
        ])->read();
    }

    private function version(): string
    {
        return $this->stored()['version'];
    }

    private function auditRows(string $action): array
    {
        $stmt = self::$pdo->prepare('SELECT ip, action, detail FROM admin_audit WHERE action = ? ORDER BY id');
        $stmt->execute([$action]);

        return $stmt->fetchAll();
    }

    public function testTheFirstVisitSeedsStorageFromTheBundleAndListsTheGroups(): void
    {
        self::assertFileDoesNotExist(self::$root . '/storage/aliases.json');
        $page = $this->get('p=aliases');

        self::assertSame(200, $page['status']);
        self::assertFileExists(self::$root . '/storage/aliases.json');
        self::assertSame([['ps5', 'پلی استیشن 5']], $this->stored()['groups']);
        self::assertStringContainsString('پلی استیشن 5', $page['body']);
        self::assertStringContainsString('ps5', $page['body']);
        $deny = (string) file_get_contents(self::$root . '/storage/.htaccess');
        self::assertStringContainsString('Require all denied', $deny);
    }

    public function testSavingANewGroupWritesTheFileAuditsAndReadsBackInThePanel(): void
    {
        $this->get('p=aliases');
        $saved = $this->post('p=aliases', [
            'action' => 'alias_save', 'index' => '', 'version' => $this->version(),
            'terms' => "Xbox Series X\nاکس باکس\n",
        ]);

        self::assertSame(303, $saved['status']);
        self::assertSame([['ps5', 'پلی استیشن 5'], ['Xbox Series X', 'اکس باکس']], $this->stored()['groups']);
        $audit = $this->auditRows('alias_save');
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('precious', $audit[0]['detail']);
        self::assertNotNull(json_decode($audit[0]['detail']), 'the audit row is whole JSON, not cut mid-way');

        // The audit page shows a sentence, never the stored JSON.
        $page = $this->get('p=audit')['body'];
        self::assertStringNotContainsString('{&quot;', $page);
        self::assertStringNotContainsString('"mode"', $page);
        self::assertStringContainsString('گروه‌ها: 2', $page);
        self::assertStringContainsString('xbox series x', $page);
    }

    public function testAStaleFormIsRejectedAndTheFileKeepsTheOtherEditorsChange(): void
    {
        $this->get('p=aliases');
        $stale = $this->version();
        $this->saveGroup('', $stale, "alpha one\nbeta two");

        $rejected = $this->saveGroup('', $stale, "gamma one\ndelta two");

        self::assertSame(200, $rejected['status']);
        self::assertStringContainsString('تغییر کرده است', $rejected['body']);
        self::assertStringContainsString('gamma one', $rejected['body'], 'what was typed comes back in the form');
        self::assertCount(2, $this->stored()['groups']);
        self::assertCount(1, $this->auditRows('alias_save'));
    }

    public function testErrorsBlockTheSaveAndAreListedPerGroup(): void
    {
        $this->get('p=aliases');
        $before = $this->version();
        $response = $this->saveGroup('', $before, 'only-one-term');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('دست‌کم دو واژهٔ متفاوت', $response['body']);
        self::assertSame($before, $this->version());
        self::assertSame([], $this->auditRows('alias_save'));
    }

    public function testWarningsNeedAnExplicitConfirm(): void
    {
        $this->get('p=aliases');
        $form = ['action' => 'alias_save', 'index' => '', 'version' => $this->version(), 'terms' => "tv\nتلویزیون"];

        $first = $this->post('p=aliases', $form);
        self::assertSame(200, $first['status']);
        self::assertStringContainsString('کمتر از 3 نویسه', $first['body']);
        self::assertStringContainsString('name="confirm" value="1"', $first['body']);
        self::assertCount(1, $this->stored()['groups'], 'nothing saved yet');

        $second = $this->post('p=aliases', $form + ['confirm' => '1']);
        self::assertSame(303, $second['status']);
        self::assertCount(2, $this->stored()['groups']);
    }

    public function testEditingAndDeletingAGroup(): void
    {
        $this->get('p=aliases');
        $edit = $this->get('p=aliases&tab=groups&edit=0')['body'];
        self::assertStringContainsString('پلی استیشن 5', $edit);
        self::assertStringContainsString('<textarea name="terms"', $edit);

        $this->saveGroup('0', $this->version(), "ps5\nپلی استیشن 5\nپی اس فایو");
        self::assertSame([['ps5', 'پلی استیشن 5', 'پی اس فایو']], $this->stored()['groups']);

        $deleted = $this->post('p=aliases', [
            'action' => 'alias_delete', 'index' => '0', 'version' => $this->version(),
        ]);
        self::assertSame(303, $deleted['status']);
        self::assertSame([], $this->stored()['groups']);
        self::assertCount(2, $this->auditRows('alias_save'));

        $gone = $this->post('p=aliases', ['action' => 'alias_delete', 'index' => '7', 'version' => $this->version()]);
        self::assertSame(200, $gone['status']);
        self::assertStringContainsString('گروه انتخاب‌شده وجود ندارد', $gone['body']);
    }

    public function testCountsAreComputedOnDemandForOneGroup(): void
    {
        $this->get('p=aliases');
        $without = $this->get('p=aliases')['body'];
        self::assertStringNotContainsString('تعداد محصولاتی که هر واژه', $without);

        $with = $this->get('p=aliases&tab=groups&count=0')['body'];
        self::assertStringContainsString('تعداد محصولاتی که هر واژه', $with);
    }

    public function testHistoryListsBackupsDiffsThemAndRestores(): void
    {
        $this->get('p=aliases');
        $this->saveGroup('', $this->version(), "alpha one\nbeta two");

        $history = $this->get('p=aliases&tab=history')['body'];
        self::assertSame(1, preg_match('/aliases-\d{8}-\d{6}-\d{6}\.json/', $history, $m));
        $backup = $m[0];

        $diff = $this->get('p=aliases&tab=history&diff=' . rawurlencode($backup))['body'];
        self::assertStringContainsString('alpha one | beta two', $diff);

        $restored = $this->post('p=aliases&tab=history', [
            'action' => 'alias_restore', 'backup' => $backup, 'version' => $this->version(),
        ]);
        self::assertSame(303, $restored['status']);
        self::assertSame([['ps5', 'پلی استیشن 5']], $this->stored()['groups']);
        self::assertCount(1, $this->auditRows('alias_restore'));
        self::assertStringContainsString('بازگردانی', $this->get('p=audit')['body']);

        $forged = $this->post('p=aliases&tab=history', [
            'action' => 'alias_restore', 'backup' => '../aliases.json', 'version' => $this->version(),
        ]);
        self::assertSame(200, $forged['status']);
        self::assertStringContainsString('این نسخهٔ پشتیبان وجود ندارد', $forged['body']);
    }

    public function testTheAdvancedEditorValidatesSavesAndDownloads(): void
    {
        $this->get('p=aliases');
        $bad = $this->post('p=aliases&tab=advanced', [
            'action' => 'alias_raw', 'version' => $this->version(), 'json' => '[["a","b"],]',
        ]);
        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('فهرستی از گروه‌ها', $bad['body']);
        self::assertStringContainsString('[[&quot;a&quot;,&quot;b&quot;],]', $bad['body'], 'the text stays editable');

        $good = $this->post('p=aliases&tab=advanced', [
            'action' => 'alias_raw', 'version' => $this->version(), 'json' => '[["samsung","سامسونگ"]]',
        ]);
        self::assertSame(303, $good['status']);
        self::assertSame([['samsung', 'سامسونگ']], $this->stored()['groups']);

        $download = $this->get('p=aliases&download=1');
        self::assertStringContainsString('attachment', $download['headers']['content-disposition'] ?? '');
        self::assertSame([['samsung', 'سامسونگ']], json_decode($download['body'], true));
    }

    public function testPostsNeedTheCsrfTokenAndTheLogin(): void
    {
        $this->get('p=aliases');
        $noToken = $this->client->request('POST', '/admin.php?p=aliases', [
            'action' => 'alias_save', 'index' => '', 'version' => $this->version(), 'terms' => "a b\nc d",
        ]);
        self::assertSame(403, $noToken['status']);
        self::assertCount(1, $this->stored()['groups']);

        $anonymous = self::$server->client();
        foreach (['aliases', 'synonyms', 'tester'] as $page) {
            $response = $anonymous->request('GET', '/admin.php?p=' . $page);
            self::assertSame(303, $response['status'], $page);
            self::assertStringContainsString('p=login', $response['headers']['location'] ?? '');
        }
    }

    public function testTheSynonymsPageIsReadOnlyAndFindsGroupsOfATerm(): void
    {
        $page = $this->get('p=synonyms')['body'];
        self::assertStringContainsString('notebook', $page);

        $lookup = $this->get('p=synonyms&term=' . rawurlencode('لپ تاپ'))['body'];
        self::assertStringContainsString('گروه‌های مترادف کاتالوگ', $lookup);
        self::assertStringContainsString('edit=new&amp;prefill=', $lookup);

        $none = $this->get('p=synonyms&term=zzzz')['body'];
        self::assertStringContainsString('این واژه در هیچ گروهی نیست', $none);
        self::assertSame(405, $this->post('p=synonyms', [])['status'], 'no write action on this page');
    }

    public function testTheTesterShowsTierAndTitlesAndLeavesNoLogRow(): void
    {
        $page = $this->get('p=tester&q=' . rawurlencode('laptop'))['body'];

        self::assertStringContainsString('فقط کلمه‌ای', $page);
        self::assertStringContainsString('<table>', $page);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM search_logs')->fetchColumn());

        $empty = $this->get('p=tester&q=')['body'];
        self::assertStringContainsString('عبارتی را بنویسید', $empty);
    }

    public function testEveryPageCarriesTheThemeSwitchIncludingTheOnesWithoutTheSidebar(): void
    {
        foreach (['status', 'ops', 'aliases', 'synonyms', 'tester', 'analytics', 'logs', 'audit', 'help'] as $page) {
            $html = $this->get('p=' . $page)['body'];
            self::assertSame(1, substr_count($html, 'id="theme"'), "one theme switch on {$page}");
        }
        $login = self::$server->client()->request('GET', '/admin.php?p=login')['body'];
        self::assertSame(1, substr_count($login, 'id="theme"'), 'the login page has it too');
        $refusal = self::$server->client(false)->request('GET', '/admin.php?p=status')['body'];
        self::assertSame(1, substr_count($refusal, 'id="theme"'), 'and so does a refusal page');
    }

    public function testDatesShowTheJalaliDateBesideTheStoredOne(): void
    {
        self::$pdo->exec(
            "INSERT INTO admin_audit (ts, ip, action, detail)"
            . " VALUES ('2026-10-25 14:03:07', '203.0.113.9', 'logout', '')"
        );
        $page = $this->get('p=audit')['body'];

        self::assertStringContainsString('2026-10-25 14:03', $page);
        self::assertStringNotContainsString('14:03:07', $page);
        self::assertStringContainsString('1405/08/03', $page);
    }

    public function testStatusAndOpsShowTheShortDateOfAnIsoBuildTime(): void
    {
        file_put_contents(self::$root . '/data/meta.json', (string) json_encode([
            'model' => 'm', 'dim' => 4, 'normalization_version' => 4, 'count' => 3,
            'built_at' => '2026-10-24T17:39:53.561934+00:00',
        ]));
        try {
            self::$pdo->exec(
                "INSERT INTO search_logs (ts, raw_q, normalized_q, had_vector, result_count, top_ids, latency_ms, tier)"
                . " VALUES ('2026-10-25 14:03:07', 'x', 'x', 0, 1, '[]', 5, 'keyword_only')"
            );
            $logs = $this->get('p=logs')['body'];
            self::assertStringContainsString('<bdi class="ltr">2026-10-25 14:03</bdi>', $logs);
            self::assertStringNotContainsString('14:03:07', $logs);
            foreach (['status', 'ops'] as $page) {
                $html = $this->get('p=' . $page)['body'];
                self::assertStringContainsString('<bdi class="ltr">2026-10-24 17:39</bdi>', $html, $page);
                self::assertStringContainsString('1405/08/02', $html, $page);
                foreach (['T17:39', '.561934', '+00:00'] as $leftover) {
                    self::assertStringNotContainsString($leftover, $html, "{$page}: {$leftover}");
                }
            }
        } finally {
            unlink(self::$root . '/data/meta.json');
        }
    }

    public function testAnalyticsLivesInsideTheShellWithoutCartOrOrderCards(): void
    {
        $page = $this->get('p=analytics')['body'];

        self::assertStringContainsString('class="shell"', $page);
        self::assertStringContainsString('<nav aria-label=', $page);
        self::assertStringNotContainsString('tile_cart', $page);
        self::assertStringNotContainsString('نرخ افزودن به سبد', $page);
        self::assertStringNotContainsString('tile_order', $page);
        // The JSON stays as it was: the rates are still reported for a consumer that wants them.
        $json = json_decode($this->get('p=analytics&format=json')['body'], true);
        self::assertArrayHasKey('engagement', $json);
    }
}
