# Changelog

What an upgrade needs from `config.php`, milestone by milestone, newest first. Only the **Config changes** are
tracked here; the behaviour of each milestone is in [ARCHITECTURE.md](ARCHITECTURE.md) and the upgrade steps in
[DEPLOY.md](DEPLOY.md). Every PR that adds, removes, renames or re-defaults a config key adds a "Config changes"
entry here (and, for a removed or renamed key, a line in `App\Config::deprecated()`).

How to read an entry: **added** keys are optional, the default applies until you override them (`config.php` only
holds overrides). A **renamed** key keeps working for two releases (its value is used for the new name) and the
config doctor / status page warn with the new name. A **removed** key is ignored; the doctor says why and that the
line can be deleted. `php server/tools/config-check.php` lists all of it.

## VPS call log and the VPS page

- **Config changes**: **added** `vps.log_retention_days` = `30` (days the `vps_calls` rows are kept; editable on the
  settings page, 1 to 365). Nothing removed, renamed or re-defaulted. The table name is fixed (`vps_calls`, no `db.*` key).
- **Behaviour**: every PHP -> VPS call (a `/search` cache miss, the panel's "load vectors", a probe) is recorded in the
  new `vps_calls` table with its outcome and timings; `?p=vps` shows summary, hourly strips, incidents and the call list
  ([ADMIN.md](ADMIN.md#vps-page)). `tools/vps-probe.php` is the once-a-minute probe for cron. `/search` results, ranking and
  cache keys are unchanged; the existing `error_log` line is unchanged. The VPS service gains request-id echo,
  `Server-Timing`, `X-Vps-Inflight` headers and one journal line per request (old and new sides work with each other).
- **After deploying**: see [DEPLOY.md](DEPLOY.md#upgrading-to-the-vps-call-log): apply `db/migrations/2026-11-03_vps_calls.sql`,
  upload the listed `server/` files, add the cron line; copy `vps/search_vectors/app.py` to the VPS and restart it.

## Evaluation depth and golden-set labeling helper

- **Config changes**: none (no key added, removed, renamed or re-defaulted). `/search` and every ranking default are unchanged.
- **Behaviour**: the admin tester page and `tools/eval/run_eval.php` ask for the OpenCart storefront's `limit` (300,
  `GoldenEval::STOREFRONT_DEPTH`) and cut the list to the first 10 / 50 (tester) or k (evaluation) afterwards. Saved runs
  record `depth`; `--compare` refuses runs of different depth. New: `tools/eval/make_candidates.php` and
  `finish_golden.php` (repo checkout only, [EVALUATION.md](EVALUATION.md)).
- **After deploying**: upload `server/public/admin.php`, `server/src/AdminAliasViews.php`, `server/src/AdminStrings.php`,
  `server/src/GoldenEval.php` (the `tools/eval/*` scripts and `server/src/GoldenLabeling.php` are not needed on cPanel). No
  migration, no bundle rebuild.

## Settings page: per-row Apply

- **Config changes**: none (no key added, removed, renamed or re-defaulted; no change to `settings.json`'s format or to any
  rule or default).
- **Behaviour**: the settings page can apply one setting on its own (JavaScript, `POST ?p=settings`
  `action=settings_apply_one`, JSON answer), with a sticky action bar, collapsed groups and a "differs from default" filter.
  Same validation and save path as "Save all"; see [ADMIN.md](ADMIN.md#settings-page-m46).
- **After deploying**: upload the changed files (listed in the PR). No migration, no bundle rebuild, no `config.php` edit.

## Status page: alias count (fix)

- **Config changes**: none (no key added, removed, renamed or re-defaulted).
- **Behaviour**: the status page's "aliases (manual)" group count reads the file the search uses, `storage/aliases.json`,
  and falls back to the bundle's `aliases.json` only while storage is not seeded (as `Synonyms` does). Before, it read only
  the bundle file, so it showed "file unreadable" once bundles stopped shipping one (M41). The build-derived synonyms count
  is unchanged.

## Settings page (M46)

- **Added**: `admin.settings_backups` = `20` (newest backups of `storage/settings.json` kept; same role as `admin.alias_backups`).
- **Removed / renamed**: none. **Changed defaults**: none (no default of any key changed).
- **New layer, not a key**: `server/storage/settings.json` (or the path in the environment variable `SEARCH_SETTINGS_FILE`),
  written only by the admin panel's settings page. Precedence: code defaults < `config.php` < `settings.json`. It is read
  for editable keys only (see [ADMIN.md](ADMIN.md#settings-page-m46)); credentials, `db.*`, `paths.*`, `admin.*`,
  `reload.*` and `model.normalization_version` in it are ignored and reported. `config.php` is never rewritten.
  The status page's source column, `/health` (`config` section) and `config-check.php` (flag `--no-panel` for `config.php`
  alone) describe the merged result.
- **After deploying**: upload the changed files; `server/storage/` must be writable by the PHP user. No migration, no bundle
  rebuild, no `config.php` edit. To go back to `config.php` + defaults, delete `server/storage/settings.json`.

## Brand recognition: ambiguous brand words

- **Added**: `search.brand_blocklist` = `''` (comma-separated normalized names, like `search.synonym_blocklist`): a listed name is
  never recognized as a brand in a query. Use it for a manufacturer name the catalog also uses as an ordinary word (it
  shows on the status page's effective configuration). Part of the result-cache version, so changing it invalidates cached
  answers on its own.
- **Removed / renamed**: none. **Changed defaults**: none.
- **Behaviour** (no key): `entities.json` is version 2 and flags `"ambiguous": true` on a brand that is a colour / common
  word seed or that other brands' titles and tags name often; the server reads version 1 files too (no flags) and `/reload`
  accepts both. An ambiguous brand is recognized only when the whole query (stopwords aside) is that brand. `brand_unmatched`
  is now judged per brand: a brand is unmatched only if no candidate carries it (brand field) or names it (whole-word phrase
  in title, tags or category). Boost / demotion are unchanged.
- **After deploying**: rebuild the bundle to get the flags (optional; an old file keeps working), `/reload`, flush the result cache.

## Analytics: what counts as a search

- **Added**: `log.duplicate_seconds` = `120`: the repeat-submit window of `search_logs.is_duplicate` (the same session, normalized
  query and page). It was a fixed 30 s; `0` turns the flag off.
- **Removed / renamed**: none.
- **Changed defaults**: none (the window above was not configurable before).
- **Database**: `search_logs.is_burst` (`db/migrations/2026-10-31_search_logs_is_burst.sql` with a `.down.sql`; the logger also
  adds it when it may ALTER). Set on the 4th and later identical page-1 rows of one session (without a session: one
  normalized query) within 60 s; `is_suspicious` keeps meaning SQL-probe text.
- **Behaviour**: the analytics count a row as a search only when `is_duplicate = 0 AND page = 1 AND source <> 'filter' AND
  is_burst = 0` (one definition, `App\SearchRows`, used by `Analytics` and `AnalyticsInsights`, the zero-rate trend
  included); a condition whose column the table lacks is left out. The raw log list is unchanged. The analytics JSON gains
  the additive key `requests` (`{requests, searches, filtered}`), shown as a tile on the dashboard.

## Keyboard-layout recovery

- **Config**: no key added, removed, renamed or re-defaulted.
- **Behaviour**: the layout map gains `;` `'` `,` `[` `]` `\` and Shift+C; Arabic ي / ك are unified first; digits no longer
  disable the remap (letter runs are remapped, digits kept); a failed remap is followed by one spelling pass on the remapped
  text; an applied correction is what the VPS embeds. `keymap.json` in the bundle now lists 33 keys; rebuild to refresh it
  (the server does not read that file).

## M45: used / new condition and brand-mention fix

- **Added**: `search.condition_enabled` = `true`: the used / new condition logic (it reuses `search.entity_boost` /
  `search.entity_demote`). `false` disables only this logic.
- **Removed / renamed**: none.
- **Changed defaults**: none.
- **Behaviour** (no other key):
  - `entities.json` gains the optional `used` key (sorted product ids whose name or `meta_title` says `کارکرده` /
    `کار کرده`); the version stays 2, a file without the key is valid and means no condition data. `/reload` validates
    the key when present (`invalid_entities`).
  - A query asking for used (`کارکرده`, `کار کرده`, `دست دوم`, `used`, `second hand`, `secondhand`) lifts used products and
    lowers the others; `آکبند` / `sealed` and a query with no condition word lower the used ones. Nothing is removed.
  - A candidate of another brand field that mentions the recognized brand in its title, tags or category is neutral,
    no longer demoted (the products filed under the manufacturer "سایر").
- **After deploying**: build the bundle (adds `used` and the ambiguous flags), upload it, `/reload`, flush the result
  cache, check the status page (`search.condition_enabled` in the effective configuration).

## M44: query understanding and evaluation

- **Added**:
  - `search.generic_df_ratio` = `0.15`: a token in more than this share of the catalog's titles and tags is generic (it can
    no longer qualify a product by itself). `0` = frequency rule off.
  - `search.stopwords` = `خرید,قیمت,مدل,نوع,یک,با,برای,و,از,buy,price,for,with,and,the`: generic whatever the catalog says.
  - `search.entities_enabled` = `true`, `search.entity_boost` = `0.15`, `search.entity_demote` = `0.10`: brand / platform
    recognition. The boost and the demotion both at `0` (or the switch off) disable it.
- **Removed / renamed**: none.
- **Changed defaults**: none.
- **Behaviour** (no key; some of it needs the new bundle):
  - A token shorter than `search.min_token_size` is no longer a `LIKE '%token%'` scan over title, specs and description:
    a number joins the word before it, any other short token is a whole word of the title or tags, `match_type` `like`
    is gone (`title` for a query of short tokens only, `intent` for an intent-map answer).
  - The bundle gains `token_df.json` and `entities.json` and `meta.json` gains `token_df_version` / `entities_version`.
    Both files are optional; `/reload` rejects a present one that is invalid (`invalid_token_df`, `invalid_entities`).
  - `search_logs.brand_unmatched` (`db/migrations/2026-10-28_search_logs_brand_unmatched.sql`, also added by the
    logger itself when it may ALTER) and the analytics JSON keys `brand_unmatched` and `silent_failures[].top_id` /
    `top_title`.
  - "Did you mean" corrects the word of a model name (`redeption2` -> `redemption2`) and never drops a digit.
- **After deploying**: build and upload the bundle, `/reload`, flush the result cache (the cache key does not carry
  these settings), check the effective configuration on the status page, run the log migration if the logger's user may
  not ALTER. See [DEPLOY.md](DEPLOY.md).

## M43: off-topic hygiene, tracking and ranking tweaks

- **Added**:
  - `search.tail_cap` = `40`: in a hybrid answer every solid keyword row is kept but only this many of the other rows
    (semantic-only neighbours, weak keyword hits) follow, before the `limit` slice. `0` = no cap.
  - `search.synonym_blocklist` = `''` (comma-separated normalized terms, like `search.suggest_blocklist`): a
    **build-derived** synonym group holding one is dropped at runtime; owner aliases are never filtered. It is part of
    the result-cache version, so changing it invalidates cached answers on its own.
- **Changed defaults** (an override in `config.php` still wins; delete it to take the new value):
  - `search.suggest_max_distance` `2` -> `1`. Two edits remain possible only for a Latin token of 8+ characters that keeps
    its first letter.
  - `search.semantic_top_k` `300` -> `100`.
  - `search.latency_budget_ms` `200` -> `800`. A documented target only: the runtime never enforces it, and the CI latency
    guards use their own fixed 200 ms bar.
  - `vps.timeout_ms` `300` -> `600` (room for the keyword stage and the database inside the 800 ms target).
- **Removed / renamed**: none.
- **Behaviour** (no key):
  - `/search` sends the VPS the **normalized** query, not the raw text (same results for spellings that normalize alike).
  - Cart and order tracking are gone: `POST /events` accepts only `click` and `dym_click` (cart / order -> `400`), the
    analytics JSON loses `engagement.cart_rate` and `engagement.order_rate`, and the zero-rate trend now counts only unique
    first-page searches and carries `n` (replaces `searches`), `zero_rate_ma`, `low_confidence` and `partial`. No migration:
    old `cart` / `order` rows may stay in `search_events`, every query filters on `action IN ('click','dym_click')`.
  - Letter + digit names (`gta6` / `gta 6`, `fc27`, `rtx4060`) meet their other spelling; no normalization change.
  - `search_logs` stores a masked form of queries that look like personal data (7+ digits in a row become `#`; a query over
    80 characters becomes `[long query]`); flagged probes stay verbatim.
- **After deploying**: clear the result cache (admin panel, operations page) and check the effective configuration on the
  status page.

## M42: no vectors on cPanel, panel polish

- **Added**: `logs.scan_limit` = 200000. The logs page's keyword search and its sorts on columns without an index read at most this
  many of the newest rows (0 = all), so one click cannot scan a multi-million-row `search_logs` on the shared host; the page
  says when older rows were left out. Newest-first and by-time views always cover every row. The rows-per-page default is the
  existing `logs.page_size` (50); the aliases page defaults to 50 per page with no key.
- **Removed** (ignored; the config doctor says they can be deleted from `config.php`):
  - `model.name`, `model.revision`, `model.dim`: the cPanel bundle carries no vectors any more, so there is no model or
    dimension to check on `/reload`. The semantic model lives on the VPS (`VPS_MODEL*`).
  - Their pipeline counterparts in `.env`: `SEARCH_MODEL`, `SEARCH_MODEL_REVISION`, `SEARCH_MODEL_DIM` and
    `SEARCH_BUNDLE_EMBEDDER` (the pipeline ignores them; delete them).
- **Kept**: `model.normalization_version` (still checked on `/reload`, still defaults to the code's version).
- **Renamed / re-defaulted**: nothing.
- **Behaviour**: `/reload` (and the panel's validate / apply) compares `meta.count` with the staging rows; a bundle that
  still ships `vectors.bin` / `vectors.idx` keeps its old checks (index lines, size from `meta.dim`, checksum) during the
  transition. The installer form lost its model name and dimension fields.

## M41: alias manager and synonyms viewer

- **Added**:
  - `paths.storage` = `<app base>/storage` (the owner's `aliases.json` and its `backups/`; writable by the PHP user,
    never served: `storage/.htaccess` ships in `release.zip`).
  - `admin.alias_max_term_chars` = `64`, `admin.alias_warn_matches` = `500` (a term that alone matches more products
    than this needs a confirm), `admin.alias_backups` = `20` (newest backups kept).
- **Removed / renamed / changed defaults**: none. The build no longer reads or ships aliases, so
  `SEARCH_ALIASES_FILE` / `build.aliases_file` only feed `gen_aliases.py --merge` and seed new installs.
- **Upgrade**: no action needed. The first visit to `?p=aliases` creates `storage/aliases.json` from the live bundle's
  `aliases.json`; until then the search reads the bundle's file. `/reload` no longer validates a bundled
  `aliases.json` (`invalid_aliases` is gone) and never touches storage.

## M40: operations page, config upgrade safety

- **Added**: `vps.reload_timeout_ms` = `60000` (timeout of the panel's "load the uploaded vectors on the VPS" call).
- **Removed / renamed / changed defaults**: none. (`App\Config::deprecated()` now exists: it lists `logs.token` and
  `admin.ip_header`, both removed in M39; nothing was renamed since M29.)
- **New behaviour of existing files** (no key): the doctor reports renamed / removed keys with their replacement or reason
  instead of "unknown key"; the status page lists the effective configuration; `POST /reload` answers `409 reload_busy`
  when another reload or rollback holds `server/reload.lock`; the CLI tools stop with a clear message on PHP older than 8.1.

## M39: admin panel (login, status, audit)

- **Added** (see [ADMIN.md](ADMIN.md), [CONFIGURATION.md](CONFIGURATION.md#admin-panel-section-m39)):
  - `admin.username` = `''` and `admin.password_hash` = `''`: both are needed, otherwise `admin.php` answers 503.
    Make the hash with `php server/tools/admin-password.php`; a plaintext password is not accepted.
  - `admin.allowed_ips` = `''` (any address), `admin.trusted_proxies` = `''` (the client address is `REMOTE_ADDR`),
    `admin.trust_forwarded_proto` = `false`.
  - `admin.idle_timeout_minutes` = `30`, `admin.session_hours` = `8`, `admin.login_max_failures` = `5`,
    `admin.login_max_failures_global` = `50`, `admin.login_window_minutes` = `15`, `admin.audit_retention_days` = `180`,
    `admin.probe_timeout_ms` = `1000`.
  - `db.admin_audit_table` = `admin_audit` (create it with `db/migrations/2026-10-25_admin_audit.sql`).
- **Removed**:
  - `logs.token` (env `SEARCH_LOGS_TOKEN`): the logs and analytics pages sit behind the admin login; `analytics.php`
    and `logs.php` only redirect. Delete the key.
  - `admin.ip_header`: added and dropped inside M39 before any release note; X-Forwarded-For is no longer trusted by
    default. If you set it, replace it with `admin.trusted_proxies` (the proxy's own address, not a header name).
- **Renamed**: none. **Changed defaults**: none.

## M38: Persian analytics dashboard

- No config changes.

## M37: search fixes round

- **Added**:
  - `search.query_max_chars` = `100` and `search.query_max_tokens` = `8` (a longer query is cut before the spell
    correction and the VPS call), `search.result_cap` = `300` (a result list this long counts as capped in the log).
  - `search.suggest_blocklist` = `کالا,عدد,باب,محصول,مدل,نوع,سری,اصل,جدید,قیمت,خرید,فروش` (generic words never offered as
    "did you mean"; comma separated, normalized).
  - `redis.ttl_zero` = `300` (seconds a zero-result answer stays in the result cache).
  - `db.search_events_table` = `search_events` (create it with `db/migrations/2026-10-24_search_logs_v2.sql`).
  - `events.allowed_origin` = `''` (origin allowed to call `POST /events` with `fetch()`; `sendBeacon` needs none).
- **Changed defaults**: `model.normalization_version` follows the code: `3` → `4` (a bundle built before M37 is
  rejected by `/reload` until it is rebuilt).
- **Removed / renamed**: none.
