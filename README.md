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

> **Semantic pivot in progress (M17+).** A separate VPS service
> ([`vps/README.md`](./vps/README.md)) embeds queries with bge-m3 and returns
> the top-K product ids by cosine; `pipeline/build.py --vps-out` builds its
> product vectors. It is deployed and tested on its own for now: cPanel does
> not call it yet, and the in-browser model described below is still the live
> semantic path until the follow-up PRs land.

## Repository layout

```
db/         SQL schema (products + FULLTEXT, search_logs)
pipeline/   Offline build pipeline (Python 3.11+, GPU or mock)
server/     cPanel runtime (PHP 8.1+, no framework)
vps/        VPS vector service (bge-m3 query embedding + cosine top-K; see vps/README.md)
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

- Server: on the host, `public/install.php` writes `server/config.php` from
  `server/config.example.php` (see [one-time setup](#one-time-setup-cpanel-host)).
  Otherwise copy the example to `config.php` and edit it, or supply the
  environment variables it reads (see `.env.example`).
- Pipeline: the same `SEARCH_MODEL*` variables, plus `EMBEDDER=mock|real`,
  `SEARCH_DESC_CHAR_LIMIT` (description characters in the embedded passage),
  `SEARCH_PASSAGE_CHAR_LIMIT` (cap on the whole embedded passage, default 1000),
  `SEARCH_DESC_INDEX_CHARS` (leading description characters keyword-indexed,
  default 800; 0 = whole description), `SEARCH_ALIASES_FILE` (the shop
  owner's [alias file](#aliases), default `pipeline/aliases.json`) and
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
| `POST /reload` | Token-protected (`X-Reload-Token`). Validates the staged bundle and swaps it in atomically. With `?load=1` it first loads `data_incoming/products.load.sql` into the staging table itself. |

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
| `sku` | optional product code; exact and prefix SKU queries rank first (see [SKU search](#sku-search)) |
| `price`, `stock`, `popularity` | numeric |
| `url`, `image` | display fields |
| `attributes` | optional; `name: value` pairs joined with ` \| ` (the export's `GROUP_CONCAT`), see [Specs](#specs-field) |
| `feature` | optional; `oc_product.feature` exactly as stored (PHP `serialize()` output), see [Specs](#specs-field) |

Deriving it from **OpenCart 2.0.3.1** is step 1 of the
[deploy runbook](#deploy--update-runbook) below. `build.py` decodes the HTML
escaping OpenCart stores in names and descriptions and strips the markup, so the
export can be taken from the OpenCart tables as-is.

**Bundle output** (`build.py --csv export.csv --out ./bundle`): `vectors.bin`
(little-endian float32, `count × dim`, L2-normalized), `vectors.idx` (one
`product_id` per line, row order), `products.load.sql` (drops and recreates the
`products_new` staging table from the `products` definition in `db/schema.sql`,
so schema changes such as the `sku` and `normalized_specs` columns reach the live table through the
swap with no manual `ALTER`; then its rows, normalized columns from the canonical Normalizer,
split into statements of at most `SEARCH_LOAD_MAX_STATEMENT_BYTES`, 1 MB by
default, so each fits a shared host's `max_allowed_packet`),
`synonyms.json`, `aliases.json` (the owner's [alias file](#aliases),
normalized), `spellcheck.txt`, `keymap.json`, and `meta.json`
(`{model, revision, dim, normalization_version, count, built_at, checksum, embedder}`).

**Embedding contract (model parity, contract 2).** The default model
`intfloat/multilingual-e5-small` requires **asymmetric prefixes**: `build.py`
embeds each product passage with `"passage: "`, so the **M4 browser client MUST
embed the query with `"query: "`** — vectors from mismatched prefixes are not
comparable. The passage is a bounded composed field, in priority order
`title_fa title_en brand category model`, the feature titles, the attribute
values, then the truncated `desc` (at most `SEARCH_DESC_CHAR_LIMIT`), all within
`SEARCH_PASSAGE_CHAR_LIMIT` characters (default 1000): the description gives
way first, then the tail of the specs. With the real e5 tokenizer a
1000-character passage measured at most 349 tokens on realistic text and 417
on a digit-heavy worst case, under the model's 512. It is embedded from
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

<a id="sku-search"></a>**SKU search (M12).** Shoppers can search by product
code. The SKU is stored raw (`sku`) and canonicalized (`normalized_sku`,
indexed): normalized like model codes, then every non-letter/non-digit is
removed, so `AB-12 34`, `ab.1234` and `AB1234` are the same code
(`Normalizer::normalizeSku` / `normalize_sku`, parity-tested on the shared
fixture). A product whose normalized SKU equals the query's ranks at the very
top, then SKU prefix matches (shortest code first), above every title match,
also in hybrid mode and with no "did you mean" on an exact hit. Prefix
matching needs at least `SEARCH_SKU_PREFIX_MIN_LENGTH` (default 4) characters
and a digit, so a word like "sony" never pins products whose code starts with
it. The normalized SKU is also appended to the title-weighted text, so a code
typed with other words, or a partial code, still matches through FULLTEXT
like any title word. Needs a rebuild + reload (new columns); until then the
SKU lookup is skipped and text search answers.

<a id="roman-numerals"></a>**Roman numerals (M15, normalization version 3).**
Product names mix "GTA V" and "GTA 5", "Diablo IV" and "Diablo 4". The
canonical normalizer (both `normalize.py` and `Normalizer.php`, parity-tested)
maps a **standalone** Roman numeral `ii`..`x` to its digits, so either form
matches the other: `GTA VI` → `gta 6`, `Sony A7 IV` → `sony a7 4`. It is
deliberately narrow: only a whole token (no letter or digit on either side,
so `xbox`, `vivo`, `mix`, `x2`, `markiv` are untouched), never `i` (the
English word), and never right after a number and a space, where `x` / `v`
are a dimension or a unit (`1920 x 1080`, `12 V` stay as they are). The SKU
canonicalization skips this rule (`X 12` stays the code `x12`). Known trade-off:
a standalone `x` or `v` that is really a letter becomes a number on both the
product and the query side (`Xbox Series X` → `xbox series 10`), which still
matches itself. Needs a rebuild + reload (see [upgrading](#upgrading-to-m15)).

<a id="aliases"></a>**Synonyms and aliases (M15).** A product listed under
one name form is also found by another. At search time the normalized query
is checked against two bundle files, both a JSON list of groups of
equivalent terms:

- `synonyms.json` — generated by `build.py` from the catalog (category labels
  in two languages that share a product model). Groups larger than
  `SEARCH_SYNONYMS_MAX_GROUP_SIZE` (default 4) are ignored as likely noise.
- `aliases.json` — **maintained by the shop owner** in
  `pipeline/aliases.json` (or the file `SEARCH_ALIASES_FILE` /
  `release.py --aliases FILE` names), normalized and shipped in the bundle.

```json
[
  ["gta", "grand theft auto", "جی تی ای"],
  ["ps5", "ps 5", "playstation 5", "پلی استیشن 5", "پلی‌استیشن 5"]
]
```

Each inner list is one group; any term may be a phrase, in any script, with
Persian or ASCII digits. Every term of a group finds the products named by any
other: "GTA V" (→ `gta 5`) is also searched as `grand theft auto 5` and
`جی تی ای 5`. Terms are matched as **whole words / whole phrases**, never
inside a word (`gta` does not touch `gtax`, and `auto` alone is not `grand
theft auto`), and a variant is matched in the product **title only**, so a
description that merely mentions an alias never pulls that product in. The
literal query still searches title, specs and description as before; the
variants' hits merge into it by the same title / spec / description bands.
All variants share one extra scan of a covering title index
(`idx_title_scan`), whatever their number; `SEARCH_ALIAS_MAX_VARIANTS`
(default 6, the literal query included; 1 turns expansion off) caps how many
are tried. List every
spelling shoppers type: normalization removes the half-space, so `پلی‌استیشن`
and `پلی استیشن` are two different terms. To change the aliases: edit the
file, build a release, deploy and reload as for a catalog update. The build
and the reload both reject a malformed file (`invalid_aliases`), so a typo
never silently turns aliases off. Not solved by this: queries that describe a
need rather than name a product ("مانیتور مناسب کنسول"); aliases only fix
name / alias / form matching.

<a id="all-terms"></a>**All words (M16).** A multi-word query matches only
products that hold **every** word somewhere in title, specs and description
combined: `کیبورد قرمز` returns keyboards whose title, colour attribute or
description says red, not black keyboards and not red mice. Alias variants
apply the rule per variant (the union of the variants' hits; a variant still
matches in the title only). The surviving products rank by the field
weights below: title band, then spec band (a spec match with no title
match), then description-only, then score. The phrase bonus puts a product
named `کیبورد قرمز` first. When **no** product holds every word, even after
the keyboard-layout / spelling recovery, the products holding the most words
are served instead (most words first, then the same bands), so the shopper
still sees something. While the keyword hits hold every word, a query vector
only reorders them: semantic-only neighbours are **not** appended below. On
`multilingual-e5-small`, "کیبورد قرمز" puts black keyboards (0.83–0.84) and a
red mouse (0.83) above the 0.82 floor (measured on hand-written sample
passages, not the real catalog), so without that the one-word matches came
back through the semantic tier. Single-word queries, SKU hits and the
partial fallback keep the additive neighbours. `SEARCH_REQUIRE_ALL_TERMS=0`
always serves partial matches (products holding every word still rank first,
also in the hybrid merge) with the additive neighbours. Server-only: no
bundle rebuild or schema change.

**Relevance floor.** The nearest vectors of a query with no relevant product
are still unrelated items (on the real catalog, "ball bearing" returned case
fans). Neighbours with cosine below `SEARCH_SEMANTIC_MIN_SCORE` (default 0.82)
are dropped, and a query with no keyword hit and nothing above the floor
returns an empty result instead of `limit` far neighbours. Hybrid responses
carry `cosine_scores` so the test page can show each result's similarity while
tuning.

**Keyword field weighting (`server/src/Keyword.php`).** Title and description
are scored separately so a product named by the query beats one whose long
description merely mentions it (on the real catalog, "دوال شاک" ranked PS5
bundles above the controllers, and "بلبرینگ" ranked case fans above bearings).
Each query token is credited to the best field it matched: `score =
(title_weight × title hits + desc_weight × other hits) / tokens`, plus
`phrase_bonus` when the tokens appear adjacent and in order in the title. Any
row with a title match ranks above every description-only row, whatever the
weights (description-only matches are kept, below); the weights order rows
inside those two bands. Title hits are word-prefix matches on the short title
only — the long description is never scanned per row. Knobs:
`SEARCH_TITLE_WEIGHT` (10), `SEARCH_DESC_WEIGHT` (1), `SEARCH_PHRASE_BONUS` (5);
starting points, to be tuned on the real catalog. Ranking-only: no bundle
rebuild or schema change.

<a id="specs-field"></a>**Specs field (M13).** Product attributes and the
`feature` list are high-signal text that shoppers search for ("ASUS ROG",
"540 هرتز"). `build.py` composes them into `normalized_specs` (FULLTEXT-indexed
with the title and description): the attribute `name: value` pairs, then every
`title` in the PHP-serialized `feature` column, joined with ` | ` and
normalized like every other column. The description length cap does **not**
apply to specs. `feature` is parsed by a real PHP unserializer
(`phpserialize`) over the UTF-8 bytes, because PHP counts string lengths in
bytes and Persian characters take two; a malformed value (wrong lengths,
truncated, trailing data) is skipped, and the build prints a warning naming the
product ids. Each query token not in the title but in the specs earns
`SEARCH_SPEC_WEIGHT` (default 6; title 10, description 1): `score =
(title_weight × title hits + spec_weight × spec hits + desc_weight × other
hits) / tokens`. Every spec match ranks above every description-only match and
below every title match, whatever the weights, also in the hybrid merge; with a
query vector, spec matches are exempt from the description-only gate below.
The weight is a starting point and needs tuning on the real catalog. Needs a
rebuild + reload (new column and FULLTEXT index); until then the live table has
no specs and search runs on title and description as before.

**Description index cap and gate (M11).** Only the first
`SEARCH_DESC_INDEX_CHARS` (default 800 since M15, 400 before; or
`release.py --desc-index-chars N`; cut back to a word boundary) characters
of each cleaned description go into `normalized_desc`, the FULLTEXT column; the
full description is still stored in `description` for display. Deep spec text
("ball bearing" in a case fan's specs) therefore no longer matches, and the
FULLTEXT index over long HTML-derived descriptions shrinks. This is a
**build-time** setting: rebuild the bundle and reload for it to take effect.
At query time, when a query vector is present, a keyword hit that matched only
in the description (no title match) must also reach
`SEARCH_SEMANTIC_MIN_SCORE`, or it is dropped; title and spec matches are never
dropped, a hit with no vector is kept, and keyword-only requests are
unchanged. Disable with `SEARCH_DESC_ONLY_NEEDS_SEMANTIC=0`. M15 raised the
default from 400 to 800: attributes and feature titles are now indexed in full
as specs, and the gate keeps description-only hits out of hybrid results
unless they are semantically close. Keyword-only requests (no query vector)
see more description-only matches at the bottom of the list.

**Hybrid merge (`server/src/Ranker.php`).** Keyword (FULLTEXT) and cosine scores
are on incomparable scales, so they are **not** added raw. They are merged by
weighted **Reciprocal Rank Fusion** (rank-based, scale-free), then light business
boosts (in-stock, popularity) are applied **after** fusion. Keyword hits always
lead: every keyword match ranks above every semantic-only neighbour (and title
keyword matches above spec matches, above description-only ones), the
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
`pipeline/release.py` runs the same fetch itself when the assets are missing,
so building a release never needs this step first.
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
differs, set `store_base` / `image_base` in `config.php` (the installer asks
for them): the server then returns absolute links, which the page uses as is. Prices
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

The first deploy is: upload the zip, extract it, open `install.php`, fill in
the form. No config file editing, no separate model upload, no SSH needed.

1. **Create the database.** In cPanel, open MySQL Databases. Create a database
   and a user, and grant the user ALL privileges on that database. `RENAME
   TABLE`, `DROP` and `CREATE` are needed by the reload. The installer creates
   the tables.
2. **Build the release** (step 2 of [Every catalog update](#every-catalog-update)).
   It includes the browser model, runtime and font by default. The first run
   downloads them into `client/` on its own (about 150 MB; later runs reuse
   them), so this one command is all the first deploy needs:
   ```bash
   python pipeline/release.py --csv export.csv --out release.zip
   ```
3. **Upload and extract** `release.zip` into `~/search-service/server/`,
   outside `public_html` (File Manager: Upload, then Extract). Expose only its
   `public/` directory on the store's domain, as a symlink:
   ```bash
   ln -s ~/search-service/server/public ~/public_html/search-api
   ```
   Use the symlink, not a copy: later releases update `server/public/` in
   place, and a copy would keep serving the old files. (Without SSH, the File
   Manager cannot create a symlink; then re-copy `public/` into
   `public_html/search-api/` after every extract.)
   The storefront must reach the service on the **same origin**. There is no
   CORS support. See [INTEGRATION.md, Deployment shape](./INTEGRATION.md#deployment-shape).
4. **Open `https://shop.example.com/search-api/install.php`** and fill in the
   form (Persian, RTL):
   - database host (usually `localhost`), optional port, name, user, password;
   - the reload token: pre-filled with a fresh random value. **Copy it before
     submitting**; later updates need it and the installer never shows it again
     (it is stored in `config.php`);
   - model name, dimension and normalization version: keep the defaults unless
     the release was built with another model (a mismatch with the staged
     bundle is rejected before anything is written). The normalization version
     default is the code's own and follows later releases; another number pins it;
   - `STORE_BASE` / `IMAGE_BASE`: absolute store and image URLs (for example
     `https://shop.example.com/` and `https://shop.example.com/image/`). With
     them, `with_details` results carry absolute product links and images;
     leave them empty to keep the exported values as they are;
   - the tuning knobs (`semantic_min_score`, title / description / spec
     weights, `phrase_bonus`), pre-filled with the current defaults. The
     description index cap is not asked for: it is fixed when the release is
     built (`release.py --desc-index-chars`), and the server never re-indexes.

   On submit the installer validates the values and tests the database
   connection, creates the schema (`db/schema.sql`), then writes
   `server/config.php` from `config.example.php`. It never overwrites an
   existing `config.php`, and anything that fails before that point leaves
   nothing behind, so the form can be corrected and resubmitted. Last, it
   loads the catalog staged in `data_incoming/` with the same code as
   `reload.php?load=1` (staging load, checks, atomic swap) and reports the
   product count.

   If the load cannot finish within the host's time or memory limits, the page
   says so and shows the [manual staging load](#fallback-manual-staging-load)
   (`config.php` is already written by then; nothing was swapped).
5. **Delete `install.php`.** Once `config.php` exists it refuses to do
   anything (`403`, "already installed"), so a later release that re-extracts
   it is harmless, but removing it keeps the surface small.
   `curl -sS https://shop.example.com/search-api/health.php` should now report
   `product_count` equal to the catalog size. Set `SEARCH_MIN_TOKEN_SIZE` in
   `config.php` if the host's `innodb_ft_min_token_size` is not 3
   (`SHOW VARIABLES LIKE 'innodb_ft_min_token_size'`).
6. **Storefront.** The browser model is already under `public/client/`; check
   it as described in
   [INTEGRATION.md, Self-hosting the model](./INTEGRATION.md#self-hosting-the-model-no-huggingface-no-cdn).
   Then install the storefront snippet through the OpenCart module
   ([INTEGRATION.md, Storefront reference](./INTEGRATION.md#storefront-reference)).

**Without the installer** (or to change settings later): `config.php` is a
copy of `config.example.php` with the defaults after each `?:` (or in each
`$setting(...)`) edited; every key is described in `.env.example`, and
`SEARCH_*` environment variables still override the file where the host
supports them. Import `db/schema.sql` yourself in that case.

### Every catalog update

The whole update is three commands: export, `release`, and one `curl` after
unzipping on the host.

```
OpenCart DB --(1) export.csv--> dev/GPU machine --(2) release.zip--> cPanel host --(3) unzip + curl reload.php?load=1
```

**1. Export from OpenCart 2.0.3.1** to `build.py`'s input columns. Product
names and descriptions live in `oc_product_description`, one row per
`language_id`. Join it twice, once for Persian and once for English. Look up
your ids with `SELECT language_id, code FROM oc_language;`, and adjust the
`oc_` prefix if your install uses another `DB_PREFIX`.

```sql
SET SESSION group_concat_max_len = 1000000;   -- default 1024 bytes truncates attributes
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
    COALESCE(p.sku, '')                                       AS sku,
    p.price                                                   AS price,
    p.quantity                                                AS stock,
    CONCAT('index.php?route=product/product&product_id=', p.product_id) AS url,
    COALESCE(p.image, '')                                     AS image,
    p.viewed                                                  AS popularity,
    COALESCE((
        SELECT GROUP_CONCAT(CONCAT_WS(': ', ad.name, pa.text) SEPARATOR ' | ')
        FROM oc_product_attribute pa
        JOIN oc_attribute_description ad
          ON ad.attribute_id = pa.attribute_id AND ad.language_id = @fa
        WHERE pa.product_id = p.product_id
    ), '')                                                    AS attributes,
    COALESCE(p.feature, '')                                   AS feature
FROM oc_product p
JOIN oc_product_to_store ps ON ps.product_id = p.product_id AND ps.store_id = 0
LEFT JOIN oc_product_description d_fa
       ON d_fa.product_id = p.product_id AND d_fa.language_id = @fa
LEFT JOIN oc_product_description d_en
       ON d_en.product_id = p.product_id AND d_en.language_id = @en
LEFT JOIN oc_manufacturer m ON m.manufacturer_id = p.manufacturer_id
WHERE p.status = 1
  AND p.accept_status = '0'
ORDER BY p.product_id;
```

Notes on the query:
- Only enabled products (`status = 1`) in the default store **that are
  customer-facing (`accept_status = '0'`)** are exported. `accept_status` is a
  column this shop added to `oc_product`; it is not in stock OpenCart 2.0.3.1.
  Products with any other value (pending review, rejected) never reach search.
  Disabled or non-accepted products drop out of search at the next reload.
  `build.py` applies no filter of its own: it indexes exactly the rows it is
  given, so the filter lives in this query.
- `sku` is OpenCart's `oc_product.sku`. Empty is fine; those products just
  have no SKU match. See [SKU search](#sku-search).
- `category` is every category the product is in (Persian and English names),
  capped at 255 characters to fit the `products.category` column.
- `COALESCE` keeps NULLs out of the export. phpMyAdmin writes a SQL NULL as the
  literal text `NULL` in CSV, which would otherwise be indexed as a word.
- `popularity` uses `viewed`. Replace it with a sales count if you have a
  better signal.
- `attributes` is every attribute of the product as `name: value`, joined with
  ` | `, with the names in Persian (`@fa`, language_id 2 here). `oc_attribute_description`
  is joined on the Persian name only; `pa.text` is whatever value is stored for
  the product. `GROUP_CONCAT` stops at `group_concat_max_len` bytes (1024 by
  default), which is why the first line raises it; run it together with the
  `SELECT`, like the `@fa` / `@en` line. If many `attributes` values in
  `export.csv` end abruptly at about 1024 bytes, the `SET` was not applied.
- `feature` is this shop's `oc_product.feature` column (not in stock OpenCart),
  exported untouched: `build.py` unserializes it (see [Specs](#specs-field)).
  Do not edit or re-encode it; PHP's byte counts must stay valid.
- HTML escaping (`&lt;p&gt;`, `&amp;quot;`) and tags are left as stored.
  `build.py` decodes and strips them.

Save the result as **CSV**: phpMyAdmin, SQL tab, run the query, then **Export**
under "Query results operations", format CSV. Tick **"Put columns names in the
first row"** and keep the defaults (`"` enclosure, `"` escape). Save it as
`export.csv`, UTF-8. Use CSV, not phpMyAdmin's SQL export: `build.py`'s SQL
reader understands `''` quoting, not the backslash escapes (`\'`) that
phpMyAdmin and mysqldump write.

**2. Build the release** on the GPU machine (Python 3.11+), from the repo root:

```bash
pip install -r pipeline/requirements.txt 'sentence-transformers>=2.2'   # once
python pipeline/release.py --csv export.csv --out release.zip --no-model
# -> Built release.zip: <count> products, dim 384, embedder real, <n> files, without browser model
```

`--no-model` leaves out the browser model, runtime and font (about 150 MB) for
routine catalog updates when the host already has them. Without it the release
is self-contained (first deploy, or after the model changes): if `client/` does
not have the assets yet, `release.py` downloads them first, with the same
pinned, checksum-verified fetch as `pipeline/tools/fetch_web_model.py`, and
reuses them on later runs. A failed download stops the release before the
embedding run, and no zip is written.

`--desc-index-chars N` sets how many leading description characters are
keyword-indexed (default `SEARCH_DESC_INDEX_CHARS`, else 800; 0 = the whole
description). It only affects the build; to change it, build and deploy a new
release. `--aliases FILE` ships another [alias file](#aliases) instead of
`pipeline/aliases.json`.

On Windows: `pipeline\release.bat --csv export.csv --out release.zip --no-model`
(same arguments). The command builds the bundle with the **real** embedder
(whatever `EMBEDDER` says; `--mock` exists for tests only) and packs one
`release.zip`, laid out relative to the host's `server/` directory:

| in the zip | what |
| --- | --- |
| `data_incoming/` | the bundle: `vectors.bin`, `vectors.idx`, `products.load.sql`, `meta.json`, `spellcheck.txt`, `synonyms.json`, `aliases.json`, `keymap.json` |
| `bootstrap.php`, `src/`, `public/` | the server code (including `public/install.php` and `public/client/embedder.js`) |
| `config.example.php`, `db/schema.sql` | the installer's config template and schema |
| `public/client/model/`, `vendor/`, `fonts/` | **left out with `--no-model`**: the browser model, runtime, and font (about 150 MB), fetched into `client/` on first use |

`config.php` is **never** in the zip, so unzipping never overwrites the
server's configuration. Tests, tools, and local data are not packed either.
`SEARCH_MODEL`, `SEARCH_MODEL_REVISION` and `SEARCH_MODEL_DIM` must match
`server/config.php` (the reload rejects a mismatch). New config keys come with
defaults, so an older `config.php` keeps working.

**3. Deploy** on the cPanel host: unzip, then one `curl`.

```bash
cd ~/search-service/server && unzip -o ~/release.zip
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     "https://shop.example.com/search-api/reload.php?load=1"
# {"ok":true,"count":20000,"model":"intfloat/multilingual-e5-small","dim":384}
curl -sS https://shop.example.com/search-api/health.php   # product_count == count
```

Without SSH: upload `release.zip` into `~/search-service/server/` with the
cPanel File Manager, choose **Extract**, and allow it to overwrite, then run
the `curl` from any machine. `data_incoming/` should not hold an old bundle
before extracting; a failed earlier reload can leave one, and the extract then
overwrites every bundle file anyway.

With `?load=1` the reload does, in order, and stops at the first failure:
1. Checks `meta.json` against `config.php` (model, dim, normalization
   version), before loading anything.
2. Loads `data_incoming/products.load.sql` into `products_new`: the file drops
   and recreates the staging table, then inserts the rows. It is streamed one
   statement at a time (each at most 1 MB), and only statements aimed at
   `products_new` are accepted.
3. Checks counts, `vectors.bin` size, and checksum against the staging table.
4. Swaps tables and directories atomically, keeping the previous version.

A `422 invalid_bundle` means **nothing was swapped** and the live catalog is
unchanged. `reason` names the failed check (`staging_load_failed` also
reports the failing statement number); the fixes are in
[INTEGRATION.md](./INTEGRATION.md#post-reload). After a success, the previous
version is kept as the `products_old` table and the `data_old/` directory. The
"did you mean" dictionary (`spellcheck.txt`), the synonyms and the aliases
switch with the bundle; nothing else needs restarting. Between the unzip and the reload the new code serves the
old table; a table from before M12 lacks the SKU columns, and the SKU lookup is
simply skipped until the reload. A table from before M13 lacks
`normalized_specs`: search then covers title and description only until the
reload (also after a rollback to such a table). A table from before M15 lacks
the `idx_title_scan` index: synonym / alias expansion is then skipped (the
literal query is still served) until the reload.

<a id="upgrading-to-m15"></a>**Normalization version upgrades (M15.1).**
A release that changes the normalization rules (M15's Roman numerals made it
version 3) needs **no config edit**: the pipeline stamps its own
`NORMALIZATION_VERSION` into `meta.json`, and `config.php` defaults to the
deployed code's `Normalizer::VERSION`, so the new code and its bundle always
agree. The installer writes that default into `config.php` unless you type a
different number, which pins it (as does setting `SEARCH_NORMALIZATION_VERSION`).

A `config.php` written before M15.1 has the number hardcoded (`?: 2` or `?: 3`).
If it says 2, the M15 bundle is refused with `normalization_version_mismatch`
and **nothing is swapped**; if it says 3, the next rules bump would be. Edit it
once, so this never recurs: replace the `'normalization_version'` line in
`server/config.php` with

```php
        'normalization_version' => (int) (getenv('SEARCH_NORMALIZATION_VERSION') ?: \App\Normalizer::VERSION),
```

Deploy the code and reload together: until the reload the new code normalizes
queries with the new rules against the old table (for M15, only queries
containing a standalone Roman numeral are affected, and no aliases are served).

#### Fallback: manual staging load

The in-PHP load for 20k products (about 46 MB of SQL) runs inside one web
request. If the host's time or memory limits cut it off (`curl` returns a
timeout or `500`, or `staging_load_failed` names a server limit), load the
staging table yourself and reload **without** `?load=1`:

```bash
mysql -u cpuser_search -p cpuser_search < ~/search-service/server/data_incoming/products.load.sql
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     https://shop.example.com/search-api/reload.php
```

`products.load.sql` recreates `products_new` itself, so no `CREATE TABLE`
step is needed. Without SSH, Import it in phpMyAdmin (gzip it first: it
accepts `.sql.gz`, and a 20k-product file shrank from 46 MB to 12 MB in
testing). Check that `SELECT COUNT(*) FROM products_new` equals `count` in
`meta.json`. The bundle files can also be uploaded without the zip: copy them
into `~/search-service/server/data_incoming/` in **binary** mode (for example
SFTP). A corrupted file is caught as `vectors_size_mismatch` or
`checksum_mismatch`. `python pipeline/build.py --csv export.csv --out ./bundle`
(with `EMBEDDER=real`) builds just the bundle.

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
- [ ] Tune `SEARCH_SPEC_WEIGHT` (default 6, between title 10 and description
  1) with `server/tools/eval.php` on real attribute / feature queries (brands,
  refresh rates, sizes). Check the build's malformed-`feature` warning count on
  the real export, and that `attributes` is not cut at 1024 bytes.
- [ ] Keyboard-layout recovery covers the common US→Persian keys only (M2), and
  synonyms and spelling are untuned. Review `search_logs` zero-result queries
  after launch; name variants found there go into `pipeline/aliases.json`.
- [ ] Review the generated `synonyms.json` of the real build (M15 expands
  queries with it): a group joining unrelated categories through a shared junk
  `model` code would add their products to each other's results. Lower
  `SEARCH_SYNONYMS_MAX_GROUP_SIZE`, or fix the `model` values, if so.

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
- [ ] **Specs and the LIKE fallback (M13).** A query with a token shorter than
  `SEARCH_MIN_TOKEN_SIZE` scans every row's title, specs and indexed
  description. On a synthetic 20k catalog with about 550 characters of specs
  per product, with the table in memory on local MariaDB 10.11, that path took
  about **220 ms** (about 105–120 ms without specs); with the table larger
  than the buffer pool (128 MB default) it was I/O-bound at 600–800 ms. The
  FULLTEXT path stayed at about 50–60 ms. Measure `latency_ms` for short-token
  queries on the host with the real specs size.
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
- [ ] **In-PHP staging load (`reload.php?load=1`) within host limits.** It
  streams one ≤1 MB statement at a time (memory stays near one statement), and
  asks for no time limit and to survive a client disconnect, but hosts can
  forbid both, and LiteSpeed / the LVE may still stop a long request. Time a
  full 20k load on the host and compare it with `max_execution_time` and the LiteSpeed
  request timeout. If it is cut off, the live catalog stays untouched; use the
  [manual fallback](#fallback-manual-staging-load). For scale: a synthetic
  20k-product release (44 MB `products.load.sql`) loaded, validated and
  swapped in about 2.6 s with a 33 MB peak under `memory_limit=64M` on a local
  MariaDB 10.11 dev container. That was not a shared host, and real
  descriptions are longer.
- [ ] **Web installer on the real host (`install.php`).** Its initial load is
  the same in-PHP load as above, inside the browser's form submit, so the same
  limits apply, plus the browser waiting on the page. Check that the host lets
  PHP create `server/config.php` (and its `0640` mode suits how PHP runs
  there), that a load cut off by a limit shows the manual fallback, and that
  `install.php` answers `403` once `config.php` exists.
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
- [ ] **M10** — Keyword relevance by field: title vs description scored separately (configurable weights), title phrase bonus, title matches always above description-only matches (also in the hybrid merge); keyword latency guard.
- [ ] **M11** — Keyword index covers only the first `desc_index_chars` of each description (requires a rebuild); with a query vector, description-only keyword hits below the semantic floor are dropped.
- [ ] **M12** — SKU search (exact/prefix SKU matches ranked first, SKU in the title-weighted text), `accept_status = '0'` export filter, one-command `release.py` (+ `release.bat`) producing `release.zip`, and `reload.php?load=1` loading the staging table in PHP before the atomic swap.
- [ ] **M13** — Specs field: product attributes and PHP-serialized `feature` titles in a FULLTEXT-indexed `normalized_specs` column (`spec_weight`, ranked between title and description-only matches, also in hybrid mode), and in the embedded passage within `SEARCH_PASSAGE_CHAR_LIMIT`.
- [ ] **M14** — Self-contained release (browser model included by default, `--no-model` for routine updates, `db/schema.sql` packed) and a web installer, `public/install.php`: validates the form, tests the DB connection, creates the schema, writes `config.php` (never overwriting one), loads the staged catalog, then refuses to run again. `store_base` / `image_base` config for absolute `with_details` links.
- [ ] **M15** — Name / alias / form matching: standalone Roman numerals → digits (normalization version 3), query-side expansion from `synonyms.json` and an owner-maintained `aliases.json` (whole terms, title-only variants), `desc_index_chars` default 800.
- [ ] **M14.1** — `release.py` downloads the browser assets itself when `client/` lacks them (cached; `--no-model` skips), so one command builds a complete release. `desc_index_chars` leaves the installer and server config and becomes `release.py --desc-index-chars`.
- [ ] **M15.1** — `normalization_version` in `config.php` (and the installer prefill) defaults to the code's `Normalizer::VERSION`, and the pipeline always stamps its own `NORMALIZATION_VERSION`, so a rules bump reloads with no config edit.
- [ ] **M17** — VPS vector service (`vps/`): bge-m3 (ONNX, CPU) query embedding, `POST /search-vectors` global cosine top-K with a score floor, token auth, validated atomic `POST /reload`, `GET /health`, `setup.sh` + systemd; `build.py` / `release.py --vps-out` produce its bge-m3 product vectors. Not wired into cPanel yet.
- [ ] **M16** — Multi-word queries require every word across title, specs and description (per alias variant), ranked title band, spec band (no title match), description-only, then score; best-partial fallback when nothing holds every word; semantic neighbours not appended to all-words hits (`SEARCH_REQUIRE_ALL_TERMS`, default on).

## Contributing

- One PR per milestone, in order; each ships with tests and passes CI.
- Never commit or push to `main`/`master`; never merge your own PR.
- Conventional Commits; PSR-12 (PHP) and ruff-clean (Python); English only.

See `CLAUDE.md` §9–§11 for the full workflow and coding standards.
