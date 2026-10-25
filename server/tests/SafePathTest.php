<?php

declare(strict_types=1);

namespace App\Tests;

use App\SafePath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafePathTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = TempTree::make('safepath');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->base);
    }

    private function refuses(callable $call, string $fragment = 'refusing'): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());

            return;
        }
        self::fail('expected the guard to throw');
    }

    public function testEmptyPathAndEmptyBaseAreRefused(): void
    {
        $this->refuses(fn () => SafePath::removeTree('', $this->base));
        $this->refuses(fn () => SafePath::removeTree($this->base, ''));
        $this->refuses(fn () => SafePath::removeFiles('', '*', $this->base));
        $this->refuses(fn () => SafePath::removeFile('', $this->base));
        $this->refuses(fn () => TempTree::remove(''));
    }

    public function testRelativeAndNulPathsAreRefused(): void
    {
        $this->refuses(fn () => SafePath::removeTree('data_old', $this->base));
        $this->refuses(fn () => SafePath::removeTree($this->base . "/a\0b", $this->base));
        $this->refuses(fn () => SafePath::removeTree($this->base . '/a', 'relative/base'));
    }

    public function testTheFilesystemRootIsRefusedAsTargetAndAsBase(): void
    {
        $this->refuses(fn () => SafePath::removeTree('/', $this->base));
        $this->refuses(fn () => SafePath::removeTree($this->base, '/'));
        $this->refuses(fn () => SafePath::removeFiles('/', '*', '/'));
        self::assertDirectoryExists('/usr');
    }

    public function testAMissingBaseIsRefused(): void
    {
        $this->refuses(fn () => SafePath::removeTree($this->base . '/x', $this->base . '/missing'));
    }

    public function testFewerThanThreeSegmentsIsRefused(): void
    {
        // "/usr/<name>" has two segments, whatever the base is.
        $this->refuses(fn () => SafePath::removeTree('/usr/safepath_never_exists', '/usr'), 'segments');
        $this->refuses(fn () => SafePath::removeTree('/usr/bin', '/usr'), 'segments');
        self::assertDirectoryExists('/usr/bin');
    }

    public function testThePathOutsideTheBaseIsRefusedAndSurvives(): void
    {
        $outside = TempTree::make('safepath_outside');
        file_put_contents($outside . '/keep.txt', 'x');
        try {
            $this->refuses(fn () => SafePath::removeTree($outside, $this->base), 'outside');
            $this->refuses(fn () => SafePath::removeFile($outside . '/keep.txt', $this->base), 'outside');
            self::assertFileExists($outside . '/keep.txt');
        } finally {
            TempTree::remove($outside);
        }
    }

    public function testTheBaseItselfIsRefused(): void
    {
        $this->refuses(fn () => SafePath::removeTree($this->base, $this->base), 'outside');
        self::assertDirectoryExists($this->base);
    }

    public function testDotDotTraversalIsResolvedBeforeTheCheck(): void
    {
        $outside = TempTree::make('safepath_dotdot');
        file_put_contents($outside . '/keep.txt', 'x');
        mkdir($this->base . '/sub');
        try {
            $escape = $this->base . '/sub/../../' . basename($outside);
            $this->refuses(fn () => SafePath::removeTree($escape, $this->base));
            self::assertFileExists($outside . '/keep.txt');
        } finally {
            TempTree::remove($outside);
        }
    }

    public function testATopLevelSymlinkIsRefusedAndItsTargetSurvives(): void
    {
        $target = TempTree::make('safepath_target');
        file_put_contents($target . '/keep.txt', 'x');
        symlink($target, $this->base . '/link');
        try {
            $this->refuses(fn () => SafePath::removeTree($this->base . '/link', $this->base), 'symlink');
            self::assertFileExists($target . '/keep.txt');
        } finally {
            TempTree::remove($target);
        }
    }

    public function testSymlinksInsideATreeAreUnlinkedNotFollowed(): void
    {
        $target = TempTree::make('safepath_followed');
        file_put_contents($target . '/keep.txt', 'x');
        mkdir($this->base . '/tree/deep', 0777, true);
        file_put_contents($this->base . '/tree/deep/file.txt', 'y');
        symlink($target, $this->base . '/tree/to-dir');
        symlink($target . '/keep.txt', $this->base . '/tree/deep/to-file');
        try {
            SafePath::removeTree($this->base . '/tree', $this->base);
            self::assertDirectoryDoesNotExist($this->base . '/tree');
            self::assertFileExists($target . '/keep.txt', 'the link was removed, its target was not');
        } finally {
            TempTree::remove($target);
        }
    }

    public function testRemovesAnOrdinaryTreeAndIgnoresAMissingOne(): void
    {
        mkdir($this->base . '/data_old/nested', 0777, true);
        file_put_contents($this->base . '/data_old/nested/a.txt', 'a');
        file_put_contents($this->base . '/data_old/b.txt', 'b');

        SafePath::removeTree($this->base . '/data_old', $this->base);
        self::assertDirectoryDoesNotExist($this->base . '/data_old');
        SafePath::removeTree($this->base . '/data_old', $this->base);
        self::assertDirectoryExists($this->base);
    }

    public function testAFileWhereATreeIsExpectedIsRefused(): void
    {
        file_put_contents($this->base . '/plain', 'x');
        $this->refuses(fn () => SafePath::removeTree($this->base . '/plain', $this->base), 'not a directory');
        self::assertFileExists($this->base . '/plain');
    }

    public function testRemoveFilesTouchesOnlyMatchingFilesOfThatDirectory(): void
    {
        mkdir($this->base . '/backups/inner', 0777, true);
        file_put_contents($this->base . '/backups/aliases-1.json', '1');
        file_put_contents($this->base . '/backups/aliases-2.json', '2');
        file_put_contents($this->base . '/backups/keep.txt', 'k');
        file_put_contents($this->base . '/backups/inner/aliases-3.json', '3');

        SafePath::removeFiles($this->base . '/backups', 'aliases-*.json', $this->base);

        self::assertFileDoesNotExist($this->base . '/backups/aliases-1.json');
        self::assertFileDoesNotExist($this->base . '/backups/aliases-2.json');
        self::assertFileExists($this->base . '/backups/keep.txt');
        self::assertFileExists($this->base . '/backups/inner/aliases-3.json');
    }

    public function testRemoveFileUnlinksASymlinkWithoutFollowingIt(): void
    {
        $target = TempTree::make('safepath_linkfile');
        file_put_contents($target . '/keep.txt', 'x');
        symlink($target . '/keep.txt', $this->base . '/link');
        try {
            SafePath::removeFile($this->base . '/link', $this->base);
            self::assertFalse(is_link($this->base . '/link'));
            self::assertFileExists($target . '/keep.txt');
        } finally {
            TempTree::remove($target);
        }
    }

    /** The incident: a never-assigned '' directory must never become glob('/*'). */
    public function testAnUnassignedDirectoryNeverExpandsToTheRoot(): void
    {
        $unassigned = '';
        $this->refuses(fn () => TempTree::removeFiles($unassigned));
        $this->refuses(fn () => TempTree::remove($unassigned));
        foreach (['/bin', '/lib', '/usr'] as $path) {
            self::assertFileExists($path);
        }
    }

    public function testNoTestDeletesWithARawGlobOrScandirLoop(): void
    {
        $offenders = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            if (in_array(basename($file), ['SafePathTest.php', 'TempTree.php'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file);
            $globLoop = "#array_map\\(\\s*'unlink'|glob\\([^;]*'/\\*[^']*'\\)[^;]*as\\s+\\$\\w+\\)\\s*\\{[^}]*unlink#s";
            $scanLoop = '#scandir\\([^;]*;[^}]*(unlink|rmdir)#s';
            if (preg_match($globLoop, $source) === 1 || preg_match($scanLoop, $source) === 1) {
                $offenders[] = basename($file);
            }
        }
        self::assertSame([], $offenders, 'delete through TempTree::remove(), never a raw glob/scandir loop');
    }
}
