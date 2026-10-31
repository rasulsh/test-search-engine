<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Writes one row per search to search_logs. `ts` defaults in the schema.
 *
 * M21 adds did_you_mean and tier, M26 adds cache_hit, log version 2 the columns of
 * db/migrations/2026-10-24_search_logs_v2.sql. search_logs is not swapped
 * by /reload, so an install from before them lacks the columns: the first write
 * that finds one missing adds what is missing (ALTER .. IF NOT EXISTS), and when
 * the database user may not alter, that write and the following ones fall back
 * to the columns the table has. The caller treats any exception as a failed log
 * write, never a failed search.
 *
 * Privacy (M43): a query that looks like personal data is stored masked, never as typed: a run of
 * {@see MASK_DIGITS} or more digits (a phone or ID number) becomes "#", and a query longer than
 * {@see MASK_LONG_QUERY} characters (a pasted paragraph) is replaced by a placeholder. Flagged
 * probes are kept verbatim: they are not shopper input and the panel lists them for review.
 */
final class Logger
{
    public const TIER_KEYWORD_ONLY = 'keyword_only';
    public const TIER_HYBRID = 'hybrid';

    /** Rows written by this code carry log_version 2; older rows keep the column default 1. */
    public const LOG_VERSION = 2;

    /** Default window of the repeat-submit flag; config `log.duplicate_seconds`. */
    public const DUPLICATE_SECONDS = 120;

    /** This many identical page-1 rows of one session within BURST_SECONDS are a burst: the last ones are flagged. */
    public const BURST_ROWS = 4;
    public const BURST_SECONDS = 60;

    /** A run of this many digits (any script) is phone / ID-like. */
    public const MASK_DIGITS = 7;
    /** A raw query longer than this is not a product search. */
    public const MASK_LONG_QUERY = 80;
    public const LONG_PLACEHOLDER = '[long query]';

    /** Longest values the VARCHAR columns hold; longer text is cut, not rejected. */
    private const TEXT_LIMIT = 512;
    private const BRAND_LIMIT = 190;

    private PDO $pdo;
    private string $table;
    private int $duplicateSeconds;

    public function __construct(PDO $pdo, string $searchLogsTable, int $duplicateSeconds = self::DUPLICATE_SECONDS)
    {
        $this->duplicateSeconds = max(0, $duplicateSeconds);
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
     *     tier?: string,
     *     cache_hit?: bool,
     *     search_uuid?: string,
     *     session_id?: ?string,
     *     source?: string,
     *     page?: int,
     *     expanded_q?: ?string,
     *     alias_hit?: bool,
     *     dym_shown?: bool,
     *     is_capped?: bool,
     *     lat_keyword_ms?: ?int,
     *     lat_vector_ms?: ?int,
     *     lat_spell_ms?: ?int,
     *     is_suspicious?: bool,
     *     brand_unmatched?: ?string,
     *     is_burst?: bool
     * } $entry
     */
    public function log(array $entry): void
    {
        $topIds = $entry['top_ids'] ?? '';
        if (is_array($topIds)) {
            $topIds = implode(',', $topIds);
        }
        $didYouMean = $entry['did_you_mean'] ?? null;
        $expanded = $entry['expanded_q'] ?? null;
        $rawQ = $entry['raw_q'];
        $normalizedQ = $entry['normalized_q'] ?? '';
        if (!($entry['is_suspicious'] ?? false)) {
            $long = mb_strlen($rawQ) > self::MASK_LONG_QUERY;
            $rawQ = $long ? self::LONG_PLACEHOLDER : self::maskDigits($rawQ);
            $normalizedQ = $long ? self::LONG_PLACEHOLDER : self::maskDigits($normalizedQ);
            $didYouMean = $didYouMean === null ? null : self::maskDigits($didYouMean);
            $expanded = $expanded === null ? null : self::maskDigits($expanded);
        }

        $values = [
            'raw_q'        => mb_substr($rawQ, 0, self::TEXT_LIMIT),
            'normalized_q' => mb_substr($normalizedQ, 0, self::TEXT_LIMIT),
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
        $cache = ['cache_hit' => ($entry['cache_hit'] ?? false) ? 1 : 0];

        $session = $entry['session_id'] ?? null;
        $page = max(1, min(255, (int) ($entry['page'] ?? 1)));
        $v2 = [
            'log_version'    => self::LOG_VERSION,
            'search_uuid'    => $entry['search_uuid'] ?? '',
            'session_id'     => $session,
            'source'         => $entry['source'] ?? 'page',
            'page'           => $page,
            'expanded_q'     => $expanded === null ? null : mb_substr($expanded, 0, self::TEXT_LIMIT),
            'alias_hit'      => ($entry['alias_hit'] ?? false) ? 1 : 0,
            'dym_shown'      => ($entry['dym_shown'] ?? false) ? 1 : 0,
            'is_capped'      => ($entry['is_capped'] ?? false) ? 1 : 0,
            'lat_keyword_ms' => $entry['lat_keyword_ms'] ?? null,
            'lat_vector_ms'  => $entry['lat_vector_ms'] ?? null,
            'lat_spell_ms'   => $entry['lat_spell_ms'] ?? null,
            'is_suspicious'  => ($entry['is_suspicious'] ?? false) ? 1 : 0,
            'brand_unmatched' => isset($entry['brand_unmatched'])
                ? mb_substr((string) $entry['brand_unmatched'], 0, self::BRAND_LIMIT)
                : null,
            'is_duplicate'   => $this->isDuplicate($session, $values['normalized_q'], $page) ? 1 : 0,
            'is_burst'       => $this->isBurst($session, $values['normalized_q'], $page) ? 1 : 0,
        ];

        // Fewest columns last: the full row, then without v2, then without M26, then the original.
        $ladder = [$values + $extra + $cache + $v2, $values + $extra + $cache, $values + $extra, $values];
        try {
            $this->insert($ladder[0]);
        } catch (PDOException $e) {
            // 42S22: unknown column, a table from before M21 / M26 / v2.
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
            $this->addColumns();
            foreach ($ladder as $i => $row) {
                try {
                    $this->insert($row);

                    return;
                } catch (PDOException $retry) {
                    if ($retry->getCode() !== '42S22' || $i === count($ladder) - 1) {
                        throw $retry;
                    }
                }
            }
        }
    }

    /** Every run of {@see MASK_DIGITS} or more digits (Latin, Persian or Arabic-Indic) becomes "#". */
    public static function maskDigits(string $text): string
    {
        return (string) preg_replace('/\p{Nd}{' . self::MASK_DIGITS . ',}/u', '#', $text);
    }

    /**
     * The same session searched the same normalized query on the same page in the
     * last `log.duplicate_seconds` (a double submit, a refresh): it still gets a row, flagged,
     * so counts can leave it out. Best effort: a table without the v2 columns says no.
     */
    private function isDuplicate(?string $sessionId, string $normalizedQuery, int $page): bool
    {
        if ($this->duplicateSeconds === 0 || $sessionId === null || $sessionId === '' || $normalizedQuery === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM {$this->table} WHERE session_id = :session AND normalized_q = :query"
                . ' AND page = :page AND ts >= NOW() - INTERVAL ' . $this->duplicateSeconds . ' SECOND LIMIT 1'
            );
            $stmt->execute(['session' => $sessionId, 'query' => $normalizedQuery, 'page' => $page]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * This row would be the BURST_ROWS-th or later identical page-1 row within BURST_SECONDS: of one
     * session, or, without a session, of the same normalized query. Rows already flagged count, so every
     * row after the third is marked. Best effort: a table without the columns says no.
     */
    private function isBurst(?string $sessionId, string $normalizedQuery, int $page): bool
    {
        if ($page !== 1 || $normalizedQuery === '') {
            return false;
        }
        $hasSession = $sessionId !== null && $sessionId !== '';
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE normalized_q = :query AND page = 1"
                . ($hasSession ? ' AND session_id = :session' : " AND (session_id IS NULL OR session_id = '')")
                . ' AND ts >= NOW() - INTERVAL ' . self::BURST_SECONDS . ' SECOND'
            );
            $stmt->execute(['query' => $normalizedQuery] + ($hasSession ? ['session' => $sessionId] : []));

            return (int) $stmt->fetchColumn() >= self::BURST_ROWS - 1;
        } catch (PDOException) {
            return false;
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

    /**
     * Best effort: a refused or already applied ALTER is not an error here. The
     * indexes come from the migration file (a column-only upgrade still works).
     */
    private function addColumns(): void
    {
        try {
            $this->pdo->exec(
                "ALTER TABLE {$this->table}
                    ADD COLUMN IF NOT EXISTS did_you_mean VARCHAR(512) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS tier VARCHAR(16) NOT NULL DEFAULT 'keyword_only',
                    ADD COLUMN IF NOT EXISTS cache_hit TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS log_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    ADD COLUMN IF NOT EXISTS search_uuid CHAR(36) NOT NULL DEFAULT '',
                    ADD COLUMN IF NOT EXISTS session_id VARCHAR(64) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'page',
                    ADD COLUMN IF NOT EXISTS page TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    ADD COLUMN IF NOT EXISTS expanded_q VARCHAR(512) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS alias_hit TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS dym_shown TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS is_capped TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS lat_keyword_ms INT UNSIGNED DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS lat_vector_ms INT UNSIGNED DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS lat_spell_ms INT UNSIGNED DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS brand_unmatched VARCHAR(190) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS is_burst TINYINT(1) NOT NULL DEFAULT 0"
            );
        } catch (PDOException) {
            // Another request added them first, or the user cannot alter.
        }
    }
}
