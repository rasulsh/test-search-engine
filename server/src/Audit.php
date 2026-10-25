<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * The admin panel's audit trail (M39): logins, failed logins and every write action.
 * The failed-login throttle counts the same rows, so no second table or lock file is needed.
 * Never given a password: callers pass an action code and a short detail only.
 */
final class Audit
{
    public const LOGIN_OK = 'login_ok';
    public const LOGIN_FAIL = 'login_fail';
    public const LOGIN_THROTTLED = 'login_throttled';
    public const LOGOUT = 'logout';
    public const RELOAD_API = 'reload_api';
    public const RELOAD_VALIDATE = 'reload_validate';
    public const RELOAD_APPLY = 'reload_apply';
    public const ROLLBACK = 'rollback';
    public const CACHE_FLUSH = 'cache_flush';
    public const VPS_RELOAD = 'vps_reload';
    public const ALIAS_SAVE = 'alias_save';
    public const ALIAS_RESTORE = 'alias_restore';

    private string $table;

    public function __construct(private readonly PDO $pdo, string $table, private readonly int $retentionDays)
    {
        $this->table = Identifier::quote($table);
    }

    /**
     * Appends a row, then drops a small batch of rows past the retention (cheap: idx_ts, LIMIT).
     *
     * @return int the new row's id
     */
    public function write(string $ip, string $action, string $detail = ''): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (ip, action, detail) VALUES (:ip, :action, :detail)"
        );
        $stmt->execute([
            'ip' => mb_substr($ip, 0, 45),
            'action' => mb_substr($action, 0, 32),
            'detail' => mb_substr($detail, 0, 512),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $days = max(1, $this->retentionDays);
        $this->pdo->exec("DELETE FROM {$this->table} WHERE ts < NOW() - INTERVAL {$days} DAY LIMIT 200");

        return $id;
    }

    /**
     * The newest rows of the given actions, newest first.
     *
     * @param list<string> $actions
     * @return list<array<string, mixed>>
     */
    public function latest(array $actions, int $limit): array
    {
        if ($actions === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            "SELECT id, ts, ip, action, detail FROM {$this->table} WHERE action IN ({$marks})"
            . " ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute(array_values($actions));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Failed logins inside the window; for one address, or (null) from every address. */
    public function failures(?string $ip, int $windowMinutes): int
    {
        $minutes = max(1, $windowMinutes);
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE action = :action"
            . " AND ts >= NOW() - INTERVAL {$minutes} MINUTE" . ($ip === null ? '' : ' AND ip = :ip');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['action' => self::LOGIN_FAIL] + ($ip === null ? [] : ['ip' => $ip]));

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, from: int, to: int}
     */
    public function recent(int $page, int $pageSize = 50): array
    {
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
        $paging = Paging::of($total, $page, $pageSize);
        $rows = $this->pdo->query(
            "SELECT id, ts, ip, action, detail FROM {$this->table} ORDER BY id DESC"
            . " LIMIT {$paging['limit']} OFFSET {$paging['offset']}"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows' => $rows, 'total' => $paging['total'], 'page' => $paging['page'], 'pages' => $paging['pages'],
            'from' => $paging['from'], 'to' => $paging['to'],
        ];
    }

    /** Timestamp of the newest row of any of $actions, or null. */
    public function lastOf(array $actions): ?string
    {
        if ($actions === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $stmt = $this->pdo->prepare("SELECT MAX(ts) FROM {$this->table} WHERE action IN ({$marks})");
        $stmt->execute(array_values($actions));
        $ts = $stmt->fetchColumn();

        return is_string($ts) ? $ts : null;
    }
}
