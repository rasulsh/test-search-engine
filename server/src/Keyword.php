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
 * Both paths share one field-weighted score (M10). A combined title+desc match
 * score let long descriptions that merely mention the query words (bundles,
 * accessories) outrank the products actually named by it, so each query token
 * is credited to the best field it matched in:
 *
 *   score = (title_weight * title_hits + desc_weight * (tokens - title_hits)) / tokens
 *         + phrase_bonus * (tokens appear adjacent, in order, in the title)
 *
 * Both paths require every token in title or description, so a token that is
 * not in the title is in the description: only the short title is inspected
 * per row, never the long description text. Title hits are word-prefix matches
 * (the FULLTEXT "word*" semantics) via REGEXP. Every row with at least one
 * token in its title ranks above every description-only row, whatever the
 * weights; the weights order rows within those two bands.
 */
final class Keyword
{
    private PDO $pdo;
    private string $table;
    private int $minTokenSize;
    private int $defaultLimit;
    /** @var callable(?string): string */
    private $normalize;
    private float $titleWeight;
    private float $descWeight;
    private float $phraseBonus;

    /** Non-word boundary, matching Tokenizer's split on [^\p{L}\p{N}]. */
    private const BOUNDARY = '[^\\p{L}\\p{N}]';

    public function __construct(
        PDO $pdo,
        string $productsTable,
        int $minTokenSize = 3,
        int $defaultLimit = 20,
        ?callable $normalizer = null,
        float $titleWeight = 10.0,
        float $descWeight = 1.0,
        float $phraseBonus = 5.0
    ) {
        $this->pdo = $pdo;
        $this->table = Identifier::quote($productsTable);
        $this->minTokenSize = max(1, $minTokenSize);
        $this->defaultLimit = max(1, $defaultLimit);
        // Query is normalized the same way the indexed columns were, so both
        // sides of the match agree (contract 1).
        $this->normalize = $normalizer ?? [Normalizer::class, 'normalize'];
        $this->titleWeight = max(0.0, $titleWeight);
        $this->descWeight = max(0.0, $descWeight);
        $this->phraseBonus = max(0.0, $phraseBonus);
    }

    /**
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool}>
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
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool}>
     */
    private function fulltextSearch(array $tokens, int $limit): array
    {
        // Each token required and prefix-matched: "+word*".
        $expr = implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $tokens));

        return $this->scoredSearch(
            $tokens,
            'MATCH(normalized_title, normalized_desc) AGAINST(? IN BOOLEAN MODE)',
            [$expr],
            'MATCH(normalized_title, normalized_desc) AGAINST(? IN BOOLEAN MODE)',
            [$expr],
            $limit,
            'fulltext'
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool}>
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

        return $this->scoredSearch($tokens, '0', [], implode(' AND ', $clauses), $params, $limit, 'like');
    }

    /**
     * Rank the rows matching $where by the field-weighted score, title band
     * first. $relevance (the engine's own score, or a constant) only breaks ties
     * between equal field scores, ahead of popularity.
     *
     * @param list<string> $tokens
     * @param list<string> $relevanceParams
     * @param list<string> $whereParams
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool}>
     */
    private function scoredSearch(
        array $tokens,
        string $relevance,
        array $relevanceParams,
        string $where,
        array $whereParams,
        int $limit,
        string $matchType
    ): array {
        $titleHits = [];
        $params = $relevanceParams;
        foreach ($tokens as $token) {
            // Tokens hold only letters and digits (Tokenizer), so they are safe
            // inside a regex and need no escaping; they are still bound.
            $titleHits[] = '(normalized_title REGEXP ?)';
            $params[] = '(^|' . self::BOUNDARY . ')' . $token;
        }
        $phrase = '0';
        if (count($tokens) > 1) {
            $phrase = '(normalized_title REGEXP ?)';
            $params[] = '(^|' . self::BOUNDARY . ')' . implode(self::BOUNDARY . '+', $tokens);
        }

        // Weights come from config as floats; inlined in a fixed format.
        $weight = static fn (float $w): string => sprintf('%.6F', $w);
        $count = count($tokens);
        $score = '((' . $weight($this->titleWeight) . ' * title_hits + '
               . $weight($this->descWeight) . " * ({$count} - title_hits)) / {$count}"
               . ' + ' . $weight($this->phraseBonus) . ' * phrase_hit)';

        $sql = "SELECT product_id, title_hits, {$score} AS score
                FROM (
                    SELECT product_id, popularity,
                           {$relevance} AS relevance,
                           (" . implode(' + ', $titleHits) . ") AS title_hits,
                           {$phrase} AS phrase_hit
                    FROM {$this->table}
                    WHERE {$where}
                ) matched
                ORDER BY (title_hits > 0) DESC, score DESC, relevance DESC, popularity DESC, product_id ASC
                LIMIT " . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($params, $whereParams));

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), $matchType);
    }

    /** Escape LIKE wildcards (defensive; tokenization already strips them). */
    private function escapeLike(string $token): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool}>
     */
    private function hydrate(array $rows, string $matchType): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id'  => (int) $row['product_id'],
                'score'       => (float) $row['score'],
                'match_type'  => $matchType,
                'title_match' => (int) $row['title_hits'] > 0,
            ],
            $rows
        );
    }
}
