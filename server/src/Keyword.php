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
 *
 * Alias variants (M23): a query with several aliased terms also gets the
 * variant with all of them swapped ({@see Synonyms::variants()}), and a variant
 * whose tokens are all FULLTEXT-sized is matched in the title, tags, brand,
 * category and specs (never the description: a synonym must not pull in
 * products that merely mention it), not only the title. A variant with a short
 * token keeps the title-only covering-index scan: its LIKE alternative reads
 * every field of every row.
 *
 * Soft AND (M23): when fewer than soft_and_min_results products hold every
 * term (across the literal query and its variants), a multi-word query also
 * returns products holding at least soft_and_min_coverage of a variant's
 * terms. They are "partial" matches ranked strictly below every full-coverage
 * hit (most terms first, then the field score scaled by
 * soft_and_partial_penalty), so a token nothing matches ("وارفار" before its
 * alias is known) no longer empties the result. 0 results = off. An alias
 * variant's top-up only looks at rows holding a word it swapped in and scores
 * at most soft_and_candidate_cap of them (the engine's best first): a swapped-in
 * word as common as a stop word cannot cost a full scan per variant.
 *
 * Collapsed names (M23): "farcry" and "far cry" are one name. The collapsed
 * query (tokens joined) is substring-matched against normalized_collapsed (the
 * collapsed title, brand and tags) and verified to start at a word of the
 * spaced fields, then merged as a name hit scored collapse_weight, below a
 * regular title match. A live table without the column is searched without it
 * until the next reload.
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
    private float $collapseWeight;
    private int $collapseMinLength;
    /** False once the live table turned out to predate normalized_collapsed (M23). */
    private bool $collapsedColumn = true;
    private int $softAndMinResults;
    private float $softAndMinCoverage;
    private float $softAndPartialPenalty;
    private int $softAndCandidateCap;

    public const MATCH_SKU = 'sku';
    public const MATCH_ALIAS = 'alias';
    public const MATCH_COLLAPSED = 'collapsed';
    /** A hit that misses at least one query token (any-terms mode only). */
    public const MATCH_PARTIAL = 'partial';
    private const TAGS_COLUMN = 'normalized_tags';
    private const SPECS_COLUMN = 'normalized_specs';
    /** Columns added in M21; a live table from an older bundle lacks them. */
    private const M21_COLUMNS = [self::TAGS_COLUMN, 'normalized_brand', 'normalized_category'];
    /** Covering index for the title-only variant scan (db/schema.sql). */
    private const TITLE_INDEX = 'idx_title_scan';
    /** Covering index of the collapsed-identity scan (db/schema.sql). */
    private const COLLAPSED_INDEX = 'idx_collapsed_scan';
    /** SKU hits outrank any text score (title band tops out near title_weight + phrase_bonus). */
    private const SKU_EXACT_SCORE = 2000000.0;
    private const SKU_PREFIX_SCORE = 1000000.0;
    /** Longest collapsed query that is regex-verified (longer ones are not product names). */
    private const COLLAPSED_QUERY_MAX = 64;

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
        float $categoryWeight = 5.0,
        float $collapseWeight = 9.0,
        int $collapseMinLength = 5,
        int $softAndMinResults = 3,
        float $softAndMinCoverage = 0.5,
        float $softAndPartialPenalty = 0.5,
        int $softAndCandidateCap = 100
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
        $this->collapseWeight = max(0.0, $collapseWeight);
        $this->collapseMinLength = max(1, $collapseMinLength);
        $this->softAndMinResults = max(0, $softAndMinResults);
        $this->softAndMinCoverage = min(1.0, max(0.0, $softAndMinCoverage));
        $this->softAndPartialPenalty = max(0.0, $softAndPartialPenalty);
        $this->softAndCandidateCap = max(0, $softAndCandidateCap);
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
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
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
        $strict = ($allTerms && $this->requireAllTerms) || count($tokens) === 1;
        $textHits = count($variants) === 1
            ? $this->textSearch($tokens, $limit, $strict)
            : $this->variantSearch($variants, $limit, $strict);
        if ($strict) {
            // An additional candidate source: a product the token match already found
            // keeps that hit (its band and score are better informed).
            $known = array_flip(array_column($textHits, 'product_id'));
            $collapsedHits = array_values(array_filter(
                $this->collapsedCanRank($textHits, $limit) ? $this->collapsedSearch($tokens, $limit) : [],
                static fn (array $hit): bool => !isset($known[$hit['product_id']])
            ));
            if ($collapsedHits !== []) {
                $textHits = $this->mergeBest(array_merge($textHits, $collapsedHits), $limit);
            }
            // A page already full of full-coverage hits (limit below the minimum) needs no top-up.
            $enough = min($this->softAndMinResults, $limit);
            if ($allTerms && count($tokens) > 1 && $this->fullCoverageCount($textHits) < $enough) {
                $textHits = $this->mergeBest(array_merge($textHits, $this->softHits($variants, $limit)), $limit);
            }
        }
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
     * The literal query as usual, the other variants (see aliasHits()), merged:
     * a product keeps its best (all terms, title band, spec band, score); ties
     * keep first-seen order, which lists the literal query's hits first.
     *
     * @param non-empty-list<list<string>> $variants
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function variantSearch(array $variants, int $limit, bool $allTerms): array
    {
        return $this->mergeBest(
            array_merge(
                $this->textSearch($variants[0], $limit, $allTerms),
                $this->aliasHits(array_slice($variants, 1), $limit)
            ),
            $limit
        );
    }

    /**
     * Hits holding every token of an alias variant. A variant whose tokens are
     * all FULLTEXT-sized is matched in the structured fields (title, tags,
     * brand, category, specs; not the description), so a transliteration found
     * in a tag or spec bridges too. The others share one title-only scan of the
     * covering index (see titleSearch()).
     *
     * @param list<list<string>> $variants
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function aliasHits(array $variants, int $limit): array
    {
        $hits = [];
        $titleOnly = [];
        foreach ($variants as $tokens) {
            if ($this->fulltextSized($tokens)) {
                $hits = array_merge($hits, $this->textSearch($tokens, $limit, true, 0, true));
            } else {
                $titleOnly[] = $tokens;
            }
        }

        return $titleOnly === [] ? $hits : array_merge($hits, $this->titleSearch($titleOnly, $limit));
    }

    /**
     * Partial-coverage hits (soft AND): per variant, the products holding at
     * least soft_and_min_coverage of its tokens but not all, scaled down by
     * soft_and_partial_penalty. The literal query is searched in every field
     * like a strict search; an alias variant as in aliasHits() (structured
     * fields, FULLTEXT-sized tokens only).
     *
     * @param non-empty-list<list<string>> $variants
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function softHits(array $variants, int $limit): array
    {
        $hits = [];
        foreach ($variants as $i => $tokens) {
            $count = count($tokens);
            $needed = max(1, (int) ceil($this->softAndMinCoverage * $count - 1e-9));
            if ($count < 2 || $needed >= $count || ($i > 0 && !$this->fulltextSized($tokens))) {
                continue;
            }
            // An alias variant only adds the rows holding a token swapped in: the rows
            // holding just the literal words are the literal query's partial hits (same
            // coverage), and scanning them again for every variant is what costs.
            $swapped = $i > 0 ? array_values(array_diff($tokens, $variants[0])) : null;
            if ($swapped === []) {
                continue;
            }
            $cap = $i > 0 ? $this->softAndCandidateCap : 0;
            foreach ($this->textSearch($tokens, $limit, false, $needed, $i > 0, $swapped, $cap) as $hit) {
                if ($hit['match_type'] === self::MATCH_PARTIAL) {
                    $hit['score'] *= $this->softAndPartialPenalty;
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }

    /** @param list<array{match_type: string}> $hits */
    private function fullCoverageCount(array $hits): int
    {
        return count(array_filter($hits, static fn (array $hit): bool => $hit['match_type'] !== self::MATCH_PARTIAL));
    }

    /**
     * Merge hits keeping each product's best: full coverage first, then the
     * share of terms held, title band, spec band and score; ties keep
     * first-seen order.
     *
     * @param list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }> $hits
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function mergeBest(array $hits, int $limit): array
    {
        $rank = static fn (array $hit): array => [
            $hit['match_type'] !== self::MATCH_PARTIAL,
            $hit['coverage'],
            $hit['title_match'],
            $hit['spec_match'] && !$hit['title_match'],
            $hit['score'],
        ];
        $best = [];
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

    /** @param list<string> $tokens */
    private function fulltextSized(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) < $this->minTokenSize) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $tokens
     * @param int $minTerms any-terms: only rows holding at least this many tokens
     * @param bool $structured count only the title and structured fields (never the
     *        description) toward the terms held; tokens must be FULLTEXT-sized
     * @param null|list<string> $prefilter FULLTEXT-sized tokens, any-terms: the engine
     *        scans only rows holding one of these (the rest of $tokens still count)
     * @param int $candidateCap FULLTEXT only: score at most this many rows, the engine's
     *        best first (0 = every matching row)
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function textSearch(
        array $tokens,
        int $limit,
        bool $allTerms,
        int $minTerms = 0,
        bool $structured = false,
        ?array $prefilter = null,
        int $candidateCap = 0
    ): array {
        while (true) {
            try {
                return $this->textSearchOnce(
                    $tokens,
                    $limit,
                    $allTerms,
                    $minTerms,
                    $structured,
                    $prefilter,
                    $candidateCap
                );
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
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function textSearchOnce(
        array $tokens,
        int $limit,
        bool $allTerms,
        int $minTerms,
        bool $structured,
        ?array $prefilter,
        int $candidateCap
    ): array {
        if (!$this->fulltextSized($tokens)) {
            return $this->likeSearch($tokens, $limit, $allTerms, $minTerms);
        }

        return $this->fulltextSearch(
            $tokens,
            $limit,
            $allTerms,
            $minTerms,
            $structured,
            $prefilter,
            $candidateCap
        );
    }

    /**
     * Exact SKU matches first, then prefix matches (shortest code first), via
     * the normalized_sku index. SKU hits count as title matches, so the
     * description-only gate and the hybrid merge keep them.
     *
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
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
                'coverage'    => 1.0,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function fulltextSearch(
        array $tokens,
        int $limit,
        bool $allTerms,
        int $minTerms = 0,
        bool $structured = false,
        ?array $prefilter = null,
        int $candidateCap = 0
    ): array {
        // The column list must equal the FULLTEXT index definition exactly.
        $match = 'MATCH(' . implode(', ', $this->searchedColumns()) . ') AGAINST(? IN BOOLEAN MODE)';
        // Prefix-matched, "+word*" when every token is required, else "word*".
        $expr = implode(' ', array_map(
            static fn (string $t): string => ($allTerms ? '+' : '') . $t . '*',
            $prefilter ?? $tokens
        ));
        // Structured searches count the tokens held from the field levels instead (see
        // scoredSearch): the engine's per-token counts would be one more full FULLTEXT
        // search per token, on every variant.
        $termHits = null;
        if (!$allTerms && !$structured) {
            $termHits = [
                '(' . implode(' + ', array_fill(0, count($tokens), "({$match} > 0)")) . ')',
                array_map(static fn (string $t): string => $t . '*', $tokens),
            ];
        }

        return $this->scoredSearch(
            $tokens,
            $match,
            [$expr],
            $match,
            [$expr],
            $limit,
            $structured ? self::MATCH_ALIAS : 'fulltext',
            $termHits,
            $minTerms,
            $structured,
            $candidateCap
        );
    }

    /**
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function likeSearch(array $tokens, int $limit, bool $allTerms, int $minTerms = 0): array
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
            $termHits,
            $minTerms
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
     * @param int $minTerms with $termHits: rows holding fewer tokens are dropped
     * @param int $candidateCap score only this many rows of $where, best $relevance first
     * @param bool $structured the tokens held are those found in the title or a
     *        structured field (all required, or $minTerms of them), never only in the
     *        description: alias variants must not match on a mere mention
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
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
        ?array $termHits = null,
        int $minTerms = 0,
        bool $structured = false,
        int $candidateCap = 0
    ): array {
        // One CASE per token names the best field it is found in (1 = title,
        // 2.. = $this->fields in order, 0 = description only): one regex chain
        // per token, everything below is arithmetic on these levels. Tokens
        // hold only letters and digits (Tokenizer), so they are safe inside a
        // regex and need no escaping; they are still bound.
        $levels = [];
        $params = [];
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
        // Tokens held: the engine's count over every field, or (structured) those
        // found in the title or a structured field only.
        $held = $structured ? "({$titleHits} + {$fieldHits})" : 'term_hits';
        $needed = $minTerms > 0 ? $minTerms : ($structured ? $count : 0);
        $filter = $needed > 0 ? "WHERE {$held} >= " . (int) $needed : '';
        // GREATEST: a word-prefix REGEXP and the engine's own match can
        // disagree on an odd token edge; never credit a negative count.
        $score = "(({$credit} + " . $weight($this->descWeight)
               . " * GREATEST({$held} - {$titleHits} - {$fieldHits}, 0))"
               . " / {$count} + " . $weight($this->phraseBonus) . ' * phrase_hit)';

        // With a candidate cap the best rows by the engine's relevance are picked first
        // (one cheap FULLTEXT query) and only they are read and scored: ordering the wide
        // select directly would evaluate every level for every matching row before the
        // sort. Only without per-token engine counts ($termHits), i.e. structured searches.
        $capped = $candidateCap > 0 && $termHits === null;
        // The inner LIMIT keeps the derived table from being merged into the
        // outer query (MySQL and MariaDB both materialize a derived table with
        // a LIMIT); merged, every use of a level in the score and ORDER BY
        // re-ran its REGEXPs, 3x slower with the specs.
        $sql = "SELECT product_id, {$titleHits} AS title_hits, {$fieldHits} AS spec_hits,
                       {$nameHits} AS name_hits, {$held} < {$count} AS partial, {$held} AS held,
                       {$score} AS score
                FROM (
                    " . ($capped
            ? "SELECT STRAIGHT_JOIN p.product_id, p.popularity, c.relevance,
                           " . implode(', ', $levels) . ",
                           {$phrase} AS phrase_hit,
                           {$terms} AS term_hits
                    FROM (
                        SELECT product_id, {$relevance} AS relevance
                        FROM {$this->table}
                        WHERE {$where}
                        ORDER BY relevance DESC
                        LIMIT {$candidateCap}
                    ) c
                    JOIN {$this->table} p ON p.product_id = c.product_id"
            : "SELECT product_id, popularity,
                           {$relevance} AS relevance,
                           " . implode(', ', $levels) . ",
                           {$phrase} AS phrase_hit,
                           {$terms} AS term_hits
                    FROM {$this->table}
                    WHERE {$where}
                    LIMIT " . PHP_INT_MAX) . "
                ) matched
                {$filter}
                ORDER BY {$held} DESC, ({$titleHits} > 0) DESC, ({$titleHits} = 0 AND {$fieldHits} > 0) DESC,
                         score DESC, relevance DESC, popularity DESC, product_id ASC
                LIMIT " . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        // Placeholders in SQL order: the levels and phrase of the select list come
        // first when the capped form puts the engine query in a derived table below it.
        $stmt->execute($capped
            ? array_merge($params, $relevanceParams, $whereParams)
            : array_merge($relevanceParams, $params, $whereParams));

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC), $matchType, count($tokens));
    }

    /**
     * False when the page is already full of title matches that outrank any
     * collapsed hit (a full-coverage title hit scoring at least collapse_weight):
     * a broad query. The scan then cannot change the answer, and for a word held
     * by thousands of titles it is the costly part of the request.
     *
     * @param list<array{match_type: string, title_match: bool, score: float}> $hits best first
     */
    private function collapsedCanRank(array $hits, int $limit): bool
    {
        $last = $hits[$limit - 1] ?? null;

        return $last === null
            || $last['match_type'] === self::MATCH_PARTIAL
            || !$last['title_match']
            || $last['score'] < $this->collapseWeight;
    }

    /**
     * Products whose collapsed identity (title, brand, tags; normalized_collapsed)
     * holds the collapsed query ("far cry" and "farcry" alike), as a name hit scored
     * collapse_weight (in the title band when the name is in the title, else in
     * the structured-field band). The substring scan runs over the covering index; each
     * candidate is then verified to start at a word of a spaced field (letters
     * and digits of the query in order, any non-word characters between them), so
     * "far cry" does not match "sofar crystal". A single-token query already finds
     * rows holding the token itself through the regular path, so those are left
     * out: only the spaced forms remain ("farcry" in "Far Cry 5").
     *
     * @param list<string> $tokens
     * @return list<array{
     *     product_id: int, score: float, match_type: string,
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
     * }>
     */
    private function collapsedSearch(array $tokens, int $limit): array
    {
        $collapsed = implode('', $tokens);
        $length = mb_strlen($collapsed);
        if (
            !$this->collapsedColumn || $this->collapseWeight <= 0.0
            || $length < $this->collapseMinLength || $length > self::COLLAPSED_QUERY_MAX
        ) {
            return [];
        }

        $names = ['normalized_title', self::TAGS_COLUMN, 'normalized_brand'];
        $pattern = self::WORD_START . implode(self::BOUNDARY . '*', mb_str_split($collapsed));
        $params = ['%' . $this->escapeLike($collapsed) . '%'];
        $conditions = [];
        if (count($tokens) === 1) {
            foreach ($names as $column) {
                $conditions[] = "p.{$column} NOT LIKE ?";
                $params[] = $params[0];
            }
        }
        $conditions[] = '(' . implode(' OR ', array_map(
            static fn (string $column): string => "p.{$column} REGEXP ?",
            $names
        )) . ')';
        // Placeholders in SQL order: the two flags of the select list come first.
        $params = array_merge([$pattern, $pattern], $params, [$pattern, $pattern, $pattern]);

        // The derived table is materialized (the inner LIMIT, as in titleSearch()),
        // keeping the covering-index scan; the products are then read by key.
        // STRAIGHT_JOIN: left to itself the optimizer scans every product first
        // and runs the verification regexes on all of them.
        $sql = 'SELECT STRAIGHT_JOIN p.product_id,
                       p.normalized_title REGEXP ? AS in_title, p.' . self::TAGS_COLUMN . ' REGEXP ? AS in_tags
                FROM (
                    SELECT product_id FROM ' . $this->table . ' FORCE INDEX (' . self::COLLAPSED_INDEX . ')
                    WHERE normalized_collapsed LIKE ?
                    LIMIT ' . PHP_INT_MAX . '
                ) c
                JOIN ' . $this->table . ' p ON p.product_id = c.product_id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY p.popularity DESC, p.product_id ASC
                LIMIT ' . (int) $limit;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            // 42S22 (no normalized_collapsed / tags / brand) or 1176 (no index): the
            // live table predates M23 or M21, e.g. between code upload and reload.
            if ($e->getCode() !== '42S22' && ($e->errorInfo[1] ?? null) !== 1176) {
                throw $e;
            }
            $this->collapsedColumn = false;

            return [];
        }

        return array_map(
            fn (array $row): array => [
                'product_id'  => (int) $row['product_id'],
                'score'       => $this->collapseWeight,
                'match_type'  => self::MATCH_COLLAPSED,
                // In the title: the title band. Only in the tags or the brand: the
                // structured-field band, like any tag or brand match.
                'title_match' => (int) $row['in_title'] === 1,
                'spec_match'  => (int) $row['in_title'] !== 1,
                'title_all'   => (int) $row['in_title'] === 1,
                'name_all'    => (int) $row['in_title'] === 1 || (int) $row['in_tags'] === 1,
                'coverage'    => 1.0,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
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
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
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
     *     title_match: bool, spec_match: bool, title_all: bool, name_all: bool, coverage: float
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
                'coverage'    => min(1.0, (int) ($row['held'] ?? $termCount) / max(1, $termCount)),
            ],
            $rows
        );
    }
}
