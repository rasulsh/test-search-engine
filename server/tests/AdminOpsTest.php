<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong -- long literals and assertion messages in an HTTP / fixture test

namespace App\Tests;

use App\AdminOps;
use App\AdminOpsViews;
use App\AdminStrings;
use App\Audit;
use App\Cache;
use App\Config;
use App\VpsClient;

/** The operations page's actions against mocks: Redis (prefix only), the VPS, and the reload path. */
final class AdminOpsTest extends DatabaseTestCase
{
    private const PREFIX = 'search:cache:';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            TempTree::remove($dir);
        }
        $this->dirs = [];
    }

    private function ops(?Cache $cache = null, ?VpsClient $vps = null, array $overrides = []): AdminOps
    {
        $config = Config::merge($overrides);

        return new AdminOps($config, $this->pdo, new Audit($this->pdo, 'admin_audit', 180), '203.0.113.5', $cache, $vps);
    }

    /** @return array<string, mixed> the newest audit row's detail */
    private function lastDetail(string $action): array
    {
        $row = (new Audit($this->pdo, 'admin_audit', 180))->latest([$action], 1)[0];

        return json_decode($row['detail'], true);
    }

    public function testFlushingTheCacheDeletesOnlyTheServicesKeysAndNeverFlushesTheDatabase(): void
    {
        $redis = new FakeRedis();
        $redis->data = [
            self::PREFIX . 'a' => '1', self::PREFIX . 'b' => '2', self::PREFIX . 'freq:a' => '3',
            'other-app:session:1' => 'keep', 'search:cachefoo' => 'keep',
        ];

        $result = $this->ops(new Cache($redis, self::PREFIX, 60))->flushCache();

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['removed']);
        self::assertSame(['other-app:session:1' => 'keep', 'search:cachefoo' => 'keep'], $redis->data);
        self::assertSame(['SCAN', 'DEL'], array_values(array_unique($redis->log)), 'no FLUSHDB / FLUSHALL / KEYS');
        self::assertSame(['ok' => true, 'removed' => 3], array_intersect_key($this->lastDetail('cache_flush'), ['ok' => 1, 'removed' => 1]));
    }

    public function testFlushingWithRedisOffOrDownReportsWhyAndWritesAnAuditRow(): void
    {
        $off = $this->ops()->flushCache();
        self::assertSame(['ok' => false, 'reason' => 'redis_off'], array_intersect_key($off, ['ok' => 1, 'reason' => 1]));

        $redis = new FakeRedis();
        $redis->down = true;
        $down = $this->ops(new Cache($redis, self::PREFIX, 60))->flushCache();
        self::assertSame('redis_unreachable', $down['reason']);
        self::assertSame('redis_unreachable', $this->lastDetail('cache_flush')['reason']);
        self::assertGreaterThan($off['id'], $down['id'], 'every attempt is audited');
    }

    /** @param array{status: int, body: string}|string $response */
    private function vps(array|string $response, ?array &$seen = null): VpsClient
    {
        return new VpsClient('http://vps.test/', 'vps-token', 300, static function (string $url, array $headers, string $body, int $timeout) use ($response, &$seen) {
            $seen = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

            return $response;
        }, 45000);
    }

    public function testVpsReloadCallsItsReloadEndpointWithTheTokenAndALongTimeout(): void
    {
        $body = (string) json_encode(['ok' => true, 'count' => 24000, 'model' => 'BAAI/bge-m3', 'built_at' => '2026-10-20']);

        $result = $this->ops(null, $this->vps(['status' => 200, 'body' => $body], $seen))->vpsReload();

        self::assertSame('http://vps.test/reload', $seen['url']);
        self::assertSame(['Authorization: Bearer vps-token'], $seen['headers']);
        self::assertSame(45000, $seen['timeout'], 'its own reload timeout, not the 300 ms search budget');
        self::assertTrue($result['ok']);
        self::assertSame(24000, $result['count']);
        self::assertSame(24000, $this->lastDetail('vps_reload')['count']);
        self::assertStringNotContainsString('vps-token', (string) json_encode($this->lastDetail('vps_reload')));
    }

    /** @return array<string, array{array{status: int, body: string}|string, string}> */
    public static function vpsFailures(): array
    {
        return [
            'busy' => [['status' => 409, 'body' => '{"ok":false,"error":"reload already running"}'], 'reload already running'],
            'rejected' => [['status' => 422, 'body' => '{"ok":false,"error":"model mismatch"}'], 'model mismatch'],
            'bad token' => [['status' => 401, 'body' => '{"detail":"missing or wrong token"}'], 'http_401'],
            'timeout' => ['timeout', 'timeout'],
            'down' => ['unreachable: connection refused', 'unreachable: connection refused'],
        ];
    }

    /** @param array{status: int, body: string}|string $response */
    #[\PHPUnit\Framework\Attributes\DataProvider('vpsFailures')]
    public function testVpsReloadFailuresAreReportedNotThrown(array|string $response, string $reason): void
    {
        $result = $this->ops(null, $this->vps($response))->vpsReload();

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertFalse($this->lastDetail('vps_reload')['ok']);
    }

    public function testVpsReloadWithoutAVpsUrlIsRefusedAndAudited(): void
    {
        $result = $this->ops()->vpsReload();

        self::assertSame(['ok' => false, 'reason' => 'vps_off'], array_intersect_key($result, ['ok' => 1, 'reason' => 1]));
        self::assertSame('vps_off', $this->lastDetail('vps_reload')['reason']);
    }

    public function testAVerboseVpsErrorCannotOverflowTheAuditDetail(): void
    {
        $long = str_repeat('x', 900);

        $result = $this->ops(null, $this->vps(['status' => 422, 'body' => (string) json_encode(['ok' => false, 'error' => $long])]))->vpsReload();

        self::assertSame($long, $result['reason'], 'the page of this request still has it');
        $detail = $this->lastDetail('vps_reload');
        self::assertSame(120, strlen($detail['reason']));
        self::assertFalse($detail['ok']);
    }

    private function bundle(int $count, array $meta = []): string
    {
        $dir = TempTree::path('adminops');
        mkdir($dir);
        $this->dirs[] = $dir;
        file_put_contents($dir . '/meta.json', json_encode($meta + [
            'normalization_version' => 2, 'count' => $count, 'built_at' => '2026-10-01',
        ]));

        return $dir;
    }

    private function opsFor(string $data, string $incoming): AdminOps
    {
        return $this->ops(null, null, [
            'paths' => ['data' => $data, 'data_incoming' => $incoming],
            'model' => ['normalization_version' => 2],
        ]);
    }

    public function testValidateAndApplyGoThroughTheReloadPathAndAreAudited(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS products_new, products_old');
        $this->pdo->exec('CREATE TABLE products_new LIKE products');
        $insert = $this->pdo->prepare("INSERT INTO products_new (product_id, title, description, normalized_title, normalized_desc) VALUES (?, 't', 'd', 't', 'd')");
        foreach ([3000, 3001] as $id) {
            $insert->execute([$id]);
        }
        $data = TempTree::path('adminops') . '_data';
        $incoming = $this->bundle(2);
        $ops = $this->opsFor($data, $incoming);

        $checked = $ops->validate(false);
        self::assertTrue($checked['ok']);
        self::assertSame(2, $checked['count']);
        self::assertDirectoryExists($incoming, 'validate swaps nothing');

        $applied = $ops->apply(false);
        self::assertTrue($applied['ok']);
        self::assertDirectoryExists($data);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $detail = $this->lastDetail('reload_apply');
        self::assertSame([true, 2], [$detail['ok'], $detail['count']]);
        self::assertIsFloat($detail['s']);
        $this->dirs[] = $data;
        $this->pdo->exec('DROP TABLE IF EXISTS products_old');
    }

    public function testARejectedBundleIsReportedWithItsReasonAndNothingChanges(): void
    {
        $data = TempTree::path('adminops') . '_data';
        $incoming = $this->bundle(2, ['normalization_version' => 9]);

        $result = $this->opsFor($data, $incoming)->apply(false);

        self::assertFalse($result['ok']);
        self::assertSame('normalization_version_mismatch', $result['reason']);
        self::assertSame('normalization_version_mismatch', $this->lastDetail('reload_apply')['reason']);
        self::assertDirectoryExists($incoming);
        self::assertDirectoryDoesNotExist($data);
    }

    public function testASecondOperationWhileOneRunsIsBusyNotQueued(): void
    {
        $data = TempTree::path('adminops') . '_data';
        $incoming = $this->bundle(2);
        $held = fopen(dirname($data) . '/reload.lock', 'c');
        flock($held, LOCK_EX | LOCK_NB);

        $result = $this->opsFor($data, $incoming)->apply(false);
        flock($held, LOCK_UN);
        fclose($held);

        self::assertSame('busy', $result['reason']);
        self::assertSame('busy', $this->lastDetail('reload_apply')['reason']);
        self::assertDirectoryExists($incoming);
    }

    public function testRollbackWithNothingToRollBackToIsAnAuditedRefusal(): void
    {
        $result = $this->opsFor(TempTree::root() . '/adminops_none_data', TempTree::root() . '/adminops_none_in')->rollback();

        self::assertFalse($result['ok']);
        self::assertSame('nothing_to_roll_back', $result['reason']);
        self::assertSame('rollback', $result['action']);
    }

    public function testEveryResultReadsAsAPersianSentenceWithItsDuration(): void
    {
        $row = static fn (string $action, array $detail): array => ['id' => 1, 'ts' => '2026-10-25 10:00:00', 'ip' => '1.1.1.1',
            'action' => $action, 'detail' => (string) json_encode($detail)];

        [$kind, $text] = AdminOpsViews::message($row('reload_apply', ['ok' => true, 'count' => 24000, 's' => 12.3, 'cache_flushed' => true]));
        self::assertSame('ok', $kind);
        self::assertStringContainsString('24,000', $text);
        self::assertStringContainsString('12.3', $text);
        self::assertStringContainsString('حافظهٔ نهان', $text);

        [$kind, $text] = AdminOpsViews::message($row('cache_flush', ['ok' => true, 'removed' => 5, 's' => 0.1]));
        self::assertSame(['ok', true], [$kind, str_contains($text, '5 کلید')]);

        [$kind, $text] = AdminOpsViews::message($row('reload_apply', ['ok' => false, 'reason' => 'busy', 's' => 0.0]));
        self::assertSame('bad', $kind);
        self::assertStringContainsString(AdminStrings::fa()['ops']['reasons']['busy'], $text);

        [, $text] = AdminOpsViews::message($row('vps_reload', ['ok' => false, 'reason' => 'model mismatch: x', 's' => 1.0]));
        self::assertStringContainsString('model mismatch: x', $text, 'an unknown reason is shown as it came');

        [, $text] = AdminOpsViews::message($row('vps_reload', ['ok' => false, 'reason' => 'unreachable: Connection refused']));
        self::assertStringContainsString(AdminStrings::fa()['ops']['reasons']['unreachable'], $text);

        [$kind] = AdminOpsViews::message(['id' => 2, 'ts' => 'x', 'ip' => 'x', 'action' => 'reload_api', 'detail' => 'count=7']);
        self::assertSame('ok', $kind, 'the token API row carries no JSON');
    }

    public function testEveryReloadReasonHasAPersianMessage(): void
    {
        $reasons = AdminStrings::fa()['ops']['reasons'];
        $known = ['busy', 'nothing_to_roll_back', 'missing_meta', 'invalid_meta',
            'normalization_version_mismatch', 'invalid_synonyms', 'invalid_intent_map',
            'count_mismatch', 'missing_index', 'missing_vectors', 'vectors_size_mismatch', 'checksum_mismatch',
            'missing_staging_table', 'missing_load_sql', 'staging_load_failed', 'unexpected_statement',
            'directory_swap_failed', 'lock_unavailable', 'redis_off', 'redis_unreachable', 'vps_off', 'internal'];
        foreach ($known as $reason) {
            self::assertArrayHasKey($reason, $reasons, $reason);
        }
    }
}
