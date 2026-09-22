<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Tier 1 keyword search over the FULLTEXT-indexed normalized_* columns.
 *
 * Two paths:
 *  - FULLTEXT boolean mode (prefix, all-terms-required) when every token is at
 *    least min_token_size long.
 *  - A LIKE scan when any token is shorter, because InnoDB drops tokens below
 *    innodb_ft_min_token_size (default 3, unchangeable on shared cPanel).
 *
 * M1 searches the passthrough-normalized columns; M2's Normalizer improves recall
 * (ZWNJ, ی/ک unification, digit folding) without changing this class.
 */
final class Keyword
{
    private PDO $pdo;
    private string $table;
    private int $minTokenSize;
    private int $defaultLimit;
    /** @var callable(?string): string */
    private $normalize;

    public function __construct(
        PDO $pdo,
        string $productsTable,
        int $minTokenSize = 3,
        int $defaultLimit = 20,
        ?callable $normalizer = null
    ) {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($productsTable);
        $this->minTokenSize = max(1, $minTokenSize);
        $this->defaultLimit = max(1, $defaultLimit);
        // Query is normalized the same way the indexed columns were, so both
        // sides of the match agree (contract 1).
        $this->normalize = $normalizer ?? [Normalizer::class, 'normalize'];
    }

    /**
     * @return list<array{product_id: int, score: float, match_type: string}>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $limit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
        $tokens = Tokenizer::split(($this->normalize)($query));
        if ($tokens === []) {
            return [];
        }

        foreach ($tokens as $token) {
            if (mb_strlen($token) < $this->minTokenSize) {
                return $this->likeSearch($tokens, $limit);
            }
        }

        return $this->fulltextSearch($tokens, $limit);
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string}>
     */
    private function fulltextSearch(array $tokens, int $limit): array
    {
        // Each token required and prefix-matched: "+word*".
        $expr = implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $tokens));

        $sql = "SELECT product_id,
                       MATCH(normalized_title, normalized_desc) AGAINST(? IN BOOLEAN MODE) AS score
                FROM {$this->table}
                WHERE MATCH(normalized_title, normalized_desc) AGAINST(? IN BOOLEAN MODE)
                ORDER BY score DESC, popularity DESC, product_id ASC
                LIMIT " . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$expr, $expr]);

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), 'fulltext');
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string}>
     */
    private function likeSearch(array $tokens, int $limit): array
    {
        $clauses = [];
        $params = [];
        foreach ($tokens as $token) {
            $clauses[] = '(normalized_title LIKE ? OR normalized_desc LIKE ?)';
            $like = '%' . $this->escapeLike($token) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT product_id, 0 AS score
                FROM {$this->table}
                WHERE " . implode(' AND ', $clauses) . "
                ORDER BY popularity DESC, product_id ASC
                LIMIT " . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), 'like');
    }

    /** Escape LIKE wildcards (defensive; tokenization already strips them). */
    private function escapeLike(string $token): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{product_id: int, score: float, match_type: string}>
     */
    private function hydrate(array $rows, string $matchType): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'score'      => (float) $row['score'],
                'match_type' => $matchType,
            ],
            $rows
        );
    }
}
