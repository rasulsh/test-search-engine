<?php

declare(strict_types=1);

namespace App;

/**
 * Hybrid ranking (CLAUDE.md sec. 5.5).
 *
 * Keyword (FULLTEXT) scores and cosine similarity live on different, incomparable
 * scales, so they are NOT added raw. Instead they are merged by Reciprocal Rank
 * Fusion (RRF): each result contributes 1/(k + rank) from every list it appears
 * in, using only its rank position. This is scale-free and robust to outliers.
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

    public function __construct(int $rrfK = 60, float $stockBoost = 0.1, float $popularityBoost = 0.1)
    {
        $this->rrfK = max(1, $rrfK);
        $this->stockBoost = max(0.0, $stockBoost);
        $this->popularityBoost = max(0.0, $popularityBoost);
    }

    /**
     * Fuse two ranked id lists and return ids ordered by fused-and-boosted score.
     *
     * @param list<int> $keywordOrder  product_ids in keyword rank order (best first)
     * @param list<int> $semanticOrder product_ids in semantic rank order (best first)
     * @param array<int, array{stock: int, popularity: int}> $signals per-id business signals
     * @return list<array{product_id: int, score: float}>
     */
    public function fuse(array $keywordOrder, array $semanticOrder, array $signals, int $limit): array
    {
        $rrf = [];
        foreach ([$keywordOrder, $semanticOrder] as $list) {
            $rank = 1;
            foreach ($list as $id) {
                $rrf[$id] = ($rrf[$id] ?? 0.0) + 1.0 / ($this->rrfK + $rank);
                $rank++;
            }
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
            $rows[] = ['product_id' => $id, 'score' => $base * $boost];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['score'] <=> $a['score']) ?: ($a['product_id'] <=> $b['product_id'])
        );

        return array_slice($rows, 0, max(1, $limit));
    }
}
