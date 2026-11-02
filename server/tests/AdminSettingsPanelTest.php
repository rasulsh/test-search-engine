<?php

declare(strict_types=1);

namespace App\Tests;

use App\AdminSettingsViews;
use App\Config;
use App\SettingsStore;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** M46 over HTTP: the settings page, its dry run, saves, history and the guarantees around credentials. */
final class AdminSettingsPanelTest extends TestCase
{
    private const SECRETS = [
        'vps_token' => 'tok-live-9f8e7d6c5b4a-VPS',
        'redis_auth' => 'redis-pass-Zq81xLmN-AUTH',
        'debug_token' => 'dbg-7712aa90ccdd-DEBUG',
        'reload_token' => 'rld-b3b9c8d7e6f5-RELOAD',
        'dsn' => 'search_test',
    ];
    private const SECRET_KEYS = ['vps.token', 'redis.auth', 'debug.token', 'reload.token', 'db.password', 'db.dsn',
        'db.user', 'admin.password_hash', 'password_hash'];

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

        self::$root = TempTree::make('settings_panel');
        mkdir(self::$root . '/data');
        self::$server = new AdminServer([
            'db' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
            'paths' => [
                'data' => self::$root . '/data',
                'data_incoming' => self::$root . '/data_incoming',
                'storage' => self::$root . '/storage',
            ],
            'admin' => ['trusted_proxies' => '127.0.0.1'],
            'vps' => ['token' => self::SECRETS['vps_token']],
            'redis' => ['auth' => self::SECRETS['redis_auth']],
            'debug' => ['token' => self::SECRETS['debug_token']],
            'reload' => ['token' => self::SECRETS['reload_token']],
            'search' => ['tail_cap' => 12],
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
        TempTree::remove(self::$root . '/storage');
        $this->client = self::$server->client();
        self::assertSame(303, $this->client->login()['status']);
        $this->csrf = $this->client->csrf();
    }

    private function get(string $query): array
    {
        return $this->client->request('GET', '/admin.php?' . $query);
    }

    /** @param array<string, mixed> $form */
    private function post(string $query, array $form): array
    {
        return $this->client->request('POST', '/admin.php?' . $query, ['csrf' => $this->csrf] + $form);
    }

    private function version(string $html): string
    {
        return preg_match('/name="version" value="([0-9a-f]*)"/', $html, $m) === 1 ? $m[1] : 'NOT-FOUND';
    }

    /**
     * Posts the settings form as the browser would: every editable field, the given ones changed.
     *
     * @param array<string, string> $change
     * @param array<string, mixed> $extra
     */
    private function submit(string $action, array $change, array $extra = [], ?string $version = null): array
    {
        $version ??= $this->version($this->get('p=settings')['body']);

        return $this->post('p=settings', [
            'action' => $action, 'version' => $version, 'v' => $change,
        ] + $extra);
    }

    private function store(): SettingsStore
    {
        return new SettingsStore(self::$root . '/storage/settings.json', 20);
    }

    private function auditRows(string $action): array
    {
        $stmt = self::$pdo->prepare('SELECT ip, action, detail FROM admin_audit WHERE action = ? ORDER BY id');
        $stmt->execute([$action]);

        return $stmt->fetchAll();
    }

    public function testTheLoginAndTheCsrfTokenAreRequired(): void
    {
        $anonymous = self::$server->client()->request('GET', '/admin.php?p=settings');
        self::assertSame(303, $anonymous['status']);
        self::assertStringContainsString('p=login', $anonymous['headers']['location']);

        $noCsrf = $this->client->request('POST', '/admin.php?p=settings', [
            'action' => 'settings_save', 'v' => ['search.tail_cap' => '30'],
        ]);
        self::assertSame(403, $noCsrf['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');

        $anonymousPost = self::$server->client()->request('POST', '/admin.php?p=settings', [
            'csrf' => 'x', 'action' => 'settings_save', 'v' => ['search.tail_cap' => '30'],
        ]);
        self::assertContains($anonymousPost['status'], [303, 403]);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
    }

    public function testTheFormShowsDefaultsConfigPhpValuesAndTheirSources(): void
    {
        $page = $this->get('p=settings');
        self::assertSame(200, $page['status']);
        $html = $page['body'];

        // every editable key has a field; the code default and a config.php override are told apart
        foreach (array_keys(\App\SettingRules::table()) as $path) {
            self::assertStringContainsString('name="v[' . $path . ']"', $html, $path);
        }
        self::assertMatchesRegularExpression('/name="v\[search\.tail_cap\]" value="12"/', $html);
        self::assertMatchesRegularExpression('/name="v\[search\.title_weight\]" value="10"/', $html);
        self::assertStringContainsString('config.php', $html);
        self::assertStringContainsString('پیش‌فرض کد', $html);
        // the locked section lists read-only keys with a badge, as text, not inputs
        self::assertStringContainsString('db.products_table', $html);
        self::assertStringContainsString('paths.storage', $html);
        self::assertStringNotContainsString('name="v[db.products_table]"', $html);
        self::assertStringNotContainsString('name="v[paths.storage]"', $html);
        self::assertStringNotContainsString('name="v[admin.username]"', $html);
        // the page works without JavaScript: a plain POST form, the filter box hidden until a script shows it
        self::assertStringContainsString('<form method="post" action="?p=settings"', $html);
        self::assertMatchesRegularExpression('/id="settings-filter" hidden/', $html);
        self::assertStringContainsString('<details class="card sgroup" open>', $html);
    }

    public function testACheckIsADryRunThatShowsTheDiffAndWritesNothing(): void
    {
        $result = $this->submit('settings_check', ['search.title_weight' => '14', 'search.tail_cap' => '12']);

        self::assertSame(200, $result['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));
        self::assertStringContainsString('search.title_weight', $result['body']);
        self::assertMatchesRegularExpression('/>10<\/bdi>.*?>14<\/bdi>/s', $result['body']);
        // an unchanged field (the config.php value) is not in the diff
        self::assertDoesNotMatchRegularExpression(
            '/<td><bdi class="ltr">search\.tail_cap<\/bdi><\/td><td>/',
            $result['body']
        );
        // what was typed stays in the form
        self::assertMatchesRegularExpression('/name="v\[search\.title_weight\]" value="14"/', $result['body']);
    }

    public function testErrorsBlockTheSaveAndTheTypedValueStaysNextToItsField(): void
    {
        $result = $this->submit('settings_save', [
            'search.entity_demote' => '1.7', 'search.default_limit' => '2.5', 'search.title_weight' => '14',
        ]);

        self::assertSame(200, $result['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));
        self::assertMatchesRegularExpression('/name="v\[search\.entity_demote\]" value="1.7"/', $result['body']);
        self::assertMatchesRegularExpression('/name="v\[search\.default_limit\]" value="2.5"/', $result['body']);
        self::assertMatchesRegularExpression('/name="v\[search\.title_weight\]" value="14"/', $result['body']);
        self::assertStringContainsString('class="msg bad"', $result['body']);
        self::assertStringContainsString('عدد صحیح', $result['body']);
    }

    public function testWarningsNeedAnExplicitConfirmation(): void
    {
        $change = ['search.tail_cap' => '150'];
        $first = $this->submit('settings_save', $change);
        self::assertSame(200, $first['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertStringContainsString('name="confirm"', $first['body']);
        self::assertStringContainsString('class="msg warn"', $first['body']);

        $confirmed = $this->submit('settings_save', $change, ['confirm' => '1']);
        self::assertSame(303, $confirmed['status']);
        self::assertSame(['search.tail_cap' => 150], $this->store()->read()['values']);
    }

    public function testASuccessfulSaveWritesTheFileAuditsAndRedirectsWithTheCacheNotice(): void
    {
        $result = $this->submit('settings_save', ['search.title_weight' => '14', 'logs.page_size' => '25']);

        self::assertSame(303, $result['status']);
        self::assertStringContainsString('saved=saved', $result['headers']['location']);
        self::assertStringContainsString('flush=1', $result['headers']['location']);
        self::assertSame(['logs.page_size' => 25, 'search.title_weight' => 14.0], $this->store()->read()['values']);

        $rows = $this->auditRows('settings_save');
        self::assertCount(1, $rows);
        $detail = json_decode($rows[0]['detail'], true);
        self::assertEqualsCanonicalizing(['logs.page_size', 'search.title_weight'], $detail['keys']);
        self::assertSame(2, $detail['total']);
        self::assertStringNotContainsString('14', $rows[0]['detail']);

        $page = $this->get(substr($result['headers']['location'], 1));
        self::assertStringContainsString('class="msg ok"', $page['body']);
        self::assertStringContainsString('href="?p=ops"', $page['body']);
        self::assertMatchesRegularExpression('/name="v\[search\.title_weight\]" value="14"/', $page['body']);
        // the panel layer is what the app now uses, and the status table says so
        $status = $this->get('p=status')['body'];
        self::assertStringContainsString('پنل (settings.json)', $status);

        $audit = $this->get('p=audit')['body'];
        self::assertStringContainsString('ذخیرهٔ تنظیمات', $audit);
        self::assertStringContainsString('search.title_weight', $audit);
    }

    public function testNoCacheNoticeWhenNothingThatAffectsResultsChanged(): void
    {
        $result = $this->submit('settings_save', ['logs.page_size' => '25']);
        self::assertSame(303, $result['status']);
        self::assertStringNotContainsString('flush=1', $result['headers']['location']);
    }

    public function testResettingAKeyRemovesItFromTheFile(): void
    {
        $this->submit('settings_save', ['search.title_weight' => '14', 'logs.page_size' => '25']);
        $page = $this->get('p=settings');
        self::assertStringContainsString('name="r[search.title_weight]"', $page['body']);
        self::assertStringNotContainsString('name="r[search.desc_weight]"', $page['body']);

        $reset = $this->post('p=settings', [
            'action' => 'settings_save', 'version' => $this->version($page['body']),
            'v' => ['search.title_weight' => '14'], 'r' => ['search.title_weight' => '1'],
        ]);
        self::assertSame(303, $reset['status']);
        self::assertSame(['logs.page_size' => 25], $this->store()->read()['values']);
        // setting a key back to the inherited value, or blank, removes it as well
        $this->submit('settings_save', ['logs.page_size' => '']);
        self::assertSame([], $this->store()->read()['values']);
    }

    public function testAKeyEqualToTheConfigPhpValueIsNotStored(): void
    {
        $this->submit('settings_save', ['search.tail_cap' => '12', 'logs.page_size' => '25']);
        self::assertSame(['logs.page_size' => 25], $this->store()->read()['values']);
    }

    public function testAStaleFormIsRefused(): void
    {
        $page = $this->get('p=settings');
        $version = $this->version($page['body']);
        $this->submit('settings_save', ['logs.page_size' => '25']);

        $late = $this->submit('settings_save', ['logs.page_size' => '30'], [], $version);
        self::assertSame(200, $late['status']);
        self::assertStringContainsString('تغییر کرده است', $late['body']);
        self::assertSame(['logs.page_size' => 25], $this->store()->read()['values']);
    }

    /**
     * The per-row action as the page's script sends it.
     *
     * @param array<string, string> $fields
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>}
     */
    private function apply(string $key, string $value, ?string $version = null, array $fields = []): array
    {
        $version ??= $this->store()->read()['version'];
        $response = $this->post('p=settings', [
            'action' => 'settings_apply_one', 'key' => $key, 'value' => $value, 'version' => $version,
        ] + $fields);
        $response['json'] = json_decode($response['body'], true) ?? [];

        return $response;
    }

    public function testApplyOneNeedsTheLoginAndTheCsrfTokenAndAcceptsTheHeader(): void
    {
        $form = ['action' => 'settings_apply_one', 'key' => 'logs.page_size', 'value' => '25', 'version' => ''];
        $anonymous = self::$server->client()->request('POST', '/admin.php?p=settings', ['csrf' => 'x'] + $form);
        self::assertContains($anonymous['status'], [303, 403]);
        self::assertSame(403, $this->client->request('POST', '/admin.php?p=settings', $form)['status']);
        self::assertSame(
            403,
            $this->client->request('POST', '/admin.php?p=settings', $form, ['X-CSRF-Token' => 'wrong'])['status']
        );
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');

        $viaHeader = $this->client->request('POST', '/admin.php?p=settings', $form, ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $viaHeader['status']);
        self::assertSame(true, json_decode($viaHeader['body'], true)['ok']);
        self::assertSame(['logs.page_size' => 25], $this->store()->read()['values']);
    }

    public function testApplyOneAnswersJsonThatIsNeverCached(): void
    {
        $r = $this->apply('logs.page_size', '25');
        self::assertSame(200, $r['status']);
        self::assertStringStartsWith('application/json', $r['headers']['content-type']);
        self::assertStringContainsString('no-store', $r['headers']['cache-control']);
        $json = $r['json'];
        self::assertTrue($json['ok']);
        self::assertSame('saved', $json['status']);
        self::assertSame('logs.page_size', $json['key']);
        self::assertSame('25', $json['value']);
        self::assertSame('panel', $json['source']);
        self::assertSame('پنل', $json['source_label']);
        self::assertSame('50', $json['default']);
        self::assertFalse($json['needs_confirm']);
        self::assertFalse($json['flush']);
        self::assertSame([], $json['errors']);
        self::assertSame($this->store()->read()['version'], $json['version']);
        self::assertStringContainsString('ذخیره شد', $json['message']);
    }

    /** @return array<string, array<int, mixed>> */
    public static function applyOneRefusals(): array
    {
        return [
            'a secret' => ['vps.token', 'attacker'],
            'a database credential' => ['db.dsn', 'mysql:host=evil'],
            'a read-only table name' => ['db.products_table', 'x'],
            'a read-only path' => ['paths.storage', '/tmp/x'],
            'a read-only admin key' => ['admin.username', 'mallory'],
            'the normalization version' => ['model.normalization_version', '9'],
            'an unknown key' => ['search.nope', '1'],
            'an empty key' => ['', '1'],
            'a huge value' => ['search.stopwords', str_repeat('x', 9000)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('applyOneRefusals')]
    public function testApplyOneRefusesWhatThePageDoesNotEditWithoutEchoingIt(string $key, string $value): void
    {
        $r = $this->apply($key, $value);
        self::assertSame(400, $r['status']);
        self::assertFalse($r['json']['ok']);
        self::assertSame('rejected', $r['json']['status']);
        self::assertStringNotContainsString('attacker', $r['body']);
        if ($key !== '') {
            self::assertStringNotContainsString($key, $r['body']);
        }
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));
    }

    public function testApplyOneRefusesNestedValuesAndFlagsOnSecretKeys(): void
    {
        $nested = $this->post('p=settings', [
            'action' => 'settings_apply_one', 'key' => 'search.tail_cap', 'value' => ['1'], 'version' => '',
        ]);
        self::assertSame(400, $nested['status']);
        $keyArray = $this->post('p=settings', [
            'action' => 'settings_apply_one', 'key' => ['reload.token'], 'value' => '1', 'version' => '',
        ]);
        self::assertSame(400, $keyArray['status']);
        // a reset flag only ever applies to the posted editable key
        $r = $this->apply('reload.token', '', null, ['reset' => '1']);
        self::assertSame(400, $r['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
    }

    public function testApplyOneValidationErrorsAreReturnedAndNothingIsSaved(): void
    {
        $r = $this->apply('search.entity_demote', '1.7');
        self::assertSame(200, $r['status']);
        self::assertFalse($r['json']['ok']);
        self::assertSame('invalid', $r['json']['status']);
        self::assertSame('search.entity_demote', $r['json']['errors'][0]['key']);
        self::assertSame('range', $r['json']['errors'][0]['code']);
        self::assertStringContainsString('بین 0 و 1', $r['json']['errors'][0]['text']);
        self::assertArrayNotHasKey('version', $r['json']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));

        self::assertSame('int', $this->apply('search.default_limit', '2.5')['json']['errors'][0]['code']);
        self::assertSame('url', $this->apply('vps.url', 'not a url')['json']['errors'][0]['code']);
    }

    public function testApplyOneWarningsNeedTheSecondConfirmedCall(): void
    {
        $first = $this->apply('search.tail_cap', '150');
        self::assertSame(200, $first['status']);
        self::assertFalse($first['json']['ok']);
        self::assertTrue($first['json']['needs_confirm']);
        self::assertSame('confirm', $first['json']['status']);
        self::assertSame('tail_over_top_k', $first['json']['warnings'][0]['code']);
        self::assertSame(['150', '100'], $first['json']['warnings'][0]['args']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));

        $second = $this->apply('search.tail_cap', '150', null, ['confirm' => '1']);
        self::assertTrue($second['json']['ok']);
        self::assertTrue($second['json']['flush'], 'a search.* key may change results');
        self::assertSame(['search.tail_cap' => 150], $this->store()->read()['values']);
    }

    public function testApplyOneChecksConflictsAgainstTheEffectiveConfigNotJustTheRow(): void
    {
        // config.php sets tail_cap 12: lowering semantic_top_k below it conflicts although tail_cap is not posted
        $r = $this->apply('search.semantic_top_k', '8');
        self::assertSame('confirm', $r['json']['status']);
        self::assertSame('tail_over_top_k', $r['json']['warnings'][0]['code']);
        self::assertSame('search.tail_cap', $r['json']['warnings'][0]['key']);
    }

    public function testApplyOneWritesOnlyThatKeyAndAuditsItsNameWithoutValues(): void
    {
        $this->submit('settings_save', ['logs.page_size' => '25']);
        self::assertSame([], array_diff(array_keys($this->store()->read()['values']), ['logs.page_size']));
        $this->apply('search.title_weight', '14');

        self::assertSame(
            ['logs.page_size' => 25, 'search.title_weight' => 14.0],
            $this->store()->read()['values']
        );
        $rows = $this->auditRows('settings_save');
        self::assertCount(2, $rows);
        $detail = json_decode($rows[1]['detail'], true);
        self::assertSame(['search.title_weight'], $detail['keys']);
        self::assertSame(1, $detail['total']);
        self::assertStringNotContainsString('14', $rows[1]['detail']);
        self::assertCount(1, $this->store()->backups(), 'the replaced file was backed up');
    }

    public function testApplyOneVersionsChainAcrossConsecutiveCalls(): void
    {
        $v0 = $this->version($this->get('p=settings')['body']);
        $a = $this->apply('logs.page_size', '25', $v0);
        $b = $this->apply('logs.refresh_seconds', '30', $a['json']['version']);
        self::assertTrue($a['json']['ok'] && $b['json']['ok']);
        self::assertNotSame($v0, $a['json']['version']);
        self::assertNotSame($a['json']['version'], $b['json']['version']);
        self::assertSame($this->store()->read()['version'], $b['json']['version']);
        self::assertSame(['logs.page_size' => 25, 'logs.refresh_seconds' => 30], $this->store()->read()['values']);

        // the full form loaded after the applies starts from the same version
        self::assertSame($b['json']['version'], $this->version($this->get('p=settings')['body']));
    }

    public function testApplyOneRefusesAStaleVersionWith409AndWritesNothing(): void
    {
        $v0 = $this->store()->read()['version'];
        $this->submit('settings_save', ['logs.page_size' => '25']);
        $before = (string) file_get_contents(self::$root . '/storage/settings.json');

        foreach ([
            ['logs.page_size', '30'],          // would change a value
            ['logs.page_size', '25'],          // would change nothing
            ['search.entity_demote', '1.7'],   // would be invalid
        ] as [$key, $value]) {
            $r = $this->apply($key, $value, $v0);
            self::assertSame(409, $r['status'], $key . $value);
            self::assertSame('stale', $r['json']['status']);
            self::assertTrue($r['json']['reload']);
            self::assertFalse($r['json']['ok']);
            self::assertArrayNotHasKey('version', $r['json']);
            self::assertStringContainsString('تغییر کرده', $r['json']['message']);
        }
        self::assertSame($before, (string) file_get_contents(self::$root . '/storage/settings.json'));
        self::assertCount(1, $this->auditRows('settings_save'));
    }

    public function testApplyOneWithNothingToChangeSavesAndAuditsNothing(): void
    {
        $this->submit('settings_save', ['logs.page_size' => '25']);
        $version = $this->store()->read()['version'];
        $r = $this->apply('logs.page_size', '25.0', $version);

        self::assertSame(200, $r['status']);
        self::assertTrue($r['json']['ok']);
        self::assertSame('no_change', $r['json']['status']);
        self::assertSame($version, $r['json']['version']);
        self::assertSame($version, $this->store()->read()['version']);
        self::assertCount(1, $this->auditRows('settings_save'));
        self::assertSame([], $this->store()->backups());
    }

    public function testApplyOneResetAndEmptyValueControls(): void
    {
        $this->apply('search.title_weight', '14');
        $reset = $this->apply('search.title_weight', '14', null, ['reset' => '1']);
        self::assertTrue($reset['json']['ok']);
        self::assertSame('10', $reset['json']['value']);
        self::assertSame('default', $reset['json']['source']);
        self::assertSame([], $this->store()->read()['values']);

        // a blank value without the flag means "inherit", like the full form
        $this->apply('logs.page_size', '25');
        $blank = $this->apply('logs.page_size', '');
        self::assertSame('default', $blank['json']['source']);
        self::assertSame([], $this->store()->read()['values']);

        // the one way to blank a list the code fills: the explicit "empty value" flag
        $empty = $this->apply('search.stopwords', '', null, ['empty' => '1']);
        self::assertTrue($empty['json']['ok']);
        self::assertSame('', $empty['json']['value']);
        self::assertSame('panel', $empty['json']['source']);
        self::assertSame(['search.stopwords' => ''], $this->store()->read()['values']);
    }

    public function testApplyOneReportsTheConfigPhpSourceAndTheCodeDefault(): void
    {
        // config.php sets tail_cap 12 (the code default is 40): resetting returns the config.php value
        $this->apply('search.tail_cap', '30');
        $r = $this->apply('search.tail_cap', '30', null, ['reset' => '1']);
        self::assertSame('12', $r['json']['value']);
        self::assertSame('config.php', $r['json']['source']);
        self::assertSame('config.php', $r['json']['source_label']);
        self::assertSame('40', $r['json']['default']);
    }

    public function testApplyOneNeverLeaksACredentialOrAHiddenKey(): void
    {
        $responses = [
            $this->apply('vps.url', 'https://vps.example.com'),
            $this->apply('vps.url', 'https://vps.example.com', null, ['confirm' => '1']),
            $this->apply('redis.enabled', '1', null, ['confirm' => '1']),
            $this->apply('search.default_limit', 'x'),
            $this->apply('vps.token', 'attacker'),
            $this->apply('search.tail_cap', '150', '0000'),
        ];
        // a warning about the VPS token names the editable key vps.url and no secret
        self::assertSame('vps_token_missing', $responses[0]['json']['warnings'][0]['code'] ?? '');
        self::assertSame('vps.url', $responses[0]['json']['warnings'][0]['key']);
        foreach ($responses as $i => $r) {
            foreach (self::SECRETS as $name => $value) {
                self::assertStringNotContainsString($value, $r['body'], "response {$i} shows {$name}");
            }
            foreach (self::SECRET_KEYS as $key) {
                self::assertStringNotContainsString($key, $r['body'], "response {$i} names {$key}");
            }
            self::assertStringNotContainsString(\App\Tests\TempConfig::ADMIN_HASH, $r['body']);
            self::assertStringNotContainsString(self::$root, $r['body']);
        }
    }

    public function testApplyOneIsInvisibleToAPlainRequestAndGetIsNotAnAction(): void
    {
        $get = $this->get('p=settings&action=settings_apply_one&key=logs.page_size&value=25');
        self::assertSame(200, $get['status']);
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame(405, $this->client->request('PUT', '/admin.php?p=settings')['status']);
    }

    public function testThePageCarriesWhatTheScriptNeedsAndStaysAPlainFormWithoutIt(): void
    {
        $html = $this->get('p=settings')['body'];
        // plain form intact
        self::assertStringContainsString('<form method="post" action="?p=settings"', $html);
        self::assertStringContainsString('value="settings_check"', $html);
        self::assertStringContainsString('value="settings_save"', $html);
        // script hooks, all inert without JS
        self::assertMatchesRegularExpression('/<tr class="srow" data-key="search\.tail_cap" data-differs="1"/', $html);
        self::assertMatchesRegularExpression('/<tr class="srow" data-key="search\.title_weight" data-differs="0"/', $html);
        self::assertMatchesRegularExpression('/id="settings-flush"[^>]* hidden/', $html);
        self::assertMatchesRegularExpression('/id="settings-pending"[^>]* hidden/', $html);
        self::assertStringContainsString('hidden id="settings-only-label"', $html);
        self::assertStringContainsString('class="actions bar-sticky"', $html);
        self::assertStringContainsString('settings_apply_one', $html);
        self::assertStringNotContainsString('data-readonly', $html);
        // the script's words come from AdminStrings
        self::assertStringContainsString('اعمال با وجود هشدار', $html);
        foreach (self::SECRET_KEYS as $key) {
            self::assertStringNotContainsString($key, $html);
        }
    }

    public function testHistoryListsBackupsAndRestoreIsItselfReversible(): void
    {
        $this->submit('settings_save', ['logs.page_size' => '25']);
        $this->submit('settings_save', ['logs.page_size' => '30']);
        $history = $this->get('p=settings&tab=history');
        self::assertSame(200, $history['status']);
        $backups = $this->store()->backups();
        self::assertCount(1, $backups);
        self::assertStringContainsString($backups[0]['name'], $history['body']);

        $diff = $this->get('p=settings&tab=history&diff=' . $backups[0]['name']);
        self::assertStringContainsString('logs.page_size', $diff['body']);

        $restore = $this->post('p=settings&tab=history', [
            'action' => 'settings_restore', 'backup' => $backups[0]['name'],
            'version' => $this->store()->read()['version'],
        ]);
        self::assertSame(303, $restore['status']);
        self::assertSame(['logs.page_size' => 25], $this->store()->read()['values']);
        self::assertCount(2, $this->store()->backups(), 'the restore was backed up first');
        $rows = $this->auditRows('settings_restore');
        self::assertCount(1, $rows);
        self::assertSame(['logs.page_size'], json_decode($rows[0]['detail'], true)['keys']);
        self::assertStringContainsString('بازگردانی تنظیمات', $this->get('p=audit')['body']);

        $bad = $this->post('p=settings&tab=history', [
            'action' => 'settings_restore', 'backup' => '../settings.json',
            'version' => $this->store()->read()['version'],
        ]);
        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('class="msg bad"', $bad['body']);
    }

    /** @return array<string, array<string, mixed>> */
    public static function craftedForms(): array
    {
        return [
            'a secret' => [['v' => ['vps.token' => 'attacker']]],
            'db dsn' => [['v' => ['db.dsn' => 'mysql:host=evil']]],
            'db password' => [['v' => ['db.password' => 'x']]],
            'read-only db table' => [['v' => ['db.products_table' => 'x']]],
            'read-only path' => [['v' => ['paths.storage' => '/tmp/x']]],
            'read-only admin' => [['v' => ['admin.username' => 'mallory']]],
            'read-only normalization' => [['v' => ['model.normalization_version' => '9']]],
            'unknown key' => [['v' => ['search.nope' => '1']]],
            'secret in the reset group' => [['v' => [], 'r' => ['reload.token' => '1']]],
            'unknown in the empty group' => [['v' => [], 'e' => ['nope' => '1']]],
            'a nested value' => [['v' => ['search.tail_cap' => ['1']]]],
            'a huge value' => [['v' => ['search.stopwords' => str_repeat('x', 9000)]]],
            'v is not an array' => [['v' => 'x']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('craftedForms')]
    public function testACraftedRequestIsRejectedWithoutEchoingTheKeyOrWritingAnything(array $form): void
    {
        foreach (['settings_check', 'settings_save'] as $action) {
            $result = $this->post('p=settings', ['action' => $action, 'version' => ''] + $form);
            self::assertSame(400, $result['status'], $action);
            self::assertStringNotContainsString('attacker', $result['body']);
            self::assertStringNotContainsString('vps.token', $result['body']);
            self::assertStringNotContainsString('reload.token', $result['body']);
        }
        self::assertFileDoesNotExist(self::$root . '/storage/settings.json');
        self::assertSame([], $this->auditRows('settings_save'));
    }

    public function testTooManyFieldsAreRejected(): void
    {
        $many = [];
        for ($i = 0; $i < 400; $i++) {
            $many['search.k' . $i] = '1';
        }
        $result = $this->post('p=settings', ['action' => 'settings_save', 'version' => '', 'v' => $many]);
        self::assertSame(400, $result['status']);
    }

    public function testOnlyThePostAndGetMethodsAndKnownActionsWork(): void
    {
        self::assertSame(400, $this->post('p=settings', ['action' => 'drop_everything'])['status']);
        self::assertSame(405, $this->client->request('PUT', '/admin.php?p=settings')['status']);
    }

    public function testNoCredentialValueOrKeyNameIsEverRendered(): void
    {
        $this->submit('settings_save', ['search.tail_cap' => '150'], ['confirm' => '1']);
        $pages = [
            $this->get('p=settings')['body'],
            $this->get('p=settings&tab=history')['body'],
            $this->submit('settings_check', ['vps.url' => 'https://vps.example.com', 'redis.enabled' => '1'])['body'],
            $this->submit('settings_save', ['search.default_limit' => 'x'])['body'],
            $this->get('p=audit')['body'],
        ];
        foreach ($pages as $i => $html) {
            foreach (self::SECRETS as $name => $value) {
                self::assertStringNotContainsString($value, $html, "page {$i} shows {$name}");
            }
            foreach (self::SECRET_KEYS as $key) {
                self::assertStringNotContainsString($key, $html, "page {$i} names {$key}");
            }
            self::assertStringNotContainsString(\App\Tests\TempConfig::ADMIN_HASH, $html);
        }
        $files = array_merge(
            glob(self::$root . '/storage/*.json') ?: [],
            glob(self::$root . '/storage/backups/*.json') ?: []
        );
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            foreach (array_merge(array_values(self::SECRETS), self::SECRET_KEYS) as $needle) {
                self::assertStringNotContainsString($needle, $content, $file);
            }
        }
        foreach ($this->auditRows('settings_save') as $row) {
            self::assertStringNotContainsString(self::SECRETS['vps_token'], $row['detail']);
        }
    }

    public function testAHandEditedFileWithSecretsIsIgnoredAndReportedByKeyOnly(): void
    {
        mkdir(self::$root . '/storage', 0755, true);
        file_put_contents(self::$root . '/storage/settings.json', (string) json_encode([
            'vps.token' => 'planted-token-123', 'search.tail_cap' => 33,
        ]));

        $page = $this->get('p=settings')['body'];
        self::assertStringNotContainsString('planted-token-123', $page);
        self::assertMatchesRegularExpression('/name="v\[search\.tail_cap\]" value="33"/', $page);
        self::assertStringContainsString('vps.token', $page, 'the ignored key path is reported (path only)');

        $health = json_decode(self::$server->client()->request('GET', '/health')['body'], true);
        self::assertContains(
            'vps.token: in settings.json but not editable there, ignored',
            $health['config']['warnings']
        );
        self::assertStringNotContainsString('planted-token-123', (string) json_encode($health));
    }

    public function testACorruptFileKeepsTheSiteAndThePanelUpAndIsReported(): void
    {
        mkdir(self::$root . '/storage', 0755, true);
        file_put_contents(self::$root . '/storage/settings.json', '{"search.tail_cap": ');

        $health = self::$server->client()->request('GET', '/health');
        self::assertSame(200, $health['status']);
        $body = json_decode($health['body'], true);
        self::assertSame('ok', $body['status']);
        self::assertFalse($body['config']['ok']);
        self::assertContains(
            'settings.json: corrupt, ignored (config.php and the code defaults are in use)',
            $body['config']['errors']
        );

        $page = $this->get('p=settings');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('class="msg bad"', $page['body']);
        // config.php's value keeps serving
        self::assertMatchesRegularExpression('/name="v\[search\.tail_cap\]" value="12"/', $page['body']);
        $status = $this->get('p=status');
        self::assertSame(200, $status['status']);
        self::assertStringContainsString('settings.json', $status['body']);

        // saving over it repairs it (the corrupt content becomes a backup)
        $fix = $this->submit('settings_save', ['logs.page_size' => '25']);
        self::assertSame(303, $fix['status']);
        self::assertNull($this->store()->read()['problem']);
    }

    public function testDeletingTheFileReturnsToConfigPhpAndDefaults(): void
    {
        $this->submit('settings_save', ['search.tail_cap' => '30']);
        $field = static fn (string $value): string => '/name="v\[search\.tail_cap\]" value="' . $value . '"/';
        self::assertMatchesRegularExpression($field('30'), $this->get('p=settings')['body']);
        unlink(self::$root . '/storage/settings.json');
        self::assertMatchesRegularExpression($field('12'), $this->get('p=settings')['body']);
    }

    public function testThePageIsListedInTheNavigation(): void
    {
        self::assertStringContainsString('href="?p=settings"', $this->get('p=status')['body']);
    }

    public function testAnUnwritableStorageDisablesSaving(): void
    {
        $state = [
            'stored' => [
                'values' => [], 'version' => '', 'exists' => false, 'problem' => null, 'ignored' => [], 'invalid' => [],
            ],
            'inherited' => Config::flatten(Config::defaults()), 'effective' => Config::flatten(Config::defaults()),
            'defaults' => Config::flatten(Config::defaults()), 'file' => [],
        ];
        $html = AdminSettingsViews::page([
            'tab' => 'settings', 'csrf' => 'c', 'state' => $state, 'writable' => false, 'result' => null,
            'saved' => '', 'flush' => false, 'backups' => [], 'diff' => null,
        ]);
        self::assertStringContainsString('قابل نوشتن نیست', $html);
        self::assertMatchesRegularExpression('/value="settings_save" disabled/', $html);
    }
}
