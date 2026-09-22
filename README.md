# Product Search Service

A standalone two-tier product-search service for an OpenCart 2.0.3.1 storefront
(~20,000 bilingual Persian/English products). Designed to run on **cPanel shared
hosting** (PHP 8.x + LiteSpeed + MariaDB) with no daemons and no external search
engines.

> This is the implementation repository. Authoritative design, contracts, and the
> milestone plan live in [`CLAUDE.md`](./CLAUDE.md). The storefront-side HTTP
> contract will be documented in `INTEGRATION.md` (added in M5).

## Architecture

Two tiers, degrading gracefully:

- **Tier 1 — keyword (always on, server-side).** MySQL FULLTEXT + Persian
  normalization + typo/keyboard-layout tolerance + "did you mean". Works with
  zero ML and never breaks.
- **Tier 2 — semantic (additive).** Cosine similarity over precomputed,
  L2-normalized product vectors. If Tier 2 is unavailable, Tier 1 still answers.

Heavy work (product embedding) runs **offline** on a developer GPU machine and
produces a static index *bundle*. **Query embedding happens off-server, in the
browser (WASM).** The server only serves requests: keyword search plus cosine
math over the precomputed vectors.

## Repository layout

```
db/         SQL schema (products + FULLTEXT, search_logs)          [M1]
pipeline/   Offline build pipeline (Python 3.11+, GPU or mock)     [M3]
server/     cPanel runtime (PHP 8.1+, no framework)                [M1+]
client/     Browser query embedder (transformers.js / ONNX)        [M4]
fixtures/   Shared test fixtures (normalization cases, eval set)   [M1+]
```

Directories are scaffolded now; files land in the milestone shown in brackets.

## Requirements

- **Server (runtime):** PHP 8.1+, PDO/MySQLi, MySQL/MariaDB. **Zero Composer
  runtime dependencies** — Composer is dev-only.
- **Pipeline (offline):** Python 3.11+. Real embedding needs a GPU; tests use a
  deterministic mock embedder, so CI needs no GPU or model download.
- **Client (browser):** the query embedder runs transformers.js (Xenova/ONNX) in
  the storefront. Node + `@xenova/transformers` are needed only for the offline
  parity check; CI verifies the client via static config-parity assertions.
- **Dev tooling:** Composer (PHPUnit, PHP_CodeSniffer), Python (pytest, ruff).

## Configuration

All model names, dimensions, thresholds, and credentials are read from config —
never hardcoded.

- Server: copy `server/config.example.php` to `server/config.php`, or supply the
  environment variables it reads (see `.env.example`).
- The embedding model, revision, and dimension are parameterized and shared
  across server, pipeline, and client (parity contracts — see `CLAUDE.md` §3).

Never commit secrets, `server/data/`, bundles, or model files.

## Development

```bash
# PHP (server)
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpcs        # PSR-12
vendor/bin/phpunit      # server tests

# Python (pipeline)
pip install -r pipeline/requirements-dev.txt
ruff check pipeline     # lint
pytest                  # pipeline tests
```

CI (`.github/workflows/ci.yml`) runs the same checks on every pull request.

### Running the database-backed tests

Keyword/FULLTEXT tests need a real MySQL/MariaDB (FULLTEXT is engine-specific).
They read the connection from `SEARCH_TEST_DB_*`; when unset they **skip**
locally, but **fail under CI** (where a MariaDB service is always provided) so a
missing database can never hide a green build.

```bash
docker run -d --name search-mariadb \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=search_test \
  -p 3306:3306 mariadb:11.4

export SEARCH_TEST_DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=search_test;charset=utf8mb4"
export SEARCH_TEST_DB_USER=root SEARCH_TEST_DB_PASSWORD=root
vendor/bin/phpunit
```

## HTTP endpoints

- `GET /health` — returns `{ "status": "ok|degraded", "checks": { "database":
  bool, "product_count": int|null } }` (200 when the database is reachable, 503
  otherwise).
- `POST /search` — body `{ "q": string, "limit"?: int, "customer_id"?: string,
  "q_vector"?: number[dim] }`. Returns `{ "query": { "raw", "normalized" },
  "did_you_mean": string|null, "count": int, "product_ids": int[] }`. Tier 1
  (keyword) is always on: the query is normalized (contract 1), searched, and — if
  nothing matches — recovered via keyboard-layout remap or spell correction; every
  request is logged to `search_logs`. **Tier 2 (semantic) is additive:** when a
  `q_vector` of the configured dimension is present *and* a bundle is loaded, the
  server runs global cosine top-K and fuses it with the keyword ranking (see
  below). If the vector is absent, the wrong length, or the bundle is unavailable,
  the request returns Tier 1 results and never errors.

- `POST /reload` — token-protected (`X-Reload-Token` header or `{"token": …}`
  body). Validates the staged bundle and atomically swaps it in. Returns
  `{ "ok": true, "count": int, "model", "dim" }` on success; `422 invalid_bundle`
  with a `reason` when validation fails; `401` on a bad token; `503
  reload_disabled` when no token is configured.

Served via the `server/public` front controller; `/health.php`, `/search.php`,
and `/reload.php` are also reachable directly for hosts without URL rewriting.

## Offline pipeline & the bundle

Heavy work runs offline (developer GPU machine); the server only consumes the
static **bundle** it produces.

**`build.py` input** — a simple, documented shape (NOT the raw OpenCart export).
Provide a `.sql` (INSERT statements) or `.csv` with these columns:

| column | notes |
| --- | --- |
| `id` | product id (integer, primary key) |
| `title_fa`, `title_en` | Persian and English titles |
| `desc` | description (truncated for the embedding input) |
| `brand`, `category`, `model` | facets; `model` also feeds catalog synonyms |
| `price`, `stock`, `popularity` | numeric |
| `url`, `image` | display fields |

Deriving it from **OpenCart 2.0.3.1**: join `oc_product` to `oc_product_description`
twice (once per `language_id`, FA and EN) and select the columns above, e.g.

```sql
SELECT p.product_id AS id, d_fa.name AS title_fa, d_en.name AS title_en,
       d_en.description AS `desc`, m.name AS brand, c.name AS category,
       p.model, p.price, p.quantity AS stock, p.image, p.viewed AS popularity,
       CONCAT('/index.php?route=product/product&product_id=', p.product_id) AS url
FROM oc_product p
LEFT JOIN oc_product_description d_fa ON d_fa.product_id = p.product_id AND d_fa.language_id = :fa
LEFT JOIN oc_product_description d_en ON d_en.product_id = p.product_id AND d_en.language_id = :en
LEFT JOIN oc_manufacturer m ON m.manufacturer_id = p.manufacturer_id
-- category via oc_product_to_category + oc_category_description (pick the primary)
;
```
Set `:fa` / `:en` to your store's `language_id` values. Export the result as CSV
(or as `INSERT` statements) — that is `build.py`'s input, decoupled from the
OpenCart schema.

**Bundle output** (`build.py --sql export.sql --out ./bundle`): `vectors.bin`
(little-endian float32, `count × dim`, L2-normalized), `vectors.idx` (one
`product_id` per line, row order), `products.load.sql` (rows for the
`products_new` staging table, normalized columns from the canonical Normalizer),
`synonyms.json`, `spellcheck.txt`, `keymap.json`, and `meta.json`
(`{model, revision, dim, normalization_version, count, built_at, checksum, embedder}`).

**Embedding contract (model parity, contract 2).** The default model
`intfloat/multilingual-e5-small` requires **asymmetric prefixes**: `build.py`
embeds each product passage with `"passage: "`, so the **M4 browser client MUST
embed the query with `"query: "`** — vectors from mismatched prefixes are not
comparable. The passage is a bounded composed field
(`title_fa title_en brand category model` + truncated `desc`) embedded from
**raw** text (not the keyword-normalized text), so the client can embed the raw
query without re-implementing the normalizer.

## Semantic tier (Tier 2)

Tier 2 adds cross-language recall on top of keyword search. It is purely
additive: keyword-only requests are unchanged.

**Query embedding — in the browser (`client/embedder.js`).** A transformers.js
(Xenova/ONNX) wrapper loads the **same** model as the pipeline and, per the e5
asymmetric contract, prepends **`"query: "`** to the query, mean-pools, and
L2-normalizes — mirroring `pipeline/embed.py` (`"passage: "` for products). The
resulting vector is sent as `q_vector`. The model id, revision, dim, and prefix
are asserted against the pipeline and server config in CI
(`pipeline/tests/test_client_parity.py`); real numerical parity between the JS and
Python embedders is checked offline (see below).

**Cosine top-K (`server/src/Vectors.php`).** Product and query vectors are both
L2-normalized, so cosine is a dot product. The server does a global brute-force
scan over `vectors.bin` (`O(count·dim)`), which is what delivers cross-language
recall. The dot products are cheap; the cost is reading and unpacking the ~30 MB
file, so the parsed matrix is cached for the PHP worker's lifetime and, when APCu
is present, the raw bytes are cached to skip the disk read on a cold worker. It
degrades to a plain disk read when APCu is absent. A wrong-dimension query or an
inconsistent/missing bundle simply falls back to keyword-only.

**Hybrid merge (`server/src/Ranker.php`).** Keyword (FULLTEXT) and cosine scores
are on incomparable scales, so they are **not** added raw. They are merged by
**Reciprocal Rank Fusion** (rank-based, scale-free), then light business boosts
(in-stock, popularity) are applied **after** fusion. Weights are configurable
(`SEARCH_RRF_K`, `SEARCH_STOCK_BOOST`, `SEARCH_POPULARITY_BOOST`).

**Reader tolerance.** A semantic hit whose `product_id` is missing from the
`products` table (e.g. a transient partial reload) is dropped rather than
surfaced, and products without a vector remain keyword-only — so a partial update
degrades instead of breaking.

### Eval harness

`server/tools/eval.php` runs the labeled queries in `fixtures/eval_queries.json`
through the real search pipeline and reports precision@k / recall@k so search
changes are measurable:

```bash
php server/tools/eval.php --k=5 [--queries=<path>] [--min-recall=<float>]
```

The seed labels are the true **bilingual** relevant sets, so the keyword tier
alone recovers the in-language half (recall ≈ 0.5 on the seed); closing the
cross-language gap is Tier 2's job and needs the real model. Grow the set from
real logs; add a per-query `q_vector` to measure the hybrid tier offline.

### Offline model-parity check

Numerical parity between `client/embedder.js` and `pipeline/embed.py` needs the
real model + a JS runtime, so it runs offline (not in CI):

```bash
npm --prefix client install @xenova/transformers      # once; node_modules is gitignored
EMBEDDER=real python pipeline/tools/model_parity.py    # cosine ≈ 1 per fixed string
```

It embeds `fixtures/parity_strings.json` with both implementations (each with the
`"query: "` prefix) and asserts each pair's cosine is ~1.

## Deploy / update flow

See `CLAUDE.md` §6. In short:

1. Export products from OpenCart to `export.sql`/`.csv` (columns above).
2. `python pipeline/build.py --sql export.sql --out ./bundle`
   (real embeddings need a GPU + `sentence-transformers` and `EMBEDDER=real`;
   the default `EMBEDDER=mock` needs neither).
3. Upload `./bundle` to `server/data_incoming/`, then load its
   `products.load.sql` into the `products_new` staging table.
4. `POST /reload` with the token. It validates `meta.json` (model, dim,
   `normalization_version`) and that `count == vectors.idx lines == staging rows
   == vectors.bin size/checksum`, then swaps atomically —
   `RENAME TABLE products TO products_old, products_new TO products` and
   `rename(data_incoming → data)` — keeping `products_old` / `data_old` for a
   one-step rollback.

## Milestone status

- [x] **M0** — Scaffold: structure, `.gitignore`, CI, config examples, README.
- [x] **M1** — Keyword backbone (schema, loader, FULLTEXT + LIKE fallback, `/health`).
- [x] **M2** — Persian normalization (parity), typo/keymap tolerance, "did you mean", logging, `POST /search`.
- [x] **M3** — Offline pipeline (`build.py`, `embed.py` mock+real), bundle + `meta.json`, atomic `POST /reload`.
- [x] **M4** — Semantic tier: browser query embedder, cosine top-K + caching, RRF hybrid + business boosts, `/search` vector path, latency guard, eval harness.
- [ ] **M5** — Integration + docs (`INTEGRATION.md`, finalized README).

## Contributing

- One PR per milestone, in order; each ships with tests and passes CI.
- Never commit or push to `main`/`master`; never merge your own PR.
- Conventional Commits; PSR-12 (PHP) and ruff-clean (Python); English only.

See `CLAUDE.md` §9–§11 for the full workflow and coding standards.
