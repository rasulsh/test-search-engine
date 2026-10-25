# Changelog

What an upgrade needs from `config.php`, milestone by milestone, newest first. Only the **Config changes** are
tracked here; the behaviour of each milestone is in [ARCHITECTURE.md](ARCHITECTURE.md) and the upgrade steps in
[DEPLOY.md](DEPLOY.md). Every PR that adds, removes, renames or re-defaults a config key adds a "Config changes"
entry here (and, for a removed or renamed key, a line in `App\Config::deprecated()`).

How to read an entry: **added** keys are optional, the default applies until you override them (`config.php` only
holds overrides). A **renamed** key keeps working for two releases (its value is used for the new name) and the
config doctor / status page warn with the new name. A **removed** key is ignored; the doctor says why and that the
line can be deleted. `php server/tools/config-check.php` lists all of it.

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
