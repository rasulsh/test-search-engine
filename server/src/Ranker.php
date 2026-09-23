<?php

declare(strict_types=1);

namespace App;

/**
 * Hybrid ranking (CLAUDE.md sec. 5.5).
 *
 * Keyword (FULLTEXT) scores and cosine similarity live on different, incomparable
 * scales, so they are NOT added raw. Instead they are merged by weighted
 * Reciprocal Rank Fusion (RRF): each result contributes weight/(k + rank) from
 * every list it appears in, using only its rank position.
 *
 * Keyword hits lead: every keyword match ranks above every semantic-only
 * neighbour. On the real catalog the nearest vectors of a query with no truly
 * relevant product are unrelated items, and plain RRF let them interleave with
 * (and outrank) exact keyword matches. Semantic evidence still reorders keyword
 * hits among themselves, and semantic-only results augment below them.
 *
 * Within the keyword band, hits that matched in the product title lead those
 * that matched only in the description (M10), so semantic evidence and boosts
 * cannot lift a description-only mention above a product named by the query.
 * Hits that matched in the specs (attributes, feature titles; M13) but not the
 * title sit between the two.
 *
 * Partial keyword hits (M16: any-terms mode, missing a query word) rank below
 * every hit holding all the words, in the same three bands, and above the
 * semantic-only neighbours.
 *
 * Business signals (in-stock, popularity) are applied AFTER fusion as light
 * multiplicative boosts, so they nudge ordering among comparably-relevant items
 * without overriding relevance. Weights are configurable and default to small.
 */
final class Ranker
{
    private int $rrfK;
    private float $stockBoost;
    private float $popularityBoost;
    private float $keywordWeight;
    private float $semanticWeight;

    public function __construct(
        int $rrfK = 60,
        float $stockBoost = 0.1,
        float $popularityBoost = 0.1,
        float $keywordWeight = 1.0,
        float $semanticWeight = 1.0
    ) {
        $this->rrfK = max(1, $rrfK);
        $this->stockBoost = max(0.0, $stockBoost);
        $this->popularityBoost = max(0.0, $popularityBoost);
        $this->keywordWeight = max(0.0, $keywordWeight);
        $this->semanticWeight = max(0.0, $semanticWeight);
    }

    /**
     * Fuse two ranked id lists and return ids ordered title keyword hits
     * first, then spec keyword hits, then description-only keyword hits, then
     * semantic-only ids, each band by fused-and-boosted score.
     *
     * @param list<int> $keywordOrder  product_ids in keyword rank order (best first)
     * @param list<int> $semanticOrder product_ids in semantic rank order (best first)
     * @param array<int, array{stock: int, popularity: int}> $signals per-id business signals
     * @param list<int> $titleMatches  keyword ids that matched in the title
     * @param list<int> $specMatches   keyword ids that matched in the specs
     * @param list<int> $partialMatches keyword ids missing a query word
     * @return list<array{product_id: int, score: float, keyword: bool}>
     */
    public function fuse(
        array $keywordOrder,
        array $semanticOrder,
        array $signals,
        int $limit,
        array $titleMatches = [],
        array $specMatches = [],
        array $partialMatches = []
    ): array {
        $rrf = [];
        foreach ($keywordOrder as $i => $id) {
            $rrf[$id] = ($rrf[$id] ?? 0.0) + $this->keywordWeight / ($this->rrfK + $i + 1);
        }
        $isKeyword = $rrf;
        $inTitle = array_intersect_key(array_flip($titleMatches), $isKeyword);
        $inSpecs = array_intersect_key(array_flip($specMatches), $isKeyword);
        $partial = array_flip($partialMatches);
        foreach ($semanticOrder as $i => $id) {
            $rrf[$id] = ($rrf[$id] ?? 0.0) + $this->semanticWeight / ($this->rrfK + $i + 1);
        }

        // Normalize popularity across the candidate set so the boost is bounded
        // regardless of absolute view counts.
        $maxPopularity = 0;
        foreach (array_keys($rrf) as $id) {
            $maxPopularity = max($maxPopularity, $signals[$id]['popularity'] ?? 0);
        }

        $rows = [];
        foreach ($rrf as $id => $base) {
            $inStock = ($signals[$id]['stock'] ?? 0) > 0 ? 1.0 : 0.0;
            $popularity = $maxPopularity > 0
                ? ($signals[$id]['popularity'] ?? 0) / $maxPopularity
                : 0.0;
            $boost = 1.0 + $this->stockBoost * $inStock + $this->popularityBoost * $popularity;
            $rows[] = [
                'product_id' => $id,
                'score'      => $base * $boost,
                'keyword'    => isset($isKeyword[$id]),
                'band'       => match (true) {
                    isset($inTitle[$id]) => 3,
                    isset($inSpecs[$id]) => 2,
                    isset($isKeyword[$id]) => 1,
                    default => 0,
                } + (isset($isKeyword[$id]) && !isset($partial[$id]) ? 3 : 0),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['band'] <=> $a['band'])
                ?: ($b['score'] <=> $a['score'])
                ?: ($a['product_id'] <=> $b['product_id'])
        );

        return array_map(
            static fn (array $row): array => [
                'product_id' => $row['product_id'],
                'score'      => $row['score'],
                'keyword'    => $row['keyword'],
            ],
            array_slice($rows, 0, max(1, $limit))
        );
    }
}
