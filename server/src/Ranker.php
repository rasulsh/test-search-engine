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
 * evidence alone, with no per-tier gate. Stock and popularity are applied
 * AFTER the floor as light multiplicative boosts: they nudge ordering among
 * comparably relevant items and never rescue a dropped one.
 */
final class Ranker
{
    private float $stockBoost;
    private float $popularityBoost;
    private float $keywordWeight;
    private float $semanticWeight;
    private float $minRelevance;

    public function __construct(
        float $stockBoost = 0.1,
        float $popularityBoost = 0.1,
        float $keywordWeight = 0.4,
        float $semanticWeight = 0.6,
        float $minRelevance = 0.45
    ) {
        $this->stockBoost = max(0.0, $stockBoost);
        $this->popularityBoost = max(0.0, $popularityBoost);
        $this->keywordWeight = max(0.0, $keywordWeight);
        $this->semanticWeight = max(0.0, $semanticWeight);
        $this->minRelevance = $minRelevance;
    }

    /**
     * @param array<int, float> $keywordScores product_id => keyword score (keyword hits only)
     * @param array<int, float> $cosines       product_id => cosine from the VPS (semantic hits only)
     * @param array<int, array{stock: int, popularity: int}> $signals per-id business signals
     * @return list<array{product_id: int, score: float, keyword: bool}> best first
     */
    public function blend(array $keywordScores, array $cosines, array $signals, int $limit): array
    {
        $maxKeyword = $keywordScores === [] ? 0.0 : max($keywordScores);
        $maxCosine = $cosines === [] ? 0.0 : max($cosines);
        $maxPopularity = 0;
        foreach ($signals as $signal) {
            $maxPopularity = max($maxPopularity, $signal['popularity']);
        }

        $rows = [];
        foreach (array_keys($keywordScores + $cosines) as $id) {
            $keyword = $maxKeyword > 0.0 ? max(0.0, $keywordScores[$id] ?? 0.0) / $maxKeyword : 0.0;
            $semantic = $maxCosine > 0.0 ? max(0.0, $cosines[$id] ?? 0.0) / $maxCosine : 0.0;
            $relevance = $this->keywordWeight * $keyword + $this->semanticWeight * $semantic;
            if ($relevance < $this->minRelevance) {
                continue;
            }
            $inStock = ($signals[$id]['stock'] ?? 0) > 0 ? 1.0 : 0.0;
            $popularity = $maxPopularity > 0 ? ($signals[$id]['popularity'] ?? 0) / $maxPopularity : 0.0;
            $rows[] = [
                'product_id' => $id,
                'score'      => $relevance
                    * (1.0 + $this->stockBoost * $inStock + $this->popularityBoost * $popularity),
                'keyword'    => isset($keywordScores[$id]),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['score'] <=> $a['score']) ?: ($a['product_id'] <=> $b['product_id'])
        );

        return array_slice($rows, 0, max(1, $limit));
    }
}
