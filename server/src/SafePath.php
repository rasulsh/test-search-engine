<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * The only place that deletes directory trees. A path built from an empty or unset value
 * ('' . '/*' is the filesystem root) must never reach unlink()/rmdir(), so every delete is
 * checked against an expected base and throws instead of deleting when the check fails.
 */
final class SafePath
{
    /** "/" has 0 segments; "/tmp/x" has 2. A real target (a data dir, a test dir) has at least 3. */
    public const MIN_SEGMENTS = 3;

    /**
     * Validates a delete target and returns its real path, or null when nothing exists there.
     * Refuses: an empty / relative / NUL-containing path or base, "/", fewer than MIN_SEGMENTS
     * segments, a symlink, the base itself, and anything not strictly inside the real base.
     */
    public static function resolve(string $path, string $base): ?string
    {
        foreach ([$path, $base] as $value) {
            if ($value === '' || $value[0] !== '/' || str_contains($value, "\0")) {
                throw new RuntimeException('refusing to delete: path and base must be non-empty absolute paths');
            }
        }

        $realBase = realpath($base);
        if ($realBase === false || $realBase === '/' || self::segments($realBase) < 1) {
            throw new RuntimeException('refusing to delete: the base directory is missing or is the filesystem root');
        }

        if (is_link($path)) {
            throw new RuntimeException("refusing to delete {$path}: it is a symlink");
        }
        if (!file_exists($path)) {
            // Nothing to delete, but the location must still be one we would have accepted.
            $parent = realpath(dirname($path));
            $lexical = ($parent === false ? '' : $parent) . '/' . basename($path);
            self::assertInside($lexical, $realBase);

            return null;
        }

        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException("refusing to delete {$path}: it cannot be resolved");
        }
        self::assertInside($real, $realBase);

        return $real;
    }

    /** Removes a directory tree. Symlinks inside it are unlinked, never followed. */
    public static function removeTree(string $path, string $base): void
    {
        $real = self::resolve($path, $base);
        if ($real === null) {
            return;
        }
        if (!is_dir($real)) {
            throw new RuntimeException("refusing to delete {$real}: not a directory");
        }
        self::clear($real, (string) realpath($base));
        if (!rmdir($real)) {
            throw new RuntimeException("cannot remove {$real}");
        }
    }

    /** Removes the files of one directory (not the directory) whose names match a glob pattern. */
    public static function removeFiles(string $dir, string $pattern, string $base): void
    {
        $real = self::resolve($dir, $base);
        if ($real === null) {
            return;
        }
        foreach (glob($real . '/' . $pattern) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                self::removeFile($file, $base);
            }
        }
    }

    /** Removes one file (or one symlink, without following it). */
    public static function removeFile(string $file, string $base): void
    {
        if (is_link($file)) {
            self::assertInside(self::lexicalParent($file) . '/' . basename($file), (string) realpath($base));
            if (!unlink($file)) {
                throw new RuntimeException("cannot remove {$file}");
            }

            return;
        }
        $real = self::resolve($file, $base);
        if ($real === null) {
            return;
        }
        if (!is_file($real)) {
            throw new RuntimeException("refusing to delete {$real}: not a file");
        }
        if (!unlink($real)) {
            throw new RuntimeException("cannot remove {$real}");
        }
    }

    private static function clear(string $dir, string $realBase): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $dir . '/' . $item;
            if (is_link($child) || !is_dir($child)) {
                if (!unlink($child)) {
                    throw new RuntimeException("cannot remove {$child}");
                }
                continue;
            }
            self::assertInside((string) realpath($child), $realBase);
            self::clear($child, $realBase);
            if (!rmdir($child)) {
                throw new RuntimeException("cannot remove {$child}");
            }
        }
    }

    private static function assertInside(string $real, string $realBase): void
    {
        if (self::segments($real) < self::MIN_SEGMENTS) {
            $min = self::MIN_SEGMENTS;
            throw new RuntimeException("refusing to delete {$real}: fewer than {$min} path segments");
        }
        if (!str_starts_with($real, rtrim($realBase, '/') . '/')) {
            throw new RuntimeException("refusing to delete {$real}: outside {$realBase}");
        }
    }

    private static function lexicalParent(string $path): string
    {
        $parent = realpath(dirname($path));

        return $parent === false ? '' : $parent;
    }

    private static function segments(string $path): int
    {
        return count(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
    }
}
