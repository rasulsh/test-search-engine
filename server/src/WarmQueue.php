<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * The hand-off between the web side and the cron job that warms the result cache. A web request cannot
 * run a many-minute job, so anything that empties or outdates the cache leaves a small flag file in
 * storage/ (warm-cache.pending); `tools/warm-cache.php --if-pending`, run every minute by cron, picks it
 * up, removes it as the run starts and records the outcome in warm-cache.status.json. Flag and status
 * writes are atomic (temp file + rename); a failure to write is never allowed to fail the caller.
 */
final class WarmQueue
{
    public const PENDING_FILE = 'warm-cache.pending';
    public const STATUS_FILE = 'warm-cache.status.json';
    public const LOCK_FILE = 'warm-cache.lock';
    /** A flag the cron job has not consumed for this long means the cron job is not running (the panel warns). */
    public const STALE_SECONDS = 180;
    /** A run that cannot start (Redis down, lock held) this long after the request gives the flag up. */
    public const GIVE_UP_SECONDS = 1800;
    public const MAX_REASONS = 5;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self((string) $config['paths']['storage']);
    }

    /**
     * Requests a warm-up after an automatic trigger: nothing when `warm.auto` is off or the result cache
     * is off, an audit row (reason only) when a flag was written. Never throws.
     */
    public static function trigger(array $config, PDO $pdo, string $ip, string $reason): bool
    {
        if (!(bool) $config['warm']['auto'] || !(bool) $config['redis']['enabled']) {
            return false;
        }
        try {
            if (!self::fromConfig($config)->request($reason)) {
                return false;
            }
            (new Audit(
                $pdo,
                (string) $config['db']['admin_audit_table'],
                (int) $config['admin']['audit_retention_days']
            ))->write($ip, Audit::WARM_REQUESTED, (string) json_encode(['ok' => true, 'for' => $reason]));

            return true;
        } catch (Throwable $e) {
            error_log('warm: could not record the request: ' . $e->getMessage());

            return true; // the flag itself was written; only its audit row failed
        }
    }

    /**
     * Writes the flag, or, when one is already pending, keeps its first requested_at and appends the reason
     * (at most MAX_REASONS, no repeats).
     */
    public function request(string $reason): bool
    {
        $lock = @fopen($this->dir . '/' . self::PENDING_FILE . '.lock', 'c');
        if ($lock === false) {
            error_log('warm: cannot create the flag lock in ' . $this->dir);

            return false;
        }
        try {
            flock($lock, LOCK_EX);
            $current = $this->pending();
            $reasons = $current['reasons'] ?? [];
            if (!in_array($reason, $reasons, true) && count($reasons) < self::MAX_REASONS) {
                $reasons[] = $reason;
            }

            return $this->writeAtomic(self::PENDING_FILE, [
                'requested_at' => $current['requested_at'] ?? gmdate('c'),
                'reasons' => $reasons,
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{requested_at: string, reasons: list<string>}|null null when no flag (or an unreadable one) */
    public function pending(): ?array
    {
        $data = $this->readJson(self::PENDING_FILE);
        if ($data === null || !is_string($data['requested_at'] ?? null) || strtotime($data['requested_at']) === false) {
            return null;
        }
        $reasons = array_values(array_filter((array) ($data['reasons'] ?? []), 'is_string'));

        return ['requested_at' => $data['requested_at'], 'reasons' => array_slice($reasons, 0, self::MAX_REASONS)];
    }

    /** Cheap: one is_file() and nothing else, for the every-minute cron check. */
    public function hasFlag(): bool
    {
        $path = $this->dir . '/' . self::PENDING_FILE;
        clearstatcache(true, $path); // a long-lived caller (tests) must see the cron job's removal

        return is_file($path);
    }

    public function clear(): void
    {
        @unlink($this->dir . '/' . self::PENDING_FILE);
    }

    /** Seconds the flag has waited (a flag that cannot be read counts as just made, so it is not stuck forever). */
    public function age(?array $pending): int
    {
        return $pending === null ? 0 : max(0, time() - (int) strtotime($pending['requested_at']));
    }

    /** @param array<string, mixed> $status */
    public function writeStatus(array $status): bool
    {
        return $this->writeAtomic(self::STATUS_FILE, $status);
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return $this->readJson(self::STATUS_FILE);
    }

    /** True while a run holds the lock (the OS drops the lock if the run dies, so this cannot stick). */
    public function isRunning(): bool
    {
        $handle = @fopen($this->dir . '/' . self::LOCK_FILE, 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /**
     * What the operations card shows.
     *
     * @return array{state: string, pending: ?array, age: int, last: ?array<string, mixed>}
     *     state: running | waiting | idle
     */
    public function describe(): array
    {
        $pending = $this->pending();
        $running = $this->isRunning();

        return [
            'state' => $running ? 'running' : ($pending !== null ? 'waiting' : 'idle'),
            'pending' => $pending,
            'age' => $this->age($pending),
            'last' => $this->status(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function writeAtomic(string $file, array $data): bool
    {
        $path = $this->dir . '/' . $file;
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            error_log('warm: cannot write ' . $file . ' (is storage/ writable?)');

            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $file): ?array
    {
        $path = $this->dir . '/' . $file;
        clearstatcache(true, $path);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
