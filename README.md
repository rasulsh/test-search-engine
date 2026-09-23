# Product Search Service

A standalone two-tier product-search service for an OpenCart 2.0.3.1 storefront
(~20,000 bilingual Persian/English products). Designed to run on **cPanel shared
hosting** (PHP 8.x + LiteSpeed + MariaDB) with no daemons and no external search
engines.

> Authoritative design, contracts, and the milestone plan live in
> [`CLAUDE.md`](./CLAUDE.md). The storefront-side HTTP contract, the reference
> storefront snippet, and model self-hosting are in
> [`INTEGRATION.md`](./INTEGRATION.md). This README is the operator runbook.

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
db/         SQL schema (products + FULLTEXT, search_logs)
pipeline/   Offline build pipeline (Python 3.11+, GPU or mock)
server/     cPanel runtime (PHP 8.1+, no framework)
client/     Browser query embedder (transformers.js / ONNX); model/ is gitignored
fixtures/   Shared test fixtures (normalization cases, eval set, parity strings)
```

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
  environment variables it reads (see `.env.example`). On cPanel, edit the
  defaults in `config.php` (see [one-time setup](#one-time-setup-cpanel-host)).
- Pipeline: the same `SEARCH_MODEL*` variables, plus `EMBEDDER=mock|real`,
  `SEARCH_DESC_CHAR_LIMIT` (description characters in the embedded passage) and
  `SEARCH_LOAD_MAX_STATEMENT_BYTES` (maximum size of one statement in
  `products.load.sql`). See `pipeline/config.py`.
- The embedding model, revision, and dimension are parameterized and shared
  across server, pipeline, and client (parity contracts, see `CLAUDE.md` §3,
  and [changing the model](./INTEGRATION.md#changing-the-model)).

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

| endpoint | purpose |
| --- | --- |
| `POST /search` | `{q, q_vector?, customer_id?, limit?, with_details?}`, returns ordered `product_ids` + `did_you_mean` / `did_you_mean_applied` (plus `cosine_scores` on hybrid responses, and `products` display fields only with `"with_details": true`). Keyword tier always; hybrid when a valid `q_vector` is sent and a bundle is loaded. |
| `GET /health` | Database reachability + live product count, for monitoring. |
| `POST /reload` | Token-protected (`X-Reload-Token`). Validates the staged bundle and swaps it in atomically. |

The exact request/response JSON, headers, status codes, and every error and
`invalid_bundle` reason are in [`INTEGRATION.md`](./INTEGRATION.md#http-contract).
On a subdirectory deploy, call the scripts directly (`/search-api/search.php`,
and so on). The front controller's pretty paths only route at a web root.

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

Deriving it from **OpenCart 2.0.3.1** is step 1 of the
[deploy runbook](#deploy--update-runbook) below. `build.py` decodes the HTML
escaping OpenCart stores in names and descriptions and strips the markup, so the
export can be taken from the OpenCart tables as-is.

**Bundle output** (`build.py --csv export.csv --out ./bundle`): `vectors.bin`
(little-endian float32, `count × dim`, L2-normalized), `vectors.idx` (one
`product_id` per line, row order), `products.load.sql` (rows for the
`products_new` staging table, normalized columns from the canonical Normalizer,
split into statements of at most `SEARCH_LOAD_MAX_STATEMENT_BYTES`, 1 MB by
default, so each fits a shared host's `max_allowed_packet`),
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

**"Did you mean" (`server/src/Speller.php`).** The speller runs only when a
query has fewer than `SEARCH_SUGGEST_MIN_RESULTS` keyword hits, and a
suggestion is returned only if it has more hits than the literal query (with no
literal hit, its results are served; see `did_you_mean_applied` in
INTEGRATION.md). Its vocabulary is the bundle's `spellcheck.txt`, built from
product titles, brand, category and model only (descriptions contribute rare
incidental words that won corrections on the real catalog), with one count per
product. Candidates must appear in at least `SEARCH_SUGGEST_MIN_FREQUENCY`
products and lie within `SEARCH_SUGGEST_MAX_DISTANCE` edits (1 edit for tokens
of 4 characters or fewer). A known word, however rare, is never corrected.
Bundles built before M9 still work, but their dictionary includes description
words; rebuild to get the high-signal one. The dictionary is cached
like the vectors: parsed once per PHP worker, and stored in APCu (about 3 MB for
32k terms) so a fresh worker skips the parse. The cache key is the file's
identity, so a reload is picked up by every worker, and `POST /reload` also
evicts the previous entry and warms the new one. Each term is pre-encoded one
byte per character so the edit distance runs in PHP's native `levenshtein()`,
with results identical to a multibyte Levenshtein. Only if `spellcheck.txt` is
missing does it fall back to scanning the `products` table, which is too slow
for the budget on a full catalog.

**Relevance floor.** The nearest vectors of a query with no relevant product
are still unrelated items (on the real catalog, "ball bearing" returned case
fans). Neighbours with cosine below `SEARCH_SEMANTIC_MIN_SCORE` (default 0.82)
are dropped, and a query with no keyword hit and nothing above the floor
returns an empty result instead of `limit` far neighbours. Hybrid responses
carry `cosine_scores` so the test page can show each result's similarity while
tuning.

**Hybrid merge (`server/src/Ranker.php`).** Keyword (FULLTEXT) and cosine scores
are on incomparable scales, so they are **not** added raw. They are merged by
weighted **Reciprocal Rank Fusion** (rank-based, scale-free), then light business
boosts (in-stock, popularity) are applied **after** fusion. Keyword hits always
lead: every keyword match ranks above every semantic-only neighbour, the
semantic side reorders keyword hits among themselves and augments below them.
Weights are configurable (`SEARCH_RRF_K`, `SEARCH_KEYWORD_WEIGHT`,
`SEARCH_SEMANTIC_WEIGHT`, `SEARCH_STOCK_BOOST`, `SEARCH_POPULARITY_BOOST`).

**Tuning.** Every knob above lives in `server/config.php` (`search` section) or
the matching `SEARCH_*` environment variable; a `config.php` copied before M9
that lacks the new keys uses the defaults shown in `config.example.php`. The
defaults are starting points: tune `semantic_min_score` and the weights against
a labeled eval set from real queries (`server/tools/eval.php`, which uses the
same wiring and config as `/search`).

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
npm --prefix client install @xenova/transformers@2.17.2   # once; node_modules is gitignored
EMBEDDER=real python pipeline/tools/model_parity.py       # PASS when every cosine >= 0.99
```

It embeds `fixtures/parity_strings.json` with both implementations (each with the
`"query: "` prefix) and asserts each pair's cosine is at least 0.99. When the
self-hosted model is present under `client/model/` (see
[INTEGRATION.md](./INTEGRATION.md#self-hosting-the-model-no-huggingface-no-cdn)),
the JS side uses exactly those files with remote loading disabled, so the check
covers what shoppers' browsers run: an int8-quantized ONNX model against the
full-precision pipeline model. On the M5 run it measured 0.9956–0.9985.

## Search test page

`server/public/test.html` is a standalone Persian (RTL) search page for trying
the live service: type a query, see result cards (image, title, price), the
result count, a clickable "did you mean", and the round-trip time. A **Semantic
(AI)** switch compares the two tiers on the same query. Off sends `q` only
(keyword). On also embeds the query in the browser (`"query: "` prefix) and sends
`q_vector` (hybrid). In hybrid mode each card shows its cosine similarity to
the query (small, muted), which is how to pick `SEARCH_SEMANTIC_MIN_SCORE`. An
empty result shows a "no results" message. Clicking a card opens the product in
a new tab.

It makes **no third-party requests**. The page, runtime, WASM, model and font are
all served from the service's own directory, and every path is relative, so the
page works under any subdirectory. If the model is missing, fails, or is still
downloading after 30 s, the search runs keyword-only and a short notice says so.
If the service itself fails, an error notice is shown.

### 1. Fetch the browser assets (developer machine)

```bash
python pipeline/tools/fetch_web_model.py        # stdlib only; writes into client/
```

Every download is pinned (npm version or HuggingFace commit) and checked against
a hard-coded checksum. The script also checks that the model's `config.json`
names the configured `SEARCH_MODEL` and dim, so the browser uses the same model
as `embed.py` (contract 2). Re-running skips files that are already present.
After fetching, `client/` looks like this (everything except `embedder.js` and
`tools/` is gitignored):

```
client/
    embedder.js
    vendor/transformers.min.js
    vendor/ort-wasm.wasm  ort-wasm-simd.wasm  ort-wasm-threaded.wasm  ort-wasm-simd-threaded.wasm
    model/intfloat/multilingual-e5-small/config.json  tokenizer.json  tokenizer_config.json
    model/intfloat/multilingual-e5-small/special_tokens_map.json
    model/intfloat/multilingual-e5-small/onnx/model_quantized.onnx     (~118 MB)
    fonts/Vazirmatn-Variable.woff2  fonts/OFL.txt
```

Then run the parity check
([Offline model-parity check](#offline-model-parity-check)). It uses exactly
these files.

### 2. Upload (cPanel)

The page expects a `client/` folder **next to it**, that is, inside the web-exposed
directory that holds `search.php`:

```
public_html/search-api/          <- server/public (search.php, health.php, test.html, ...)
    test.html
    client/                      <- upload the whole client/ folder from step 1 here
        embedder.js  vendor/  model/  fonts/
```

- Upload in **binary** mode (`.onnx`, `.wasm`, `.woff2`).
- **`.wasm` must be served as `application/wasm`.** LiteSpeed does this by
  default. Check with
  `curl -sI https://shop.example.com/search-api/client/vendor/ort-wasm-simd.wasm`,
  and if needed add `AddType application/wasm .wasm` to the folder's `.htaccess`.
- `client/tools/` does not need to be uploaded.
- Open `https://shop.example.com/search-api/test.html`. The first visit
  downloads about 135 MB (the model, ~118 MB, and `tokenizer.json`, ~17 MB). The
  browser caches them for later visits. HTTPS is needed for that cache.

### Store links and prices

The service only knows the product `url` and `image` exactly as exported, for
example `index.php?route=product/product&product_id=42` and `catalog/x.jpg`. The
page resolves relative values against the **store root, taken as the parent of
the page's directory** (`../`), and images against `../image/`, which matches
OpenCart's layout when the service is at `/<store>/search-api/`. If yours
differs, edit `STORE_BASE` / `IMAGE_BASE` at the top of the page's script. Prices
are shown as stored, with no currency label. Set `PRICE_SUFFIX` to add one.

The page is public wherever `server/public` is. It reads nothing the search
endpoint does not already expose, but delete it or protect the directory if you
don't want it reachable in production.

## Deploy / update runbook

The runbook has three machines' worth of steps. The **OpenCart database**
provides the export. The **developer GPU machine** builds the bundle. The
**cPanel host** serves it. Nothing here runs as a daemon or needs root.
Placeholders: `shop.example.com`, `~/search-service`, database `cpuser_search`.
Replace them with yours.

### One-time setup (cPanel host)

1. **Upload the service code.** Put the repo's `server/` directory at
   `~/search-service/server/`, outside `public_html`. Expose only its `public/`
   directory on the store's domain, as a symlink (via SSH) or a copy:
   ```bash
   ln -s ~/search-service/server/public ~/public_html/search-api
   ```
   The storefront must reach the service on the **same origin**. There is no
   CORS support. See [INTEGRATION.md, Deployment shape](./INTEGRATION.md#deployment-shape).
2. **Create the database.** In cPanel, open MySQL Databases. Create a database
   and a user, and grant the user ALL privileges on that database. `RENAME
   TABLE`, `DROP` and `CREATE` are needed by the reload. Then import the schema,
   via phpMyAdmin Import or over SSH:
   ```bash
   mysql -u cpuser_search -p cpuser_search < db/schema.sql
   ```
3. **Configure.** On shared hosting, environment variables are awkward, so use
   the config file. It is gitignored and not web-exposed:
   ```bash
   cp ~/search-service/server/config.example.php ~/search-service/server/config.php
   ```
   In `config.php`, set the defaults after each `?:`. At minimum, set the DSN,
   user and password, and a long random reload token
   (`php -r 'echo bin2hex(random_bytes(32)), "\n";'`). Leave `model`, `dim`,
   and `normalization_version` equal to what the pipeline builds with. Every
   key is described in `.env.example`, and `SEARCH_*` environment variables
   still override the file where the host supports them. Set
   `SEARCH_MIN_TOKEN_SIZE` to the host's `innodb_ft_min_token_size`
   (`SHOW VARIABLES LIKE 'innodb_ft_min_token_size'`, usually 3).
4. **Check health:** `curl -sS https://shop.example.com/search-api/health.php`
   should return `{"status":"ok",…,"product_count":0}`. A count of 0 is
   expected until the first reload.
5. **Host the browser model and storefront assets.** Follow
   [INTEGRATION.md, Self-hosting the model](./INTEGRATION.md#self-hosting-the-model-no-huggingface-no-cdn).
   Then install the storefront snippet through the OpenCart module
   ([INTEGRATION.md, Storefront reference](./INTEGRATION.md#storefront-reference)).

### Every catalog update

**1. Export from OpenCart 2.0.3.1** to `build.py`'s input columns. Product
names and descriptions live in `oc_product_description`, one row per
`language_id`. Join it twice, once for Persian and once for English. Look up
your ids with `SELECT language_id, code FROM oc_language;`, and adjust the
`oc_` prefix if your install uses another `DB_PREFIX`.

```sql
SET @fa := 2, @en := 1;   -- your Persian / English language_id

SELECT
    p.product_id                                              AS id,
    COALESCE(d_fa.name, '')                                   AS title_fa,
    COALESCE(d_en.name, '')                                   AS title_en,
    CONCAT_WS(' ', d_fa.description, d_en.description)        AS `desc`,
    COALESCE(m.name, '')                                      AS brand,
    COALESCE((
        SELECT LEFT(GROUP_CONCAT(DISTINCT CONCAT_WS(' ', c_fa.name, c_en.name)
                                 ORDER BY pc.category_id SEPARATOR ' / '), 255)
        FROM oc_product_to_category pc
        LEFT JOIN oc_category_description c_fa
               ON c_fa.category_id = pc.category_id AND c_fa.language_id = @fa
        LEFT JOIN oc_category_description c_en
               ON c_en.category_id = pc.category_id AND c_en.language_id = @en
        WHERE pc.product_id = p.product_id
    ), '')                                                    AS category,
    p.model                                                   AS model,
    p.price                                                   AS price,
    p.quantity                                                AS stock,
    CONCAT('index.php?route=product/product&product_id=', p.product_id) AS url,
    COALESCE(p.image, '')                                     AS image,
    p.viewed                                                  AS popularity
FROM oc_product p
JOIN oc_product_to_store ps ON ps.product_id = p.product_id AND ps.store_id = 0
LEFT JOIN oc_product_description d_fa
       ON d_fa.product_id = p.product_id AND d_fa.language_id = @fa
LEFT JOIN oc_product_description d_en
       ON d_en.product_id = p.product_id AND d_en.language_id = @en
LEFT JOIN oc_manufacturer m ON m.manufacturer_id = p.manufacturer_id
WHERE p.status = 1
ORDER BY p.product_id;
```

Notes on the query:
- Only enabled products (`status = 1`) in the default store are exported.
  Disabled products drop out of search at the next reload.
- `category` is every category the product is in (Persian and English names),
  capped at 255 characters to fit the `products.category` column.
- `COALESCE` keeps NULLs out of the export. phpMyAdmin writes a SQL NULL as the
  literal text `NULL` in CSV, which would otherwise be indexed as a word.
- `popularity` uses `viewed`. Replace it with a sales count if you have a
  better signal.
- HTML escaping (`&lt;p&gt;`, `&amp;quot;`) and tags are left as stored.
  `build.py` decodes and strips them.

Save the result as **CSV**: phpMyAdmin, SQL tab, run the query, then **Export**
under "Query results operations", format CSV. Tick **"Put columns names in the
first row"** and keep the defaults (`"` enclosure, `"` escape). Save it as
`export.csv`, UTF-8. Use CSV, not phpMyAdmin's SQL export: `build.py`'s SQL
reader understands `''` quoting, not the backslash escapes (`\'`) that
phpMyAdmin and mysqldump write.

**2. Build the bundle** on the GPU machine (Python 3.11+):

```bash
pip install -r pipeline/requirements.txt 'sentence-transformers>=2.2'
EMBEDDER=real python pipeline/build.py --csv export.csv --out ./bundle
# -> Built bundle: <count> products, dim 384, embedder real
cat bundle/meta.json   # model, dim, normalization_version, count, checksum
```

`EMBEDDER=real` is required for production. The default `mock` produces
random vectors that are only good for tests. `SEARCH_MODEL`,
`SEARCH_MODEL_REVISION` and `SEARCH_MODEL_DIM` must match `server/config.php`.

**3. Upload and stage** on the cPanel host:

- Upload the bundle files into `~/search-service/server/data_incoming/`. Create
  the directory if needed; it must not contain an old bundle. Use **binary**
  mode for `vectors.bin`, for example SFTP, or a zip extracted with the cPanel
  File Manager. A corrupted file is caught later as `vectors_size_mismatch` or
  `checksum_mismatch`.
- Recreate the staging table and load the rows. This leaves `products` live
  and untouched:
  ```bash
  mysql -u cpuser_search -p cpuser_search \
        -e 'DROP TABLE IF EXISTS products_new; CREATE TABLE products_new LIKE products;'
  mysql -u cpuser_search -p cpuser_search < bundle/products.load.sql
  ```
  Without SSH, run the two statements in phpMyAdmin's SQL tab, then Import
  `products.load.sql`. For a large catalog, gzip it first: phpMyAdmin accepts
  `.sql.gz`, and a 20k-product file shrank from 46 MB to 12 MB in testing.
  Check that `SELECT COUNT(*) FROM products_new` equals `count` in `meta.json`.

**4. Reload.** This validates, then swaps atomically:

```bash
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     https://shop.example.com/search-api/reload.php
# {"ok":true,"count":20000,"model":"intfloat/multilingual-e5-small","dim":384}
curl -sS https://shop.example.com/search-api/health.php   # product_count == count
```

A `422 invalid_bundle` means nothing was swapped. Its `reason` names the
failed check; the fixes are in
[INTEGRATION.md](./INTEGRATION.md#post-reload). After a success, the
previous version is kept as the `products_old` table and the `data_old/`
directory. The "did you mean" dictionary (`spellcheck.txt`) switches with the
bundle; nothing else needs restarting.

### Rollback (one step)

To return to the previous catalog after a bad reload:

```sql
RENAME TABLE products TO products_bad, products_old TO products;
```
```bash
cd ~/search-service/server && mv data data_bad && mv data_old data
```

Do both together, because the table and the vectors must come from the same
bundle. Afterwards, drop `products_bad` and remove `data_bad/`. Each PHP
worker caches the vectors under a key that includes `vectors.bin`'s mtime, so
the restored files are picked up without a restart.

## Production Go-Live Checklist

Everything below was impossible to verify without the real host, catalog, or
shoppers' devices. It consolidates the "Needs production validation" items from
M0–M5. Verify each item on the production host before wide rollout.

**Blocking: search quality and the model**
- [ ] **Persian embedding-model feasibility test (mandatory before wide
  rollout).** `intfloat/multilingual-e5-small` is a provisional default
  (CLAUDE.md §7). Label Persian, English, and mixed queries from real traffic
  into `fixtures/eval_queries.json`, then run
  `php server/tools/eval.php --k=10` in keyword-only and hybrid mode (add
  per-query `q_vector`s embedded by the browser client). Accept the model only
  if hybrid clearly beats keyword-only on Persian and cross-language queries.
  Changing the model means following
  [INTEGRATION.md, Changing the model](./INTEGRATION.md#changing-the-model).
- [ ] **Persian search quality on the real ~20k catalog.** Check keyword-tier
  results for common Persian queries: ZWNJ variants, ی/ک variants, Persian
  digits, model numbers. Normalization rules were only verified against
  fixtures.
- [ ] **JS↔Python embedder parity** with the exact model files deployed to the
  store: `EMBEDDER=real python pipeline/tools/model_parity.py` must PASS
  (cosine ≥ 0.99). Rerun it whenever the model, its files, or the
  transformers.js version change.
- [ ] Consider pinning `SEARCH_MODEL_REVISION` / `MODEL_REVISION` to a commit
  hash instead of `main`, so a later upstream change cannot desynchronize
  rebuilt product vectors from the browser model.
- [ ] Tune `SEARCH_SEMANTIC_MIN_SCORE` (default 0.82) and the fusion weights
  on the real catalog: search known "no match" queries (e.g. ball bearing,
  shorts) and known good cross-language queries on the test page, read each
  card's cosine, and set the floor between them. Confirm with
  `server/tools/eval.php` in hybrid mode. Too high loses cross-language recall;
  too low brings back unrelated neighbours.
- [ ] Keyboard-layout recovery covers the common US→Persian keys only (M2), and
  synonyms and spelling are untuned. Review `search_logs` zero-result queries
  after launch.

**Blocking: host capacity and latency**
- [ ] **Real latency on cPanel** for keyword-only and hybrid requests, measured
  with `latency_ms` in `search_logs` and end-to-end from the storefront, against
  the 200 ms budget. CI measured about 105–127 ms for a warm 20k×384 cosine
  top-K; the production host was never measured.
- [ ] **Zero-result query latency.** "Did you mean" now reads the bundle's
  `spellcheck.txt` instead of scanning the `products` table on each request
  (M6). On a synthetic 20k catalog (32k-term dictionary) on local MariaDB, a
  warm zero-result request took about **15–20 ms**; the same data on the old
  path took about **350–500 ms**. Check `latency_ms` for zero-result rows in
  `search_logs` on the host. Short Persian tokens (shorter than
  `innodb_ft_min_token_size`) use the LIKE fallback, about 150 ms on the same
  data.
- [ ] **LVE memory headroom.** The parsed vector matrix costs about **150 MB
  per PHP worker** (about 300 MB peak while loading). Check the account's LVE
  memory limit (PMEM) and PHP `memory_limit` against
  `workers × 150 MB`. Under pressure, reduce LSAPI children or fall back to
  re-ranking keyword candidates (CLAUDE.md §5).
- [ ] **APCu availability.** Check `php -m | grep apcu` on the host (CLI and web
  SAPI can differ) and that `apc.shm_size` holds the ~30 MB `vectors.bin`
  (the default is often 32 MB) plus about 3 MB for the spellcheck dictionary.
  Without APCu, or when the segment is full, each cold worker reads and parses
  the files once, which is slower but correct.
- [ ] `max_allowed_packet` and the phpMyAdmin upload limit accept the staged
  `products.load.sql`. Statements are at most 1 MB
  (`SEARCH_LOAD_MAX_STATEMENT_BYTES`), and the file is about 46 MB (12 MB
  gzipped) for 20k products.
- [ ] The reload's table and directory swaps are each atomic, but not atomic
  together. A process kill between them is recovered with the rollback above.

**Blocking: storefront and shoppers**
- [ ] **No third-party requests** from the live store: DevTools, then Network,
  shows only the store's domain. There must be no `huggingface.co` or
  `cdn.jsdelivr.net` requests, tested from inside Iran.
- [ ] **Model download on real devices and networks.** The first search-box
  focus downloads about 118 MB (ONNX) + 17 MB (tokenizer) + about 10 MB
  (WASM). Check the load time on typical Iranian mobile networks, that the
  Cache API serves repeat visits (requires HTTPS), and that keyword-only
  search stays usable meanwhile. If the download is too heavy, consider
  enabling the model only on desktop or on Wi-Fi.
- [ ] **Low-end device behavior.** Tune `EMBED_TIMEOUT_MS`. The one-time
  17 MB tokenizer parse on first focus runs on the main thread. In local
  Chromium runs, the first search took 38 ms in one run and 885 ms in another,
  so check for jank on low-end phones. If the store sets a
  Content-Security-Policy, it must allow `worker-src blob:` for the inference
  worker.
- [ ] The OpenCart module renders real product cards for `product_ids`, sends
  `customer_id` as a string, and falls back to OpenCart's native search when the
  service fails.
- [ ] Uptime monitoring uses `GET /health` (not `HEAD`) and alerts on
  `status != ok` and on `product_count == 0`.

## Milestone status

- [x] **M0** — Scaffold: structure, `.gitignore`, CI, config examples, README.
- [x] **M1** — Keyword backbone (schema, loader, FULLTEXT + LIKE fallback, `/health`).
- [x] **M2** — Persian normalization (parity), typo/keymap tolerance, "did you mean", logging, `POST /search`.
- [x] **M3** — Offline pipeline (`build.py`, `embed.py` mock+real), bundle + `meta.json`, atomic `POST /reload`.
- [x] **M4** — Semantic tier: browser query embedder, cosine top-K + caching, RRF hybrid + business boosts, `/search` vector path, latency guard, eval harness.
- [x] **M5** — Integration + docs: `INTEGRATION.md` (HTTP contract, storefront reference, model self-hosting), operator runbook, Go-Live checklist.
- [ ] **M6** — Follow-up: "did you mean" served from the bundle's `spellcheck.txt` (cached per worker + APCu, refreshed on `/reload`); zero-result latency guard.
- [ ] **M8** — Search test page (`server/public/test.html`), opt-in `with_details` on `/search`, `fetch_web_model.py` for self-hosted browser assets.
- [ ] **M9** — Relevance tuning: semantic cosine floor (empty result instead of far neighbours), keyword-first hybrid fusion, frequency-gated "did you mean" from high-signal fields, cosine score + "no results" state on the test page.

## Contributing

- One PR per milestone, in order; each ships with tests and passes CI.
- Never commit or push to `main`/`master`; never merge your own PR.
- Conventional Commits; PSR-12 (PHP) and ruff-clean (Python); English only.

See `CLAUDE.md` §9–§11 for the full workflow and coding standards.
