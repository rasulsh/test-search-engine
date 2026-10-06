# Runtime flow of `POST /search`

What actually happens between a shopper's query and the ordered product ids, as implemented in `App\SearchController::search()` (`server/src/SearchController.php`), after M29 (layered config), M30 (deployment layout) and M31 (analytics). This is technical documentation: **keep it in sync with the code** (see [Keeping this in sync](#keeping-this-in-sync)).

Related: the tiers and contracts in [ARCHITECTURE.md](ARCHITECTURE.md), how matching and ranking behave in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md), every knob named below in [CONFIGURATION.md](CONFIGURATION.md), the HTTP surface and tooling in [SEARCH-API.md](SEARCH-API.md) and the exact JSON in [INTEGRATION.md](../INTEGRATION.md#http-contract).

## The flow at a glance

```
storefront JS ── {q, limit?} ──► public/search.php  (or index.php -> search.php)
                                        │  parse JSON, debug-token check, SearchController::fromConfig()
                                        ▼
 1  normalize q ───────────────────────────────────────────── Normalizer (parity with the pipeline)
 2  result cache?  ── hit ───────────────────────────────────► log (cache_hit=1) ─► response
        │ miss/off                       Redis (optional, on the VPS LAN)
        ▼
 3  Tier 1 keyword ── Keyword::search():  SKU · token/alias/synonym variants · collapsed names
        │              · FULLTEXT MATCH (title, tags, brand, category, specs, desc) · all-terms
        │              · soft-AND top-up · facet flag                         MySQL products
        ▼
 4  did-you-mean ── only when < suggest_min_results full-coverage hits and no SKU hit:
        │             keyboard layout, then spell correction (applied only with 0 literal hits)
        ▼
 5  any-terms fallback ── multi-word, strict AND found nothing → search again, most words first
        ▼
 6  Tier 2 semantic ── VpsClient ──► VPS /search-vectors (bge-m3 cosine top-K, floor)   null = skipped
        ▼
 7  Ranker hybrid ── only if step 6 answered: blend + min_relevance floor + boosts + SKU pin
        ▼
 8  log to search_logs (best effort)
 9  build response {query, did_you_mean, did_you_mean_applied, count, product_ids, cosine_scores?, debug?}
10  cache set (unless degraded) ─► (search.php adds `products` for with_details) ─► JSON
```

## Entry

- **Endpoint:** `POST /search` through the front controller (`public/index.php` routes by file name, so `/search` and `/search.php` both work, at a web root or under a subfolder) or `public/search.php` directly. Both resolve the app through `public/app_base.php` (M30) and load the config with `App\Config::load` (M29, [below](#configuration)).
- **Request body (JSON):** `{q, limit?, customer_id?, with_details?, debug?}`.
  - `q` is the raw query text. **The storefront sends only the text: the VPS embeds it.** Nothing embeds in the browser (no model ships to shoppers since M18) and cPanel never runs a model. The `q_vector` mentioned in older material (and in CLAUDE.md's request line) is legacy: a stale client may still send it, and it is ignored.
  - `limit`: the number of ids wanted (default `search.default_limit`). It is clamped to at least 1 and is not capped above.
  - `customer_id` (string) is only logged. `with_details: true` adds display fields to the response (step 9). `debug: 1` needs the `X-Debug-Token` header matching `debug.token` (`403 debug_forbidden` otherwise) and is never cached or logged.
- `405` for a non-`POST`, `400 invalid_json` for a body that is not a JSON object, `500 internal_error` for any other failure. A failing Tier 2 never produces an error.
- `SearchController::fromConfig()` wires the controller from the merged config: the `Keyword` engine (weights, soft-AND, facet knobs), the `Ranker`, the VPS client (`null` when `vps.url` is empty), the optional `Cache` (`null` unless `redis.enabled`), the spell-checker and the business-signal provider.

## Stages in the order `search()` runs them

### 1. Normalize

`Normalizer::normalize($raw)` (ZWNJ / half-space, ی/ک, digit folding, diacritics, model-name canonicalization, whitespace). It mirrors `pipeline/normalize.py` exactly (contract 1), so a query and the indexed `normalized_*` columns agree. An empty normalized query skips the cache and the VPS and returns whatever the keyword tier does with no tokens (nothing).

### 2. Result cache check (M26)

Only when the cache is configured, the request is not `debug` and the normalized query is not empty. The key is the sha1 of `[normalized query, limit, semantic configured?]` under `redis.prefix`. A valid hit returns the stored response immediately: the raw text and `customer_id` are the caller's own, the row is logged with `cache_hit = 1` (and the original `tier` / `had_vector`), and none of stages 3-8 run. A miss, a Redis error, a timeout (`redis.timeout_ms`) or a malformed entry is simply a miss: the search recomputes. Redis is never required.

### 3. Tier 1: keyword (`Keyword::search()`)

Always on, MySQL only. In order:

1. **Tokenize** the normalized query (`Tokenizer`). No tokens: no hits.
2. **SKU:** the query normalized as a SKU is matched against `normalized_sku`: an exact match scores `2,000,000`, a prefix match (at least `search.sku_prefix_min_length` characters and a digit) `1,000,000`. SKU hits come first in the result and are marked `match_type = sku`.
3. **Variants:** the literal tokens plus alias / synonym variants from the bundle's `aliases.json` and `synonyms.json` (`Synonyms`, at most `search.alias_max_variants`, generated groups larger than `search.synonyms_max_group_size` ignored). The literal query is searched in every field; the other variants are matched in the title and structured fields only.
4. **Text search per variant.** A token shorter than the host's `innodb_ft_min_token_size` (`search.min_token_size`) sends the variant down a `LIKE` scan; otherwise one FULLTEXT query `MATCH(normalized_title, normalized_tags, normalized_brand, normalized_category, normalized_specs, normalized_desc) AGAINST('+tok*' IN BOOLEAN MODE)` (the column list must equal the index in `db/schema.sql`). Each token is credited to the best field it is found in: title `search.title_weight`, then tags, brand, category, specs (`search.tag_weight`, `brand_weight`, `category_weight`, `spec_weight`), then the description (`desc_weight`), averaged over the tokens plus `search.phrase_bonus` for the tokens adjacent and in order in the title. Rows are ordered by terms held, then title band, then field band, then score, relevance, popularity.
5. **`require_all_terms`** (`search.require_all_terms`, default on): a multi-word query keeps only products holding every word (`+tok*` for each); with it off, most words first.
6. **Collapsed names** (M23): the query with its spaces removed is matched as a substring of `normalized_collapsed` (title, brand, tags), verified at a word start, scored `search.collapse_weight`. Skipped when the page is already full of better title hits or the collapsed query is shorter than `search.collapse_min_length`.
7. **Soft AND** (M23): when fewer than `search.soft_and_min_results` full-coverage hits exist for a multi-word query, products holding at least `search.soft_and_min_coverage` of a variant's words are added with `match_type = partial`, their keyword score scaled by `search.soft_and_partial_penalty`. They always rank below every full-coverage hit.
8. **Facet flag** (M28): each hit also records `facet_all`: every query term is in the title / tags, or is a spec-only term that at least `search.facet_min_products` products carry in their specs (a genre / feature value). The Ranker uses it as a floor exemption (stage 7).

A live table that predates the M21 columns (tags, brand, category) or M13 specs (error `42S22`) is searched without them; a missing `normalized_sku` / `normalized_collapsed` just yields no SKU / collapsed hits.

### 4. Did-you-mean recovery

Tried only when there is **no SKU hit**, the query is not empty and fewer than `search.suggest_min_results` (3) **full-coverage** hits came back (soft-AND partial hits do not count). `recover()` tries, in order:

1. the keyboard-layout remaps (Latin typed on a Persian layout and the reverse, `Keymap`, hard-coded layout), then
2. the spell-checker (`Speller`: dictionary from the bundle's `spellcheck.txt`, cached per worker and in APCu when available; a data directory without it falls back to a scan of the `products` table), within `search.suggest_max_distance` edits for candidates seen in at least `search.suggest_min_frequency` products.

An alternative is accepted only if it returns **more** full-coverage hits than the literal query. The suggestion is always reported as `did_you_mean`. It is **applied** (its results replace the literal ones, `did_you_mean_applied = true`) only when the literal query had **zero** full-coverage hits; with a thin literal match the shopper's own results stay and the suggestion is only offered.

### 5. Any-terms fallback

If the results are still empty for a multi-word query while `require_all_terms` is on, the keyword search runs once more with strict AND off: the products matching the most words are served.

The controller then notes whether the result is a clean all-words result (multi-word, no SKU hit, hits present, none partial): that decides stage 7's neighbour rule.

### 6. Tier 2: semantic (`VpsClient`)

Only when a VPS is configured (`vps.url`) and the normalized query is not empty. `POST {vps.url}/search-vectors` with `{q, limit: search.semantic_top_k, min_score: search.semantic_min_score}`, a bearer token (`vps.token`) and a whole-call budget of `vps.timeout_ms` (connect included). The VPS embeds the text with bge-m3 and returns the global cosine top-K `[{product_id, score}]` (brute force over all its product vectors: this is what recovers cross-language matches). The client re-applies the floor and sorts. The result is `null` when the VPS is off, unreachable, slow (over the timeout), returns a non-200 or a malformed body: the reason goes to `error_log` and the search carries on keyword-only.

### 7. Hybrid ranking (`Ranker::blend()`), only when stage 6 answered

If stage 6 returned `null`, the keyword order of stages 3-5 is the answer. If it returned a list (even an empty one), `hybrid()` runs:

1. **Inputs.** SKU hits are set aside: they are pinned first and never blended. The other keyword hits keep their score; a hit is **solid** when `name_all` (every term in the title or tags) or `facet_all` (stage 3) holds.
2. **Neighbour rule.** Semantic neighbours that have no keyword hit are added as candidates only when the result is not a clean all-words result. When all-words hits exist, the cosine only reorders them and nothing is appended below.
3. **Existence filter.** One query for the candidates' business signals (`stock`, `popularity`, and the normalized brand / category / tags for the boosts) doubles as a filter: ids the `products` table does not have are dropped, so a partial reload cannot surface dead ids.
4. **Blend.** `relevance = keyword_weight · kw_norm + semantic_weight · sem_norm`, where `kw_norm` is the keyword score over the best keyword score in the set and `sem_norm` the cosine over the best cosine (0 when the VPS did not return the id). A solid hit the VPS did not return is assumed to sit at the floor (`search.semantic_min_score`, capped at the best cosine returned).
5. **Floor.** Candidates below `search.min_relevance` are dropped, **except** solid hits (title / tag names, or a common facet value), which are only ranked. So a weak keyword hit (the query only in rare specs, the description, brand or category) needs semantic support, and a semantic-only neighbour must clear the floor on cosine alone. An exact SKU hit never reaches the floor: it is pinned.
6. **Boosts**, applied after the floor and multiplying the relevance by `1 + boosts`: in-stock (`search.stock_boost`), popularity (`search.popularity_boost`, relative to the best in the set), and the match boosts `brand_match_boost`, `category_match_boost` (all words of the product's brand / category are in the query or one of its alias variants) and `tag_match_boost` (a query of at least `tag_match_min_tokens` words is a phrase inside the tags). They reorder close candidates and cannot admit a dropped one.
7. **Order and cut.** Sorted by final score (ties by id), then SKU-pinned ids first, de-duplicated, cut to `limit`. `cosine_scores` is the VPS cosine of each returned id (`null` where the VPS did not return it).

### 8. Log

One `search_logs` row, best effort: `ts`, `raw_q`, `normalized_q`, `had_vector` (stage 6 answered), `result_count`, `top_ids` (the first ten), `customer_id`, `latency_ms`, `did_you_mean`, `tier` (`hybrid` when stage 6 answered, else `keyword_only`) and `cache_hit`. A failed write (missing table, a rejected value) goes to `error_log` and never fails the search. A table without `did_you_mean`, `tier` or `cache_hit` is back-filled with `ALTER TABLE` on the first write that needs it, falling back to the columns it has.

### 9. Response

```
{ "query": {"raw", "normalized"}, "did_you_mean": ?string, "did_you_mean_applied": bool,
  "count": int, "product_ids": [int…], "cosine_scores"?: [?float…], "debug"?: {…} }
```

`cosine_scores` appears only on hybrid answers. `debug` (token-guarded) adds `{tier, settings, results[]}` with each id's keyword hit, pin flag and blend detail. `search.php` then adds `products` when `with_details` is true: `ProductDetails` reads `{id, title, url, image, price}` for the returned ids, with `url` / `image` made absolute through `storefront.store_base` / `image_base` when set.

### 10. Cache set

The response (and whether it had a vector) is stored under the stage-2 key with TTL `redis.ttl`, unless it is a **degraded** answer: when a VPS is configured but stage 6 returned `null` (down, slow, failing), the keyword-only answer is **not** cached, so a transient failure never outlives its request. Debug responses are never stored. `POST /reload` flushes every key under `redis.prefix` after a successful swap.

## Components and data

| component | role in `/search` | notes |
| --- | --- | --- |
| **MySQL / MariaDB** | `products` (FULLTEXT over the `normalized_*` columns, `normalized_sku`, `normalized_collapsed`, business signals `stock` / `popularity`, display fields), `search_logs` | the only hard dependency. Table names come from `db.products_table` / `db.search_logs_table` |
| **VPS vector service** | query embedding (bge-m3) and the cosine top-K over its own product vectors | optional; bearer token; reachable only from cPanel; bounded by `vps.timeout_ms` |
| **Redis** | result cache | optional, **off by default** (`redis.enabled`), runs on the VPS LAN (never on cPanel); an in-repo RESP client, no extension |
| **On-disk bundle** (`paths.data`) | `aliases.json` and `synonyms.json` (variants), `spellcheck.txt` (did-you-mean) | `keymap.json` is built into the bundle but the runtime layout is the in-code `Keymap`. `vectors.bin` / `vectors.idx` are present for `/reload`'s checks but **`/search` does not read them since M18**: the cosine is computed VPS-side from the VPS's own vectors |
| **Config** (`App\Config::load`) | every knob above | code defaults + the deployer's `config.php` overrides, see below |
| **Analytics** (M31) | not part of `/search`: `analytics.php` reads `search_logs` afterwards | `logs.token`, read-only, `?window=`, `?format=json` ([SEARCH-API.md](SEARCH-API.md#analytics)) |

## Degradation ladder

| failure | what `/search` does |
| --- | --- |
| VPS not configured, down, slow or failing | keyword-only answer (`tier = keyword_only`, no `cosine_scores`), reason in `error_log`; not cached |
| Redis down, slow or returns junk | treated as a miss: recompute; the answer is still returned (a failed `set` is ignored, a failed flush is reported by `/reload`) |
| bundle missing or incomplete (`aliases.json` / `synonyms.json` / `spellcheck.txt`) | no alias variants; the speller falls back to the `products` table; keyword search still answers |
| `products` table from before M21 / M13 / M12 / M23 (missing columns) | the keyword tier searches without those fields; no error |
| `search_logs` from before M21 / M26 (missing columns) | the logger adds them or writes the columns it has; a log failure never fails the search |
| `search_logs` / analytics unavailable | `/search` is unaffected; `analytics.php` answers `500 Internal error` |
| config problems | tunables fall back to code defaults; a missing `db.dsn` / `db.user` is reported by `config-check` and `/health` `config`, and database errors surface as `500 internal_error` |
| MySQL down | `500 internal_error`: the one failure the service cannot hide (`/health` reports `degraded`, `503`) |

## Configuration

`bootstrap.php` returns `App\Config::load(Config::file())`: the code's defaults (`App\Config::table()`, the single source of truth) deep-merged with the overrides in `config.php` (or the file named by `SEARCH_CONFIG_FILE`), every known key cast to its type. `search()` and its helpers read keys directly, with no fallback at the use site. Details and every key: [CONFIGURATION.md](CONFIGURATION.md#the-layered-server-config); `php server/tools/config-check.php` and `GET /health` (`config` section) report problems by key path.

## Deployment context (M30)

`server/public/` is the web document root; the app tree sits one level above it and is not web-accessible:

```
~/search-service/server/      app base (SEARCH_APP_BASE, else the parent of public/)
├── bootstrap.php  src/  tools/  db/
├── config.php                deployer overrides: DB credentials, tokens, VPS, redis (never in the zip)
├── data/                     the active bundle (aliases, synonyms, spellcheck, vectors, meta.json)
├── data_incoming/  data_old/ staging for the atomic reload swap, and the previous bundle
└── public/                   served: index.php search.php health.php reload.php logs.php analytics.php
```

`config.php` and `data/` are outside the served folder, and deny-all `.htaccess` files (`server/`, `data/`, `data_incoming/`) are a second net if the tree is ever misplaced. Layouts and checks: [DEPLOY.md](DEPLOY.md#layout).

## Keeping this in sync

This document describes `SearchController::search()` and the classes it drives (`Keyword`, `VpsClient`, `Ranker`, `Cache`, `Logger`). **When `search()` changes (a stage added, removed or reordered, a new fallback, a new cache rule, a new response field), update this file in the same PR**, and update the knob lists in [CONFIGURATION.md](CONFIGURATION.md) and the behavior notes in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md) with it. A stage-by-stage check against the code takes minutes; stale flow docs mislead the next person to debug a ranking.
