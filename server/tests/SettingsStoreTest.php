<?php

declare(strict_types=1);

namespace App\Tests;

use App\SettingsStore;
use App\SettingsStoreException;
use PHPUnit\Framework\TestCase;

/** M46: the settings file's atomic write, version check, backups, retention and restore. */
final class SettingsStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = TempTree::make('settings_store');
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->dir);
    }

    private function store(int $keep = 20): SettingsStore
    {
        return new SettingsStore($this->dir . '/storage/settings.json', $keep);
    }

    public function testFirstSaveCreatesTheDirectoryFileAndDenyRule(): void
    {
        $store = $this->store();
        self::assertSame('', $store->read()['version']);
        $version = $store->save(['search.tail_cap' => 5, 'search.title_weight' => 12.0], '');

        self::assertSame(['search.tail_cap' => 5, 'search.title_weight' => 12.0], $store->read()['values']);
        self::assertSame($version, $store->read()['version']);
        $deny = (string) file_get_contents($this->dir . '/storage/.htaccess');
        self::assertStringContainsString('Require all denied', $deny);
        self::assertStringContainsString('"search.title_weight": 12.0', (string) file_get_contents($store->path()));
        self::assertSame([], glob($this->dir . '/storage/settings.tmp*') ?: []);
        self::assertSame([], $store->backups(), 'the first write has nothing to back up');
    }

    public function testASaveBacksUpThePreviousContentAndIsAtomic(): void
    {
        $store = $this->store();
        $v1 = $store->save(['search.tail_cap' => 5], '');
        $before = (string) file_get_contents($store->path());
        $store->save(['search.tail_cap' => 6], $v1);

        $backups = $store->backups();
        self::assertCount(1, $backups);
        self::assertSame($before, $store->backupContent($backups[0]['name']));
        self::assertSame(1, $backups[0]['keys']);
        self::assertSame([], glob($this->dir . '/storage/settings.tmp*') ?: []);
    }

    public function testAnUnchangedSaveWritesNoBackup(): void
    {
        $store = $this->store();
        $v1 = $store->save(['search.tail_cap' => 5], '');
        self::assertSame($v1, $store->save(['search.tail_cap' => 5], $v1));
        self::assertSame([], $store->backups());
    }

    public function testAStaleVersionIsRefusedAndTheFileIsUntouched(): void
    {
        $store = $this->store();
        $v1 = $store->save(['search.tail_cap' => 5], '');
        $store->save(['search.tail_cap' => 6], $v1);
        $content = (string) file_get_contents($store->path());

        try {
            $store->save(['search.tail_cap' => 7], $v1);
            self::fail('stale save accepted');
        } catch (SettingsStoreException $e) {
            self::assertSame('stale', $e->reason());
        }
        self::assertSame($content, (string) file_get_contents($store->path()));

        // creating when a file exists meanwhile is stale too
        try {
            $store->save(['search.tail_cap' => 8], '');
            self::fail('stale create accepted');
        } catch (SettingsStoreException $e) {
            self::assertSame('stale', $e->reason());
        }
    }

    public function testOnlyTheNewestBackupsAreKept(): void
    {
        $store = $this->store(3);
        $version = '';
        for ($i = 1; $i <= 7; $i++) {
            $version = $store->save(['search.tail_cap' => $i], $version);
        }
        $backups = $store->backups();
        self::assertCount(3, $backups);
        // newest first: the backup of value 6 (replaced by 7) leads
        self::assertStringContainsString('"search.tail_cap": 6', (string) $store->backupContent($backups[0]['name']));
    }

    public function testRestoreReplacesTheFileAndIsItselfBackedUp(): void
    {
        $store = $this->store();
        $v = $store->save(['search.tail_cap' => 5], '');
        $v = $store->save(['search.tail_cap' => 6, 'search.title_weight' => 11.0], $v);
        $oldest = $store->backups()[0]['name'];

        $store->restore($oldest, $v);
        self::assertSame(['search.tail_cap' => 5], $store->read()['values']);
        // the content restore replaced is a backup now, so the restore can be undone
        $contents = array_map(fn (array $b): ?string => $store->backupContent($b['name']), $store->backups());
        $replaced = array_filter($contents, static fn (?string $c): bool => str_contains((string) $c, 'title_weight'));
        self::assertCount(1, $replaced);
    }

    public function testRestoreOnlyTakesEditableValidKeysAndRefusesBadNames(): void
    {
        $store = $this->store();
        $v = $store->save(['search.tail_cap' => 5], '');
        $v = $store->save(['search.tail_cap' => 6], $v);
        $name = $store->backups()[0]['name'];
        file_put_contents(
            $this->dir . '/storage/backups/' . $name,
            '{"search.tail_cap": 9, "vps.token": "x", "search.default_limit": "zz"}'
        );
        $store->restore($name, $v);
        self::assertSame(['search.tail_cap' => 9], $store->read()['values']);
        self::assertStringNotContainsString('vps.token', (string) file_get_contents($store->path()));

        foreach (['../settings.json', 'settings-1.json', '/etc/passwd', 'nope'] as $bad) {
            try {
                $store->restore($bad, $store->read()['version']);
                self::fail("restored {$bad}");
            } catch (SettingsStoreException $e) {
                self::assertSame('unknown_backup', $e->reason());
            }
        }
        // a corrupt backup is refused as well
        file_put_contents($this->dir . '/storage/backups/' . $name, '{broken');
        $this->expectException(SettingsStoreException::class);
        $store->restore($name, $store->read()['version']);
    }

    public function testACorruptCurrentFileCanBeReplacedByASave(): void
    {
        $store = $this->store();
        mkdir($this->dir . '/storage');
        file_put_contents($store->path(), '{broken');
        $read = $store->read();
        self::assertSame('corrupt', $read['problem']);

        $store->save(['search.tail_cap' => 5], $read['version']);
        self::assertNull($store->read()['problem']);
        self::assertCount(1, $store->backups(), 'the corrupt content is kept as a backup');
    }

    public function testAnEmptySaveWritesAnEmptyObject(): void
    {
        $store = $this->store();
        $v = $store->save(['search.tail_cap' => 5], '');
        $store->save([], $v);
        self::assertSame("{}\n", (string) file_get_contents($store->path()));
        self::assertSame([], $store->read()['values']);
    }

    public function testNotWritableStorageIsDetectedAndFailsCleanly(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes anywhere');
        }
        mkdir($this->dir . '/storage');
        chmod($this->dir . '/storage', 0555);
        $store = $this->store();
        self::assertFalse($store->writable());
        try {
            $store->save(['search.tail_cap' => 5], '');
            self::fail('saved into a read-only directory');
        } catch (SettingsStoreException $e) {
            self::assertContains($e->reason(), ['io']);
        } finally {
            chmod($this->dir . '/storage', 0755);
        }
    }

    public function testAMissingDirectoryUnderAWritableParentCountsAsWritable(): void
    {
        self::assertTrue($this->store()->writable());
    }

    public function testARelativeOrEmptyPathIsRefused(): void
    {
        foreach (['', 'storage/settings.json'] as $path) {
            try {
                new SettingsStore($path);
                self::fail('accepted ' . $path);
            } catch (SettingsStoreException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFromConfigHonoursTheEnvironmentPath(): void
    {
        $config = ['paths' => ['storage' => $this->dir . '/s'], 'admin' => ['settings_backups' => 4]];
        self::assertSame($this->dir . '/s/settings.json', SettingsStore::fromConfig($config)->path());
        putenv('SEARCH_SETTINGS_FILE=' . $this->dir . '/other/x.json');
        try {
            self::assertSame($this->dir . '/other/x.json', SettingsStore::fromConfig($config)->path());
        } finally {
            putenv('SEARCH_SETTINGS_FILE');
        }
    }

    public function testConcurrentSaversCannotBothWin(): void
    {
        $store = $this->store();
        $v = $store->save(['search.tail_cap' => 1], '');
        $script = TempTree::path('settings_race') . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . '$s = new App\SettingsStore($argv[1]); try { $s->save(["search.tail_cap" => (int) $argv[2]], $argv[3]);'
            . ' echo "ok"; } catch (App\SettingsStoreException $e) { echo $e->reason(); }');
        $procs = [];
        foreach ([2, 3, 4] as $n) {
            $command = [PHP_BINARY, $script, $store->path(), (string) $n, $v];
            $procs[$n] = popen(implode(' ', array_map('escapeshellarg', $command)), 'r');
        }
        $outcomes = array_map(static function ($p): string {
            $out = (string) stream_get_contents($p);
            pclose($p);

            return $out;
        }, $procs);
        @unlink($script);

        $count = static fn (string $what): int => count(array_keys($outcomes, $what, true));
        self::assertSame(1, $count('ok'), implode(',', $outcomes));
        self::assertSame(2, $count('stale'));
        self::assertJson((string) file_get_contents($store->path()));
    }
}
