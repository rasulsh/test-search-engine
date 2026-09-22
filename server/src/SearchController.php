<?php

declare(strict_types=1);

namespace App;

/**
 * POST /search flow.
 *
 * Tier 1 (always on): normalize, keyword search, and — only when nothing matches
 * — recover via keyboard-layout remap or spell correction, computing "did you
 * mean".
 *
 * Tier 2 (additive, M4): when the request carries a query vector AND a bundle is
 * loaded, run global cosine top-K and fuse it with the keyword ranking by
 * Reciprocal Rank Fusion, then apply light business boosts. Tier 2 is purely
 * additive — if the vector is absent, malformed, or the bundle is unavailable,
 * the request returns Tier 1 results and never errors (CLAUDE.md sec. 5).
 */
final class SearchController
{
    private Keyword $keyword;
    private Logger $logger;
    /** @var callable(): Speller */
    private $spellerFactory;
    /** @var callable(?string): string */
    private $normalize;
    private int $topIdsLimit;
    private ?Vectors $vectors;
    private ?Ranker $ranker;
    /** @var null|callable(list<int>): array<int, array{stock: int, popularity: int}> */
    private $signalsProvider;
    private int $semanticTopK;
    private int $defaultLimit;

    /**
     * @param callable(): Speller $spellerFactory Built lazily (a catalog scan),
     *        so it only runs when the primary search returns nothing.
     * @param null|callable(list<int>): array<int, array{stock: int, popularity: int}> $signalsProvider
     *        Returns business signals keyed by product_id for the ids that EXIST
     *        in the products table; ids it omits are treated as absent, which is
     *        how a transient vector/table mismatch degrades (contract-tolerant
     *        reader). Required, with $vectors and $ranker, to enable Tier 2.
     */
    public function __construct(
        Keyword $keyword,
        Logger $logger,
        callable $spellerFactory,
        ?callable $normalizer = null,
        int $topIdsLimit = 10,
        ?Vectors $vectors = null,
        ?Ranker $ranker = null,
        ?callable $signalsProvider = null,
        int $semanticTopK = 100,
        int $defaultLimit = 20
    ) {
        $this->keyword = $keyword;
        $this->logger = $logger;
        $this->spellerFactory = $spellerFactory;
        $this->normalize = $normalizer ?? [Normalizer::class, 'normalize'];
        $this->topIdsLimit = max(1, $topIdsLimit);
        $this->vectors = $vectors;
        $this->ranker = $ranker;
        $this->signalsProvider = $signalsProvider;
        $this->semanticTopK = max(1, $semanticTopK);
        $this->defaultLimit = max(1, $defaultLimit);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{
     *     query: array{raw: string, normalized: string},
     *     did_you_mean: ?string,
     *     count: int,
     *     product_ids: list<int>
     * }
     */
    public function search(array $request): array
    {
        $raw = is_string($request['q'] ?? null) ? $request['q'] : '';
        $limit = isset($request['limit']) ? (int) $request['limit'] : null;
        $customerId = isset($request['customer_id']) && is_string($request['customer_id'])
            ? $request['customer_id']
            : null;
        $queryVector = $this->queryVector($request);

        $start = microtime(true);
        $normalized = ($this->normalize)($raw);
        $results = $this->keyword->search($raw, $limit);
        $didYouMean = null;

        if ($results === [] && $normalized !== '') {
            [$results, $didYouMean] = $this->recover($raw, $normalized, $limit);
        }

        $keywordIds = array_map(static fn (array $row): int => $row['product_id'], $results);
        $productIds = $keywordIds;

        // Tier 2 is additive: only reshuffle when a usable vector and bundle are
        // present. Otherwise the keyword ordering above stands.
        if ($queryVector !== null && $this->semanticEnabled()) {
            $productIds = $this->hybrid($keywordIds, $queryVector, $limit);
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'raw_q'        => $raw,
            'normalized_q' => $normalized,
            'had_vector'   => $queryVector !== null,
            'result_count' => count($productIds),
            'top_ids'      => array_slice($productIds, 0, $this->topIdsLimit),
            'customer_id'  => $customerId,
            'latency_ms'   => $latencyMs,
        ]);

        return [
            'query'        => ['raw' => $raw, 'normalized' => $normalized],
            'did_you_mean' => $didYouMean,
            'count'        => count($productIds),
            'product_ids'  => $productIds,
        ];
    }

    /**
     * Global cosine top-K fused with the keyword ranking by RRF + business boosts.
     * Semantic ids not present in the products table are dropped (the signals
     * provider omits them), so a partial reload degrades instead of surfacing
     * dead ids.
     *
     * @param list<int> $keywordIds
     * @param list<float> $queryVector
     * @return list<int>
     */
    private function hybrid(array $keywordIds, array $queryVector, ?int $limit): array
    {
        $semantic = $this->vectors->topK($queryVector, $this->semanticTopK);
        if ($semantic === []) {
            return $keywordIds; // bundle unavailable at call time; stay keyword-only
        }
        $semanticIds = array_map(static fn (array $row): int => $row['product_id'], $semantic);

        $candidates = array_values(array_unique(array_merge($keywordIds, $semanticIds)));
        $signals = ($this->signalsProvider)($candidates);

        // Existence filter: keep only ids the products table actually has.
        $exists = static fn (int $id): bool => isset($signals[$id]);
        $keywordIds = array_values(array_filter($keywordIds, $exists));
        $semanticIds = array_values(array_filter($semanticIds, $exists));

        $effectiveLimit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
        $fused = $this->ranker->fuse($keywordIds, $semanticIds, $signals, $effectiveLimit);

        return array_map(static fn (array $row): int => $row['product_id'], $fused);
    }

    private function semanticEnabled(): bool
    {
        return $this->vectors !== null
            && $this->ranker !== null
            && $this->signalsProvider !== null
            && $this->vectors->isLoaded();
    }

    /**
     * A candidate query vector: a non-empty numeric list. Absent, non-list, or
     * non-numeric input yields null (Tier 1 only). Dimension is not checked here
     * — Vectors::topK owns that and degrades to keyword-only on a mismatch, so a
     * stale client dim can never error the request.
     *
     * @param array<string, mixed> $request
     * @return list<float>|null
     */
    private function queryVector(array $request): ?array
    {
        $raw = $request['q_vector'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return null;
        }
        $vector = [];
        foreach ($raw as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }

    /**
     * Try keyboard-layout remaps first, then spell correction. Returns the
     * recovered results and the suggestion to show, or empty + null.
     *
     * @return array{0: list<array{product_id: int, score: float, match_type: string}>, 1: ?string}
     */
    private function recover(string $raw, string $normalized, ?int $limit): array
    {
        foreach ([Keymap::enToFa($raw), Keymap::faToEn($raw)] as $candidate) {
            $candidateNormalized = ($this->normalize)($candidate);
            if ($candidateNormalized === '' || $candidateNormalized === $normalized) {
                continue;
            }
            $alternative = $this->keyword->search($candidate, $limit);
            if ($alternative !== []) {
                return [$alternative, $candidateNormalized];
            }
        }

        $suggestion = ($this->spellerFactory)()->suggest($normalized);
        if ($suggestion !== null && $suggestion !== $normalized) {
            $alternative = $this->keyword->search($suggestion, $limit);
            if ($alternative !== []) {
                return [$alternative, $suggestion];
            }
        }

        return [[], null];
    }
}
