<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

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
 *
 * Specs (M13): attribute pairs and feature titles live in normalized_specs, a
 * third field between title and description: a token not in the title but in
 * the specs is credited spec_weight, and every row with a spec match (and no
 * title match) ranks above every description-only row:
 *
 *   score = (title_weight * title_hits + spec_weight * spec_hits
 *            + desc_weight * (tokens - title_hits - spec_hits)) / tokens
 *         + phrase_bonus * (tokens appear adjacent, in order, in the title)
 *
 * The specs are only inspected (word-prefix REGEXP) for tokens missing from
 * the title. A live table from a bundle built before M13 has no
 * normalized_specs column: search then runs on title + description as before
 * until the next reload.
 *
 * SKU (M12): shoppers paste product codes. A product whose normalized SKU
 * equals the query's (Normalizer::normalizeSku) ranks first, then SKU prefix
 * matches, above every text match. Prefix matching needs at least
 * sku_prefix_min_length characters and a digit, so an ordinary word ("sony")
 * never pulls products whose code happens to start with it to the top.
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
    private int $skuPrefixMinLength;
    private float $specWeight;
    /** False once the live table turned out to predate the normalized_specs column. */
    private bool $specs = true;

    public const MATCH_SKU = 'sku';
    /** SKU hits outrank any text score (title band tops out near title_weight + phrase_bonus). */
    private const SKU_EXACT_SCORE = 2000000.0;
    private const SKU_PREFIX_SCORE = 1000000.0;

    /** Non-word boundary, matching Tokenizer's split on [^\p{L}\p{N}]. */
    private const BOUNDARY = '[^\\p{L}\\p{N}]';
    /**
     * Word start: not preceded by a letter or digit. Same matches as
     * "(^|BOUNDARY)", but a lookbehind lets the regex engine scan for the
     * token's literal first, about 3x faster on the longer specs text.
     */
    private const WORD_START = '(?<![\\p{L}\\p{N}])';

    public function __construct(
        PDO $pdo,
        string $productsTable,
        int $minTokenSize = 3,
        int $defaultLimit = 20,
        ?callable $normalizer = null,
        float $titleWeight = 10.0,
        float $descWeight = 1.0,
        float $phraseBonus = 5.0,
        int $skuPrefixMinLength = 4,
        float $specWeight = 6.0
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
        $this->skuPrefixMinLength = max(1, $skuPrefixMinLength);
        $this->specWeight = max(0.0, $specWeight);
    }

    /**
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $limit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
        $tokens = Tokenizer::split(($this->normalize)($query));
        if ($tokens === []) {
            return [];
        }

        $skuHits = $this->skuSearch(Normalizer::normalizeSku($query), $limit);
        $textHits = $this->textSearch($tokens, $limit);
        if ($skuHits === []) {
            return $textHits;
        }

        $seen = array_flip(array_column($skuHits, 'product_id'));
        foreach ($textHits as $hit) {
            if (!isset($seen[$hit['product_id']])) {
                $skuHits[] = $hit;
            }
        }

        return array_slice($skuHits, 0, $limit);
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function textSearch(array $tokens, int $limit): array
    {
        try {
            return $this->textSearchOnce($tokens, $limit);
        } catch (PDOException $e) {
            // 42S22 (unknown column): the live table predates M13 (between code
            // upload and reload, or after a rollback). Search it without specs.
            if (!$this->specs || $e->getCode() !== '42S22') {
                throw $e;
            }
            $this->specs = false;

            return $this->textSearchOnce($tokens, $limit);
        }
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function textSearchOnce(array $tokens, int $limit): array
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) < $this->minTokenSize) {
                return $this->likeSearch($tokens, $limit);
            }
        }

        return $this->fulltextSearch($tokens, $limit);
    }

    /**
     * Exact SKU matches first, then prefix matches (shortest code first), via
     * the normalized_sku index. SKU hits count as title matches, so the
     * description-only gate and the hybrid merge keep them.
     *
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function skuSearch(string $sku, int $limit): array
    {
        if ($sku === '') {
            return [];
        }
        $prefix = mb_strlen($sku) >= $this->skuPrefixMinLength && preg_match('/\p{N}/u', $sku) === 1;

        $sql = "SELECT product_id, normalized_sku = ? AS exact_hit
                FROM {$this->table}
                WHERE " . ($prefix ? 'normalized_sku LIKE ?' : 'normalized_sku = ?') . "
                ORDER BY exact_hit DESC, CHAR_LENGTH(normalized_sku) ASC, popularity DESC, product_id ASC
                LIMIT " . (int) $limit;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sku, $prefix ? $this->escapeLike($sku) . '%' : $sku]);
        } catch (PDOException $e) {
            // 42S22 (unknown column): a products table from a bundle built before
            // M12 is still live, e.g. between code upload and reload. Text search
            // still finds the product; the next reload adds the column.
            if ($e->getCode() === '42S22') {
                return [];
            }
            throw $e;
        }

        return array_map(
            static fn (array $row): array => [
                'product_id'  => (int) $row['product_id'],
                'score'       => (int) $row['exact_hit'] === 1 ? self::SKU_EXACT_SCORE : self::SKU_PREFIX_SCORE,
                'match_type'  => self::MATCH_SKU,
                'title_match' => true,
                'spec_match'  => false,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function fulltextSearch(array $tokens, int $limit): array
    {
        // Each token required and prefix-matched: "+word*".
        $expr = implode(' ', array_map(static fn (string $t): string => '+' . $t . '*', $tokens));
        // The column list must equal the FULLTEXT index definition exactly.
        $match = 'MATCH(' . ($this->specs ? 'normalized_title, normalized_specs, normalized_desc'
            : 'normalized_title, normalized_desc') . ') AGAINST(? IN BOOLEAN MODE)';

        return $this->scoredSearch(
            $tokens,
            $match,
            [$expr],
            $match,
            [$expr],
            $limit,
            'fulltext'
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function likeSearch(array $tokens, int $limit): array
    {
        $clauses = [];
        $params = [];
        $columns = $this->specs
            ? ['normalized_title', 'normalized_specs', 'normalized_desc']
            : ['normalized_title', 'normalized_desc'];
        foreach ($tokens as $token) {
            $clauses[] = '(' . implode(' LIKE ? OR ', $columns) . ' LIKE ?)';
            $params = array_merge($params, array_fill(0, count($columns), '%' . $this->escapeLike($token) . '%'));
        }

        return $this->scoredSearch($tokens, '0', [], implode(' AND ', $clauses), $params, $limit, 'like');
    }

    /**
     * Rank the rows matching $where by the field-weighted score: title band,
     * then spec band, then description-only. $relevance (the engine's own
     * score, or a constant) only breaks ties between equal field scores, ahead
     * of popularity.
     *
     * @param list<string> $tokens
     * @param list<string> $relevanceParams
     * @param list<string> $whereParams
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
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
        $specHits = [];
        $params = $relevanceParams;
        $specParams = [];
        foreach ($tokens as $token) {
            // Tokens hold only letters and digits (Tokenizer), so they are safe
            // inside a regex and need no escaping; they are still bound.
            $pattern = self::WORD_START . $token;
            $titleHits[] = '(normalized_title REGEXP ?)';
            $params[] = $pattern;
            if ($this->specs) {
                $specHits[] = '(CASE WHEN normalized_title REGEXP ? THEN 0 ELSE normalized_specs REGEXP ? END)';
                array_push($specParams, $pattern, $pattern);
            }
        }
        $params = array_merge($params, $specParams);
        $phrase = '0';
        if (count($tokens) > 1) {
            $phrase = '(normalized_title REGEXP ?)';
            $params[] = self::WORD_START . implode(self::BOUNDARY . '+', $tokens);
        }

        // Weights come from config as floats; inlined in a fixed format.
        $weight = static fn (float $w): string => sprintf('%.6F', $w);
        $count = count($tokens);
        $score = '((' . $weight($this->titleWeight) . ' * title_hits + '
               . $weight($this->specWeight) . ' * spec_hits + '
               . $weight($this->descWeight) . " * ({$count} - title_hits - spec_hits)) / {$count}"
               . ' + ' . $weight($this->phraseBonus) . ' * phrase_hit)';

        // The inner LIMIT keeps the derived table from being merged into the
        // outer query (MySQL and MariaDB both materialize a derived table with
        // a LIMIT); merged, every use of title_hits / spec_hits in the score
        // and ORDER BY re-ran their REGEXPs, 3x slower with the specs.
        $sql = "SELECT product_id, title_hits, spec_hits, {$score} AS score
                FROM (
                    SELECT product_id, popularity,
                           {$relevance} AS relevance,
                           (" . implode(' + ', $titleHits) . ") AS title_hits,
                           (" . ($specHits === [] ? '0' : implode(' + ', $specHits)) . ") AS spec_hits,
                           {$phrase} AS phrase_hit
                    FROM {$this->table}
                    WHERE {$where}
                    LIMIT " . PHP_INT_MAX . "
                ) matched
                ORDER BY (title_hits > 0) DESC, (spec_hits > 0) DESC, score DESC, relevance DESC,
                         popularity DESC, product_id ASC
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
     * @return list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>
     */
    private function hydrate(array $rows, string $matchType): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id'  => (int) $row['product_id'],
                'score'       => (float) $row['score'],
                'match_type'  => $matchType,
                'title_match' => (int) $row['title_hits'] > 0,
                'spec_match'  => (int) $row['spec_hits'] > 0,
            ],
            $rows
        );
    }
}
