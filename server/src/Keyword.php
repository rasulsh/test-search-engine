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
 * Tags, brand, category (M21): oc_tag names (franchise / alternate product
 * names), the manufacturer and the category are searched fields of their own
 * (normalized_tags, normalized_brand, normalized_category). A token not in the
 * title but in one of them is credited that field's weight (tag_weight just
 * below the title, then brand_weight, category_weight, spec_weight); the
 * fields rank in that order and, like specs, every row matching in one of them
 * (and not the title) ranks above every description-only row. A hit holding
 * every term in title or tags is `name_all`: a name match, as solid as a title
 * one for the hybrid floor (SearchController). A live table that predates
 * these columns is searched without them until the next reload.
 *
 * SKU (M12): shoppers paste product codes. A product whose normalized SKU
 * equals the query's (Normalizer::normalizeSku) ranks first, then SKU prefix
 * matches, above every text match. Prefix matching needs at least
 * sku_prefix_min_length characters and a digit, so an ordinary word ("sony")
 * never pulls products whose code happens to start with it to the top.
 *
 * Synonyms and aliases (M15): with a {@see Synonyms} map, the query is also
 * searched as each variant with a whole-term synonym or alias swapped in ("gta
 * 5" also as "grand theft auto 5"), at most $maxVariants queries in all, the
 * literal one included. A variant is a product-name form, so it is matched in
 * the title only (every token a word prefix in normalized_title, scored as a
 * full title match): that keeps a synonym from pulling in products that merely
 * mention it in their description, and lets all variants share one scan of a
 * covering title index — the three-field LIKE fallback a short-token variant
 * ("جی تی ای") would otherwise take costs several times the latency budget on
 * a full catalog. Hits are merged keeping each product's best field band and
 * score; ties keep the literal query's order first.
 *
 * All terms (M16): a hit holds every query token somewhere in title, specs or
 * description combined, per variant (the union across variants); a product
 * matching only some tokens is not a hit. With require_all_terms off, or via
 * {@see search()} with $allTerms false (the caller's fallback when the strict
 * query found nothing), products matching ANY token are returned instead,
 * ranked by how many tokens they hold first, then by the field score; those
 * that miss a token carry match_type "partial". Alias variants stay
 * all-terms in both modes.
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
    /**
     * Searched columns after the title, best field first, in the order of the
     * FULLTEXT index definition (db/schema.sql): [column, weight]. Pared down
     * when the live table predates them (see dropNewestFields()).
     *
     * @var list<array{0: string, 1: float}>
     */
    private array $fields;
    private ?Synonyms $synonyms;
    private int $maxVariants;
    private bool $requireAllTerms;
    /** False once the live table turned out to predate the idx_title_scan index (M15). */
    private bool $titleIndex = true;

    public const MATCH_SKU = 'sku';
    public const MATCH_ALIAS = 'alias';
    /** A hit that misses at least one query token (any-terms mode only). */
    public const MATCH_PARTIAL = 'partial';
    private const TAGS_COLUMN = 'normalized_tags';
    private const SPECS_COLUMN = 'normalized_specs';
    /** Columns added in M21; a live table from an older bundle lacks them. */
    private const M21_COLUMNS = [self::TAGS_COLUMN, 'normalized_brand', 'normalized_category'];
    /** Covering index for the title-only variant scan (db/schema.sql). */
    private const TITLE_INDEX = 'idx_title_scan';
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
        float $specWeight = 6.0,
        ?Synonyms $synonyms = null,
        int $maxVariants = 6,
        bool $requireAllTerms = true,
        float $tagWeight = 8.0,
        float $brandWeight = 7.0,
        float $categoryWeight = 5.0
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
        $this->fields = [
            [self::TAGS_COLUMN, max(0.0, $tagWeight)],
            ['normalized_brand', max(0.0, $brandWeight)],
            ['normalized_category', max(0.0, $categoryWeight)],
            [self::SPECS_COLUMN, max(0.0, $specWeight)],
        ];
        $this->synonyms = $synonyms;
        $this->maxVariants = max(1, $maxVariants);
        $this->requireAllTerms = $requireAllTerms;
    }

    /**
     * The normalized query's tokens first, then its alias / synonym variants
     * (the ones {@see search()} also looks up); Ranker's brand / category / tag
     * boosts match against all of them.
     *
     * @return list<list<string>>
     */
    public function variants(string $query): array
    {
        $tokens = Tokenizer::split(($this->normalize)($query));
        if ($tokens === []) {
            return [];
        }

        return $this->synonyms?->variants($tokens, $this->maxVariants) ?? [$tokens];
    }

    public function requiresAllTerms(): bool
    {
        return $this->requireAllTerms;
    }

    /**
     * @param bool $allTerms false searches any-terms even when require_all_terms is on
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    public function search(string $query, ?int $limit = null, bool $allTerms = true): array
    {
        $limit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
        $tokens = Tokenizer::split(($this->normalize)($query));
        if ($tokens === []) {
            return [];
        }

        $skuHits = $this->skuSearch(Normalizer::normalizeSku($query), $limit);
        $variants = $this->titleIndex ? $this->synonyms?->variants($tokens, $this->maxVariants) : null;
        $variants ??= [$tokens];
        // One token: any-terms and all-terms are the same query.
        $allTerms = ($allTerms && $this->requireAllTerms) || count($tokens) === 1;
        $textHits = count($variants) === 1
            ? $this->textSearch($tokens, $limit, $allTerms)
            : $this->variantSearch($variants, $limit, $allTerms);
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
     * The literal query as usual, the other variants in one title-only scan,
     * merged: a product keeps its best (all terms, title band, spec band,
     * score); ties keep first-seen order, which lists the literal query's hits
     * first.
     *
     * @param non-empty-list<list<string>> $variants
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function variantSearch(array $variants, int $limit, bool $allTerms): array
    {
        $rank = static fn (array $hit): array => [
            $hit['match_type'] !== self::MATCH_PARTIAL,
            $hit['title_match'],
            $hit['spec_match'] && !$hit['title_match'],
            $hit['score'],
        ];
        $best = [];
        $hits = array_merge(
            $this->textSearch($variants[0], $limit, $allTerms),
            $this->titleSearch(array_slice($variants, 1), $limit)
        );
        foreach ($hits as $hit) {
            $id = $hit['product_id'];
            if (!isset($best[$id])) {
                $best[$id] = [count($best), $hit];
            } elseif ($rank($hit) > $rank($best[$id][1])) {
                $best[$id][1] = $hit;
            }
        }
        usort($best, static fn (array $a, array $b): int => [$rank($b[1]), $a[0]] <=> [$rank($a[1]), $b[0]]);

        return array_slice(array_column($best, 1), 0, $limit);
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function textSearch(array $tokens, int $limit, bool $allTerms): array
    {
        while (true) {
            try {
                return $this->textSearchOnce($tokens, $limit, $allTerms);
            } catch (PDOException $e) {
                // 42S22 (unknown column): the live table predates M13 (specs) or
                // M21 (tags, brand, category), e.g. between code upload and
                // reload, or after a rollback. Search it without those columns.
                if ($e->getCode() !== '42S22' || !$this->dropNewestFields()) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Stop searching the newest generation of extra columns: the M21 ones
     * first, then specs. False when none are left to drop.
     */
    private function dropNewestFields(): bool
    {
        if ($this->fields === []) {
            return false;
        }
        $older = array_values(array_filter(
            $this->fields,
            static fn (array $field): bool => !in_array($field[0], self::M21_COLUMNS, true)
        ));
        $this->fields = count($older) === count($this->fields) ? [] : $older;

        return true;
    }

    /** @return list<string> every searched column, in FULLTEXT index order */
    private function searchedColumns(): array
    {
        return array_merge(['normalized_title'], array_column($this->fields, 0), ['normalized_desc']);
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function textSearchOnce(array $tokens, int $limit, bool $allTerms): array
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) < $this->minTokenSize) {
                return $this->likeSearch($tokens, $limit, $allTerms);
            }
        }

        return $this->fulltextSearch($tokens, $limit, $allTerms);
    }

    /**
     * Exact SKU matches first, then prefix matches (shortest code first), via
     * the normalized_sku index. SKU hits count as title matches, so the
     * description-only gate and the hybrid merge keep them.
     *
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
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
                'title_all'   => true,
                'name_all'    => true,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function fulltextSearch(array $tokens, int $limit, bool $allTerms): array
    {
        // The column list must equal the FULLTEXT index definition exactly.
        $match = 'MATCH(' . implode(', ', $this->searchedColumns()) . ') AGAINST(? IN BOOLEAN MODE)';
        // Prefix-matched, "+word*" when every token is required, else "word*".
        $expr = implode(' ', array_map(
            static fn (string $t): string => ($allTerms ? '+' : '') . $t . '*',
            $tokens
        ));
        $termHits = null;
        if (!$allTerms) {
            $termHits = [
                '(' . implode(' + ', array_fill(0, count($tokens), "({$match} > 0)")) . ')',
                array_map(static fn (string $t): string => $t . '*', $tokens),
            ];
        }

        return $this->scoredSearch($tokens, $match, [$expr], $match, [$expr], $limit, 'fulltext', $termHits);
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function likeSearch(array $tokens, int $limit, bool $allTerms): array
    {
        $clauses = [];
        $params = [];
        $columns = $this->searchedColumns();
        foreach ($tokens as $token) {
            $clauses[] = '(' . implode(' LIKE ? OR ', $columns) . ' LIKE ?)';
            $params = array_merge($params, array_fill(0, count($columns), '%' . $this->escapeLike($token) . '%'));
        }
        $termHits = $allTerms ? null : ['(' . implode(' + ', $clauses) . ')', $params];

        return $this->scoredSearch(
            $tokens,
            '0',
            [],
            implode($allTerms ? ' AND ' : ' OR ', $clauses),
            $params,
            $limit,
            'like',
            $termHits
        );
    }

    /**
     * Rank the rows matching $where by the field-weighted score: title band,
     * then the band of the other structured fields (tags, brand, category,
     * specs: a match there, none in the title), then description-only.
     * $relevance (the engine's own score, or a constant) only breaks ties
     * between equal field scores, ahead of popularity.
     *
     * $termHits (any-terms mode) is an SQL expression counting the tokens a
     * row holds in any field, with its params. Rows are then ranked by that
     * count before the bands, a missing token earns no weight, and a row
     * missing any token is hydrated as a partial match. Without it every row
     * holds every token ($where requires them all).
     *
     * @param list<string> $tokens
     * @param list<string> $relevanceParams
     * @param list<string> $whereParams
     * @param null|array{0: string, 1: list<string>} $termHits
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function scoredSearch(
        array $tokens,
        string $relevance,
        array $relevanceParams,
        string $where,
        array $whereParams,
        int $limit,
        string $matchType,
        ?array $termHits = null
    ): array {
        // One CASE per token names the best field it is found in (1 = title,
        // 2.. = $this->fields in order, 0 = description only): one regex chain
        // per token, everything below is arithmetic on these levels. Tokens
        // hold only letters and digits (Tokenizer), so they are safe inside a
        // regex and need no escaping; they are still bound.
        $levels = [];
        $params = $relevanceParams;
        foreach ($tokens as $i => $token) {
            $branches = 'WHEN normalized_title REGEXP ? THEN 1';
            $params[] = self::WORD_START . $token;
            foreach ($this->fields as $n => [$column]) {
                $branches .= " WHEN {$column} REGEXP ? THEN " . ($n + 2);
                $params[] = self::WORD_START . $token;
            }
            $levels[] = "CASE {$branches} ELSE 0 END AS lv{$i}";
        }
        $phrase = '0';
        if (count($tokens) > 1) {
            $phrase = '(normalized_title REGEXP ?)';
            $params[] = self::WORD_START . implode(self::BOUNDARY . '+', $tokens);
        }
        $count = count($tokens);
        $terms = (string) $count;
        if ($termHits !== null) {
            $terms = $termHits[0];
            $params = array_merge($params, $termHits[1]);
        }

        // Weights come from config as floats; inlined in a fixed format.
        $weight = static fn (float $w): string => sprintf('%.6F', $w);
        $levelWeights = [1 => $this->titleWeight];
        $tagsLevel = null;
        foreach ($this->fields as $n => [$column, $fieldWeight]) {
            $levelWeights[$n + 2] = $fieldWeight;
            $tagsLevel = $column === self::TAGS_COLUMN ? $n + 2 : $tagsLevel;
        }
        $sum = static function (callable $term) use ($count): string {
            $parts = [];
            for ($i = 0; $i < $count; $i++) {
                $parts[] = $term($i);
            }

            return '(' . implode(' + ', $parts) . ')';
        };
        $titleHits = $sum(static fn (int $i): string => "(lv{$i} = 1)");
        $fieldHits = $sum(static fn (int $i): string => "(lv{$i} > 1)");
        // Title or tags: the product's names, solid evidence (see title_all).
        $nameHits = $sum(static fn (int $i): string => "(lv{$i} = 1"
            . ($tagsLevel === null ? '' : " OR lv{$i} = {$tagsLevel}") . ')');
        $credit = $sum(static function (int $i) use ($levelWeights, $weight): string {
            $cases = '';
            foreach ($levelWeights as $level => $w) {
                $cases .= " WHEN {$level} THEN " . $weight($w);
            }

            return "CASE lv{$i}{$cases} ELSE 0 END";
        });
        // GREATEST: a word-prefix REGEXP and the engine's own match can
        // disagree on an odd token edge; never credit a negative count.
        $score = "(({$credit} + " . $weight($this->descWeight)
               . " * GREATEST(term_hits - {$titleHits} - {$fieldHits}, 0))"
               . " / {$count} + " . $weight($this->phraseBonus) . ' * phrase_hit)';

        // The inner LIMIT keeps the derived table from being merged into the
        // outer query (MySQL and MariaDB both materialize a derived table with
        // a LIMIT); merged, every use of a level in the score and ORDER BY
        // re-ran its REGEXPs, 3x slower with the specs.
        $sql = "SELECT product_id, {$titleHits} AS title_hits, {$fieldHits} AS spec_hits,
                       {$nameHits} AS name_hits, term_hits < {$count} AS partial, {$score} AS score
                FROM (
                    SELECT product_id, popularity,
                           {$relevance} AS relevance,
                           " . implode(', ', $levels) . ",
                           {$phrase} AS phrase_hit,
                           {$terms} AS term_hits
                    FROM {$this->table}
                    WHERE {$where}
                    LIMIT " . PHP_INT_MAX . "
                ) matched
                ORDER BY term_hits DESC, ({$titleHits} > 0) DESC, ({$titleHits} = 0 AND {$fieldHits} > 0) DESC,
                         score DESC, relevance DESC, popularity DESC, product_id ASC
                LIMIT " . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($params, $whereParams));

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), $matchType, count($tokens));
    }

    /**
     * Rows whose title holds every token of at least one variant as a word
     * prefix (the FULLTEXT "word*" semantics), scored like a full title match
     * in {@see scoredSearch}: the phrase bonus when some multi-token variant
     * appears adjacent and in order. All variants share one scan of the
     * covering title index; the LIKE prefilter lets the cheap substring test
     * reject most rows before the REGEXP runs. Without that index the scan
     * reads every row's long text (seconds per query on a full catalog), so a
     * live table that predates it (before the first M15 reload) gets none.
     *
     * @param list<list<string>> $variants
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function titleSearch(array $variants, int $limit): array
    {
        $phrases = [];
        $phraseParams = [];
        $matches = [];
        $matchParams = [];
        foreach ($variants as $tokens) {
            $all = [];
            foreach ($tokens as $token) {
                $all[] = 'normalized_title LIKE ? AND normalized_title REGEXP ?';
                array_push($matchParams, '%' . $this->escapeLike($token) . '%', self::WORD_START . $token);
            }
            $matches[] = '(' . implode(' AND ', $all) . ')';
            if (count($tokens) > 1) {
                $phrases[] = 'normalized_title REGEXP ?';
                $phraseParams[] = self::WORD_START . implode(self::BOUNDARY . '+', $tokens);
            }
        }
        $phrase = $phrases === [] ? '0' : '(' . implode(' OR ', $phrases) . ')';

        // Materialized first (the inner LIMIT, as in scoredSearch): sorted
        // directly, MariaDB abandons the covering title-index scan and takes
        // ~50x longer on a 20k catalog.
        $sql = 'SELECT product_id, 1 AS title_hits, 0 AS spec_hits, '
             . sprintf('%.6F + %.6F', $this->titleWeight, $this->phraseBonus) . " * {$phrase} AS score
                FROM (
                    SELECT product_id, popularity, normalized_title
                    FROM {$this->table} FORCE INDEX (" . self::TITLE_INDEX . ')
                    WHERE ' . implode(' OR ', $matches) . '
                    LIMIT ' . PHP_INT_MAX . '
                ) matched
                ORDER BY score DESC, popularity DESC, product_id ASC
                LIMIT ' . (int) $limit;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($phraseParams, $matchParams));
        } catch (PDOException $e) {
            // 1176: key does not exist — the live table predates M15.
            if (($e->errorInfo[1] ?? null) !== 1176) {
                throw $e;
            }
            $this->titleIndex = false;

            return [];
        }

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), self::MATCH_ALIAS);
    }

    /** Escape LIKE wildcards (defensive; tokenization already strips them). */
    private function escapeLike(string $token): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool
     * }>
     */
    private function hydrate(array $rows, string $matchType, int $termCount = 1): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id'  => (int) $row['product_id'],
                'score'       => (float) $row['score'],
                'match_type'  => (int) ($row['partial'] ?? 0) === 1 ? self::MATCH_PARTIAL : $matchType,
                'title_match' => (int) $row['title_hits'] > 0,
                'spec_match'  => (int) $row['spec_hits'] > 0,
                'title_all'   => (int) $row['title_hits'] >= $termCount,
                'name_all'    => (int) ($row['name_hits'] ?? $row['title_hits']) >= $termCount,
            ],
            $rows
        );
    }
}
