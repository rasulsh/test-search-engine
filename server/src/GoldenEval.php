<?php

declare(strict_types=1);

namespace App;

/**
 * Search quality against an owner-labelled golden set (M44, tools/eval/run_eval.php).
 *
 * A case is a query and the product ids that are right for it. A run reports, over all cases, success@k (a relevant
 * product in the top k), MRR (1 / rank of the first relevant product), nDCG@k with binary relevance and the zero-result
 * rate, and per query the rank of the first relevant hit. A case with no relevant ids means "nothing should come back":
 * it scores 1 on every metric when the search returns nothing and 0 otherwise. {@see compare()} diffs two saved runs
 * query by query. Pure computation: the search function is injected, so tests need no database.
 */
final class GoldenEval
{
    /**
     * @param string $csv columns `query`, `relevant_ids` (`;`-separated), `note`; lines starting with `#`
     *        before the header are comments
     * @return list<array{query: string, relevant: list<int>, note: string}>
     */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $columns = null;
        $cases = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null] || (isset($row[0]) && str_starts_with(ltrim((string) $row[0]), '#'))) {
                continue;
            }
            if ($columns === null) {
                $columns = array_map(static fn (?string $c): string => strtolower(trim((string) $c)), $row);
                if (!in_array('query', $columns, true) || !in_array('relevant_ids', $columns, true)) {
                    throw new \InvalidArgumentException('the golden file needs the columns query and relevant_ids');
                }
                continue;
            }
            $padded = array_pad(array_slice($row, 0, count($columns)), count($columns), '');
            $cells = array_combine($columns, $padded) ?: [];
            $query = trim((string) ($cells['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $ids = [];
            foreach (explode(';', (string) ($cells['relevant_ids'] ?? '')) as $id) {
                if (trim($id) !== '') {
                    $ids[] = (int) trim($id);
                }
            }
            $cases[] = [
                'query'    => $query,
                'relevant' => array_values(array_unique($ids)),
                'note'     => (string) ($cells['note'] ?? ''),
            ];
        }
        fclose($stream);

        return $cases;
    }

    /** 1-based rank of the first relevant id, or null. @param list<int> $ranked @param list<int> $relevant */
    public static function firstRank(array $ranked, array $relevant): ?int
    {
        foreach ($ranked as $i => $id) {
            if (in_array($id, $relevant, true)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * nDCG@k with binary relevance: sum of 1 / log2(rank + 1) over the relevant ids in the top k, over the same sum
     * for the best possible list (min(relevant, k) hits first).
     *
     * @param list<int> $ranked
     * @param list<int> $relevant
     */
    public static function ndcg(array $ranked, array $relevant, int $k): float
    {
        if ($relevant === []) {
            return 0.0;
        }
        $dcg = 0.0;
        foreach (array_slice($ranked, 0, $k) as $i => $id) {
            if (in_array($id, $relevant, true)) {
                $dcg += 1.0 / log($i + 2, 2);
            }
        }
        $ideal = 0.0;
        for ($i = 0, $n = min(count($relevant), $k); $i < $n; $i++) {
            $ideal += 1.0 / log($i + 2, 2);
        }

        return $dcg / $ideal;
    }

    /**
     * @param list<array{query: string, relevant: list<int>, note?: string}> $cases
     * @param callable(string): list<int> $search ranked product ids for a query
     * @return array{
     *     k: int, query_count: int, success_at_k: float, mrr: float, ndcg_at_k: float, zero_result_rate: float,
     *     per_query: list<array{query: string, note: string, relevant: int, results: int, first_rank: ?int,
     *         success: bool, rr: float, ndcg: float, zero: bool}>
     * }
     */
    public static function run(array $cases, callable $search, int $k = 10): array
    {
        $k = max(1, $k);
        $rows = [];
        foreach ($cases as $case) {
            $ranked = array_slice(array_map('intval', $search($case['query'])), 0, $k);
            $relevant = array_map('intval', $case['relevant']);
            if ($relevant === []) {
                $none = $ranked === [] ? 1.0 : 0.0;
                $rank = null;
                $success = $ranked === [];
                $rr = $ndcg = $none;
            } else {
                $rank = self::firstRank($ranked, $relevant);
                $success = $rank !== null;
                $rr = $rank === null ? 0.0 : 1.0 / $rank;
                $ndcg = self::ndcg($ranked, $relevant, $k);
            }
            $rows[] = [
                'query'      => $case['query'],
                'note'       => (string) ($case['note'] ?? ''),
                'relevant'   => count($relevant),
                'results'    => count($ranked),
                'first_rank' => $rank,
                'success'    => $success,
                'rr'         => $rr,
                'ndcg'       => $ndcg,
                'zero'       => $ranked === [],
            ];
        }
        $n = max(1, count($rows));
        $mean = static fn (string $key): float => array_sum(array_map(
            static fn (array $row): float => (float) $row[$key],
            $rows
        )) / $n;

        return [
            'k'                => $k,
            'query_count'      => count($rows),
            'success_at_k'     => count(array_filter($rows, static fn (array $r): bool => $r['success'])) / (float) $n,
            'mrr'              => $mean('rr'),
            'ndcg_at_k'        => $mean('ndcg'),
            'zero_result_rate' => count(array_filter($rows, static fn (array $r): bool => $r['zero'])) / (float) $n,
            'per_query'        => $rows,
        ];
    }

    /**
     * Two saved runs, query by query. A query regresses when its first relevant hit moved down or disappeared (or a
     * "nothing should come back" case now returns something), and improves in the opposite cases.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{
     *     metrics: array<string, array{before: float, after: float, delta: float}>,
     *     regressions: list<array{query: string, before: ?int, after: ?int}>,
     *     improvements: list<array{query: string, before: ?int, after: ?int}>,
     *     added: list<string>, removed: list<string>
     * }
     */
    public static function compare(array $before, array $after): array
    {
        $index = static function (array $run): array {
            $rows = [];
            foreach ($run['per_query'] ?? [] as $row) {
                $rows[(string) $row['query']] = $row;
            }

            return $rows;
        };
        $a = $index($before);
        $b = $index($after);
        $metrics = [];
        foreach (['success_at_k', 'mrr', 'ndcg_at_k', 'zero_result_rate'] as $key) {
            $x = (float) ($before[$key] ?? 0.0);
            $y = (float) ($after[$key] ?? 0.0);
            $metrics[$key] = ['before' => $x, 'after' => $y, 'delta' => $y - $x];
        }
        // Lower is better; "no relevant hit" is worse than any rank. A "nothing should come back" case has no rank,
        // so its success flag decides.
        $worth = static fn (array $row): float => $row['relevant'] === 0
            ? ($row['success'] ? 0.0 : INF)
            : ($row['first_rank'] === null ? INF : (float) $row['first_rank']);
        $regressions = [];
        $improvements = [];
        foreach (array_intersect_key($b, $a) as $query => $row) {
            $was = $worth($a[$query]);
            $now = $worth($row);
            $entry = ['query' => (string) $query, 'before' => $a[$query]['first_rank'], 'after' => $row['first_rank']];
            if ($now > $was) {
                $regressions[] = $entry;
            } elseif ($now < $was) {
                $improvements[] = $entry;
            }
        }

        return [
            'metrics'      => $metrics,
            'regressions'  => $regressions,
            'improvements' => $improvements,
            'added'        => array_map('strval', array_keys(array_diff_key($b, $a))),
            'removed'      => array_map('strval', array_keys(array_diff_key($a, $b))),
        ];
    }
}
