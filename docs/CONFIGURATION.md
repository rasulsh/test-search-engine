# Configuration reference

Every model name, dimension, threshold, table name and credential is read from configuration, never hardcoded. This file lists every key for the three places that have their own settings: the **server** (cPanel), the **pipeline** (the build machine, e.g. Colab) and the **VPS vector service**. What a ranking knob does is explained in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md); where the build keys act is in [PIPELINE.md](PIPELINE.md).

Where each search knob acts in the request path: [RUNTIME-FLOW.md](RUNTIME-FLOW.md).

Never commit secrets, `server/data/`, bundles or model files.

## How the settings are supplied

- **Server.** Layered: the code owns the schema and every default (`server/src/Config.php`, one table of `key => [type, default]`), and `server/config.php` holds **only the deployer's overrides** (see [The layered server config](#the-layered-server-config)). On the host, `public/install.php` writes `config.php` from `server/config.example.php` on the first deploy ([DEPLOY.md](DEPLOY.md#one-time-setup-cpanel-host)); otherwise copy the example to `config.php` and edit it. The example reads the connection settings and secrets from the environment (`.env.example` lists them); tunables are not read from the environment.
- **Pipeline.** Environment variables only (a `.env` next to the repo works for `db_export.py`; `cp .env.example .env`). Defaults live in `pipeline/config.py`. Command-line flags of `release.py` / `build.py` / `db_export.py` override them for one run.
- **VPS.** `/etc/search-vectors.env` on the vector-service VM, written once by `vps/setup.sh` (template `vps/search-vectors.env.example`, runbook [`vps/README.md`](../vps/README.md)).

The model, revision, dimension, pooling and prefixes are parameterized and shared between the pipeline and the VPS (bge-m3, contract 2), and, for the cPanel bundle check, only the normalization version is shared (contract 3; the bundle has carried no model since M42); see [ARCHITECTURE.md](ARCHITECTURE.md#the-three-contracts) and [INTEGRATION.md, Changing the model](../INTEGRATION.md#changing-the-model).

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
| WARN | an unknown key path (a typo); a **renamed or removed key** (below), with its replacement or the reason; a half-configured feature: `redis.enabled` on with an empty `redis.host`, `vps.url` set with an empty `vps.token`, `reload.token` empty (`/reload` is disabled) |
| INFO (`--verbose`) | every tunable still at its code default, so all available knobs are visible |

**Renamed and removed keys (M40).** `App\Config::deprecated()` lists every key that was renamed or removed since M29, so an
old `config.php` gets an instruction instead of a bare "unknown key": for a *renamed* key the old value still feeds the new
key for two releases (unless the new key is set) and the warning names the new key and the last release that honours the old
one; for a *removed* key the warning says why it has no effect and that the line can be deleted. Today: `logs.token`
(replaced by the admin login) and `admin.ip_header` (replaced by `admin.trusted_proxies`), both removed in M39. The full list
of what each milestone added, removed, renamed or re-defaulted is [CHANGELOG.md](CHANGELOG.md). The status page's
"effective configuration" table shows every key's value (credentials masked), its source (`config.php` or code default) and
the doctor's notes beside it ([ADMIN.md](ADMIN.md#effective-configuration-and-upgrades-m40)).

It prints key paths only, never values, and exits non-zero on any ERROR, so a deploy script or CI can gate on it. `--file=<path>` checks another overrides file. `GET /health` carries the same verdict as `config: {ok, errors[], warnings[]}` (key paths and statuses only; `/health` is unauthenticated, so never values).

## Server (`config.php` overrides)

### Database and paths

| key | default | meaning |
| --- | --- | --- |
| `db.dsn` | none (required) | PDO DSN, e.g. `mysql:host=localhost;dbname=search;charset=utf8mb4` |
| `db.user`, `db.password` | none (user required), empty | credentials (secret; the password may be empty) |
| `db.products_table` | `products` | live table (the load file stages into `<table>_new`) |
| `db.search_logs_table` | `search_logs` | log table |
| `db.search_events_table` | `search_events` | click / suggestion-click events table (`POST /events`) |
| `events.allowed_origin` | empty | storefront origin for a `fetch()`-based events call (CORS); `sendBeacon` needs none |
| `paths.data` | `server/data` | active bundle (spellcheck, synonyms, aliases, keymap) |
| `paths.data_incoming` | `server/data_incoming` | staging directory for the atomic reload swap |
| `paths.storage` | `server/storage` | the owner's `aliases.json` and its `backups/` (M41); writable, never served |
| `reload.token` | empty | enables `POST /reload` (`X-Reload-Token`); set a strong random value |

### Bundle compatibility (checked on `POST /reload`, contract 3)

| key | default | meaning |
| --- | --- | --- |
| `model.normalization_version` | the code's `Normalizer::VERSION` (4) | set only to pin a version; a rules bump then needs no config edit |

The cPanel bundle carries no vectors and no model since M42 (the semantic tier's model, bge-m3, runs on the VPS); `/reload` checks the normalization version and `meta.count` against the staging rows. `model.name`, `model.revision` and `model.dim` were removed; an old `config.php` that still sets them keeps loading and the config doctor says they can be deleted ([CHANGELOG.md](CHANGELOG.md)).

### Keyword search

| key | default | meaning |
| --- | --- | --- |
| `search.default_limit` | 20 | results when the request has no `limit` |
| `search.min_token_size` | 3 | mirror the host's `innodb_ft_min_token_size`. A shorter token ("2", "rx") is never a substring scan since M44: a short number joins the word before it (`ps 4` / `ps4`), any other short token is a whole word of the title or tags ([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#short-tokens)) |
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
| `search.suggest_max_distance` | 1 | maximum edits of a "did you mean" (M43; was 2). Two edits are still allowed for a Latin token of 8+ characters whose first letter is kept (`kaybourd` → `keyboard`); on any other token a second edit jumped between unrelated words. Setting 2 here restores the old behaviour for every token over 4 characters |
| `search.suggest_blocklist` | `کالا,عدد,باب,…` | comma-separated generic words never offered as "did you mean" |
| `search.synonym_blocklist` | empty | M43: comma-separated terms (normalized like the file); a **build-derived** synonym group (`synonyms.json`) holding one is dropped at runtime, which is how a bad generated group is removed without editing build output (a rebuild would bring it back). The owner's aliases are never filtered. Part of the result-cache version, so a change invalidates cached answers |
| `search.generic_df_ratio` | 0.15 | M44: a token held by more than this share of the catalog's titles and tags (from the bundle's `token_df.json`) is **generic** (`بازی`, `کارت`): it never makes a product a keyword match by itself and adds only a small score. `0` turns the frequency rule off (the stopwords still apply). Needs a bundle built with M44; without `token_df.json` only the stopwords are generic ([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#generic-tokens)) |
| `search.stopwords` | `خرید,قیمت,مدل,نوع,یک,با,برای,و,از,buy,price,for,with,and,the` | M44: comma-separated words that are generic whatever the catalog says (normalized like the query). Keep it to words no product is named by: `play`, `one`, `pro` are product words and stay out |
| `search.query_max_chars` | 100 | longer queries are cut before the keyword, spell and VPS stages |
| `search.query_max_tokens` | 8 | likewise, in words |
| `search.result_cap` | 300 | a result list this long is logged with `is_capped = 1` |

### Hybrid ranking (semantic tier)

| key | default | meaning |
| --- | --- | --- |
| `search.semantic_top_k` | 100 | neighbours asked of the VPS (M43; was 300; its cap is `VPS_MAX_LIMIT`, 500) |
| `search.tail_cap` | 40 | M43: in a hybrid answer every solid keyword row is kept, but only this many of the other rows (semantic-only neighbours, weak keyword hits) follow, best first, before the `limit` slice (0 = no cap) |
| `search.semantic_min_score` | 0.4 | cosine floor on bge-m3's scale (sent as `min_score`, re-applied); needs tuning on the real catalog |
| `search.keyword_weight` / `search.semantic_weight` / `search.min_relevance` | 0.4 / 0.6 / 0.45 | blended relevance and its floor (M20) |
| `search.stock_boost` / `search.popularity_boost` | 0.1 / 0.1 | light boosts after the floor |
| `search.brand_match_boost` / `search.category_match_boost` / `search.tag_match_boost` | 0.15 / 0.1 / 0.1 | M21 match boosts (0 disables one) |
| `search.intent_max_terms` | `4` | most intent-map keys applied to one query ([intent map](SEARCH-BEHAVIOR.md#intent-map), M34). The mapped categories / tags use the two boosts above; there is no separate knob |
| `search.tag_match_min_tokens` | 2 | shortest query that phrase-matches tags |
| `search.entities_enabled` | 1 | M44: master switch of brand / platform recognition ([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#entities)). Needs the bundle's `entities.json`; without it nothing is recognized |
| `search.entity_boost` | 0.15 | M44: a candidate carrying the brand / platform the query names is lifted by this factor (`1 + boost` per dimension), after the floor |
| `search.entity_demote` | 0.10 | M44: a candidate carrying another brand / platform is lowered by this factor (`1 - demote` per dimension); nothing is removed. Both knobs at `0` (or `entities_enabled=0`) disable the feature, including the loss of the floor exemption |
| `search.latency_budget_ms` | 800 | the documented server-side latency target per request (M43; was 200). **The runtime never enforces it**: nothing is cut or timed out at this number; it is a target for the go-live measurements and a reference value, not a limit. The CI latency guards keep their own fixed 200 ms bar |

### VPS client (`vps` section)

| key | default | meaning |
| --- | --- | --- |
| `vps.url` | empty | base URL (the client appends `/search-vectors`); empty = keyword-only |
| `vps.token` | empty | the VPS's `VPS_TOKEN` (secret) |
| `vps.reload_timeout_ms` | 60000 | timeout of the admin panel's "load the uploaded vectors" call (`POST {vps.url}/reload`), which loads and checks a whole catalog |
| `vps.timeout_ms` | 600 | whole-call budget (M43; was 300, which left too little for a loaded VPS; 600 leaves room for the keyword stage and the database inside the 800 ms target), connect included. A slow or unreachable VPS means keyword-only results for that request, never an error. The default is sized for an external VPS; on the final LAN VM it is a safety net, keep it sane |

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
| `redis.ttl` | `86400` (1 day) | seconds an entry lives; a catalog reload flushes it earlier |
| `redis.ttl_zero` | `300` | TTL of an answer with no results (an alias or reload may fix it soon) |
| `redis.ttl_hot` | `604800` (7 days) | TTL of a frequently repeated query (see below) |
| `redis.ttl_hot_threshold` | `20` | lookups (searches) of the same query inside `redis.freq_window` that make it hot; `0` disables the adaptive tier (always `redis.ttl`) |
| `redis.freq_window` | `604800` (7 days) | lifetime of the per-query counter, set once when the counter is created (its first lookup), so counts roll off |
| `redis.timeout_ms` | `100` | connect + read budget; a slow or dead Redis costs at most this per request and means a cache miss, never an error |
| `redis.prefix` | `search:cache:` | key prefix; the keyspace `POST /reload` flushes. Give each environment sharing one Redis its own prefix |

Entries are keyed by the normalized query, the `limit` and whether the semantic tier is configured. Responses
with `debug` are never cached, and neither is a result served without the semantic tier that is configured (a
slow or failing VPS), so a degradation never outlives its request. After changing any ranking setting, flush by
hand (see [DEPLOY.md](DEPLOY.md#result-cache-optional-redis-m26)) or wait out the TTL.

**Adaptive TTL (M33).** Every cache lookup (each `/search` that reaches the cache, hit or miss) bumps a
counter `<redis.prefix>freq:<hash>` (same hash as the result key) in Redis; counting lookups, not writes,
because writes are rationed by the TTL itself. When an entry is written and its counter is
`redis.ttl_hot_threshold` or more, it gets `redis.ttl_hot` instead of `redis.ttl`. One hot tier only; no database lookup. A counter error is silent and means the base TTL.
The effective lifetime is still bounded by the next `POST /reload`, which flushes the whole prefix
(results **and** counters), so the 7 days rarely bite. Long TTLs are safe: the cache holds product ids
only, and price / stock are rendered downstream by OpenCart; the only staleness is a newly added product
missing until the TTL or the next reload.

### Tooling and storefront

| key | default | meaning |
| --- | --- | --- |
| `debug.token` | empty | enables `"debug": 1` on `/search` (`X-Debug-Token`), see [SEARCH-API.md](SEARCH-API.md#search-logs-and-ranking-debug) |
| `logs.token` | empty | **deprecated (M39)**: unused, the logs and analytics pages need the admin login; the doctor warns while it is set |
| `logs.page_size` | 50 | default rows per page of the panel's logs page (the page also offers 25 / 50 / 100 / 200) |
| `logs.scan_limit` | 200000 | the logs page's search and unindexed sorts read this many of the newest rows (0 = all); the page says when older rows were left out |
| `logs.refresh_seconds` | `30` | how often the analytics dashboard re-fetches its JSON (M35); `0` disables the auto-refresh |

### Admin panel section (M39)

The panel (`admin.php`, [ADMIN.md](ADMIN.md)) needs `admin.username` and `admin.password_hash`; without both it
answers 503. Plaintext passwords are not accepted: make the hash with `php server/tools/admin-password.php`. The doctor
reports an unset admin config as a warning and a half-set or non-hash one as an error (key paths only).

| key | default | meaning |
| --- | --- | --- |
| `admin.username` | empty | the login name (compared with `hash_equals`) |
| `admin.password_hash` | empty | a `password_hash()` value (checked with `password_verify`) |
| `admin.allowed_ips` | empty | comma separated IPs and CIDR ranges (IPv4 / IPv6) that may reach the panel at all; empty = any address |
| `admin.idle_timeout_minutes` | 30 | a session with no page view for this long ends (background JSON polls do not count) |
| `admin.session_hours` | 8 | absolute session lifetime, however active |
| `admin.login_max_failures` | 5 | failed logins per address in the window before that address is refused |
| `admin.login_max_failures_global` | 50 | failed logins from all addresses in the window before everyone is refused |
| `admin.login_window_minutes` | 15 | the throttling window |
| `admin.audit_retention_days` | 180 | `admin_audit` rows older than this are deleted on write |
| `admin.trusted_proxies` | empty | comma separated IPs / CIDR of reverse proxies whose `X-Forwarded-For` is believed (read from the right); empty = the client is `REMOTE_ADDR` and the header is ignored |
| `admin.trust_forwarded_proto` | false | accept `X-Forwarded-Proto: https` as HTTPS (TLS ended at a proxy) |
| `admin.probe_timeout_ms` | 1000 | timeout of the status page's VPS `/health` and Redis probes |
| `admin.alias_max_term_chars` | 64 | longest alias term (M41); longer is an error |
| `admin.alias_warn_matches` | 500 | an alias term matching more products than this needs a confirm (M41) |
| `admin.alias_backups` | 20 | newest alias backups kept (M41) |
| `db.admin_audit_table` | `admin_audit` | the audit table ([migration](../db/migrations/2026-10-25_admin_audit.sql)) |
| `storefront.store_base`, `storefront.image_base` | empty | absolute bases for `with_details` product links and images; empty keeps the exported values |

`search_logs` is never pruned automatically (no daemon on cPanel); an optional operator-run prune is in [DEPLOY.md](DEPLOY.md#upgrading-to-m31).

The test page's `PRICE_SUFFIX` is a constant in `server/public/test.html`, not a server setting.

## Pipeline (build machine)

| env | default | meaning |
| --- | --- | --- |
| `EMBEDDER` | `mock` | `mock` (deterministic, no model download; tests) or `real`. `release.py` embeds the VPS vectors for real regardless |
| `SEARCH_NORMALIZATION_VERSION` | the code's | stamped into the cPanel bundle's `meta.json`; must match `config.php`'s `model.normalization_version` (code default; pin it only to override). `SEARCH_MODEL*` and `SEARCH_BUNDLE_EMBEDDER` were removed in M42 |
| `SEARCH_VPS_MODEL` | `BAAI/bge-m3` | model of the vector service |
| `SEARCH_VPS_MODEL_REVISION` | `5617a9f61b028005a4858fdac845db406aefb181` | pinned revision |
| `SEARCH_VPS_MODEL_DIM` | 1024 | dimension |
| `SEARCH_VPS_POOLING` | `cls` | pooling; must equal the VPS's `VPS_POOLING` |
| `SEARCH_VPS_QUERY_PREFIX`, `SEARCH_VPS_PASSAGE_PREFIX` | empty | bge-m3 dense retrieval takes no instruction |
| `SEARCH_EMBED_DEVICE` | `auto` | `auto` (cuda when torch sees a GPU, else cpu), `cpu`, `cuda` or `cuda:N` |
| `SEARCH_EMBED_FP16` | `auto` | `auto` = on for CUDA only; `on` / `off` |
| `SEARCH_EMBED_BATCH_SIZE` | 16 | fits bge-m3 on 4 GB; lower it (8, 4) on a CUDA out-of-memory error |
| `SEARCH_DESC_CHAR_LIMIT` | 300 | description characters in the embedded passage |
| `SEARCH_PASSAGE_CHAR_LIMIT` | 1000 | cap on the whole embedded passage (0 = none) |
| `SEARCH_DESC_INDEX_CHARS` | 800 | leading description characters keyword-indexed (0 = whole); `release.py --desc-index-chars` overrides. Build-time only |
| `SEARCH_ALIASES_FILE` | `pipeline/aliases.json` | the owner's [alias file](SEARCH-BEHAVIOR.md#aliases); `release.py --aliases` overrides |
| `SEARCH_INTENT_MAP_FILE` | `pipeline/intent_map.json` | the owner's [intent map](SEARCH-BEHAVIOR.md#intent-map) (M34); `release.py --intent-map` overrides |
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
