<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Writes one row per search to search_logs. `ts` defaults in the schema.
 */
final class Logger
{
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
     *     latency_ms?: int
     * } $entry
     */
    public function log(array $entry): void
    {
        $topIds = $entry['top_ids'] ?? '';
        if (is_array($topIds)) {
            $topIds = implode(',', $topIds);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
                (raw_q, normalized_q, had_vector, result_count, top_ids, customer_id, latency_ms)
             VALUES (:raw_q, :normalized_q, :had_vector, :result_count, :top_ids, :customer_id, :latency_ms)"
        );

        $stmt->execute([
            'raw_q'        => $entry['raw_q'],
            'normalized_q' => $entry['normalized_q'] ?? '',
            'had_vector'   => ($entry['had_vector'] ?? false) ? 1 : 0,
            'result_count' => $entry['result_count'] ?? 0,
            'top_ids'      => $topIds,
            'customer_id'  => $entry['customer_id'] ?? null,
            'latency_ms'   => $entry['latency_ms'] ?? 0,
        ]);
    }
}
