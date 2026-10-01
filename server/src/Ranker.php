<?php

declare(strict_types=1);

namespace App;

/**
 * Blended hybrid ranking (M20, CLAUDE.md sec. 5.5).
 *
 * Every candidate carries two relevance signals: the field-weighted keyword
 * score (title > specs > description, phrase, SKU; Keyword) and the cosine from
 * the VPS top-K (0 when the VPS did not return it). Each is scaled to 0..1 by
 * its maximum within the candidate set, then
 *
 *   relevance = keyword_weight * keyword_norm + semantic_weight * semantic_norm
 *
 * Semantic evidence therefore drives the order instead of sitting under a
 * keyword tier. Candidates whose relevance is below `min_relevance` are
 * dropped: a keyword-only hit tops out at keyword_weight, so with the floor
 * above keyword_weight a hit the model sees no link to (a description mention
 * such as "ball bearing" in a power supply's specs) cannot survive on keyword
 * evidence alone. Only WEAK keyword hits are subject to the floor: solid hits
 * (every query term matched in the product title / name; exact SKUs are pinned by the
 * caller) are always kept and just ranked by the blend, so the semantic tier
 * adds and reorders but never drops a solid keyword match, even one the VPS
 * ranks outside its top-K (a solid hit the VPS did not return is assumed to sit
 * at the VPS floor, so it leads the pure-semantic neighbours but not a hit both
 * tiers like). Stock and popularity are applied
 * AFTER the floor as light multiplicative boosts: they nudge ordering among
 * comparably relevant items and never rescue a dropped one.
 *
 * M21 adds three more boosts of the same kind, driven by the query's token
 * variants (the literal query plus its alias / synonym swaps, so a Persian
 * query still meets a Latin brand name): brand_match_boost when all the words
 * of the product's brand are in the query, category_match_boost likewise for
 * its category, and tag_match_boost when a multi-word query is a phrase inside
 * the product's tags (the franchise-name case). The tag boost needs at least
 * tag_match_min_tokens words: for one word the keyword tier has already
 * credited the tag, so boosting it again would double-count. Because they
 * apply after the floor and multiply the relevance, a boost can reorder close
 * candidates but never let a brand or category flood the results with items the
 * relevance rejected.
 */
final class Ranker
{
    private float $stockBoost;
    private float $popularityBoost;
    private float $keywordWeight;
    private float $semanticWeight;
    private float $minRelevance;
    private float $brandMatchBoost;
    private float $categoryMatchBoost;
    private float $tagMatchBoost;
    private int $tagMatchMinTokens;

    public function __construct(
        float $stockBoost = 0.1,
        float $popularityBoost = 0.1,
        float $keywordWeight = 0.4,
        float $semanticWeight = 0.6,
        float $minRelevance = 0.45,
        float $brandMatchBoost = 0.0,
        float $categoryMatchBoost = 0.0,
        float $tagMatchBoost = 0.0,
        int $tagMatchMinTokens = 2
    ) {
        $this->stockBoost = max(0.0, $stockBoost);
        $this->popularityBoost = max(0.0, $popularityBoost);
        $this->keywordWeight = max(0.0, $keywordWeight);
        $this->semanticWeight = max(0.0, $semanticWeight);
        $this->minRelevance = $minRelevance;
        $this->brandMatchBoost = max(0.0, $brandMatchBoost);
        $this->categoryMatchBoost = max(0.0, $categoryMatchBoost);
        $this->tagMatchBoost = max(0.0, $tagMatchBoost);
        $this->tagMatchMinTokens = max(1, $tagMatchMinTokens);
    }

    /** @return array<string, float|int> the knobs, for the debug breakdown */
    public function settings(): array
    {
        return [
            'keyword_weight'      => $this->keywordWeight,
            'semantic_weight'     => $this->semanticWeight,
            'min_relevance'       => $this->minRelevance,
            'stock_boost'         => $this->stockBoost,
            'popularity_boost'    => $this->popularityBoost,
            'brand_match_boost'   => $this->brandMatchBoost,
            'category_match_boost' => $this->categoryMatchBoost,
            'tag_match_boost'     => $this->tagMatchBoost,
            'tag_match_min_tokens' => $this->tagMatchMinTokens,
        ];
    }

    /**
     * @param array<int, float> $keywordScores product_id => keyword score (keyword hits only)
     * @param array<int, float> $cosines       product_id => cosine from the VPS (semantic hits only)
     * @param array<int, array{
     *     stock: int, popularity: int, brand?: string, category?: string, tags?: string
     * }> $signals per-id business signals; the optional normalized texts feed the match boosts
     * @param list<int> $solid keyword ids exempt from the floor
     * @param float $solidCosine cosine assumed for a solid hit the VPS did not return: it is
     *        absent because it scored below the VPS floor / outside its top-K, so at most this
     *        (the floor); capped at the best returned cosine
     * @param list<list<string>> $variants normalized query tokens: the literal query first,
     *        then its alias / synonym variants; empty disables the match boosts
     * @param bool $explain add each row's score breakdown as `detail`
     * @return list<array{
     *     product_id: int, score: float, keyword: bool, detail?: array<string, mixed>
     * }> best first
     */
    public function blend(
        array $keywordScores,
        array $cosines,
        array $signals,
        int $limit,
        array $solid = [],
        float $solidCosine = 0.0,
        array $variants = [],
        bool $explain = false
    ): array {
        $maxKeyword = $keywordScores === [] ? 0.0 : max($keywordScores);
        $maxCosine = $cosines === [] ? 0.0 : max($cosines);
        $maxPopularity = 0;
        foreach ($signals as $signal) {
            $maxPopularity = max($maxPopularity, $signal['popularity']);
        }

        $exempt = array_flip($solid);
        $rows = [];
        foreach (array_keys($keywordScores + $cosines) as $id) {
            $keyword = $maxKeyword > 0.0 ? max(0.0, $keywordScores[$id] ?? 0.0) / $maxKeyword : 0.0;
            $cosine = $cosines[$id] ?? (isset($exempt[$id]) ? min($solidCosine, $maxCosine) : 0.0);
            $semantic = $maxCosine > 0.0 ? max(0.0, $cosine) / $maxCosine : 0.0;
            $relevance = $this->keywordWeight * $keyword + $this->semanticWeight * $semantic;
            if ($relevance < $this->minRelevance && !isset($exempt[$id])) {
                continue;
            }
            $inStock = ($signals[$id]['stock'] ?? 0) > 0 ? 1.0 : 0.0;
            $popularity = $maxPopularity > 0 ? ($signals[$id]['popularity'] ?? 0) / $maxPopularity : 0.0;
            $brand = $this->wordsInQuery($signals[$id]['brand'] ?? '', $variants);
            $category = $this->wordsInQuery($signals[$id]['category'] ?? '', $variants);
            $tag = $this->phraseInTags($signals[$id]['tags'] ?? '', $variants);
            $boosts = [
                'stock'      => $this->stockBoost * $inStock,
                'popularity' => $this->popularityBoost * $popularity,
                'brand'      => $this->brandMatchBoost * (int) $brand,
                'category'   => $this->categoryMatchBoost * (int) $category,
                'tag'        => $this->tagMatchBoost * (int) $tag,
            ];
            $score = $relevance * (1.0 + array_sum($boosts));
            $row = ['product_id' => $id, 'score' => $score, 'keyword' => isset($keywordScores[$id])];
            if ($explain) {
                $row['detail'] = [
                    'keyword'   => [
                        'score'      => isset($keywordScores[$id]) ? round($keywordScores[$id], 4) : null,
                        'normalized' => round($keyword, 4),
                    ],
                    'semantic'  => [
                        'cosine'     => isset($cosines[$id]) ? round($cosines[$id], 4) : null,
                        'normalized' => round($semantic, 4),
                        // A solid keyword hit the VPS did not return is assumed at the floor.
                        'assumed'    => !isset($cosines[$id]) && isset($exempt[$id]) && $semantic > 0.0,
                    ],
                    'blended'   => round($relevance, 4),
                    'boosts'    => array_map(static fn (float $b): float => round($b, 4), $boosts),
                    'score'     => round($score, 4),
                ];
            }
            $rows[] = $row;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['score'] <=> $a['score']) ?: ($a['product_id'] <=> $b['product_id'])
        );

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * True when every word of $field (a normalized brand / category name) is a
     * word of some query variant. An empty field never matches.
     *
     * @param list<list<string>> $variants
     */
    private function wordsInQuery(string $field, array $variants): bool
    {
        $words = Tokenizer::split($field);
        if ($words === []) {
            return false;
        }
        foreach ($variants as $tokens) {
            if (array_diff($words, $tokens) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when some variant of at least tag_match_min_tokens words occurs as a
     * contiguous run of the product's tag words (the export joins tag names
     * with a space, so a multi-word tag is such a run).
     *
     * @param list<list<string>> $variants
     */
    private function phraseInTags(string $tags, array $variants): bool
    {
        if ($tags === '') {
            return false;
        }
        $haystack = ' ' . implode(' ', Tokenizer::split($tags)) . ' ';
        foreach ($variants as $tokens) {
            $phrase = ' ' . implode(' ', $tokens) . ' ';
            if (count($tokens) >= $this->tagMatchMinTokens && str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
