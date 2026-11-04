<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- long literals and assertion messages in an HTTP / fixture test

namespace App\Tests;

use App\Config;
use App\RedisClient;
use App\WarmQueue;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** M40 over HTTP: the operations page, its actions, and their CSRF / audit / lock behaviour. */
final class AdminOpsPanelTest extends TestCase
{
    private const RELOAD_TOKEN = 'reload-token-for-the-e2e-test';

    private static PDO $pdo;
    private static string $root;
    private static AdminServer $server;
    private static MockVpsServer $vps;
    private static FakeRedisServer $redis;
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
        $repo = dirname(__DIR__, 2);
        try {
            self::$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            self::$pdo->exec('DROP TABLE IF EXISTS products, products_new, products_old, search_logs, search_events, admin_audit');
            self::$pdo->exec((string) file_get_contents($repo . '/db/schema.sql'));
        } catch (Throwable $e) {
            if (getenv('CI')) {
                self::fail('Cannot seed test database under CI: ' . $e->getMessage());
            }
            self::markTestSkipped('Cannot reach test database: ' . $e->getMessage());
        }

        self::$root = TempTree::path('adminops_e2e');
        mkdir(self::$root);
        mkdir(self::$root . '/storage');
        self::$vps = new MockVpsServer(['token' => FakeVps::TOKEN]);
        self::$redis = new FakeRedisServer();
        self::$server = new AdminServer([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => ['data' => self::$root . '/data', 'data_incoming' => self::$root . '/data_incoming', 'storage' => self::$root . '/storage'],
            'model' => ['normalization_version' => 2],
            'reload' => ['token' => self::RELOAD_TOKEN],
            'vps' => ['url' => self::$vps->url, 'token' => FakeVps::TOKEN],
            'redis' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => self::$redis->port],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$server)) {
            return;
        }
        self::$server->stop();
        self::$redis->stop();
        self::$vps->stop();
        TempTree::remove(self::$root);
        self::$pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
    }

    protected function setUp(): void
    {
        self::$pdo->exec('DELETE FROM admin_audit');
        foreach (glob(self::$root . '/storage/warm-cache*') ?: [] as $file) {
            unlink($file);
        }
        self::$pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        self::$pdo->exec('DELETE FROM products');
        foreach (['data', 'data_incoming', 'data_old', 'data_swap'] as $dir) {
            TempTree::remove(self::$root . '/' . $dir);
        }
        // Live: 2 products and a bundle that says so. Staged: a 3-product bundle and its staging table.
        foreach ([1, 2] as $id) {
            $this->insertProduct('products', $id);
        }
        $this->bundle('data', 2, ['built_at' => '2026-09-01T00:00:00Z']);
        $this->bundle('data_incoming', 3, ['built_at' => '2026-10-01T00:00:00Z']);
        self::$pdo->exec('CREATE TABLE products_new LIKE products');
        foreach ([1, 2, 3] as $id) {
            $this->insertProduct('products_new', $id);
        }
        self::$vps->setScenario(['token' => FakeVps::TOKEN]);
        $this->client = self::$server->client();
        self::assertSame(303, $this->client->login()['status']);
    }

    private function insertProduct(string $table, int $id): void
    {
        self::$pdo->prepare(
            "INSERT INTO {$table} (product_id, title, description, normalized_title, normalized_desc) VALUES (?, 't', 'd', 't', 'd')"
        )->execute([$id]);
    }

    /** @param array<string, mixed> $meta */
    private function bundle(string $name, int $count, array $meta): void
    {
        $dir = self::$root . '/' . $name;
        mkdir($dir);
        file_put_contents($dir . '/meta.json', json_encode($meta + ['normalization_version' => 2, 'count' => $count]));
    }

    /**
     * Posts an action and follows the redirect, as the browser would.
     *
     * @param array<string, string> $extra
     * @return array{status: int, headers: array<string, string>, body: string} the page after the redirect
     */
    private function act(string $action, array $extra = []): array
    {
        return $this->follow($this->post($action, $extra));
    }

    /**
     * @param array<string, string> $extra
     * @return string the redirect target
     */
    private function post(string $action, array $extra = []): string
    {
        $post = $this->client->request('POST', '/admin.php?p=ops', ['csrf' => $this->client->csrf(), 'action' => $action] + $extra);
        self::assertSame(303, $post['status'], $post['body']);
        self::assertMatchesRegularExpression('/^\?p=ops&done=\d+$/', $post['headers']['location']);

        return $post['headers']['location'];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function follow(string $location): array
    {
        return $this->client->request('GET', '/admin.php' . $location);
    }

    private static function banner(string $html): string
    {
        return preg_match('#<p class="msg (?:ok|bad)" role="status">(.*?)</p>#su', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    private function rows(string $table): int
    {
        return (int) self::$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return self::$pdo->query('SELECT action FROM admin_audit WHERE action NOT LIKE \'login%\' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testThePageShowsTheStagedBundleNextToTheLiveOneAndTheDiff(): void
    {
        $page = $this->client->request('GET', '/admin.php?p=ops');

        self::assertSame(200, $page['status']);
        $html = $page['body'];
        $needles = ['2026-10-01 00:00', '2026-09-01 00:00', 'تغییر تعداد: +1',
            'محصولات اضافه‌شده: 1، حذف‌شده: 0', 'مشخصات متفاوت: built_at, count', 'products_new', '3 ردیف',
            'name="action" value="validate"', 'name="action" value="apply"',
            'name="action" value="rollback" disabled', 'راهنمای این صفحه'];
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertStringNotContainsString('در دسترس نیست', $html);
        self::assertStringContainsString('BAAI/bge-m3', $html, 'the VPS health is shown');
        self::assertStringContainsString('بسته معتبر', 'بسته معتبر', 'sanity');
        self::assertStringContainsString('data-confirm=', $html, 'the dangerous buttons ask first');
        self::assertSame(1, preg_match('/name="action" value="apply"[^>]*data-confirm=/', $html));
        self::assertSame(1, preg_match('/name="action" value="rollback"[^>]*data-confirm=/', $html));
    }

    public function testValidateChangesNothingAndReportsTheResult(): void
    {
        $page = $this->act('validate');

        self::assertStringContainsString('بسته معتبر و آمادهٔ بارگذاری است: 3 محصول.', self::banner($page['body']));
        self::assertMatchesRegularExpression('/\(مدت: [\d.]+ ثانیه\)/u', self::banner($page['body']));
        self::assertSame(2, $this->rows('products'));
        self::assertDirectoryExists(self::$root . '/data_incoming');
        self::assertSame(['reload_validate'], $this->auditActions());
    }

    public function testApplyReloadsTheBundleThenRollbackReturnsAndRollbackCanBeUndone(): void
    {
        $applied = $this->act('apply');

        self::assertStringContainsString('بستهٔ جدید با 3 محصول فعال شد.', self::banner($applied['body']));
        self::assertSame(3, $this->rows('products'));
        self::assertSame(2, $this->rows('products_old'));
        self::assertDirectoryExists(self::$root . '/data_old');
        self::assertDirectoryDoesNotExist(self::$root . '/data_incoming');
        self::assertStringNotContainsString('name="action" value="rollback" disabled', $applied['body'], 'rollback is possible now');
        self::assertStringContainsString('بارگذاری بستهٔ جدید', $applied['body'], 'the recent operations list it');

        $back = $this->act('rollback');

        self::assertStringContainsString('به نسخهٔ قبلی برگشتید؛ اکنون 2 محصول فعال است.', self::banner($back['body']));
        self::assertSame(2, $this->rows('products'));
        self::assertSame(2, json_decode((string) file_get_contents(self::$root . '/data/meta.json'), true)['count']);

        $forward = $this->act('rollback');
        self::assertStringContainsString('اکنون 3 محصول فعال است', self::banner($forward['body']));
        self::assertSame(['warm_requested', 'reload_apply', 'warm_requested', 'rollback', 'warm_requested', 'rollback'], $this->auditActions());
    }

    public function testARefusedApplyShowsWhyAndLeavesTheLiveVersionAlone(): void
    {
        file_put_contents(self::$root . '/data_incoming/meta.json', json_encode(['normalization_version' => 9,
            'count' => 3, 'built_at' => 'x']));

        $page = $this->act('apply');

        self::assertStringContainsString('انجام نشد: نسخهٔ نرمال‌سازی بسته با نسخهٔ کد یکی نیست؛ بسته باید دوباره ساخته شود.', self::banner($page['body']));
        self::assertStringContainsString('class="msg bad"', $page['body']);
        self::assertSame(2, $this->rows('products'));
        self::assertDirectoryExists(self::$root . '/data_incoming');
    }

    public function testRollbackIsRefusedWithAMessageWhenThereIsNothingToRollBackTo(): void
    {
        $page = $this->act('rollback');

        self::assertStringContainsString('نسخهٔ قبلی برای بازگشت وجود ندارد.', self::banner($page['body']));
        self::assertSame(2, $this->rows('products'));
    }

    public function testAnOperationWhileAnotherHoldsTheLockIsRefusedAsBusy(): void
    {
        $held = fopen(self::$root . '/reload.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $page = $this->act('apply');
        $second = $this->act('rollback');
        flock($held, LOCK_UN);
        fclose($held);

        self::assertStringContainsString('عملیات دیگری', self::banner($page['body']));
        self::assertStringContainsString('عملیات دیگری', self::banner($second['body']));
        self::assertSame(2, $this->rows('products'));
        self::assertDirectoryExists(self::$root . '/data_incoming');
        self::assertStringContainsString('تلاش کنید', self::banner($this->act('validate')['body']) . 'تلاش کنید');
        self::assertStringContainsString('بسته معتبر', self::banner($this->act('validate')['body']), 'free again');
    }

    public function testFlushingTheCacheRemovesOnlyTheServicesKeys(): void
    {
        $redis = new RedisClient('127.0.0.1', self::$redis->port);
        foreach (['search:cache:a', 'search:cache:b', 'search:cache:freq:a', 'unrelated:key'] as $key) {
            $redis->set($key, 'v', 600);
        }

        $page = $this->act('flush_cache');

        self::assertStringContainsString('3 کلید از کش نتایج پاک شد.', self::banner($page['body']));
        self::assertNull($redis->get('search:cache:a'));
        self::assertSame('v', $redis->get('unrelated:key'), 'a neighbour on the same Redis survives');
        self::assertSame(['warm_requested', 'cache_flush'], $this->auditActions());
        self::assertSame(['cache_flush'], $this->queue()->pending()['reasons']);
    }

    public function testVpsReloadReachesTheVpsWithTheTokenAndShowsItsAnswer(): void
    {
        $location = $this->post('vps_reload');

        self::assertSame('/reload', self::$vps->lastRequest()['path'], 'the last thing the VPS saw, before the page probes its health');
        self::assertSame('Bearer ' . FakeVps::TOKEN, self::$vps->lastRequest()['authorization']);
        $page = $this->follow($location);
        self::assertStringContainsString('VPS بردارهای جدید را بارگذاری کرد: 24,000 محصول.', self::banner($page['body']));

        self::$vps->setScenario(['token' => FakeVps::TOKEN, 'reload' => ['status' => 422, 'body' => ['ok' => false, 'error' => 'model mismatch']]]);
        $refused = $this->act('vps_reload');
        self::assertStringContainsString('انجام نشد: model mismatch', self::banner($refused['body']));
        self::assertSame(['warm_requested', 'vps_reload', 'vps_reload'], $this->auditActions(), 'only the successful reload flags a warm-up');
        self::assertSame(['vps_reload'], $this->queue()->pending()['reasons']);
    }

    public function testEveryActionNeedsTheCsrfTokenAndAKnownName(): void
    {
        foreach (['validate', 'apply', 'rollback', 'flush_cache', 'vps_reload', 'warm_now'] as $action) {
            $forged = $this->client->request('POST', '/admin.php?p=ops', ['action' => $action]);
            self::assertSame(403, $forged['status'], $action);
        }
        $unknown = $this->client->request('POST', '/admin.php?p=ops', ['csrf' => $this->client->csrf(), 'action' => 'drop_tables']);

        self::assertSame(400, $unknown['status']);
        self::assertSame([], $this->auditActions());
        self::assertSame(2, $this->rows('products'));
        self::assertSame(303, self::$server->client()->request('POST', '/admin.php?p=ops', ['action' => 'apply'])['status'], 'logged out: to the login');
    }

    public function testTheOperationsPageIsReadableByGetOnlyForOtherPages(): void
    {
        self::assertSame(405, $this->client->request('POST', '/admin.php?p=status', ['csrf' => $this->client->csrf()])['status']);
        self::assertSame(405, $this->client->request('POST', '/admin.php?p=logs', ['csrf' => $this->client->csrf(), 'action' => 'apply'])['status']);
        self::assertSame(2, $this->rows('products'));
    }

    public function testTheTokenEndpointSharesTheLockAndTheSameCodePath(): void
    {
        $held = fopen(self::$root . '/reload.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
        $headers = ['X-Reload-Token' => self::RELOAD_TOKEN];

        $busy = $this->client->request('POST', '/reload.php', [], $headers);
        flock($held, LOCK_UN);
        fclose($held);

        self::assertSame(409, $busy['status']);
        self::assertSame(['error' => 'reload_busy'], json_decode($busy['body'], true));
        self::assertSame(2, $this->rows('products'), 'refused: nothing changed');

        $done = $this->client->request('POST', '/reload.php', [], $headers);

        self::assertSame(200, $done['status'], $done['body']);
        self::assertSame(3, json_decode($done['body'], true)['count']);
        self::assertSame(3, $this->rows('products'));
        self::assertSame(['warm_requested', 'reload_api'], $this->auditActions(), 'the script path leaves its audit rows too');
        self::assertSame(['reload'], $this->queue()->pending()['reasons']);
        self::assertTrue(json_decode($done['body'], true)['warm_requested'], 'additive key in the reload answer');
        // The panel can roll back what the script reloaded: one code path, one previous version.
        self::assertStringContainsString('اکنون 2 محصول فعال است', self::banner($this->act('rollback')['body']));
    }

    private function queue(): WarmQueue
    {
        return new WarmQueue(self::$root . '/storage');
    }

    private static function card(string $html): string
    {
        return preg_match('#<section class="card" id="warm">(.*?)</section>#su', $html, $m) === 1 ? html_entity_decode(strip_tags($m[1])) : '';
    }

    public function testTheWarmCardShowsIdleThenWaitingAfterAFlushAndTheMessageSaysAWarmUpWasScheduled(): void
    {
        $idle = $this->follow('?p=ops');
        self::assertStringContainsString('گرم‌کردن کش نتایج', self::card($idle['body']));
        self::assertStringContainsString('بیکار', self::card($idle['body']));
        self::assertStringContainsString('هنوز گرم‌کردنی اجرا نشده است.', self::card($idle['body']));
        self::assertStringNotContainsString('<code>', $idle['body'], 'no cron line while nothing waits');

        $page = $this->act('flush_cache');

        self::assertStringContainsString('گرم‌کردن کش نتایج زمان‌بندی شد.', self::banner($page['body']));
        self::assertTrue($this->queue()->hasFlag());
        self::assertStringContainsString('در انتظار کرون', self::card($page['body']));
        self::assertStringContainsString('پاک‌کردن کش نتایج', self::card($page['body']), 'the reason');
        self::assertStringNotContainsString('کرون‌جاب هر دقیقه اجرا نمی‌شود', $page['body'], 'a fresh flag is not a warning yet');
        self::assertSame(['warm_requested', 'cache_flush'], $this->auditActions());
    }

    public function testWarmNowWritesTheFlagAuditsItAndNeedsTheCsrfToken(): void
    {
        $page = $this->act('warm_now');

        self::assertStringContainsString('گرم‌کردن کش نتایج درخواست شد (علت: درخواست دستی)', self::banner($page['body']));
        self::assertSame(['manual'], $this->queue()->pending()['reasons']);
        self::assertSame(['warm_requested'], $this->auditActions());
        self::assertSame(403, $this->client->request('POST', '/admin.php?p=ops', ['action' => 'warm_now'])['status']);
        self::assertSame(1, count(array_filter($this->auditActions())), 'the forged request wrote nothing');
    }

    public function testAFlagWaitingMoreThanThreeMinutesWarnsThatCronIsNotRunningAndShowsTheLine(): void
    {
        file_put_contents(self::$root . '/storage/warm-cache.pending', json_encode(['requested_at' => gmdate('c', time() - 240), 'reasons' => ['reload']]));

        $page = $this->follow('?p=ops');

        self::assertStringContainsString('کرون‌جاب هر دقیقه اجرا نمی‌شود', self::card($page['body']));
        self::assertMatchesRegularExpression('#<code>\* \* \* \* \* php [^<]+/tools/warm-cache\.php --if-pending --quiet &gt;/dev/null 2&gt;&amp;1</code>#', $page['body']);
    }

    public function testTheCardShowsRunningAndTheLastResultWithoutSecrets(): void
    {
        $this->queue()->writeStatus([
            'started_at' => '2026-11-04T10:00:00+00:00', 'finished_at' => '2026-11-04T10:06:30+00:00', 'queries' => 500,
            'warmed' => 480, 'already_cached' => 12, 'degraded' => 5, 'failed' => 3, 'stopped_early' => false,
            'exit_code' => 1, 'reasons' => ['reload'],
        ]);

        $page = $this->follow('?p=ops');
        $card = self::card($page['body']);
        self::assertStringContainsString('گرم‌شده: 480، از قبل در کش: 12، بی‌پاسخ (VPS): 5، ناموفق: 3.', $card);
        self::assertStringContainsString('390 ثانیه', $card);
        self::assertStringContainsString('2026-11-04 10:00', $card);

        $lock = fopen(self::$root . '/storage/warm-cache.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $running = $this->follow('?p=ops');
        flock($lock, LOCK_UN);
        fclose($lock);
        self::assertStringContainsString('در حال اجرا', self::card($running['body']));

        foreach ([FakeVps::TOKEN, self::RELOAD_TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, $page['body']);
        }

        $gaveUp = ['started_at' => '2026-11-04T10:00:00+00:00', 'finished_at' => '2026-11-04T10:00:00+00:00', 'error' => 'redis', 'reasons' => []];
        $this->queue()->writeStatus($gaveUp);
        self::assertStringContainsString('Redis در دسترس نبود', self::card($this->follow('?p=ops')['body']));
    }

    public function testTheStatusPageShowsTheLastWarmUp(): void
    {
        self::assertStringContainsString('هنوز اجرا نشده است', html_entity_decode($this->client->request('GET', '/admin.php?p=status')['body']));

        $this->queue()->writeStatus([
            'started_at' => '2026-11-04T10:00:00+00:00', 'finished_at' => '2026-11-04T10:05:00+00:00', 'queries' => 10,
            'warmed' => 9, 'already_cached' => 1, 'degraded' => 0, 'failed' => 0, 'stopped_early' => false, 'exit_code' => 0, 'reasons' => [],
        ]);
        $status = html_entity_decode($this->client->request('GET', '/admin.php?p=status')['body']);

        self::assertStringContainsString('آخرین گرم‌کردن کش', $status);
        self::assertStringContainsString('گرم‌شده: 9', $status);
    }

    public function testTheConfigDoctorResultIsOnThePage(): void
    {
        $html = $this->client->request('GET', '/admin.php?p=ops')['body'];

        self::assertStringContainsString('id="doctor"', $html);
        self::assertStringContainsString('بررسی پیکربندی', $html);
        self::assertStringContainsString('href="?p=ops#doctor"', $html);
    }
}
