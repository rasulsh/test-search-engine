<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Writes one row per PHP -> VPS call to vps_calls (db/migrations/2026-11-03_vps_calls.sql), the evidence
 * behind the admin panel's VPS page. Only cache misses reach the VPS, so a row per call is cheap.
 *
 * Fail-soft by construction: record() never throws and never changes what /search returns; a failed
 * insert (a missing table, a full disk) is one error_log line. Timestamps are UTC. Retention is purged
 * on a small share of the writes (a bounded DELETE on idx_ts), so no write pays for it every time.
 * The table name is fixed: this feature has one config key, vps.log_retention_days.
 */
final class VpsCallLog
{
    public const TABLE = 'vps_calls';
    public const KINDS = ['search', 'reload', 'probe'];
    public const OUTCOMES = ['ok', 'timeout', 'unreachable', 'http_error', 'bad_body', 'not_configured'];

    private const ERROR_LIMIT = 200;
    private const QUERY_LIMIT = 120;
    private const PURGE_BATCH = 500;

    /**
     * @param int $purgeOneIn one write in this many also purges old rows (1 = every write; tests)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $retentionDays,
        private readonly int $purgeOneIn = 50
    ) {
    }

    /**
     * @param array{
     *     kind: string,
     *     outcome: string,
     *     http_status?: ?int,
     *     duration_ms?: ?int,
     *     connect_ms?: ?int,
     *     ttfb_ms?: ?int,
     *     timeout_ms?: ?int,
     *     error?: ?string,
     *     response_bytes?: ?int,
     *     neighbours?: ?int,
     *     request_id?: ?string,
     *     vps_queue_ms?: ?float,
     *     vps_embed_ms?: ?float,
     *     vps_search_ms?: ?float,
     *     vps_inflight?: ?int,
     *     normalized_q?: ?string
     * } $call
     */
    public function record(array $call): void
    {
        try {
            $query = $call['normalized_q'] ?? null;
            if ($query !== null) {
                // The same privacy rule as search_logs: a long paste or a phone-like number is not stored.
                $query = mb_strlen($query) > Logger::MASK_LONG_QUERY
                    ? Logger::LONG_PLACEHOLDER
                    : mb_substr(Logger::maskDigits($query), 0, self::QUERY_LIMIT);
            }
            $error = $call['error'] ?? null;
            $row = [
                'kind' => in_array($call['kind'], self::KINDS, true) ? $call['kind'] : 'search',
                'outcome' => in_array($call['outcome'], self::OUTCOMES, true) ? $call['outcome'] : 'unreachable',
                'http_status' => $call['http_status'] ?? null,
                'duration_ms' => $call['duration_ms'] ?? null,
                'connect_ms' => $call['connect_ms'] ?? null,
                'ttfb_ms' => $call['ttfb_ms'] ?? null,
                'timeout_ms' => $call['timeout_ms'] ?? null,
                'error' => $error === null || $error === '' ? null : mb_substr($error, 0, self::ERROR_LIMIT),
                'response_bytes' => $call['response_bytes'] ?? null,
                'neighbours' => $call['neighbours'] ?? null,
                'request_id' => $call['request_id'] ?? null,
                'vps_queue_ms' => $call['vps_queue_ms'] ?? null,
                'vps_embed_ms' => $call['vps_embed_ms'] ?? null,
                'vps_search_ms' => $call['vps_search_ms'] ?? null,
                'vps_inflight' => $call['vps_inflight'] ?? null,
                'normalized_q' => $query,
            ];
            $columns = array_keys($row);
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (ts, ' . implode(', ', $columns) . ') VALUES (UTC_TIMESTAMP(), :'
                . implode(', :', $columns) . ')'
            );
            $stmt->execute($row);
            if ($this->purgeOneIn <= 1 || random_int(1, $this->purgeOneIn) === 1) {
                $this->purge();
            }
        } catch (Throwable $e) {
            error_log('vps_calls: could not record a call (' . get_class($e) . ')');
        }
    }

    /** Deletes up to a batch of rows older than the retention; returns how many went. */
    public function purge(): int
    {
        $days = max(1, $this->retentionDays);

        return (int) $this->pdo->exec(
            'DELETE FROM ' . self::TABLE . " WHERE ts < UTC_TIMESTAMP() - INTERVAL {$days} DAY LIMIT "
            . self::PURGE_BATCH
        );
    }
}
