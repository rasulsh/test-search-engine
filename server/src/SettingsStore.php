<?php

declare(strict_types=1);

namespace App;

/**
 * The panel's settings file (M46): `<paths.storage>/settings.json`, a flat JSON object of
 * 'dotted.key' => value holding only the keys the owner changed from the inherited (config.php or code)
 * value. Only the admin panel writes it; {@see Config::load()} reads it on every request.
 *
 * Built like {@see AliasStore}: a save takes an exclusive lock, refuses when the file changed since the
 * form loaded it (a stale version hash), keeps the previous content as a timestamped backup (the newest
 * `$keepBackups` stay) and replaces the file through a temp file and a rename, so a reader sees the old or
 * the new file, never half of one. A restore is a save, so it is itself backed up and can be undone.
 */
final class SettingsStore
{
    private const LOCK = '.settings.lock';
    private const BACKUPS = 'backups';
    private const BACKUP_PATTERN = '/^settings-\d{8}-\d{6}-\d{6}\.json$/';

    private readonly string $dir;

    public function __construct(private readonly string $file, private readonly int $keepBackups = 20)
    {
        // An unset config value would otherwise become a file at the filesystem root.
        if ($file === '' || $file[0] !== '/') {
            throw new SettingsStoreException('io', 'the settings path must be a non-empty absolute path');
        }
        $this->dir = dirname($file);
    }

    /** @param array<string, mixed> $config the loaded server config */
    public static function fromConfig(array $config): self
    {
        return new self(
            Config::settingsFile((string) $config['paths']['storage']),
            (int) $config['admin']['settings_backups']
        );
    }

    public function path(): string
    {
        return $this->file;
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /** Whether a save can work: the directory is writable, or can be created in a writable parent. */
    public function writable(): bool
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        return is_writable(dirname($this->dir));
    }

    /**
     * @return array{values: array<string, mixed>, version: string, exists: bool, problem: ?string,
     *         ignored: list<string>, invalid: list<string>}
     */
    public function read(): array
    {
        $layer = Config::panelLayer($this->file);
        $raw = $layer['exists'] ? (string) @file_get_contents($this->file) : '';

        return [
            'values' => $layer['values'],
            'version' => self::versionOf($raw, $layer['exists']),
            'exists' => $layer['exists'],
            'problem' => $layer['problem'],
            'ignored' => $layer['ignored'],
            'invalid' => $layer['invalid'],
        ];
    }

    /** The content hash a form sends back with its save; '' for a file that does not exist. */
    public static function versionOf(string $raw, bool $exists = true): string
    {
        return $exists ? sha1($raw) : '';
    }

    /**
     * @param array<string, mixed> $values already validated, only keys that differ from the inherited value
     * @param string $expectedVersion the version the form started from
     * @return string the new version
     * @throws SettingsStoreException stale, busy, io
     */
    public function save(array $values, string $expectedVersion): string
    {
        $this->ensureDirectory();

        return $this->withLock(function () use ($values, $expectedVersion): string {
            $exists = is_file($this->file);
            $current = $exists ? (string) file_get_contents($this->file) : '';
            if (!hash_equals(self::versionOf($current, $exists), $expectedVersion)) {
                throw new SettingsStoreException('stale');
            }
            $next = self::encode($values);
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
     * Makes the backup the current file (the replaced content is backed up first). Only editable keys with a
     * valid value of the backup are taken over.
     *
     * @return string the new version
     * @throws SettingsStoreException stale, busy, unknown_backup, io
     */
    public function restore(string $backup, string $expectedVersion): string
    {
        $raw = $this->backupContent($backup);
        $parsed = $raw === null ? null : Config::parsePanel($raw);
        if ($parsed === null || $parsed['problem'] !== null) {
            throw new SettingsStoreException('unknown_backup', $backup);
        }

        return $this->save($parsed['values'], $expectedVersion);
    }

    /**
     * @return list<array{name: string, time: int, size: int, keys: int|null}> newest first; keys null = unreadable
     */
    public function backups(): array
    {
        $found = [];
        foreach (glob($this->dir . '/' . self::BACKUPS . '/settings-*.json') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::BACKUP_PATTERN, $name) !== 1) {
                continue;
            }
            $parsed = Config::parsePanel((string) @file_get_contents($file));
            $found[] = [
                'name' => $name,
                'time' => (int) @filemtime($file),
                'size' => (int) @filesize($file),
                'keys' => $parsed['problem'] === null ? count($parsed['values']) : null,
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
        $file = $this->dir . '/' . self::BACKUPS . '/' . $name;
        $raw = is_file($file) ? @file_get_contents($file) : false;

        return $raw === false ? null : $raw;
    }

    /** @param array<string, mixed> $values */
    public static function encode(array $values): string
    {
        ksort($values);

        return (string) json_encode(
            (object) $values,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) . "\n";
    }

    private function ensureDirectory(): void
    {
        foreach ([$this->dir, $this->dir . '/' . self::BACKUPS] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new SettingsStoreException('io', "cannot create {$dir}");
            }
        }
        // A misplaced tree must not publish the settings or their history.
        $deny = $this->dir . '/.htaccess';
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
        $handle = @fopen($this->dir . '/' . self::LOCK, 'c');
        if ($handle === false) {
            throw new SettingsStoreException('io', 'cannot open the lock file');
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
                throw new SettingsStoreException('busy');
            }

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeAtomically(string $content): void
    {
        $temp = tempnam($this->dir, 'settings.tmp');
        if ($temp === false || file_put_contents($temp, $content) === false) {
            throw new SettingsStoreException('io', 'cannot write the temporary file');
        }
        @chmod($temp, 0644);
        if (!@rename($temp, $this->file)) {
            @unlink($temp);
            throw new SettingsStoreException('io', 'cannot replace the settings file');
        }
    }

    private function backUp(string $content): void
    {
        $stamp = microtime(true);
        $micro = (int) (($stamp - floor($stamp)) * 1_000_000);
        $name = sprintf('settings-%s-%06d.json', gmdate('Ymd-His', (int) $stamp), $micro);
        $file = $this->dir . '/' . self::BACKUPS . '/' . $name;
        if (file_put_contents($file, $content) === false) {
            throw new SettingsStoreException('io', 'cannot write the backup');
        }
        $names = array_column($this->backups(), 'name');
        foreach (array_slice($names, max(1, $this->keepBackups)) as $old) {
            SafePath::removeFile($this->dir . '/' . self::BACKUPS . '/' . $old, $this->dir);
        }
    }
}
