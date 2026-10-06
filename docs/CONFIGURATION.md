# Configuration reference

Every model name, dimension, threshold, table name and credential is read from configuration, never hardcoded. This file lists every key for the three places that have their own settings: the **server** (cPanel), the **pipeline** (the build machine, e.g. Colab) and the **VPS vector service**. What a ranking knob does is explained in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md); where the build keys act is in [PIPELINE.md](PIPELINE.md).

Never commit secrets, `server/data/`, bundles or model files.

## How the settings are supplied

- **Server.** Layered: the code owns the schema and every default (`server/src/Config.php`, one table of `key => [type, default]`), and `server/config.php` holds **only the deployer's overrides** (see [The layered server config](#the-layered-server-config)). On the host, `public/install.php` writes `config.php` from `server/config.example.php` on the first deploy ([DEPLOY.md](DEPLOY.md#one-time-setup-cpanel-host)); otherwise copy the example to `config.php` and edit it. The example reads the connection settings and secrets from the environment (`.env.example` lists them); tunables are not read from the environment.
- **Pipeline.** Environment variables only (a `.env` next to the repo works for `db_export.py`; `cp .env.example .env`). Defaults live in `pipeline/config.py`. Command-line flags of `release.py` / `build.py` / `db_export.py` override them for one run.
- **VPS.** `/etc/search-vectors.env` on the vector-service VM, written once by `vps/setup.sh` (template `vps/search-vectors.env.example`, runbook [`vps/README.md`](../vps/README.md)).

The model, revision, dimension, pooling and prefixes are parameterized and shared between the pipeline and the VPS (bge-m3, contract 2), and between the pipeline and the cPanel bundle check (e5, contract 3); see [ARCHITECTURE.md](ARCHITECTURE.md#the-three-contracts) and [INTEGRATION.md, Changing the model](../INTEGRATION.md#changing-the-model).

## The layered server config

Defaults are defined once. `App\Config` (`server/src/Config.php`) holds the whole schema as a flat table of `'section.key' => [type, default]` (`'search.title_weight' => ['float', 10.0]`). `server/config.php` returns a nested array of **overrides only**; `Config::load()` deep-merges it over the defaults and casts every known key to its schema type, so code reads `$config['search']['title_weight']` with no fallback and an old `config.php` never lacks a key a newer release added. The tables below list every key and its default.

- **Required:** `db.dsn` and `db.user`, the two things the app cannot invent. Tunables are never required. Tokens are optional: an empty token switches its feature off (`reload.token` empty disables `POST /reload`).
- **Casting:** `"0"`, `"0.0"` and `"false"` survive as `0`, `0.0` and `false` (they never fall back to the default). A blank value for a number or boolean means "unset" (the default); a value that does not cast keeps the default and is an ERROR in the doctor.
- **Without a `config.php`** (development) the app starts on the defaults; the missing credentials show up in the doctor and in `/health`, not as a crash.
- **Where the app lives:** `public/app_base.php` takes the app base (the folder with `bootstrap.php`, `src/`, `config.php`) from the environment variable `SEARCH_APP_BASE` (`SetEnv` in `public/.htaccess` works) and otherwise uses the parent of `public/`; a base without `bootstrap.php` answers `500 app_base_not_found`. Only needed when the web root holds just the public files ([DEPLOY.md](DEPLOY.md#layout)).
- **Where the file lives:** `server/config.php`, or the path in `SEARCH_CONFIG_FILE` when set (a config kept outside the code directory; the test suite uses it).

**Change a tunable:** add its key to `config.php` at the same nested path, for example

```php
'search' => ['title_weight' => 12.0, 'semantic_min_score' => 0.35],
```

**Add a tunable (developers):** add one line to `Config::table()` (type and default), read it as `$config['section']['key']` where it is used, and document it below. Do not add a `?? default` at the use site, and in tests build a config from `Config::defaults()` / `Config::merge([...])` rather than listing defaults again.

### Config doctor

`php server/tools/config-check.php` (run it after unzipping a release, see [DEPLOY.md](DEPLOY.md#every-catalog-update)) compares `config.php` with the schema:

| level | what |
| --- | --- |
| ERROR | a required key missing or empty; a value that does not cast to its type |
| WARN | an unknown key path (a typo or a removed option); a half-configured feature: `redis.enabled` on with an empty `redis.host`, `vps.url` set with an empty `vps.token`, `reload.token` empty (`/reload` is disabled) |
| INFO (`--verbose`) | every tunable still at its code default, so all available knobs are visible |

It prints key paths only, never values, and exits non-zero on any ERROR, so a deploy script or CI can gate on it. `--file=<path>` checks another overrides file. `GET /health` carries the same verdict as `config: {ok, errors[], warnings[]}` (key paths and statuses only; `/health` is unauthenticated, so never values).

## Server (`config.php` overrides)

### Database and paths

| key | default | meaning |
| --- | --- | --- |
| `db.dsn` | none (required) | PDO DSN, e.g. `mysql:host=localhost;dbname=search;charset=utf8mb4` |
| `db.user`, `db.password` | none (user required), empty | credentials (secret; the password may be empty) |
| `db.products_table` | `products` | live table (the load file stages into `<table>_new`) |
| `db.search_logs_table` | `search_logs` | log table |
| `paths.data` | `server/data` | active bundle (spellcheck, synonyms, aliases, keymap) |
| `paths.data_incoming` | `server/data_incoming` | staging directory for the atomic reload swap |
| `reload.token` | empty | enables `POST /reload` (`X-Reload-Token`); set a strong random value |

### Bundle compatibility (checked on `POST /reload`, contract 3)

| key | default | meaning |
| --- | --- | --- |
| `model.name` | `intfloat/multilingual-e5-small` | model stamped in the cPanel bundle's `meta.json` |
| `model.revision` | `main` | its revision |
| `model.dim` | `384` | its dimension |
| `model.normalization_version` | the code's `Normalizer::VERSION` (3) | set only to pin a version; a rules bump then needs no config edit |

`/search` does not read the bundle's vectors since M18 (the semantic tier's model, bge-m3, runs on the VPS); `/reload` still checks model, dim, normalization version, file size and checksum.

### Keyword search

| key | default | meaning |
| --- | --- | --- |
| `search.default_limit` | 20 | results when the request has no `limit` |
| `search.min_token_size` | 3 | mirror the host's `innodb_ft_min_token_size`; queries with a shorter token use the LIKE fallback |
| `search.title_weight` / `search.desc_weight` / `search.phrase_bonus` | 10 / 1 / 5 | field weighting and the adjacent-in-title bonus |
| `search.spec_weight` | 6 | token found only in attributes / feature titles |
| `search.tag_weight` / `search.brand_weight` / `search.category_weight` | 8 / 7 / 5 | M21 fields (tags just below the title) |
| `search.sku_prefix_min_length` | 4 | shortest query (with a digit) that prefix-matches SKUs |
| `search.alias_max_variants` | 6 | alias / synonym query variants, the literal one included (1 = off) |
| `search.synonyms_max_group_size` | 4 | generated synonym groups larger than this are ignored |
| `search.require_all_terms` | 1 | multi-word queries need every word (0 = most words first) |
| `search.soft_and_min_results` | 3 | M23: below this many full-coverage hits, add partial-coverage ones (0 = strict) |
| `search.soft_and_min_coverage` | 0.5 | share of a variant's words a partial hit must hold |
| `search.soft_and_partial_penalty` | 0.5 | scale on a partial hit's keyword score |
| `search.soft_and_candidate_cap` | 100 | rows scored per alias-variant top-up (0 = no cap) |
| `search.facet_min_products` | 20 | a spec-only query term held by at least this many products' specs is a facet (genre/feature): its hits are floor-exempt like title matches (M28; 0 = off) |
| `search.collapse_weight` | 9 | M23: score of a collapsed-name hit (0 = off) |
| `search.collapse_min_length` | 5 | shortest collapsed query tried |
| `search.suggest_min_results` | 3 | "did you mean" runs below this many keyword hits |
| `search.suggest_min_frequency` | 2 | a candidate must occur in this many products |
| `search.suggest_max_distance` | 2 | maximum edits (1 for tokens of 4 characters or fewer) |

### Hybrid ranking (semantic tier)

| key | default | meaning |
| --- | --- | --- |
| `search.semantic_top_k` | 300 | neighbours asked of the VPS (its cap is `VPS_MAX_LIMIT`, 500) |
| `search.semantic_min_score` | 0.4 | cosine floor on bge-m3's scale (sent as `min_score`, re-applied); needs tuning on the real catalog |
| `search.keyword_weight` / `search.semantic_weight` / `search.min_relevance` | 0.4 / 0.6 / 0.45 | blended relevance and its floor (M20) |
| `search.stock_boost` / `search.popularity_boost` | 0.1 / 0.1 | light boosts after the floor |
| `search.brand_match_boost` / `search.category_match_boost` / `search.tag_match_boost` | 0.15 / 0.1 / 0.1 | M21 match boosts (0 disables one) |
| `search.tag_match_min_tokens` | 2 | shortest query that phrase-matches tags |
| `search.latency_budget_ms` | 200 | the budget the latency-guard tests assert against |

### VPS client (`vps` section)

| key | default | meaning |
| --- | --- | --- |
| `vps.url` | empty | base URL (the client appends `/search-vectors`); empty = keyword-only |
| `vps.token` | empty | the VPS's `VPS_TOKEN` (secret) |
| `vps.timeout_ms` | 300 | whole-call budget, connect included. A slow or unreachable VPS means keyword-only results for that request, never an error. The default is sized for an external VPS; on the final LAN VM it is a safety net, keep it sane |

### Result cache (`redis` section, M26)

Optional and **off by default**: with `redis.enabled` off nothing changes. Repeated identical
queries are then answered from Redis instead of recomputing keyword + VPS + ranking
([DEPLOY.md](DEPLOY.md#result-cache-optional-redis-m26)). To enable it, set `redis.enabled`, `redis.host`, `redis.port` and
`redis.auth` in `config.php` (the example template reads them from the environment).

| key | default | meaning |
| --- | --- | --- |
| `redis.enabled` | off | turns the cache on |
| `redis.host`, `redis.port` | `127.0.0.1`, `6379` | the Redis server (on the VPS, `vps/setup.sh --redis`; cPanel only runs the client) |
| `redis.auth` | empty | Redis password (a secret: environment or `config.php`, never the repository) |
| `redis.db` | `0` | database number |
| `redis.ttl` | `300` | seconds an entry lives; a catalog reload flushes it earlier |
| `redis.timeout_ms` | `100` | connect + read budget; a slow or dead Redis costs at most this per request and means a cache miss, never an error |
| `redis.prefix` | `search:cache:` | key prefix; the keyspace `POST /reload` flushes. Give each environment sharing one Redis its own prefix |

Entries are keyed by the normalized query, the `limit` and whether the semantic tier is configured. Responses
with `debug` are never cached, and neither is a result served without the semantic tier that is configured (a
slow or failing VPS), so a degradation never outlives its request. After changing any ranking setting, flush by
hand (see [DEPLOY.md](DEPLOY.md#result-cache-optional-redis-m26)) or wait out the TTL.

### Tooling and storefront

| key | default | meaning |
| --- | --- | --- |
| `debug.token` | empty | enables `"debug": 1` on `/search` (`X-Debug-Token`), see [SEARCH-API.md](SEARCH-API.md#search-logs-and-ranking-debug) |
| `logs.token` | empty | enables `logs.php` (`X-Logs-Token`) |
| `logs.page_size` | 50 | rows per page |
| `storefront.store_base`, `storefront.image_base` | empty | absolute bases for `with_details` product links and images; empty keeps the exported values |

The test page's `PRICE_SUFFIX` is a constant in `server/public/test.html`, not a server setting.

## Pipeline (build machine)

| env | default | meaning |
| --- | --- | --- |
| `EMBEDDER` | `mock` | `mock` (deterministic, no model download; tests) or `real`. `release.py` embeds the VPS vectors for real regardless |
| `SEARCH_MODEL`, `SEARCH_MODEL_REVISION`, `SEARCH_MODEL_DIM`, `SEARCH_NORMALIZATION_VERSION` | as the server | stamped into the cPanel bundle's `meta.json`; must match `config.php`'s `model.*` (code defaults, pin them there) |
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
