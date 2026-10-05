# Configuration reference

Every model name, dimension, threshold, table name and credential is read from configuration, never hardcoded. This file lists every key for the three places that have their own settings: the **server** (cPanel), the **pipeline** (the build machine, e.g. Colab) and the **VPS vector service**. What a ranking knob does is explained in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md); where the build keys act is in [PIPELINE.md](PIPELINE.md).

Never commit secrets, `server/data/`, bundles or model files.

## How the settings are supplied

- **Server.** On the host, `public/install.php` writes `server/config.php` from `server/config.example.php` on the first deploy ([DEPLOY.md](DEPLOY.md#one-time-setup-cpanel-host)). Otherwise copy the example to `config.php` and edit it, or supply the environment variables it reads (`.env.example` lists them all). Each value is `getenv('SEARCH_...') ?: default`, so an environment variable overrides the file where the host supports it. A `config.php` written before a milestone lacks that milestone's keys and falls back on the defaults below; copy the lines from `config.example.php` to change them.
- **Pipeline.** Environment variables only (a `.env` next to the repo works for `db_export.py`; `cp .env.example .env`). Defaults live in `pipeline/config.py`. Command-line flags of `release.py` / `build.py` / `db_export.py` override them for one run.
- **VPS.** `/etc/search-vectors.env` on the vector-service VM, written once by `vps/setup.sh` (template `vps/search-vectors.env.example`, runbook [`vps/README.md`](../vps/README.md)).

The model, revision, dimension, pooling and prefixes are parameterized and shared between the pipeline and the VPS (bge-m3, contract 2), and between the pipeline and the cPanel bundle check (e5, contract 3); see [ARCHITECTURE.md](ARCHITECTURE.md#the-three-contracts) and [INTEGRATION.md, Changing the model](../INTEGRATION.md#changing-the-model).

## Server (`config.php` / `SEARCH_*`)

### Database and paths

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_DB_DSN` | `mysql:host=localhost;dbname=search;charset=utf8mb4` | PDO DSN |
| `SEARCH_DB_USER`, `SEARCH_DB_PASSWORD` | empty | credentials (secret) |
| `SEARCH_PRODUCTS_TABLE` | `products` | live table (the load file stages into `<table>_new`) |
| `SEARCH_SEARCH_LOGS_TABLE` | `search_logs` | log table |
| `SEARCH_DATA_DIR` | `server/data` | active bundle (spellcheck, synonyms, aliases, keymap) |
| `SEARCH_DATA_INCOMING_DIR` | `server/data_incoming` | staging directory for the atomic reload swap |
| `SEARCH_RELOAD_TOKEN` | empty | enables `POST /reload` (`X-Reload-Token`); set a strong random value |

### Bundle compatibility (checked on `POST /reload`, contract 3)

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_MODEL` | `intfloat/multilingual-e5-small` | model stamped in the cPanel bundle's `meta.json` |
| `SEARCH_MODEL_REVISION` | `main` | its revision |
| `SEARCH_MODEL_DIM` | `384` | its dimension |
| `SEARCH_NORMALIZATION_VERSION` | the code's `Normalizer::VERSION` (3) | set only to pin a version; a rules bump then needs no config edit |

`/search` does not read the bundle's vectors since M18 (the semantic tier's model, bge-m3, runs on the VPS); `/reload` still checks model, dim, normalization version, file size and checksum.

### Keyword search

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_DEFAULT_LIMIT` | 20 | results when the request has no `limit` |
| `SEARCH_MIN_TOKEN_SIZE` | 3 | mirror the host's `innodb_ft_min_token_size`; queries with a shorter token use the LIKE fallback |
| `SEARCH_TITLE_WEIGHT` / `SEARCH_DESC_WEIGHT` / `SEARCH_PHRASE_BONUS` | 10 / 1 / 5 | field weighting and the adjacent-in-title bonus |
| `SEARCH_SPEC_WEIGHT` | 6 | token found only in attributes / feature titles |
| `SEARCH_TAG_WEIGHT` / `SEARCH_BRAND_WEIGHT` / `SEARCH_CATEGORY_WEIGHT` | 8 / 7 / 5 | M21 fields (tags just below the title) |
| `SEARCH_SKU_PREFIX_MIN_LENGTH` | 4 | shortest query (with a digit) that prefix-matches SKUs |
| `SEARCH_ALIAS_MAX_VARIANTS` | 6 | alias / synonym query variants, the literal one included (1 = off) |
| `SEARCH_SYNONYMS_MAX_GROUP_SIZE` | 4 | generated synonym groups larger than this are ignored |
| `SEARCH_REQUIRE_ALL_TERMS` | 1 | multi-word queries need every word (0 = most words first) |
| `SEARCH_SOFT_AND_MIN_RESULTS` | 3 | M23: below this many full-coverage hits, add partial-coverage ones (0 = strict) |
| `SEARCH_SOFT_AND_MIN_COVERAGE` | 0.5 | share of a variant's words a partial hit must hold |
| `SEARCH_SOFT_AND_PARTIAL_PENALTY` | 0.5 | scale on a partial hit's keyword score |
| `SEARCH_SOFT_AND_CANDIDATE_CAP` | 100 | rows scored per alias-variant top-up (0 = no cap) |
| `SEARCH_FACET_MIN_PRODUCTS` | 20 | a spec-only query term held by at least this many products' specs is a facet (genre/feature): its hits are floor-exempt like title matches (M28; 0 = off) |
| `SEARCH_COLLAPSE_WEIGHT` | 9 | M23: score of a collapsed-name hit (0 = off) |
| `SEARCH_COLLAPSE_MIN_LENGTH` | 5 | shortest collapsed query tried |
| `SEARCH_SUGGEST_MIN_RESULTS` | 3 | "did you mean" runs below this many keyword hits |
| `SEARCH_SUGGEST_MIN_FREQUENCY` | 2 | a candidate must occur in this many products |
| `SEARCH_SUGGEST_MAX_DISTANCE` | 2 | maximum edits (1 for tokens of 4 characters or fewer) |

### Hybrid ranking (semantic tier)

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_SEMANTIC_TOP_K` | 300 | neighbours asked of the VPS (its cap is `VPS_MAX_LIMIT`, 500) |
| `SEARCH_SEMANTIC_MIN_SCORE` | 0.4 | cosine floor on bge-m3's scale (sent as `min_score`, re-applied); needs tuning on the real catalog |
| `SEARCH_KEYWORD_WEIGHT` / `SEARCH_SEMANTIC_WEIGHT` / `SEARCH_MIN_RELEVANCE` | 0.4 / 0.6 / 0.45 | blended relevance and its floor (M20) |
| `SEARCH_STOCK_BOOST` / `SEARCH_POPULARITY_BOOST` | 0.1 / 0.1 | light boosts after the floor |
| `SEARCH_BRAND_MATCH_BOOST` / `SEARCH_CATEGORY_MATCH_BOOST` / `SEARCH_TAG_MATCH_BOOST` | 0.15 / 0.1 / 0.1 | M21 match boosts (0 disables one) |
| `SEARCH_TAG_MATCH_MIN_TOKENS` | 2 | shortest query that phrase-matches tags |
| `SEARCH_LATENCY_BUDGET_MS` | 200 | the budget the latency-guard tests assert against |

### VPS client (`vps` section)

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_VPS_URL` | empty | base URL (the client appends `/search-vectors`); empty = keyword-only |
| `SEARCH_VPS_TOKEN` | empty | the VPS's `VPS_TOKEN` (secret) |
| `SEARCH_VPS_TIMEOUT_MS` | 300 | whole-call budget, connect included. A slow or unreachable VPS means keyword-only results for that request, never an error. The default is sized for an external VPS; on the final LAN VM it is a safety net, keep it sane |

### Result cache (`redis` section, M26)

Optional and **off by default**: with `SEARCH_REDIS_ENABLED` unset nothing changes. Repeated identical
queries are then answered from Redis instead of recomputing keyword + VPS + ranking
([DEPLOY.md](DEPLOY.md#result-cache-optional-redis-m26)). An existing `config.php` has no `redis` section:
copy the block from `config.example.php` (or set the variables in the environment) to enable it.

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_REDIS_ENABLED` | `0` | `1` turns the cache on |
| `SEARCH_REDIS_HOST`, `SEARCH_REDIS_PORT` | `127.0.0.1`, `6379` | the Redis server (on the VPS, `vps/setup.sh --redis`; cPanel only runs the client) |
| `SEARCH_REDIS_AUTH` | empty | Redis password (a secret: environment or `config.php`, never the repository) |
| `SEARCH_REDIS_DB` | `0` | database number |
| `SEARCH_REDIS_TTL` | `300` | seconds an entry lives; a catalog reload flushes it earlier |
| `SEARCH_REDIS_TIMEOUT_MS` | `100` | connect + read budget; a slow or dead Redis costs at most this per request and means a cache miss, never an error |
| `SEARCH_REDIS_PREFIX` | `search:cache:` | key prefix; the keyspace `POST /reload` flushes. Give each environment sharing one Redis its own prefix |

Entries are keyed by the normalized query, the `limit` and whether the semantic tier is configured. Responses
with `debug` are never cached, and neither is a result served without the semantic tier that is configured (a
slow or failing VPS), so a degradation never outlives its request. After changing any ranking setting, flush by
hand (see [DEPLOY.md](DEPLOY.md#result-cache-optional-redis-m26)) or wait out the TTL.

### Tooling and storefront

| key / env | default | meaning |
| --- | --- | --- |
| `SEARCH_DEBUG_TOKEN` | empty | enables `"debug": 1` on `/search` (`X-Debug-Token`), see [SEARCH-API.md](SEARCH-API.md#search-logs-and-ranking-debug) |
| `SEARCH_LOGS_TOKEN` | empty | enables `logs.php` (`X-Logs-Token`) |
| `SEARCH_LOGS_PAGE_SIZE` | 50 | rows per page |
| `SEARCH_STORE_BASE`, `SEARCH_IMAGE_BASE` | empty | absolute bases for `with_details` product links and images; empty keeps the exported values |

The test page's `PRICE_SUFFIX` is a constant in `server/public/test.html`, not a server setting.

## Pipeline (build machine)

| env | default | meaning |
| --- | --- | --- |
| `EMBEDDER` | `mock` | `mock` (deterministic, no model download; tests) or `real`. `release.py` embeds the VPS vectors for real regardless |
| `SEARCH_MODEL`, `SEARCH_MODEL_REVISION`, `SEARCH_MODEL_DIM`, `SEARCH_NORMALIZATION_VERSION` | as the server | stamped into the cPanel bundle's `meta.json`; must match `config.php` |
| `SEARCH_VPS_MODEL` | `BAAI/bge-m3` | model of the vector service |
| `SEARCH_VPS_MODEL_REVISION` | `5617a9f61b028005a4858fdac845db406aefb181` | pinned revision |
| `SEARCH_VPS_MODEL_DIM` | 1024 | dimension |
| `SEARCH_VPS_POOLING` | `cls` | pooling; must equal the VPS's `VPS_POOLING` |
| `SEARCH_VPS_QUERY_PREFIX`, `SEARCH_VPS_PASSAGE_PREFIX` | empty | bge-m3 dense retrieval takes no instruction |
| `SEARCH_EMBED_DEVICE` | `auto` | `auto` (cuda when torch sees a GPU, else cpu), `cpu`, `cuda` or `cuda:N` |
| `SEARCH_EMBED_FP16` | `auto` | `auto` = on for CUDA only; `on` / `off` |
| `SEARCH_EMBED_BATCH_SIZE` | 16 | fits bge-m3 on 4 GB; lower it (8, 4) on a CUDA out-of-memory error |
| `SEARCH_BUNDLE_EMBEDDER` | `mock` | embedder of the cPanel bundle's unread `vectors.bin`; `real` builds real e5 vectors |
| `SEARCH_DESC_CHAR_LIMIT` | 300 | description characters in the embedded passage |
| `SEARCH_PASSAGE_CHAR_LIMIT` | 1000 | cap on the whole embedded passage (0 = none) |
| `SEARCH_DESC_INDEX_CHARS` | 800 | leading description characters keyword-indexed (0 = whole); `release.py --desc-index-chars` overrides. Build-time only |
| `SEARCH_ALIASES_FILE` | `pipeline/aliases.json` | the owner's [alias file](SEARCH-BEHAVIOR.md#aliases); `release.py --aliases` overrides |
| `SEARCH_LOAD_MAX_STATEMENT_BYTES` | 1000000 | largest statement in `products.load.sql` (must fit the host's `max_allowed_packet`) |
| `SEARCH_MIN_TOKEN_LENGTH` | 2 | tokens shorter than this are ignored when building the dictionaries |
| `SEARCH_PRODUCTS_TABLE` | `products` | table whose definition seeds the staging DDL |

> `.env.example` sets `SEARCH_DESC_INDEX_CHARS=800`, the same as the code default used when the variable is unset (an older example showed 400, the pre-M15 default), so copying it to `.env` does not change behavior.

### Export (`db_export.py`)

| env | default | meaning |
| --- | --- | --- |
| `OC_DB_HOST`, `OC_DB_PORT` | `127.0.0.1`, `3306` | OpenCart database |
| `OC_DB_USER`, `OC_DB_PASSWORD`, `OC_DB_NAME` | empty | credentials (the password is never taken from the command line) |
| `OC_DB_PREFIX` | `oc_` | table prefix |
| `OC_LANGUAGE_ID` | `2` | the installed (Persian) language id |

The flags `--host/--port/--user/--name/--prefix/--language-id/--out` override these; see [PIPELINE.md](PIPELINE.md#exporting-from-opencart-db_exportpy).

## VPS vector service (`/etc/search-vectors.env`)

| env | default | meaning |
| --- | --- | --- |
| `VPS_TOKEN` | generated by `setup.sh` | shared secret (at least 16 characters); cPanel sends it as a bearer token |
| `VPS_HOST`, `VPS_PORT` | `127.0.0.1`, 8600 | bind address; keep loopback behind a TLS reverse proxy, or bind the public IP with `VPS_SSL_*` set and the firewall limited to the caller |
| `VPS_SSL_CERTFILE`, `VPS_SSL_KEYFILE` | empty | TLS without a proxy |
| `VPS_MODEL`, `VPS_MODEL_REVISION`, `VPS_MODEL_DIM`, `VPS_POOLING`, `VPS_QUERY_PREFIX` | bge-m3 values | **must match** the pipeline's `SEARCH_VPS_*` (contract 2); `/reload` refuses vectors whose `meta.json` disagrees |
| `VPS_EMBEDDER` | `onnx` | query embedder |
| `VPS_MODEL_DIR`, `VPS_ONNX_FILE` | `/opt/search-vectors/model`, `onnx/model_quantized.onnx` | model files; int8 fits 2 GB, `onnx/model.onnx` (fp32) gives exact parity at ~2.5 GB RAM |
| `VPS_MAX_TOKENS`, `VPS_THREADS` | 512, 0 | tokenizer cap, ONNX threads (0 = automatic) |
| `VPS_DATA_DIR` | `/var/lib/search-vectors` | `active/`, `incoming/`, `previous/` |
| `VPS_SEMANTIC_MIN_SCORE` | 0.5 | VPS-side default floor, used when the request sends none (cPanel sends its own `min_score`) |
| `VPS_DEFAULT_LIMIT`, `VPS_MAX_LIMIT`, `VPS_MAX_QUERY_CHARS` | 100, 500, 200 | request bounds |

## Tests only

`SEARCH_TEST_DB_DSN`, `SEARCH_TEST_DB_USER`, `SEARCH_TEST_DB_PASSWORD` point the database-backed PHPUnit tests at a real MySQL/MariaDB ([ARCHITECTURE.md](ARCHITECTURE.md#running-the-database-backed-tests)).

## Alias generator (offline, M25)

`ALIAS_LLM_BASE_URL`, `ALIAS_LLM_MODEL`, `ALIAS_LLM_API_KEY`, `ALIAS_LLM_BATCH_SIZE` (40), `ALIAS_MAX_VARIANTS` (4), `ALIAS_MAX_VARIANT_CHARS` (40), `ALIAS_MAX_NAME_CHARS` (60): used only by `pipeline/gen_aliases.py`, see [ALIASES.md](ALIASES.md).
