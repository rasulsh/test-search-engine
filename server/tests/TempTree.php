<?php

declare(strict_types=1);

namespace App\Tests;

use App\SafePath;

/**
 * Every test directory lives under one root and is removed only through SafePath, so a test
 * helper holding an empty or unset path throws instead of expanding "" . "/*" to the filesystem root.
 */
final class TempTree
{
    public static function root(): string
    {
        $root = rtrim((string) realpath(sys_get_temp_dir()), '/') . '/search-engine-tests';
        if (!is_dir($root)) {
            @mkdir($root, 0777, true);
        }

        return $root;
    }

    /** A fresh, not yet created path under the root. */
    public static function path(string $prefix): string
    {
        return self::root() . '/' . $prefix . '_' . uniqid('', true);
    }

    /** A fresh, created directory under the root. */
    public static function make(string $prefix): string
    {
        $dir = self::path($prefix);
        mkdir($dir, 0777, true);

        return $dir;
    }

    /** Removes a directory tree under the root; '' (a never-assigned path) is refused, not expanded. */
    public static function remove(string $dir): void
    {
        SafePath::removeTree($dir, self::root());
    }

    /** For tearDown: a skipped setUp never assigned the directory, so there is nothing to remove. */
    public static function removeIfAssigned(string $dir): void
    {
        if ($dir !== '') {
            self::remove($dir);
        }
    }

    /** Removes the matching files of one directory under the root. */
    public static function removeFiles(string $dir, string $pattern = '*'): void
    {
        SafePath::removeFiles($dir, $pattern, self::root());
    }
}
