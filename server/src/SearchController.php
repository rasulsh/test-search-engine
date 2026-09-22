<?php

declare(strict_types=1);

namespace App;

/**
 * POST /search flow for M2 (Tier 1 only): normalize, keyword search, and — only
 * when nothing matches — recover via keyboard-layout remap or spell correction,
 * then log the request. The Tier 2 semantic path (q_vector) arrives in M4.
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

    /**
     * @param callable(): Speller $spellerFactory Built lazily (a catalog scan),
     *        so it only runs when the primary search returns nothing.
     */
    public function __construct(
        Keyword $keyword,
        Logger $logger,
        callable $spellerFactory,
        ?callable $normalizer = null,
        int $topIdsLimit = 10
    ) {
        $this->keyword = $keyword;
        $this->logger = $logger;
        $this->spellerFactory = $spellerFactory;
        $this->normalize = $normalizer ?? [Normalizer::class, 'normalize'];
        $this->topIdsLimit = max(1, $topIdsLimit);
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
        // Tier 2 is M4; we only record whether a vector was supplied.
        $hadVector = isset($request['q_vector']) && is_array($request['q_vector']);

        $start = microtime(true);
        $normalized = ($this->normalize)($raw);
        $results = $this->keyword->search($raw, $limit);
        $didYouMean = null;

        if ($results === [] && $normalized !== '') {
            [$results, $didYouMean] = $this->recover($raw, $normalized, $limit);
        }

        $productIds = array_map(static fn (array $row): int => $row['product_id'], $results);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'raw_q'        => $raw,
            'normalized_q' => $normalized,
            'had_vector'   => $hadVector,
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
