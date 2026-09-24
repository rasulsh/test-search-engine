<?php

declare(strict_types=1);

namespace App;

/**
 * Offline search-quality measurement (CLAUDE.md sec. 8, eval harness).
 *
 * Runs labeled queries through a search function and reports precision@k and
 * recall@k so search changes are measurable and comparable over time. The seed
 * set is small and grows from real logs. Definitions:
 *   precision@k = |relevant ∩ top-k| / k
 *   recall@k    = |relevant ∩ top-k| / |relevant|
 * Aggregates are the unweighted mean across queries.
 *
 * The search function is injected, so the harness measures whatever tier is
 * wired: keyword-only (deterministic, CI-runnable) or the full hybrid when a
 * VPS vector service is configured (offline, real model).
 */
final class Evaluator
{
    /** @var callable(array{q: string, expected_ids: list<int>}): list<int> */
    private $search;

    /**
     * @param callable(array{q: string, expected_ids: list<int>}): list<int> $search
     *        Returns ranked product_ids for a labeled query case.
     */
    public function __construct(callable $search)
    {
        $this->search = $search;
    }

    /**
     * @param list<array{q: string, expected_ids: list<int>}> $cases
     * @return array{
     *     k: int,
     *     query_count: int,
     *     precision_at_k: float,
     *     recall_at_k: float,
     *     per_query: list<array{
     *         q: string, expected: int, retrieved: int, hits: int, precision: float, recall: float
     *     }>
     * }
     */
    public function run(array $cases, int $k): array
    {
        $k = max(1, $k);
        $perQuery = [];
        $precisionSum = 0.0;
        $recallSum = 0.0;

        foreach ($cases as $case) {
            $expected = array_values(array_unique(array_map('intval', $case['expected_ids'] ?? [])));
            $retrieved = array_slice(($this->search)($case), 0, $k);
            $hits = count(array_intersect($retrieved, $expected));

            $precision = $hits / $k;
            $recall = $expected === [] ? 0.0 : $hits / count($expected);
            $precisionSum += $precision;
            $recallSum += $recall;

            $perQuery[] = [
                'q'         => (string) ($case['q'] ?? ''),
                'expected'  => count($expected),
                'retrieved' => count($retrieved),
                'hits'      => $hits,
                'precision' => $precision,
                'recall'    => $recall,
            ];
        }

        $divisor = max(1, count($cases));

        return [
            'k'              => $k,
            'query_count'    => count($cases),
            'precision_at_k' => $precisionSum / $divisor,
            'recall_at_k'    => $recallSum / $divisor,
            'per_query'      => $perQuery,
        ];
    }
}
