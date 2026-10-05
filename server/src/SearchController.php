<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
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
 * Tier 2 (additive; M4, sourced from the VPS since M18): when a VPS vector
 * service is configured, the query text is sent to it server-to-server (the VPS
 * embeds it and runs the global cosine top-K) and neighbours below
 * `semanticMinScore` are dropped. Keyword hits and neighbours are then ranked
 * together by one blended relevance score and a combined floor (M20, see
 * Ranker); exact-SKU hits stay pinned on top and title (name) matches are
 * never dropped by the floor, only weak spec / description hits are. Tier 2 is purely additive — if
 * the VPS is not configured, unreachable, slow (timeout) or answers badly, the
 * request returns Tier 1 results, logs the degradation and never errors
 * (CLAUDE.md sec. 5). If neither tier yields anything the response is empty —
 * far neighbours never pad it.
 *
 * All terms (M16, require_all_terms): a multi-word query matches only products
 * holding every word (Keyword). When that finds nothing, even after the
 * keyboard-layout / spelling recovery, the best partial matches are served
 * instead, so the shopper still sees something. While the keyword hits do
 * hold every word, semantic-only neighbours are not appended: embeddings put
 * black keyboards and red mice close to "red keyboard", the one-word matches
 * the rule excludes. Semantic evidence still reorders those hits; single-word, SKU and partial-fallback
 * requests keep the additive neighbours.
 *
 * Soft AND and collapsed names (M23, Keyword): fewer than soft_and_min_results
 * full-coverage hits are topped up with partial-coverage ones, ranked below
 * them; they do not count as matches for the typo / layout recovery, so that
 * still runs, and an applied suggestion replaces them.
 *
 * M21: every request logs its suggestion and tier; `debug` (see search()) adds
 * a per-result score breakdown to the response, never to the log.
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
    private ?VpsClient $vps;
    private ?Ranker $ranker;
    /** @var null|callable(list<int>): array<int, array{stock: int, popularity: int}> */
    private $signalsProvider;
    private int $semanticTopK;
    private int $defaultLimit;
    private float $semanticMinScore;
    private int $suggestMinResults;
    private ?Cache $cache;

    /**
     * @param callable(): Speller $spellerFactory Built lazily (a catalog scan),
     *        so it only runs when the primary search returns nothing.
     * @param null|callable(list<int>): array<int, array{stock: int, popularity: int}> $signalsProvider
     *        Returns business signals keyed by product_id for the ids that EXIST
     *        in the products table; ids it omits are treated as absent, which is
     *        how a transient vector/table mismatch degrades (contract-tolerant
     *        reader). Required, with $vps and $ranker, to enable Tier 2.
     */
    public function __construct(
        Keyword $keyword,
        Logger $logger,
        callable $spellerFactory,
        ?callable $normalizer = null,
        int $topIdsLimit = 10,
        ?VpsClient $vps = null,
        ?Ranker $ranker = null,
        ?callable $signalsProvider = null,
        int $semanticTopK = 100,
        int $defaultLimit = 20,
        float $semanticMinScore = 0.4,
        int $suggestMinResults = 3,
        ?Cache $cache = null
    ) {
        $this->keyword = $keyword;
        $this->logger = $logger;
        $this->spellerFactory = $spellerFactory;
        $this->normalize = $normalizer ?? [Normalizer::class, 'normalize'];
        $this->topIdsLimit = max(1, $topIdsLimit);
        $this->vps = $vps;
        $this->ranker = $ranker;
        $this->signalsProvider = $signalsProvider;
        $this->semanticTopK = max(1, $semanticTopK);
        $this->defaultLimit = max(1, $defaultLimit);
        $this->semanticMinScore = $semanticMinScore;
        $this->suggestMinResults = max(1, $suggestMinResults);
        $this->cache = $cache;
    }

    /**
     * The production wiring shared by POST /search and the eval harness, so the
     * harness measures exactly the knobs /search serves. Search keys missing
     * from an older config.php fall back to the documented defaults (no `vps`
     * section: keyword-only).
     *
     * @param array<string, mixed> $config the server config array
     * @param VpsClient|null $vps replaces the client built from $config['vps'] (tests)
     * @param Cache|null $cache replaces the result cache built from $config['redis'] (tests)
     */
    public static function fromConfig(PDO $pdo, array $config, ?VpsClient $vps = null, ?Cache $cache = null): self
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
            (float) ($search['spec_weight'] ?? 6.0),
            Synonyms::fromDirectory($config['paths']['data'], (int) ($search['synonyms_max_group_size'] ?? 4)),
            (int) ($search['alias_max_variants'] ?? 6),
            (bool) ($search['require_all_terms'] ?? true),
            (float) ($search['tag_weight'] ?? 8.0),
            (float) ($search['brand_weight'] ?? 7.0),
            (float) ($search['category_weight'] ?? 5.0),
            (float) ($search['collapse_weight'] ?? 9.0),
            (int) ($search['collapse_min_length'] ?? 5),
            (int) ($search['soft_and_min_results'] ?? 3),
            (float) ($search['soft_and_min_coverage'] ?? 0.5),
            (float) ($search['soft_and_partial_penalty'] ?? 0.5),
            (int) ($search['soft_and_candidate_cap'] ?? 100)
        );
        // The bundle dictionary is cached per worker (and in APCu); the table scan
        // is only a fallback for a data directory without spellcheck.txt.
        $dictionaryPath = $config['paths']['data'] . '/' . Speller::DICTIONARY_FILE;
        $spellerFactory = static fn (): Speller =>
            Speller::fromDictionary($dictionaryPath, null, $maxDistance, $minFrequency)
            ?? Speller::fromProducts($pdo, $productsTable, $maxDistance, $minFrequency);

        // The signals provider both fetches business signals and acts as the
        // existence filter (ids it omits are treated as absent from the catalog).
        // The M21 match texts (normalized brand, category, tags) ride along; a live
        // table that predates them (42S22) just gets no match boosts.
        $withTexts = true;
        $signalsProvider = static function (array $ids) use ($pdo, $productsTable, &$withTexts): array {
            if ($ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = static fn (string $columns): string => 'SELECT product_id, stock, popularity' . $columns
                . ' FROM ' . Identifier::quote($productsTable) . ' WHERE product_id IN (' . $placeholders . ')';
            try {
                $texts = ', normalized_brand, normalized_category, normalized_tags';
                $stmt = $pdo->prepare($query($withTexts ? $texts : ''));
                $stmt->execute(array_values($ids));
            } catch (PDOException $e) {
                if (!$withTexts || $e->getCode() !== '42S22') {
                    throw $e;
                }
                $withTexts = false;
                $stmt = $pdo->prepare($query(''));
                $stmt->execute(array_values($ids));
            }
            $signals = [];
            foreach ($stmt as $row) {
                $signals[(int) $row['product_id']] = [
                    'stock'      => (int) $row['stock'],
                    'popularity' => (int) $row['popularity'],
                ] + ($withTexts ? [
                    'brand'    => (string) $row['normalized_brand'],
                    'category' => (string) $row['normalized_category'],
                    'tags'     => (string) $row['normalized_tags'],
                ] : []);
            }

            return $signals;
        };

        return new self(
            $keyword,
            new Logger($pdo, $config['db']['search_logs_table']),
            $spellerFactory,
            null,
            10,
            $vps ?? VpsClient::fromConfig($config['vps'] ?? []),
            new Ranker(
                (float) $search['stock_boost'],
                (float) $search['popularity_boost'],
                (float) ($search['keyword_weight'] ?? 0.4),
                (float) ($search['semantic_weight'] ?? 0.6),
                (float) ($search['min_relevance'] ?? 0.45),
                (float) ($search['brand_match_boost'] ?? 0.15),
                (float) ($search['category_match_boost'] ?? 0.1),
                (float) ($search['tag_match_boost'] ?? 0.1),
                (int) ($search['tag_match_min_tokens'] ?? 2)
            ),
            $signalsProvider,
            (int) $search['semantic_top_k'],
            (int) $search['default_limit'],
            (float) ($search['semantic_min_score'] ?? 0.4),
            (int) ($search['suggest_min_results'] ?? 3),
            $cache ?? Cache::fromConfig($config)
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
     *     cosine_scores?: list<?float>,
     *     debug?: array<string, mixed>
     * }
     * @param bool $debug the caller verified the debug token: add the per-result score breakdown
     */
    public function search(array $request, bool $debug = false): array
    {
        $raw = is_string($request['q'] ?? null) ? $request['q'] : '';
        $limit = isset($request['limit']) ? (int) $request['limit'] : null;
        $customerId = isset($request['customer_id']) && is_string($request['customer_id'])
            ? $request['customer_id']
            : null;

        $start = microtime(true);
        $normalized = ($this->normalize)($raw);

        // Result cache (M26): the debug breakdown is never cached or served from it.
        $semanticOn = $this->semanticEnabled();
        $cacheKey = $this->cache !== null && !$debug && $normalized !== ''
            ? $this->cache->key($normalized, $limit, $semanticOn)
            : null;
        if ($cacheKey !== null) {
            $hit = $this->cache->get($cacheKey);
            if ($hit !== null && $this->isCachedResponse($hit)) {
                return $this->serveCached($hit, $raw, $customerId, $start);
            }
        }

        $multiTerm = count(Tokenizer::split($normalized)) > 1;
        $results = $this->keyword->search($raw, $limit);
        $didYouMean = null;
        $applied = false;

        $skuIds = array_values(array_map(
            static fn (array $row): int => $row['product_id'],
            array_filter($results, static fn (array $row): bool => $row['match_type'] === Keyword::MATCH_SKU)
        ));

        // A SKU hit is the product the shopper asked for by code: no suggestion.
        // Soft-AND partial matches (M23) are not matches for this purpose: a typo
        // whose other word matches plenty still deserves its correction.
        $literal = $this->fullCoverageCount($results);
        if ($skuIds === [] && $literal < $this->suggestMinResults && $normalized !== '') {
            [$alternative, $didYouMean] = $this->recover($raw, $normalized, $limit, $literal);
            // A literal match, however thin, is what the shopper typed: keep it
            // and only offer the suggestion. With no literal match (partial
            // ones aside), serve it.
            if ($didYouMean !== null && $literal === 0) {
                $results = $alternative;
                $applied = true;
            }
        }

        if ($results === [] && $multiTerm && $this->keyword->requiresAllTerms()) {
            $results = $this->keyword->search($raw, $limit, false);
        }
        $allTermsHits = $multiTerm && $skuIds === [] && $results !== [] && $this->keyword->requiresAllTerms()
            && !in_array(Keyword::MATCH_PARTIAL, array_column($results, 'match_type'), true);

        $keywordIds = array_map(static fn (array $row): int => $row['product_id'], $results);
        $productIds = $keywordIds;
        $cosineScores = null;
        $explanation = null;

        // Tier 2 is additive: only reshuffle when the VPS answered. Otherwise the
        // keyword ordering above stands.
        $semantic = $this->semantic($raw, $normalized);
        if ($semantic !== null) {
            // Boost words come from the query that produced the results: the applied
            // suggestion when the literal query matched nothing.
            $boostQuery = $applied && $didYouMean !== null ? $didYouMean : $normalized;
            [$productIds, $cosineScores, $explanation] = $this->hybrid(
                $results,
                $skuIds,
                $semantic,
                $limit,
                !$allTermsHits,
                $this->keyword->variants($boostQuery),
                $debug
            );
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        // Logging is best-effort: a rejected log row (e.g. an over-long query
        // under strict SQL mode) must never fail the search itself.
        try {
            $this->logger->log([
                'raw_q'        => $raw,
                'normalized_q' => $normalized,
                'had_vector'   => $semantic !== null,
                'result_count' => count($productIds),
                'top_ids'      => array_slice($productIds, 0, $this->topIdsLimit),
                'customer_id'  => $customerId,
                'latency_ms'   => $latencyMs,
                'did_you_mean' => $didYouMean,
                'tier'         => $semantic !== null ? Logger::TIER_HYBRID : Logger::TIER_KEYWORD_ONLY,
                'cache_hit'    => false,
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
        if ($debug) {
            $response['debug'] = $this->explain($results, $productIds, $semantic !== null, $explanation);
        }

        // A result served without the semantic tier that is configured (VPS down,
        // slow) is a degradation: caching it would pin it for the whole TTL.
        if ($cacheKey !== null && (!$semanticOn || $semantic !== null)) {
            $this->cache->set($cacheKey, [
                'response'   => $response,
                'had_vector' => $semantic !== null,
            ]);
        }

        return $response;
    }

    private function semanticEnabled(): bool
    {
        return $this->vps !== null && $this->ranker !== null && $this->signalsProvider !== null;
    }

    /** @param array<string, mixed> $hit */
    private function isCachedResponse(array $hit): bool
    {
        $response = $hit['response'] ?? null;

        return is_array($response)
            && is_array($response['query'] ?? null)
            && is_array($response['product_ids'] ?? null)
            && array_key_exists('did_you_mean', $response);
    }

    /**
     * The stored response for this request, logged like any other search (flagged
     * as a cache hit). The raw text is the caller's own; the rest is as computed.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function serveCached(array $hit, string $raw, ?string $customerId, float $start): array
    {
        $response = $hit['response'];
        $response['query']['raw'] = $raw;
        $hadVector = (bool) ($hit['had_vector'] ?? false);

        try {
            $this->logger->log([
                'raw_q'        => $raw,
                'normalized_q' => (string) ($response['query']['normalized'] ?? ''),
                'had_vector'   => $hadVector,
                'result_count' => count($response['product_ids']),
                'top_ids'      => array_slice(array_map('intval', $response['product_ids']), 0, $this->topIdsLimit),
                'customer_id'  => $customerId,
                'latency_ms'   => (int) round((microtime(true) - $start) * 1000),
                'did_you_mean' => is_string($response['did_you_mean']) ? $response['did_you_mean'] : null,
                'tier'         => $hadVector ? Logger::TIER_HYBRID : Logger::TIER_KEYWORD_ONLY,
                'cache_hit'    => true,
            ]);
        } catch (Throwable $e) {
            error_log('search: log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * The VPS's global cosine top-K (above the relevance floor), or null when the
     * semantic tier is off or failed. A failure is logged and never raised.
     *
     * @return list<array{product_id: int, score: float}>|null
     */
    private function semantic(string $raw, string $normalized): ?array
    {
        if (!$this->semanticEnabled() || $normalized === '') {
            return null;
        }
        $semantic = $this->vps->search($raw, $this->semanticTopK, $this->semanticMinScore);
        if ($semantic === null) {
            error_log('search: semantic tier unavailable (' . $this->vps->failure() . '); served keyword-only results');
        }

        return $semantic;
    }

    /**
     * Keyword hits and semantic neighbours ranked by the blended relevance
     * floor (Ranker), exact-SKU hits first, plus each returned id's cosine
     * (null when the VPS did not return it). Ids not present in the products
     * table are dropped (the signals provider omits them), so a partial reload
     * degrades instead of surfacing dead ids.
     *
     * @param list<array{product_id: int, score: float, name_all: bool}> $results keyword hits
     * @param list<int> $skuIds keyword ids that matched by SKU, kept first in order
     * @param list<array{product_id: int, score: float}> $semantic best first, all >= the floor
     * @param bool $neighbours false: semantic evidence only reorders the keyword hits
     * @param list<list<string>> $variants query token variants for the Ranker's match boosts
     * @param bool $explain also return each ranked id's score breakdown (third element)
     * @return array{0: list<int>, 1: list<?float>, 2: array<int, array<string, mixed>>}
     */
    private function hybrid(
        array $results,
        array $skuIds,
        array $semantic,
        ?int $limit,
        bool $neighbours,
        array $variants,
        bool $explain
    ): array {
        $skuSet = array_flip($skuIds);
        $keywordScores = [];
        $solid = [];
        foreach ($results as $row) {
            // SKU scores are on their own huge scale and pinned anyway.
            if (!isset($skuSet[$row['product_id']])) {
                $keywordScores[$row['product_id']] = $row['score'];
                // Every query term in the title or tags (the product's names) is
                // solid: kept whatever the VPS says. Terms found only in specs /
                // description / brand / category are weak.
                if ($row['name_all']) {
                    $solid[] = $row['product_id'];
                }
            }
        }
        $known = array_column($semantic, 'score', 'product_id');
        $cosines = $neighbours ? $known : array_intersect_key($known, $keywordScores);

        $candidates = array_values(array_unique(
            array_merge($skuIds, array_keys($keywordScores), array_keys($cosines))
        ));
        $signals = ($this->signalsProvider)($candidates);
        // Existence filter: keep only ids the products table actually has.
        $keywordScores = array_intersect_key($keywordScores, $signals);
        $cosines = array_intersect_key($cosines, $signals);

        $effectiveLimit = $limit !== null ? max(1, $limit) : $this->defaultLimit;
        $ranked = $this->ranker->blend(
            $keywordScores,
            $cosines,
            $signals,
            $effectiveLimit,
            $solid,
            $this->semanticMinScore,
            $variants,
            $explain
        );
        $pinned = array_values(array_intersect($skuIds, array_keys($signals)));
        $productIds = array_slice(
            array_values(array_unique(array_merge(
                $pinned,
                array_map(static fn (array $row): int => $row['product_id'], $ranked)
            ))),
            0,
            $effectiveLimit
        );

        $cosine = array_map(
            static fn (int $id): ?float => isset($known[$id]) ? round($known[$id], 4) : null,
            $productIds
        );

        $details = [];
        foreach ($ranked as $row) {
            if (isset($row['detail'])) {
                $details[$row['product_id']] = $row['detail'];
            }
        }

        return [$productIds, $cosine, $details];
    }

    /**
     * The debug breakdown: per returned id its keyword hit (match type and
     * fields), and, for ids the hybrid ranker scored, the blend and every
     * boost; the ranker's knobs once. Never logged.
     *
     * @param list<array<string, mixed>> $results keyword hits
     * @param list<int> $productIds the returned ids, in order
     * @param array<int, array<string, mixed>>|null $scored hybrid detail per id
     * @return array<string, mixed>
     */
    private function explain(array $results, array $productIds, bool $hybrid, ?array $scored): array
    {
        $hits = array_column($results, null, 'product_id');
        $rows = [];
        foreach ($productIds as $rank => $id) {
            $hit = $hits[$id] ?? null;
            $rows[] = [
                'product_id' => $id,
                'rank'       => $rank + 1,
                'keyword_hit' => $hit === null ? null : [
                    'match_type'  => $hit['match_type'],
                    'score'       => round($hit['score'], 4),
                    'title_match' => $hit['title_match'],
                    'field_match' => $hit['spec_match'],
                    'name_all'    => $hit['name_all'],
                    'coverage'    => round($hit['coverage'], 4),
                ],
                // Exact-SKU hits are pinned above the blend and carry no blend detail.
                'pinned'     => $hit !== null && $hit['match_type'] === Keyword::MATCH_SKU,
                'blend'      => $scored[$id] ?? null,
            ];
        }

        return [
            'tier'     => $hybrid ? Logger::TIER_HYBRID : Logger::TIER_KEYWORD_ONLY,
            'settings' => $this->ranker?->settings(),
            'results'  => $rows,
        ];
    }

    /** @param list<array{match_type: string}> $rows keyword hits */
    private function fullCoverageCount(array $rows): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => $row['match_type'] !== Keyword::MATCH_PARTIAL
        ));
    }

    /**
     * Try keyboard-layout remaps first, then spell correction. Returns the
     * alternative's results and the suggestion, or empty + null when no
     * alternative returns more than $baseline results.
     *
     * @return array{
     *     0: list<array{product_id: int, score: float, match_type: string}>,
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
            if ($this->fullCoverageCount($alternative) > $baseline) {
                return [$alternative, $candidateNormalized];
            }
        }

        $suggestion = ($this->spellerFactory)()->suggest($normalized);
        if ($suggestion !== null && $suggestion !== $normalized) {
            $alternative = $this->keyword->search($suggestion, $limit);
            if ($this->fullCoverageCount($alternative) > $baseline) {
                return [$alternative, $suggestion];
            }
        }

        return [[], null];
    }
}
