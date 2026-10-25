<?php

declare(strict_types=1);

namespace App;

/**
 * The shop owner's alias file (M41): `<paths.storage>/aliases.json`, the only copy. The admin panel
 * edits it; the search reads it; a bundle reload never touches it (the bundle carries no aliases).
 *
 * A save takes an exclusive lock, refuses when the file changed since the editor loaded it (a stale
 * version hash), keeps the previous content as a timestamped backup (the newest `$keepBackups` stay),
 * writes a temp file in the same directory and renames it into place, so a reader sees the old or the
 * new file, never half of one.
 *
 * Before the first panel visit after an upgrade the storage file does not exist yet: {@see runtimePath()}
 * then points the search at the live bundle's old `aliases.json` (read only), and
 * {@see seedIfMissing()} copies it over.
 */
final class AliasStore
{
    public const FILE = 'aliases.json';
    private const LOCK = '.aliases.lock';
    private const BACKUPS = 'backups';
    private const BACKUP_PATTERN = '/^aliases-\d{8}-\d{6}-\d{6}\.json$/';

    public function __construct(
        private readonly string $storageDir,
        private readonly string $bundleFile,
        private readonly int $keepBackups = 20
    ) {
        // An unset config value would otherwise become "/aliases.json" at the filesystem root.
        if ($storageDir === '' || $storageDir[0] !== '/') {
            throw new AliasStoreException('io', 'the storage path must be a non-empty absolute path');
        }
    }

    /** @param array<string, mixed> $config the loaded server config */
    public static function fromConfig(array $config): self
    {
        return new self(
            rtrim((string) $config['paths']['storage'], '/'),
            rtrim((string) $config['paths']['data'], '/') . '/' . Synonyms::ALIASES_FILE,
            (int) $config['admin']['alias_backups']
        );
    }

    public function path(): string
    {
        return $this->storageDir . '/' . self::FILE;
    }

    public function directory(): string
    {
        return $this->storageDir;
    }

    /** The file the search reads: the storage file, else (until it is seeded) the live bundle's. */
    public function runtimePath(): string
    {
        return is_file($this->path()) ? $this->path() : $this->bundleFile;
    }

    /**
     * Creates the storage file when it is missing: a copy of the live bundle's aliases.json when that
     * parses, else an empty list. Never overwrites. Returns true when it wrote the file.
     */
    public function seedIfMissing(): bool
    {
        if (is_file($this->path())) {
            return false;
        }
        $this->ensureDirectory();
        $seed = is_file($this->bundleFile) ? @file_get_contents($this->bundleFile) : false;
        $groups = $seed === false ? null : Synonyms::parseGroups($seed);
        $this->withLock(function () use ($groups): void {
            if (!is_file($this->path())) { // someone else seeded while we waited
                $this->writeAtomically(self::encode($groups ?? []));
            }
        });

        return true;
    }

    /**
     * @return array{groups: list<list<string>>, version: string, exists: bool, valid: bool, raw: string}
     *         `valid` false when the file exists but is not a list of lists of strings (groups is then empty)
     */
    public function read(): array
    {
        $raw = is_file($this->path()) ? (string) @file_get_contents($this->path()) : '';
        $groups = Synonyms::parseGroups($raw);

        return [
            'groups' => $groups ?? [],
            'version' => self::versionOf($raw, is_file($this->path())),
            'exists' => is_file($this->path()),
            'valid' => $groups !== null || $raw === '',
            'raw' => $raw,
        ];
    }

    /** The content hash an editor sends back with its save; '' for a file that does not exist. */
    public static function versionOf(string $raw, bool $exists = true): string
    {
        return $exists ? sha1($raw) : '';
    }

    /**
     * @param list<list<string>> $groups already validated and cleaned
     * @param string $expectedVersion the version the editor started from
     * @return string the new version
     * @throws AliasStoreException stale (the file changed meanwhile), busy, io
     */
    public function save(array $groups, string $expectedVersion): string
    {
        $this->ensureDirectory();

        return $this->withLock(function () use ($groups, $expectedVersion): string {
            $exists = is_file($this->path());
            $current = $exists ? (string) file_get_contents($this->path()) : '';
            if (!hash_equals(self::versionOf($current, $exists), $expectedVersion)) {
                throw new AliasStoreException('stale');
            }
            $next = self::encode($groups);
            if ($exists && $next === $current) {
                return self::versionOf($current);
            }
            if ($exists) {
                $this->backUp($current);
            }
            $this->writeAtomically($next);

            return self::versionOf($next);
        });
    }

    /**
     * Makes the backup the current file (the replaced content is backed up first, so a restore can be undone).
     *
     * @return string the new version
     * @throws AliasStoreException stale, busy, unknown_backup, io
     */
    public function restore(string $backup, string $expectedVersion): string
    {
        $raw = $this->backupContent($backup);
        $groups = $raw === null ? null : Synonyms::parseGroups($raw);
        if ($groups === null) {
            throw new AliasStoreException('unknown_backup', $backup);
        }

        return $this->save($groups, $expectedVersion);
    }

    /**
     * @return list<array{name: string, time: int, size: int, groups: int|null}> newest first
     */
    public function backups(): array
    {
        $found = [];
        foreach (glob($this->storageDir . '/' . self::BACKUPS . '/aliases-*.json') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::BACKUP_PATTERN, $name) !== 1) {
                continue;
            }
            $groups = Synonyms::parseGroups((string) @file_get_contents($file));
            $found[] = [
                'name' => $name,
                'time' => (int) @filemtime($file),
                'size' => (int) @filesize($file),
                'groups' => $groups === null ? null : count($groups),
            ];
        }
        usort($found, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $found;
    }

    /** The backup's file content, or null for a name that is not one of ours (never a path). */
    public function backupContent(string $name): ?string
    {
        if (preg_match(self::BACKUP_PATTERN, $name) !== 1) {
            return null;
        }
        $file = $this->storageDir . '/' . self::BACKUPS . '/' . $name;
        $raw = is_file($file) ? @file_get_contents($file) : false;

        return $raw === false ? null : $raw;
    }

    /** @param list<list<string>> $groups */
    public static function encode(array $groups): string
    {
        return (string) json_encode(
            array_values(array_map('array_values', $groups)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    private function ensureDirectory(): void
    {
        foreach ([$this->storageDir, $this->storageDir . '/' . self::BACKUPS] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new AliasStoreException('io', "cannot create {$dir}");
            }
        }
        // A misplaced tree must not publish the aliases or their history.
        $deny = $this->storageDir . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "# Not web-accessible (admin panel storage).\nRequire all denied\n");
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function withLock(callable $work): mixed
    {
        $handle = @fopen($this->storageDir . '/' . self::LOCK, 'c');
        if ($handle === false) {
            throw new AliasStoreException('io', 'cannot open the lock file');
        }
        try {
            $locked = false;
            for ($attempt = 0; $attempt < 30 && !$locked; $attempt++) {
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    usleep(100_000);
                }
            }
            if (!$locked) {
                throw new AliasStoreException('busy');
            }

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeAtomically(string $content): void
    {
        $temp = tempnam($this->storageDir, 'aliases.tmp');
        if ($temp === false || file_put_contents($temp, $content) === false) {
            throw new AliasStoreException('io', 'cannot write the temporary file');
        }
        @chmod($temp, 0644);
        if (!@rename($temp, $this->path())) {
            @unlink($temp);
            throw new AliasStoreException('io', 'cannot replace the alias file');
        }
    }

    private function backUp(string $content): void
    {
        $stamp = microtime(true);
        $micro = (int) (($stamp - floor($stamp)) * 1_000_000);
        $name = sprintf('aliases-%s-%06d.json', gmdate('Ymd-His', (int) $stamp), $micro);
        $file = $this->storageDir . '/' . self::BACKUPS . '/' . $name;
        if (file_put_contents($file, $content) === false) {
            throw new AliasStoreException('io', 'cannot write the backup');
        }
        $names = array_column($this->backups(), 'name');
        foreach (array_slice($names, max(1, $this->keepBackups)) as $old) {
            SafePath::removeFile($this->storageDir . '/' . self::BACKUPS . '/' . $old, $this->storageDir);
        }
    }
}
