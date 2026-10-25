<?php

declare(strict_types=1);

namespace App\Tests;

use App\AliasStore;
use App\AliasStoreException;
use PHPUnit\Framework\TestCase;

final class AliasStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = TempTree::make('aliasstore');
        mkdir($this->root . '/data');
    }

    protected function tearDown(): void
    {
        TempTree::removeIfAssigned($this->root);
    }

    private function store(int $keep = 20): AliasStore
    {
        return new AliasStore($this->root . '/storage', $this->root . '/data/aliases.json', $keep);
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (AliasStoreException $e) {
            return $e->reason();
        }
        self::fail('expected an AliasStoreException');
    }

    public function testAnEmptyOrRelativeStoragePathIsRefused(): void
    {
        self::assertSame('io', $this->reason(fn () => new AliasStore('', $this->root . '/x')));
        self::assertSame('io', $this->reason(fn () => new AliasStore('storage', $this->root . '/x')));
    }

    public function testFromConfigReadsThePathsAndTheBackupCount(): void
    {
        $config = \App\Config::defaults($this->root);
        $config['admin']['alias_backups'] = 3;
        $store = AliasStore::fromConfig($config);
        self::assertSame($this->root . '/storage/aliases.json', $store->path());
    }

    public function testRuntimePathIsTheBundleFileUntilTheStorageFileExists(): void
    {
        $store = $this->store();
        self::assertSame($this->root . '/data/aliases.json', $store->runtimePath());
        $store->save([['a', 'b']], '');
        self::assertSame($store->path(), $store->runtimePath());
    }

    public function testSeedCopiesAValidBundleFileOnceAndNeverOverwrites(): void
    {
        file_put_contents($this->root . '/data/aliases.json', '[["gta","grand theft auto"]]');
        $store = $this->store();

        self::assertTrue($store->seedIfMissing());
        self::assertSame([['gta', 'grand theft auto']], $store->read()['groups']);

        $store->save([['x', 'y']], $store->read()['version']);
        file_put_contents($this->root . '/data/aliases.json', '[["other","pair"]]');
        self::assertFalse($store->seedIfMissing());
        self::assertSame([['x', 'y']], $store->read()['groups']);
    }

    public function testSeedWithoutAUsableBundleFileStartsEmpty(): void
    {
        $store = $this->store();
        self::assertTrue($store->seedIfMissing());
        self::assertSame([], $store->read()['groups']);

        $other = new AliasStore($this->root . '/storage2', $this->root . '/data/aliases.json');
        file_put_contents($this->root . '/data/aliases.json', '[["broken",]');
        self::assertTrue($other->seedIfMissing());
        self::assertSame([], $other->read()['groups']);
        self::assertTrue($other->read()['valid']);
    }

    public function testSaveWritesPrettyUnicodeJsonAndReturnsTheNewVersion(): void
    {
        $store = $this->store();
        $version = $store->save([['ps5', 'پلی استیشن ۵']], '');

        $raw = (string) file_get_contents($store->path());
        self::assertStringContainsString('پلی استیشن ۵', $raw);
        self::assertStringEndsWith("]\n", $raw);
        self::assertSame($version, $store->read()['version']);
        self::assertSame(sha1($raw), $version);
        self::assertSame([], glob($this->root . '/storage/aliases.tmp*') ?: [], 'no temp file is left behind');
    }

    public function testASaveFromAStaleVersionIsRejectedAndChangesNothing(): void
    {
        $store = $this->store();
        $first = $store->save([['a', 'b']], '');
        $store->save([['c', 'd']], $first); // someone else saved meanwhile

        self::assertSame('stale', $this->reason(fn () => $store->save([['e', 'f']], $first)));
        self::assertSame([['c', 'd']], $store->read()['groups']);
        // A first save from an editor that thought the file did not exist is stale too.
        self::assertSame('stale', $this->reason(fn () => $store->save([['g', 'h']], '')));
    }

    public function testASaveThatChangesNothingWritesNoBackup(): void
    {
        $store = $this->store();
        $version = $store->save([['a', 'b']], '');
        $store->save([['a', 'b']], $version);
        self::assertSame([], $store->backups());
    }

    public function testEverySaveBacksUpThePreviousContent(): void
    {
        $store = $this->store();
        $v1 = $store->save([['a', 'b']], '');
        $v2 = $store->save([['c', 'd']], $v1);
        $store->save([['e', 'f']], $v2);

        $backups = $store->backups();
        self::assertCount(2, $backups);
        self::assertSame(1, $backups[0]['groups']);
        self::assertSame([['c', 'd']], \App\Synonyms::parseGroups((string) $store->backupContent($backups[0]['name'])));
        self::assertSame([['a', 'b']], \App\Synonyms::parseGroups((string) $store->backupContent($backups[1]['name'])));
    }

    public function testOnlyTheNewestBackupsAreKept(): void
    {
        $store = $this->store(3);
        $version = '';
        for ($i = 0; $i < 7; $i++) {
            $version = $store->save([["a{$i}", "b{$i}"]], $version);
        }

        $names = array_column($store->backups(), 'name');
        self::assertCount(3, $names);
        // Newest first: the backups of the 6th, 5th and 4th states.
        self::assertSame(
            [[['a5', 'b5']], [['a4', 'b4']], [['a3', 'b3']]],
            array_map(fn (string $n) => \App\Synonyms::parseGroups((string) $store->backupContent($n)), $names)
        );
    }

    public function testRestoreBringsBackAnOldStateAndBacksUpTheReplacedOne(): void
    {
        $store = $this->store();
        $v1 = $store->save([['a', 'b']], '');
        $v2 = $store->save([['c', 'd']], $v1);
        $oldest = $store->backups()[0]['name'];

        $v3 = $store->restore($oldest, $v2);

        self::assertSame([['a', 'b']], $store->read()['groups']);
        self::assertSame($v3, $store->read()['version']);
        // The replaced state is itself restorable: the restore can be undone.
        $contents = array_map(
            fn (array $b) => \App\Synonyms::parseGroups((string) $store->backupContent($b['name'])),
            $store->backups()
        );
        self::assertContains([['c', 'd']], $contents);
    }

    public function testRestoreRejectsAStaleVersionAndAnUnknownOrForgedName(): void
    {
        $store = $this->store();
        $v1 = $store->save([['a', 'b']], '');
        $store->save([['c', 'd']], $v1);
        $name = $store->backups()[0]['name'];

        self::assertSame('stale', $this->reason(fn () => $store->restore($name, $v1)));
        $names = ['../aliases.json', '/etc/passwd', 'aliases-20200101-000000-000000.json', 'aliases-x.json', ''];
        foreach ($names as $forged) {
            $current = $store->read()['version'];
            self::assertSame('unknown_backup', $this->reason(fn () => $store->restore($forged, $current)));
            self::assertNull($store->backupContent($forged));
        }
        self::assertSame([['c', 'd']], $store->read()['groups']);
    }

    public function testReadReportsAMissingAndAnInvalidFile(): void
    {
        $store = $this->store();
        $missing = $store->read();
        self::assertFalse($missing['exists']);
        self::assertSame('', $missing['version']);
        self::assertTrue($missing['valid']);

        mkdir($this->root . '/storage', 0755, true);
        file_put_contents($store->path(), '[["a",');
        $broken = $store->read();
        self::assertTrue($broken['exists']);
        self::assertFalse($broken['valid']);
        self::assertSame([], $broken['groups']);
        self::assertSame('[["a",', $broken['raw']);
    }

    public function testTheStorageDirectoryIsClosedToTheWeb(): void
    {
        $this->store()->save([['a', 'b']], '');
        $deny = (string) file_get_contents($this->root . '/storage/.htaccess');
        self::assertStringContainsString('Require all denied', $deny);
    }

    public function testConcurrentSavesFromTheSameVersionLetExactlyOneWin(): void
    {
        $store = $this->store();
        $version = $store->save([['a', 'b']], '');
        $store->save([['c', 'd']], $version);

        $outcomes = [];
        foreach ([[['e', 'f']], [['g', 'h']]] as $groups) {
            try {
                $store->save($groups, $version);
                $outcomes[] = 'saved';
            } catch (AliasStoreException $e) {
                $outcomes[] = $e->reason();
            }
        }
        self::assertSame(['stale', 'stale'], $outcomes, 'both started from a version that was already replaced');
    }

    public function testPruningNeverLeavesTheBackupsDirectory(): void
    {
        $store = $this->store(1);
        $version = '';
        foreach ([['a', 'b'], ['c', 'd'], ['e', 'f']] as $pair) {
            $version = $store->save([$pair], $version);
        }
        // A symlink named like a backup, pointing outside: pruning must unlink the link, not follow it.
        $outside = TempTree::make('aliasstore_outside');
        file_put_contents($outside . '/keep.json', 'precious');
        symlink($outside . '/keep.json', $this->root . '/storage/backups/aliases-20000101-000000-000000.json');
        try {
            $store->save([['g', 'h']], $version);
            self::assertFileExists($outside . '/keep.json');
        } finally {
            TempTree::remove($outside);
        }
    }
}
