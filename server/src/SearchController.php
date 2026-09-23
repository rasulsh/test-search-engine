<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * POST /search flow.
 *
 * Tier 1 (always on): normalize, keyword search, and — only when the query has
 * fewer than `suggestMinResults` matches — try a keyboard-layout remap or spell
 * correction. A suggestion is offered only if it returns MORE keyword results
 * than the literal query; when the literal query matched nothing, its results
 * are served instead (`did_you_mean_applied`).
 *
 * Tier 2 (additive, M4): when the request carries a query vector AND a bundle is
 * loaded, run global cosine top-K, drop neighbours below `semanticMinScore`, and
 * fuse the rest below the keyword hits (see Ranker). With a vector, a keyword
 * hit that matched only in the description must also reach `semanticMinScore`
 * (M11): products that merely mention the query in their spec text (case fans
 * for "ball bearing") are otherwise served for things the shop does not sell.
 * Title and specs (attribute / feature title, M13) matches are never dropped, and a hit with no vector is kept (no
 * evidence either way). If neither tier yields anything the response is
 * empty — far neighbours never pad it. Tier 2 is purely
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
    private float $semanticMinScore;
    private int $suggestMinResults;
    private bool $descOnlyNeedsSemantic;

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
        int $defaultLimit = 20,
        float $semanticMinScore = 0.82,
        int $suggestMinResults = 3,
        bool $descOnlyNeedsSemantic = true
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
        $this->semanticMinScore = $semanticMinScore;
        $this->suggestMinResults = max(1, $suggestMinResults);
        $this->descOnlyNeedsSemantic = $descOnlyNeedsSemantic;
    }

    /**
     * The production wiring shared by POST /search and the eval harness, so the
     * harness measures exactly the knobs /search serves. Search keys missing
     * from an older config.php fall back to the documented defaults.
     *
     * @param array<string, mixed> $config the server config array
     */
    public static function fromConfig(PDO $pdo, array $config): self
    {
        $search = $config['search'];
        $productsTable = $config['db']['products_table'];
        $maxDistance = (int) ($search['suggest_max_distance'] ?? 2);
        $minFrequency = (int) ($search['suggest_min_frequency'] ?? 2);

        $keyword = new Keyword(
            $pdo,
            $productsTable,
            (int) $search['min_token_size'],
            (int) $search['default_limit'],
            null,
            (float) ($search['title_weight'] ?? 10.0),
            (float) ($search['desc_weight'] ?? 1.0),
            (float) ($search['phrase_bonus'] ?? 5.0),
            (int) ($search['sku_prefix_min_length'] ?? 4),
            (float) ($search['spec_weight'] ?? 6.0)
        );
        // The bundle dictionary is cached per worker (and in APCu); the table scan
        // is only a fallback for a data directory without spellcheck.txt.
        $dictionaryPath = $config['paths']['data'] . '/' . Speller::DICTIONARY_FILE;
        $spellerFactory = static fn (): Speller =>
            Speller::fromDictionary($dictionaryPath, null, $maxDistance, $minFrequency)
            ?? Speller::fromProducts($pdo, $productsTable, $maxDistance, $minFrequency);

        // The signals provider both fetches business signals and acts as the
        // existence filter (ids it omits are treated as absent from the catalog).
        $signalsProvider = static function (array $ids) use ($pdo, $productsTable): array {
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = 'SELECT product_id, stock, popularity FROM ' . Identifier::quote($productsTable)
                 . ' WHERE product_id IN (' . $placeholders . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($ids));
            $signals = [];
            foreach ($stmt as $row) {
                $signals[(int) $row['product_id']] = [
                    'stock'      => (int) $row['stock'],
                    'popularity' => (int) $row['popularity'],
                ];
            }

            return $signals;
        };

        return new self(
            $keyword,
            new Logger($pdo, $config['db']['search_logs_table']),
            $spellerFactory,
            null,
            10,
            new Vectors($config['paths']['data'], (int) $config['model']['dim']),
            new Ranker(
                (int) $search['rrf_k'],
                (float) $search['stock_boost'],
                (float) $search['popularity_boost'],
                (float) ($search['keyword_weight'] ?? 1.0),
                (float) ($search['semantic_weight'] ?? 1.0)
            ),
            $signalsProvider,
            (int) $search['semantic_top_k'],
            (int) $search['default_limit'],
            (float) ($search['semantic_min_score'] ?? 0.82),
            (int) ($search['suggest_min_results'] ?? 3),
            (bool) ($search['desc_only_needs_semantic'] ?? true)
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array{
     *     query: array{raw: string, normalized: string},
     *     did_you_mean: ?string,
     *     did_you_mean_applied: bool,
     *     count: int,
     *     product_ids: list<int>,
     *     cosine_scores?: list<?float>
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
        $applied = false;

        $skuIds = array_values(array_map(
            static fn (array $row): int => $row['product_id'],
            array_filter($results, static fn (array $row): bool => $row['match_type'] === Keyword::MATCH_SKU)
        ));

        // A SKU hit is the product the shopper asked for by code: no suggestion.
        if ($skuIds === [] && count($results) < $this->suggestMinResults && $normalized !== '') {
            [$alternative, $didYouMean] = $this->recover($raw, $normalized, $limit, count($results));
            // A literal match, however thin, is what the shopper typed: keep it
            // and only offer the suggestion. With no literal match, serve it.
            if ($didYouMean !== null && $results === []) {
                $results = $alternative;
                $applied = true;
            }
        }

        $keywordIds = array_map(static fn (array $row): int => $row['product_id'], $results);
        $titleIds = array_values(array_map(
            static fn (array $row): int => $row['product_id'],
            array_filter($results, static fn (array $row): bool => $row['title_match'])
        ));
        $specIds = array_values(array_map(
            static fn (array $row): int => $row['product_id'],
            array_filter($results, static fn (array $row): bool => $row['spec_match'] && !$row['title_match'])
        ));
        $productIds = $keywordIds;
        $cosineScores = null;

        // Tier 2 is additive: only reshuffle when a usable vector and bundle are
        // present. Otherwise the keyword ordering above stands.
        if ($queryVector !== null && $this->semanticEnabled()) {
            [$productIds, $cosineScores] = $this->hybrid(
                $keywordIds,
                $titleIds,
                $specIds,
                $skuIds,
                $queryVector,
                $limit
            );
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        // Logging is best-effort: a rejected log row (e.g. an over-long query
        // under strict SQL mode) must never fail the search itself.
        try {
            $this->logger->log([
                'raw_q'        => $raw,
                'normalized_q' => $normalized,
                'had_vector'   => $queryVector !== null,
                'result_count' => count($productIds),
                'top_ids'      => array_slice($productIds, 0, $this->topIdsLimit),
                'customer_id'  => $customerId,
                'latency_ms'   => $latencyMs,
            ]);
        } catch (Throwable $e) {
            error_log('search: log write failed: ' . $e->getMessage());
        }

        $response = [
            'query'                => ['raw' => $raw, 'normalized' => $normalized],
            'did_you_mean'         => $didYouMean,
            'did_you_mean_applied' => $applied,
            'count'                => count($productIds),
            'product_ids'          => $productIds,
        ];
        if ($cosineScores !== null) {
            $response['cosine_scores'] = $cosineScores;
        }

        return $response;
    }

    /**
     * Global cosine top-K (above the relevance floor) fused below the keyword
     * hits, plus each returned id's cosine (null when it has no vector).
     * Description-only keyword hits (no title or specs match) scoring below the
     * floor are dropped first.
     * Semantic ids not present in the products table are dropped (the signals
     * provider omits them), so a partial reload degrades instead of surfacing
     * dead ids.
     *
     * @param list<int> $keywordIds
     * @param list<int> $titleIds keyword ids that matched in the title
     * @param list<int> $specIds keyword ids that matched in the specs, not the title
     * @param list<int> $skuIds keyword ids that matched by SKU, kept first in order
     * @param list<float> $queryVector
     * @return array{0: list<int>, 1: list<?float>}
     */
    private function hybrid(
        array $keywordIds,
        array $titleIds,
        array $specIds,
        array $skuIds,
        array $queryVector,
        ?int $limit
    ): array {
        $semantic = $this->vectors->topK($queryVector, $this->semanticTopK, $this->semanticMinScore);
        $semanticIds = array_map(static fn (array $row): int => $row['product_id'], $semantic);
        $known = array_column($semantic, 'score', 'product_id');

        if ($this->descOnlyNeedsSemantic) {
            $descOnly = array_flip(array_diff($keywordIds, $titleIds, $specIds));
            $known += $this->vectors->scoresFor(
                $queryVector,
                array_values(array_diff(array_keys($descOnly), array_keys($known)))
            );
            $floor = $this->semanticMinScore;
            $keywordIds = array_values(array_filter(
                $keywordIds,
                static fn (int $id): bool => !isset($descOnly[$id]) || !isset($known[$id]) || $known[$id] >= $floor
            ));
        }

        $productIds = $keywordIds;
        if ($semanticIds !== []) {
            $candidates = array_values(array_unique(array_merge($keywordIds, $semanticIds)));
            $signals = ($this->signalsProvider)($candidates);

            // Existence filter: keep only ids the products table actually has.
            $exists = static fn (int $id): bool => isset($signals[$id]);
            $keywordIds = array_values(array_filter($keywordIds, $exists));
            $semanticIds = array_values(array_filter($semanticIds, $exists));

            $effectiveLimit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
            $fused = $this->ranker->fuse($keywordIds, $semanticIds, $signals, $effectiveLimit, $titleIds, $specIds);
            $productIds = array_map(static fn (array $row): int => $row['product_id'], $fused);
            // Semantic evidence reorders the title band; SKU hits stay on top.
            $pinned = array_values(array_intersect($skuIds, $keywordIds));
            $productIds = array_slice(
                array_values(array_unique(array_merge($pinned, $productIds))),
                0,
                $effectiveLimit
            );
        }

        $missing = array_values(array_diff($productIds, array_keys($known)));
        $known += $this->vectors->scoresFor($queryVector, $missing);
        $cosine = array_map(
            static fn (int $id): ?float => isset($known[$id]) ? round($known[$id], 4) : null,
            $productIds
        );

        return [$productIds, $cosine];
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
     * alternative's results and the suggestion, or empty + null when no
     * alternative returns more than $baseline results.
     *
     * @return array{
     *     0: list<array{product_id: int, score: float, match_type: string, title_match: bool, spec_match: bool}>,
     *     1: ?string
     * }
     */
    private function recover(string $raw, string $normalized, ?int $limit, int $baseline): array
    {
        foreach ([Keymap::enToFa($raw), Keymap::faToEn($raw)] as $candidate) {
            $candidateNormalized = ($this->normalize)($candidate);
            if ($candidateNormalized === '' || $candidateNormalized === $normalized) {
                continue;
            }
            $alternative = $this->keyword->search($candidate, $limit);
            if (count($alternative) > $baseline) {
                return [$alternative, $candidateNormalized];
            }
        }

        $suggestion = ($this->spellerFactory)()->suggest($normalized);
        if ($suggestion !== null && $suggestion !== $normalized) {
            $alternative = $this->keyword->search($suggestion, $limit);
            if (count($alternative) > $baseline) {
                return [$alternative, $suggestion];
            }
        }

        return [[], null];
    }
}
