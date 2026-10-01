<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Writes one row per search to search_logs. `ts` defaults in the schema.
 *
 * M21 adds did_you_mean and tier. search_logs is not swapped by /reload, so an
 * install from before M21 lacks them: the first write that finds them missing
 * adds them (one ALTER), and when the database user may not alter, that write
 * and the following ones fall back to the original columns. The caller treats
 * any exception as a failed log write, never a failed search.
 */
final class Logger
{
    public const TIER_KEYWORD_ONLY = 'keyword_only';
    public const TIER_HYBRID = 'hybrid';

    /** Longest values the VARCHAR columns hold; longer text is cut, not rejected. */
    private const TEXT_LIMIT = 512;

    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $searchLogsTable)
    {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($searchLogsTable);
    }

    /**
     * @param array{
     *     raw_q: string,
     *     normalized_q?: string,
     *     had_vector?: bool,
     *     result_count?: int,
     *     top_ids?: list<int>|string,
     *     customer_id?: ?string,
     *     latency_ms?: int,
     *     did_you_mean?: ?string,
     *     tier?: string
     * } $entry
     */
    public function log(array $entry): void
    {
        $topIds = $entry['top_ids'] ?? '';
        if (is_array($topIds)) {
            $topIds = implode(',', $topIds);
        }
        $didYouMean = $entry['did_you_mean'] ?? null;

        $values = [
            'raw_q'        => mb_substr($entry['raw_q'], 0, self::TEXT_LIMIT),
            'normalized_q' => mb_substr($entry['normalized_q'] ?? '', 0, self::TEXT_LIMIT),
            'had_vector'   => ($entry['had_vector'] ?? false) ? 1 : 0,
            'result_count' => $entry['result_count'] ?? 0,
            'top_ids'      => $topIds,
            'customer_id'  => $entry['customer_id'] ?? null,
            'latency_ms'   => $entry['latency_ms'] ?? 0,
        ];
        $extra = [
            'did_you_mean' => $didYouMean === null ? null : mb_substr($didYouMean, 0, self::TEXT_LIMIT),
            'tier'         => $entry['tier'] ?? self::TIER_KEYWORD_ONLY,
        ];

        try {
            $this->insert($values + $extra);
        } catch (PDOException $e) {
            // 42S22: unknown column, a table from before M21.
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
            $this->addColumns();
            try {
                $this->insert($values + $extra);
            } catch (PDOException $retry) {
                if ($retry->getCode() !== '42S22') {
                    throw $retry;
                }
                $this->insert($values);
            }
        }
    }

    /** @param array<string, mixed> $values column => value */
    private function insert(array $values): void
    {
        $columns = array_keys($values);
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (" . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')'
        );
        $stmt->execute($values);
    }

    /** Best effort: a refused or already applied ALTER is not an error here. */
    private function addColumns(): void
    {
        try {
            $this->pdo->exec(
                "ALTER TABLE {$this->table}
                    ADD COLUMN did_you_mean VARCHAR(512) DEFAULT NULL,
                    ADD COLUMN tier VARCHAR(16) NOT NULL DEFAULT 'keyword_only'"
            );
        } catch (PDOException) {
            // Another request added them first, or the user cannot alter.
        }
    }
}
