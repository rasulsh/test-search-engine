# Deploy and update runbook

Everything to put a build on the servers, update it, upgrade across milestones, roll back, and sign off before going live. To produce the build artifacts (`release.zip` for cPanel, `vps_vectors/` for the VPS) use [TRAINING-COLAB.md](TRAINING-COLAB.md); the export that feeds it is described in [PIPELINE.md](PIPELINE.md#exporting-from-opencart-db_exportpy); the VPS side is [`vps/README.md`](../vps/README.md).

For the quick step-by-step version (commands only), see [runbook.md](runbook.md).

## Deploy / update runbook

The runbook has four machines' worth of steps. The **OpenCart database**
provides the export. A **GPU machine**, in practice a free Colab T4
([TRAINING-COLAB.md](TRAINING-COLAB.md)), builds the release. The **cPanel
host** serves it and the **vector service VM** (the VPS, finally a self-hosted
VM on the owner's ESXi server on the LAN) holds the semantic vectors. Nothing
on cPanel runs as a daemon or needs root.
Placeholders: `shop.example.com`, `~/search-service`, database `cpuser_search`.
Replace them with yours.

### Layout

`server/public/` is, by design, the web document root (a front-controller layout).
Everything else in `server/` (`bootstrap.php`, `src/`, `config.php`,
`config.example.php`, `data/`, `data_incoming/`, `data_old/`, `tools/`, `db/`)
sits **one level above** `public/` and must stay out of the web root: `config.php`
holds the database password and the tokens, and `data/products.load.sql` is the
whole catalog. The endpoints find the app through `public/app_base.php`: the
environment variable `SEARCH_APP_BASE` if set, else the parent of `public/`
(`dirname(__DIR__)`). So `public/` must stay a **direct child of `server/`**
(the default), or `SEARCH_APP_BASE` must name the app folder; it does not have
to be literally `public_html`.

```
~/search-service/                  not web-accessible
└── server/                        the app base (release.zip extracts here)
    ├── bootstrap.php  src/  tools/  db/
    ├── config.php                 yours; never in the zip, never web-accessible
    ├── config.example.php
    ├── data/  data_incoming/  data_old/
    ├── .htaccess                  safety net: Require all denied
    └── public/    <──────────── the document root points HERE
        ├── index.php  search.php  health.php  reload.php  admin.php
        ├── logs.php  analytics.php    (redirects to admin.php since M39)
        ├── app_base.php  .htaccess  test.html (optional)
        └── install.php            first setup only: delete it right after
```

**In the web root:** only the contents of `server/public/`. **Never in the web
root:** `config.php`, `config.example.php`, `bootstrap.php`, `src/`, `data/`,
`data_incoming/`, `data_old/`, `tests/`, `tools/`, `vendor/` or the rest of the
repository.

Two ways to serve it on cPanel:

1. **Preferred: a subdomain or addon domain** (for example `search.example.com`).
   cPanel, Domains: set the Document Root to `~/search-service/server/public`.
   Only `public/` is served; the rest is a sibling above it. No path edits, no
   `SEARCH_APP_BASE`. The storefront then calls `https://search.example.com/search`
   (the `.htaccess` routes `/search`, `/health`, `/reload`, `/logs` to `index.php`).
2. **Fallback: a subfolder of a site with a fixed `public_html`** (for example
   `example.com/search-api`). Keep the app above the web root
   (`~/search-service/server/`) and expose only the public files:
   - with SSH, a symlink keeps updates automatic and needs nothing else:
     `ln -s ~/search-service/server/public ~/public_html/search-api`;
   - without SSH (File Manager cannot make symlinks), copy only the files of
     `server/public/` into `public_html/search-api/` (after every extract too),
     and tell them where the app is, in `public_html/search-api/.htaccess`:
     `SetEnv SEARCH_APP_BASE /home/cpuser/search-service/server`.
     Do **not** copy `src/`, `config.php` or `data/` into `public_html`.

The storefront must reach the service on the **same origin** (no CORS support);
see [INTEGRATION.md, Deployment shape](../INTEGRATION.md#deployment-shape). A
wrong `SEARCH_APP_BASE` (or a `public/` moved out of `server/`) answers `500
app_base_not_found` instead of a raw include error.

The `.htaccess` files in the release (LiteSpeed and Apache honour them) are a
**safety net, not a substitute for the layout**: `server/.htaccess`,
`server/data/.htaccess` and `server/data_incoming/.htaccess` deny everything, so a
tree misplaced under the web root does not leak `config.php`, `src/` or the
bundle; `public/.htaccess` re-opens `public/`, denies dotfiles, sets the same
security headers the admin panel sends and routes the extensionless endpoints. They
are ignored by a host that does not read `.htaccess` (an nginx front), so verify
with the checks in the [go-live checklist](#production-go-live-checklist).

### Command-line tools

`server/tools/` holds `config-check.php` (the config doctor), `logs-export.php` and `admin-password.php`. Run them
**as the account user, not as root**, with the **PHP 8.1+ binary the site uses**:

```bash
su - search -c "cd ~/search-service/server && /opt/cpanel/ea-php82/root/usr/bin/php tools/config-check.php"
```

(`search` is the cPanel account; the PHP path is the account's PHP version under `/opt/cpanel/`.) Root would create
files the web server's user cannot read or replace (a log export, a rotated config), and the host's default `php`
is often older than the site's. Every tool checks `PHP_VERSION_ID >= 80100` first (`tools/_php-guard.php`, shipped
in `release.zip`) and, on an older PHP, prints a one-paragraph English message and exits with status 1 instead of a
fatal error from inside `src/`. A PHP older than 7.0 cannot even parse the tools; use the explicit path then.

### One-time setup (cPanel host)

The first deploy is: upload the zip, extract it, open `install.php`, fill in
the form. No config file editing, no separate model upload, no SSH needed.

1. **Create the database.** In cPanel, open MySQL Databases. Create a database
   and a user, and grant the user ALL privileges on that database. `RENAME
   TABLE`, `DROP` and `CREATE` are needed by the reload. The installer creates
   the tables.
2. **Build the release** (step 2 of [Every catalog update](#every-catalog-update);
   on Colab: [TRAINING-COLAB.md](TRAINING-COLAB.md)), with the VPS vectors from
   the same export. This one command is all the first deploy needs:
   ```bash
   python pipeline/release.py --csv export.csv --out release.zip
   ```
   Set up the VPS and load `./vps_vectors` there as described in
   [`vps/README.md`](../vps/README.md) (it can also come later: until then,
   search is keyword-only).
3. **Upload and extract** `release.zip` into `~/search-service/server/`,
   outside `public_html` (File Manager: Upload, then Extract). It unzips to
   `server/{public,src,bootstrap.php,config.example.php,...}` with `public/` as a
   child. Do not move `public/` out; point the document root into it, as in
   [Layout](#layout) (a subdomain's Document Root, or the subfolder symlink /
   copy + `SEARCH_APP_BASE`).
4. **Open `https://shop.example.com/search-api/install.php`** (with a subdomain: `https://search.example.com/install.php`) and fill in the
   form (Persian, RTL):
   - database host (usually `localhost`), optional port, name, user, password;
   - the reload token: pre-filled with a fresh random value. **Copy it before
     submitting**; later updates need it and the installer never shows it again
     (it is stored in `config.php`);
   - the normalization version (since M42 the form has no model name or dimension: the
     cPanel bundle carries no vectors): its default is the code's own and follows later
     releases; another number pins it, and a mismatch with the staged bundle is rejected
     before anything is written;
   - `STORE_BASE` / `IMAGE_BASE`: absolute store and image URLs (for example
     `https://shop.example.com/` and `https://shop.example.com/image/`). With
     them, `with_details` results carry absolute product links and images;
     leave them empty to keep the exported values as they are;
   - the VPS URL and token (the VPS's `VPS_TOKEN`) and its timeout in ms
     (default 300): leave the URL empty for keyword-only search until the
     VPS is up;
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
5. **Delete `install.php` now.** The installer's last page says so too. Once
   `config.php` exists it refuses to do anything (`403`, "already installed"), so
   a later release that re-extracts it is harmless, but removing it keeps the
   surface small; also delete it from `public_html/search-api/` if you copied the
   public files. Then run `php tools/config-check.php` in `~/search-service/server`
   and check
   `curl -sS https://shop.example.com/search-api/health.php`: `product_count`
   equal to the catalog size and `config.ok` true. Set `search.min_token_size` in
   `config.php` if the host's `innodb_ft_min_token_size` is not 3
   (`SHOW VARIABLES LIKE 'innodb_ft_min_token_size'`).
6. **Storefront.** Install the storefront snippet through the OpenCart module
   ([INTEGRATION.md, Storefront reference](../INTEGRATION.md#storefront-reference)).
   It sends only the query; nothing model-related is uploaded to the store.

**Without the installer** (or to change settings later): `config.php` is a
copy of `config.example.php` holding only your overrides: the database, tokens,
VPS and storefront values, plus any tunable you change (add its key; every key
and default is in [CONFIGURATION.md](CONFIGURATION.md#the-layered-server-config)).
Anything not listed uses the code default. The example reads the connection
settings and secrets from `SEARCH_*` environment variables (`.env.example`) where
the host supports them. Run `php tools/config-check.php` afterwards. Import
`db/schema.sql` yourself in that case.

### Every catalog update

The whole update is three commands: export, `release`, and one `curl` after
unzipping on the host. Unzip into `~/search-service/server/` (the layout of
[Layout](#layout) is unchanged: `public/` stays a child of `server/`).

```
OpenCart DB --(1) export.csv--> GPU machine (Colab T4) --(2) release.zip + vps_vectors/--> cPanel host + VPS --(3) unzip + curl reload.php?load=1 (and the VPS /reload)
```

**1. Export** from OpenCart: see [PIPELINE.md](PIPELINE.md#exporting-from-opencart-db_exportpy) (`python3 pipeline/db_export.py --out export.csv`, run where the database is local).

**2. Build the release** on a GPU machine (Python 3.11+), from the repo root.
The step-by-step Colab version, with the row-count verification and the
download of both artifacts, is [TRAINING-COLAB.md](TRAINING-COLAB.md); the bare
commands are:

```bash
pip install -r pipeline/requirements.txt 'sentence-transformers>=2.2'   # once
python pipeline/release.py --csv export.csv --out release.zip
# -> Embedder: BAAI/bge-m3 on cuda, fp16=on, batch_size=16   (progress bar follows)
# -> Built VPS vectors in <dir>/vps_vectors: <count> products, BAAI/bge-m3, dim 1024, embedder real
# -> Built release.zip: <count> products, <n> files
# -> (then the copy-paste deploy commands for both hosts)
```

The release carries no browser model (M18); `--no-model` is still accepted and
does nothing. One run builds both artifacts from the same export (tags, brand
and category in both): `release.zip` for cPanel and, since M21 by default, the
VPS's bge-m3 product vectors in `vps_vectors/` next to the zip (`--vps-out DIR`
moves them, `--no-vps` skips them). Upload and reload the vectors on the VPS
([`vps/README.md`](../vps/README.md), "Updating the product vectors") together
with the cPanel deploy below, so both hosts serve the same catalog. The command
ends by printing the exact commands for both targets.

`--desc-index-chars N` sets how many leading description characters are
keyword-indexed (default `SEARCH_DESC_INDEX_CHARS`, else 800; 0 = the whole
description). It only affects the build; to change it, build and deploy a new
release. Aliases are not part of the release since M41: they live in `server/storage/aliases.json` and are edited in
the admin panel (see [ADMIN.md](ADMIN.md#alias-manager-m41)). `storage/` must be writable by the PHP user; only its
deny-all `.htaccess` ships. Upgrading from M40, in full:

1. Copy `src/`, `tools/` and `public/` over the live ones (and, when unzipping a whole `release.zip`, `storage/.htaccess`).
2. `server/storage/` must exist and be writable by the PHP user (cPanel: the account itself), mode 755; the panel
   creates it, its `backups/` and a deny-all `storage/.htaccess` on the first save when `server/` is writable, and shows
   "storage not writable" otherwise. Never put it under `public/`.
3. Nothing else: no database migration, no `config.php` edit (the four new keys have defaults), no
   `public/.htaccess` change, no bundle rebuild or reload (normalization version, model and dim are unchanged; a bundle
   built before M41 still carries `aliases.json`, which is used only until storage is seeded and then ignored).
4. Open the panel's aliases page once: it seeds `storage/aliases.json` from the live bundle's `aliases.json`, so the
   search keeps serving exactly the aliases it served before. From then on edit aliases in the panel only; editing
   `pipeline/aliases.json` no longer changes the live search.

Directory deletes (`<data>_old` / `<data>_swap` on reload and rollback, backup pruning) go through `App\SafePath`, which
needs absolute paths of at least 3 segments below the configured base: `/home/ACCOUNT/app/server/data_old` is fine,
a `paths.data` such as `/srv/data` (giving `/srv/data_old`) is refused with `directory_swap_failed`.
`--intent-map FILE` likewise ships another [intent map](SEARCH-BEHAVIOR.md#intent-map) instead of
`pipeline/intent_map.json`.

On Windows: `pipeline\release.bat --csv export.csv --out release.zip`
(same arguments). The command embeds the VPS vectors with the **real** model
(whatever `EMBEDDER` says; `--mock` exists for tests only), builds the cPanel
bundle (no vectors since M42) and packs one `release.zip`, laid out relative to the host's `server/` directory:

| in the zip | what |
| --- | --- |
| `data_incoming/` | the bundle: `products.load.sql`, `meta.json`, `spellcheck.txt`, `synonyms.json`, `intent_map.json`, `keymap.json` |
| `bootstrap.php`, `src/`, `public/`, `tools/config-check.php`, `tools/logs-export.php` | the server code (including `public/install.php` and `public/test.html`), the config doctor and the [log export](SEARCH-API.md#log-export) (M36) |
| `config.example.php`, `db/schema.sql` | the installer's config template and schema |

`config.php` is **never** in the zip, so unzipping never overwrites the
server's configuration. Tests, local data and the other tools are not packed either.
**The cPanel bundle has no vectors (M42).** `/search` has not read them since M18, so
the bundle is `products.load.sql`, `meta.json` (`normalization_version`, `count`,
`built_at`) and the keyword data, and `release.zip` is about a sixth of its former size.
The VPS vectors are always real (its `/reload` refuses mock ones). New config keys come
with code defaults, so an older `config.php` keeps working.

**Upgrading to M29 (layered config).** `config.php` now holds only overrides and
the code owns every default ([CONFIGURATION.md](CONFIGURATION.md#the-layered-server-config)).
An existing `config.php` (a full copy of the old example) keeps working
unchanged: each of its entries is simply an override. Two things change:
tunables are no longer read from `SEARCH_*` environment variables unless your
`config.php` still lists them (the new example reads only the database, VPS URL
and token, Redis connection, tokens and storefront bases); and `tools/config-check.php`
ships in the zip. For a clean file, replace `config.php` with a copy of the new
`config.example.php` carrying just your values, then run
`php tools/config-check.php --verbose`.

**Upgrading to M18 (semantic tier on the VPS).** An existing `config.php` has
no `vps` section, so search stays keyword-only after the upgrade (the old
browser vectors are ignored). To turn the VPS on, add to `config.php` (or set
the matching `SEARCH_VPS_*` environment variables):

```php
    'vps' => [
        'url'        => 'https://vsearch.example.com',
        'token'      => '<the VPS_TOKEN from /etc/search-vectors.env>',
        'timeout_ms' => 300,
    ],
```

and change `semantic_min_score` from e5's `0.82` to about `0.4` (bge-m3's
scale; tune it on the test page). Update the storefront snippet
([INTEGRATION.md](../INTEGRATION.md#storefront-reference)) **before** deleting
the old `public_html/search-client/` folder, then delete it and any `client/`
folder next to `test.html`: nothing reads them any more.

**3. Deploy** on the cPanel host: unzip, then one `curl`.

```bash
cd ~/search-service/server && unzip -o ~/release.zip
php tools/config-check.php      # exits non-zero on an ERROR: fix config.php first
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     "https://shop.example.com/search-api/reload.php?load=1"
# {"ok":true,"count":20000}
curl -sS https://shop.example.com/search-api/health.php   # product_count == count, config.ok true
```

`config.php` survives every unzip, so the new code meets an old config. The
config doctor turns that from a silent risk into a checked step: it reports a
required key (`db.dsn`, `db.user`) that is missing, a value of the wrong type
(ERROR, non-zero exit), an unknown key left over from a removed option and a
half-configured feature such as `vps.url` without `vps.token` (WARN). Tunables
added by a release need no edit: they take their code default, and
`php tools/config-check.php --verbose` lists them. The same verdict is in
`/health` under `config` (key paths only, never values).

Without SSH: upload `release.zip` into `~/search-service/server/` with the
cPanel File Manager, choose **Extract**, and allow it to overwrite, then run
the `curl` from any machine. `data_incoming/` should not hold an old bundle
before extracting; a failed earlier reload can leave one, and the extract then
overwrites every bundle file anyway.

With `?load=1` the reload does, in order, and stops at the first failure:
1. Checks `meta.json`'s normalization version against the code, before loading
   anything.
2. Loads `data_incoming/products.load.sql` into `products_new`: the file drops
   and recreates the staging table, then inserts the rows. It is streamed one
   statement at a time (each at most 1 MB), and only statements aimed at
   `products_new` are accepted.
3. Checks `meta.count` against the staging table's rows.
4. Swaps tables and directories atomically, keeping the previous version.

A `422 invalid_bundle` means **nothing was swapped** and the live catalog is
unchanged. `reason` names the failed check (`staging_load_failed` also
reports the failing statement number); the fixes are in
[INTEGRATION.md](../INTEGRATION.md#post-reload). After a success, the previous
version is kept as the `products_old` table and the `data_old/` directory. The
"did you mean" dictionary (`spellcheck.txt`), the synonyms and the aliases
switch with the bundle; nothing else needs restarting. Between the unzip and the reload the new code serves the
old table; a table from before M12 lacks the SKU columns, and the SKU lookup is
simply skipped until the reload. A table from before M13 lacks
`normalized_specs`: search then covers title and description only until the
reload (also after a rollback to such a table). A table from before M15 lacks
the `idx_title_scan` index: synonym / alias expansion is then skipped (the
literal query is still served) until the reload.

<a id="upgrading-to-m21"></a>**Upgrading to M21 (tags, brand and category, logs).**
`normalization_version`, the models and the dimensions are unchanged, so the new
bundle passes both reloads (only `count` and `checksum` differ). The order is
export, build, deploy both hosts:

```bash
# 1. Export the catalog with the repo tool (PIPELINE.md, "Exporting from
#    OpenCart"; it includes the `tags` column; phpMyAdmin's CSV is only a
#    fallback and can corrupt `feature`), then, on the GPU machine (Colab:
#    TRAINING-COLAB.md), one command:
python pipeline/db_export.py --out export.csv
python pipeline/release.py --csv export.csv --out release.zip
# -> release.zip and vps_vectors/ (bge-m3 vectors) from the same export

# 2. cPanel (SSH; without it, File Manager: upload release.zip, Extract, overwrite)
cd ~/search-service/server && unzip -o ~/release.zip
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     "https://shop.example.com/search-api/reload.php?load=1"
# {"ok":true,"count":20000}

# 3. VPS (any order relative to step 2; both hold the same catalog afterwards)
rsync -a --delete ./vps_vectors/ root@vps:/var/lib/search-vectors/incoming/
ssh root@vps chown -R searchvec: /var/lib/search-vectors/incoming
curl -s -X POST https://vsearch.example.com/reload -H "Authorization: Bearer $VPS_TOKEN"
# {"ok":true,"count":20000,"model":"BAAI/bge-m3","built_at":"..."}
```

Between the unzip and the reload the new code serves the old table: a table
from before M21 has no `normalized_tags` / `normalized_brand` /
`normalized_category`, so the keyword tier searches without them and the match
boosts are off until the reload. `search_logs` is not part of the swap: the first search after the
upgrade adds its two new columns (`did_you_mean`, `tier`) with one `ALTER TABLE`;
if the database user may not alter, run it yourself (and logging keeps working
on the old columns meanwhile):

```sql
ALTER TABLE search_logs
    ADD COLUMN did_you_mean VARCHAR(512) DEFAULT NULL,
    ADD COLUMN tier VARCHAR(16) NOT NULL DEFAULT 'keyword_only';
```

To use the new tooling set up the admin panel login (see [Upgrading to M39](#upgrading-to-m39); the
old `SEARCH_LOGS_TOKEN` is gone) and, for the score breakdown, a `SEARCH_DEBUG_TOKEN`: a long
random value, different from the reload token. New
ranking keys (`tag_weight`, `brand_weight`, `category_weight`,
`brand_match_boost`, `category_match_boost`, `tag_match_boost`,
`tag_match_min_tokens`) default in code, so an older `config.php` keeps
working; add a key to `config.php` to change it ([CONFIGURATION.md](CONFIGURATION.md)). Add the brand pairs your
shoppers type in the other script to the aliases (admin panel, `?p=aliases`)
(`["سامسونگ", "samsung"]`).

**Memory note.** The keyword tier reads each matching row's text columns, so the
table plus its FULLTEXT index should fit the host's `innodb_buffer_pool_size`.
In the latency guard a table a little over the default 128 MB pool took ~300 ms
per query instead of ~50 ms (page reads, not CPU). Check the real catalog's
`information_schema.tables` size against the pool.

<a id="upgrading-to-m15"></a>**Normalization version upgrades (M15.1).**
A release that changes the normalization rules (M15's Roman numerals made it
version 3) needs **no config edit**: the pipeline stamps its own
`NORMALIZATION_VERSION` into `meta.json`, and `config.php` defaults to the
deployed code's `Normalizer::VERSION`, so the new code and its bundle always
agree. The installer writes nothing for it unless you type a different number,
which pins it as a `model.normalization_version` override.

A `config.php` written before M15.1 has the number hardcoded (`?: 2` or `?: 3`).
If it says 2, the M15 bundle is refused with `normalization_version_mismatch`
and **nothing is swapped**; if it says 3, the next rules bump would be. Edit it
once, so this never recurs: delete the `'normalization_version'` line from
`server/config.php` (and its `'model'` section if nothing else is left in it); the
code default then follows the deployed rules. `php tools/config-check.php --verbose`
shows it as a code default.

<a id="upgrading-to-m37"></a>**Upgrading to the search-fixes round (M37).** Four things change together:
the normalization rules (version 4), the code, the log schema and the storefront. Order:

1. **Back up the log table** (`mysqldump search_db search_logs > search_logs.sql`).
2. **Migrate the log schema** (safe on the running service: the new columns have defaults,
   the old code ignores them): `mysql search_db < db/migrations/2026-10-24_search_logs_v2.sql`
   (also in `release.zip` under `db/migrations/`). It is idempotent. The service also adds the
   missing *columns* itself on its first log write when its database user may `ALTER`; the three
   new indexes only come from the file. Rollback: `mysql search_db <
   db/migrations/2026-10-24_search_logs_v2.down.sql` (a separate file; drops the v2 columns,
   their indexes and `search_events`; their data is lost).
3. **Rebuild and deploy code and bundle in one step.** Normalization version 4 changes the
   indexed columns (ZWNJ is a space now), so build with `release.py` on the GPU machine, then
   extract `release.zip` and immediately `POST /reload?load=1`. The bundle refuses to load
   against old code (`normalization_version_mismatch`) and the old bundle against the new
   code; until the reload finishes a Persian query with a half-space is normalized the new way
   against columns indexed the old way, so keep the gap short. Reload the VPS vectors from the
   same export (`vps_vectors/`, as in [Every catalog update](#every-catalog-update)); the
   product texts they embed changed with the rules.
4. **Cache.** `POST /reload` flushes the result cache. Nothing else to do: from now on the cache
   key also carries a hash of `aliases.json` / `synonyms.json`, so an alias edit is live on the
   very next request without a flush, and an answer with no results is cached for 300 s
   (`redis.ttl_zero`) only.
5. **Restart.** Nothing to restart on cPanel (PHP reads the new files; if OPcache is set to
   revalidate rarely, wait for it or reset it from cPanel's PHP selector). The VPS service only
   restarts if you also changed its code (`systemctl restart search-vectors`).
6. **Storefront (OpenCart).** Upload the changed OpenCart files described in
   [INTEGRATION.md](../INTEGRATION.md#storefront-reference) ("Result tracking"): the search
   controller sends `session_id` (hashed) and renders the returned `search_uuid`; the results
   template gets the click listener and the `data-search-*` link attributes; the product
   controller, the add-to-cart handler and `checkout/success` report `cart` / `order`. The
   storefront works unchanged without any of it (no tracking, the engagement figures stay empty).
7. **Aliases.** `server/data/aliases.json` is replaced by the bundle's on every reload, so an
   alias you edit **on the server** is lost at the next catalog update. Keep `pipeline/aliases.json`
   as the single source (this release seeds the zero-result fixes there) and use the server
   file only for an urgent, temporary edit.
8. **Check.** `curl -s -X POST .../search -d '{"q":"مافیا"}'` returns the same ids as `mafia`;
   `config-check.php` is clean; the panel's analytics JSON (`admin.php?p=analytics&format=json`, logged in) shows `"log_v2":{"available":true,...}`.

<a id="upgrading-to-m31"></a>**Upgrading to M31 (search analytics).** Server code
only (upload the new `server/`); the analytics page was token-protected then (`logs.token`); since M39 it is behind the panel login. `/reload` never
touches `search_logs` (like the column back-fill in `Logger.php`), so add the one
supporting index to the live table yourself, once. It is idempotent
(MariaDB):

```sql
ALTER TABLE search_logs ADD INDEX IF NOT EXISTS idx_ts_result (ts, result_count);
```

Without it the pages still work; the zero-result and grouped queries just scan
more rows on a large log. `db/schema.sql` carries the index for new installs.

<a id="upgrading-to-m35"></a>**Upgrading to M35 (analytics dashboard v2).** Server code only (upload the new
`server/`, which now carries `src/AnalyticsPage.js` and `src/AnalyticsPage.css`, both in
`release.zip`); there is no schema change and **no new index**: every new query is
window-bounded and uses `idx_ts` / `idx_ts_result` (the zero-rate trend is covered by
`idx_ts_result`). `logs.refresh_seconds` (default 30, `0` = off) is the only new setting. The
latency trend needs window functions (MariaDB 10.2+). The page makes no request to
any other host, so it works behind a restrictive network; open it from the panel's navigation. If you ever do need a further index, add it by hand like
the M31 one (`ALTER TABLE search_logs ADD INDEX IF NOT EXISTS ...`): `/reload` never
touches `search_logs`.

<a id="upgrading-to-m38"></a>**Upgrading to M38 (Persian analytics dashboard).** Server code only: upload the new
`server/` (or `release.zip`), which now carries `src/AnalyticsStrings.php` and the font folder
**`server/public/fonts/vazirmatn/`** (`Vazirmatn-Regular.woff2`, `Vazirmatn-Bold.woff2`, `OFL.txt`). Copy that
folder next to `admin.php` inside your document root (public/); nothing outside `public/` is
web-exposed. No schema or config change. The alias fix ships in the code too (`Synonyms` merges owner
alias groups that share a term), so no bundle rebuild is needed for it, only the new code and a `/reload`
(or any worker restart) so the cached alias map is rebuilt.

<a id="upgrading-to-m42"></a>**Upgrading to M42 (no vectors on cPanel, panel polish).** Server code only on the
cPanel side; nothing to migrate and no `.htaccess` change:

1. Copy `src/`, `public/` (including `admin.php`) and `config.example.php` over the live ones, or unzip the new `release.zip`.
2. `config.php` needs no edit. If it still sets `model.name`, `model.revision` or `model.dim`, `php tools/config-check.php`
   warns that they can be deleted ([CHANGELOG.md](CHANGELOG.md)); they are ignored. New key: `logs.scan_limit` (default 200000).
3. **No rebuild is required.** A bundle built before M42 (with `vectors.bin` / `vectors.idx`) is still accepted by
   `/reload` and keeps its old checks. The next release you build simply no longer contains them, and `meta.json` is
   `{normalization_version, count, built_at}`. On the build machine delete `SEARCH_MODEL*` and `SEARCH_BUNDLE_EMBEDDER`
   from `.env` (ignored now). The VPS vectors are unchanged.
4. A rollback to a pre-M42 `data_old/` works as before.

<a id="upgrading-to-m40"></a>**Upgrading to M40 (operations page, config upgrade safety).**
1. **Copy** the new `server/` (or `release.zip`). New files: `src/AdminOps.php`, `src/AdminOpsViews.php`, `src/ConfigReport.php`,
   `tools/_php-guard.php`; changed: `src/Reload.php`, `src/Cache.php`, `src/VpsClient.php`, `src/Audit.php`, `src/Config.php`,
   `src/ConfigDoctor.php`, `src/AdminPage.*`, `src/AdminStrings.php`, `src/AdminViews.php`, `src/AdminStatus.php`,
   `public/admin.php`, `public/reload.php`, all of `tools/*.php`. No schema change and **no `.htaccess` change**.
2. **`server/` must be writable by the web user** (it already has to be for the `data/` swap): reloads and rollbacks now take
   an exclusive lock, `server/reload.lock`, created on first use (it is gitignored and covered by `server/.htaccess`). If it
   cannot be created the operation stops with "lock unavailable" instead of running unprotected.
3. `POST /reload` can now answer `409 {"error":"reload_busy"}` when another reload or rollback (from the token endpoint or the
   panel) is running. A deploy script that retries on failure should retry on 409 after a pause, not treat it as a bad bundle.
4. **Check the config doctor**: `su - search -c "cd ~/search-service/server && /opt/cpanel/ea-php82/root/usr/bin/php tools/config-check.php"`
   (see [Command-line tools](#command-line-tools)) or the status page. A leftover `logs.token` or `admin.ip_header` is now
   reported with the reason it can be deleted; the status page's "effective configuration" table shows what the app really uses.
5. The new setting `vps.reload_timeout_ms` (60 s) only matters if the panel's VPS button is used; the VPS side is unchanged.

<a id="upgrading-to-m39"></a>**Upgrading to M39 (admin panel: login, shell, status).**
1. **Copy** the new `server/` (or `release.zip`; it carries the new files). The new files are:
   `public/admin.php`, `src/Admin*.php`, `src/Audit.php`, `src/AdminPage.js`, `src/AdminPage.css`,
   `tools/admin-password.php`; `public/analytics.php` and `public/logs.php` are now two-line redirects, and
   `src/RedisClient.php`, `src/LogsPage.php`, `src/AnalyticsPage.*`, `src/Config.php`, `src/ConfigDoctor.php`,
   `public/index.php`, `public/reload.php` changed.
2. **Create the audit table** (idempotent, a separate `.down.sql` rolls it back; use your own table name if you
   changed `db.admin_audit_table`): `mysql search_db < db/migrations/2026-10-25_admin_audit.sql`.
   `db/schema.sql` carries it for new installs.
3. **Make the password hash** on any machine with PHP, `php server/tools/admin-password.php` (it prompts, hides the
   typing and prints a `password_hash()` value; at least 12 characters), and put it in `config.php`:
   `'admin' => ['username' => 'owner', 'password_hash' => '$2y$...']`. Single-quote it: it contains `$`.
   Without both keys the panel answers 503. Optional keys: `allowed_ips` (comma separated IPs / CIDR),
   `idle_timeout_minutes` (30), `session_hours` (8), `trusted_proxies` (see below), all in
   [CONFIGURATION.md](CONFIGURATION.md#admin-panel-section-m39).
4. **Merge the `.htaccess` rewrite by hand** if your production `public/.htaccess` is customized: the web-root
   routing rule must name the new endpoint (`admin`), i.e. change
   `RewriteRule ^(search|health|reload|logs|analytics)/?$ index.php [L]` to
   `RewriteRule ^(search|health|reload|admin|logs|analytics)/?$ index.php [L]`. Direct requests to
   `admin.php?p=...` need nothing. The panel sets its own security headers from PHP, so no header block is needed.
5. **Check** `https://<site>/admin.php`: the login page opens, a wrong password shows the generic error, a right one
   lands on the status page. `curl -I http://<site>/admin.php` (plain HTTP) must answer 403.
6. **Client address.** Throttling, the address list and the audit log use `REMOTE_ADDR` only; `X-Forwarded-For` is
   ignored, because anyone can send it. If the panel really sits behind a reverse proxy or CDN (then `REMOTE_ADDR` is
   the proxy's, so every visitor shares one throttle counter), list that proxy in `admin.trusted_proxies` (IPs / CIDR):
   only a request that arrives from a listed proxy has its `X-Forwarded-For` read, from the right, skipping other listed
   proxies (see [ADMIN.md](ADMIN.md#client-address)). If TLS ends at a proxy, set `admin.trust_forwarded_proto` so `X-Forwarded-Proto: https` counts as HTTPS.
7. `logs.token` / `SEARCH_LOGS_TOKEN` is no longer used (the doctor warns while it is set): remove it. Bookmarks to
   `analytics.php` / `logs.php` redirect to the panel. Scripts that read `analytics.php?format=json` with the token
   must stop: the JSON now needs a panel session.

**Log retention (optional, operator-run).** `search_logs` grows with traffic and
nothing prunes it, on purpose: no daemon runs on the cPanel host. When the table
gets large, delete old rows by hand or from a cPanel cron you own, for example
`DELETE FROM search_logs WHERE ts < NOW() - INTERVAL 180 DAY;` (what the analytics
windows cannot see any more is gone for good; `all` then means "since the prune").

<a id="upgrading-to-m23"></a>**Upgrading to M23 (spacing + soft AND).** Two parts with
different deploy needs; `normalization_version`, the models and the dimensions
are unchanged, so the bundle passes both reloads:

- **Soft AND, alias variants over tags / specs, combined-alias variants**: server
  code only. Upload the new `server/` (it reads the new keys from `config.php`
  with the documented defaults, so no config edit is required) and it is live;
  nothing to rebuild.
- **Collapsed names** (`farcry` = `far cry`): a **schema change, so a rebuild +
  reload**. The `products` table gets `normalized_collapsed` and the covering index
  `idx_collapsed_scan`; `products.load.sql` carries the new DDL, so the reload swap
  creates them (no `ALTER`). Run the export / `release.py` / reload steps of the
  M21 upgrade above. Until the reload the new code searches the old table without
  the column, with no error. The VPS vectors do not change (the column is not part
  of the embedded text), so the VPS step may be skipped.

Settings added (all optional): `search.soft_and_min_results` (3),
`search.soft_and_min_coverage` (0.5), `search.soft_and_partial_penalty` (0.5),
`search.soft_and_candidate_cap` (100), `search.collapse_weight` (9),
`search.collapse_min_length` (5). A `config.php` written before M23 lacks them and
falls back on those defaults; add a key to `config.php` to change it.

### Fallback: manual staging load

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
SFTP). `python pipeline/build.py --csv export.csv --out ./bundle`
builds just the bundle (no embedder involved since M42). A truncated
`products.load.sql` shows up as a `count_mismatch` (or a failed staging load).

### Result cache (optional, Redis, M26)

Off until configured. Redis runs on the **VPS** (cPanel cannot run daemons) and the cPanel server only
talks to it with an in-repo RESP client: no Composer package, no PHP extension.

1. **Install and secure Redis on the VPS** (`vps/setup.sh`, safe to re-run):

   ```
   sudo ./setup.sh --redis --redis-bind <VPS_ADDRESS> --redis-allow-ip <CPANEL_OUTBOUND_IP>
   ```

   It installs `redis-server`, requires a random password (printed once, kept on re-runs in
   `/etc/redis/search-cache.conf`), turns protected mode on, disables persistence, caps memory at 128 MB
   (LRU eviction) and, with `--redis-bind`, listens on that address as well as localhost with `ufw`
   admitting **only** `--redis-allow-ip` on port 6379. Without `--redis-bind` it is localhost-only (useful
   only when the search service itself runs on the VPS). `--redis-bind` without `--redis-allow-ip` is refused.
   Redis speaks plain TCP, so the password is not encrypted on the wire: prefer a private network or a
   tunnel (WireGuard, stunnel) between the hosts, with `--redis-bind` set to the private address. The cache
   holds product ids only.
2. **Point cPanel at it** (environment or `config.php`, see [CONFIGURATION.md](CONFIGURATION.md#result-cache-redis-section-m26)):
   `SEARCH_REDIS_ENABLED=1`, `SEARCH_REDIS_HOST`, `SEARCH_REDIS_PORT`, `SEARCH_REDIS_AUTH`; for an older
   `config.php` add `'redis' => ['enabled' => true, 'host' => ..., 'port' => ..., 'auth' => ...]`.
3. **Check** that it works: send the same `/search` twice and look at the panel's logs page (`admin.php?p=logs`): the second row has
   `cache_hit = 1` (and a latency near 0).

`POST /reload` empties the cache (every key under `redis.prefix`) after a successful swap and reports
`"cache_flushed": true` (or `false`, with the swap still done, when Redis could not be reached: entries then
expire with their TTL). To flush by hand after a settings change:
`redis-cli -a <password> --scan --pattern 'search:cache:*' | xargs -r redis-cli -a <password> del`.
The pattern also covers the `search:cache:freq:*` hit counters of the adaptive TTL (M33).

If Redis stops, searches carry on uncached (each costs at most `redis.timeout_ms` extra, logged with
`error_log`); to turn the cache off set `SEARCH_REDIS_ENABLED=0`.

### Rollback (one step)

**From the panel (M40):** the operations page has a "return to the previous version" button, enabled only when both
`products_old` and `data_old` exist. It swaps the tables and the directories back in one step, flushes the result cache and
is audited; pressing it again undoes the rollback (the version you left becomes the "previous" one). It refuses, changing
nothing, when the previous bundle no longer matches this code's normalization version. Roll the VPS
vectors back separately (`vps/README.md`, "Rollback").

**By hand**, to return to the previous catalog after a bad reload:

```sql
RENAME TABLE products TO products_bad, products_old TO products;
```
```bash
cd ~/search-service/server && mv data data_bad && mv data_old data
```

Do both together, because the table and the bundle files (spellcheck,
synonyms, aliases) must come from the same bundle. Afterwards, drop
`products_bad` and remove `data_bad/`. Each PHP worker caches the dictionary
under a key that includes the file's identity, so the restored files are picked
up without a restart. Roll the VPS vectors back too if they were updated with
this catalog (`vps/README.md`, "Rollback").

## Production Go-Live Checklist

Everything below was impossible to verify without the real host, catalog, or
shoppers' devices. It consolidates the "Needs production validation" items from
M0–M5. Verify each item on the production host before wide rollout.

**Blocking: layout and secrets (the web exposure checks)**
- [ ] The document root points at `server/public` (or the subfolder holds only
  the public files plus `SEARCH_APP_BASE`), per [Layout](#layout): `config.php`,
  `src/`, `data/` and the rest of `server/` are above the web root.
- [ ] **`install.php` is deleted** from the served folder (it answers `403`
  once `config.php` exists, but it should not be there at all).
- [ ] Nothing outside `public/` is reachable. Each of these returns `403` or
  `404`, never `200`:
  ```bash
  for p in config.php config.example.php bootstrap.php src/Config.php \
           data/products.load.sql data/meta.json data_incoming/meta.json \
           ../config.php ../data/products.load.sql .htaccess; do
    printf '%-34s' "$p"; curl -s -o /dev/null --path-as-is -w '%{http_code}\n' "https://search.example.com/$p"
  done
  ```
  Also try the same under the subfolder (`https://shop.example.com/search-api/...`)
  and, if the app tree might ever have been copied under `public_html`, at
  `https://shop.example.com/search-service/server/config.php`. The `.htaccess`
  files are the safety net for that last case only; a host that ignores
  `.htaccess` makes the layout the only protection.
- [ ] `php tools/config-check.php` reports no ERROR, and `GET /health` shows
  `config.ok` true.
- [ ] **Rotate the tokens before go-live** (`reload.token`, `vps.token`,
  `debug.token`; set a fresh admin password too; `redis.auth` if the cache is on): any value that
  was typed into a chat, a ticket or a shell history is burned. Use long random,
  different values. A new `vps.token` must be set on the VPS too
  (`VPS_TOKEN`); a new `reload.token` is what the deploy `curl` sends.

**Blocking: search quality and the model**
- [ ] **Persian embedding-model feasibility test (mandatory before wide
  rollout).** bge-m3 on the VPS is the semantic model since M17/M18. Label
  Persian, English, and mixed queries from real traffic into
  `fixtures/eval_queries.json`, then run `php server/tools/eval.php --k=10`
  without and with `SEARCH_VPS_URL` / `SEARCH_VPS_TOKEN` (keyword-only vs
  hybrid). Accept the model only if hybrid clearly beats keyword-only on
  Persian and cross-language queries.
  Changing the model means following
  [INTEGRATION.md, Changing the model](../INTEGRATION.md#changing-the-model).
- [ ] **Persian search quality on the real ~20k catalog.** Check keyword-tier
  results for common Persian queries: ZWNJ variants, ی/ک variants, Persian
  digits, model numbers. Normalization rules were only verified against
  fixtures.
- [ ] **VPS query/product model parity** with the model files on the VPS
  (`vps/README.md`, "Model parity": `VPS_REAL_PARITY=1 pytest
  vps/tests/test_real_model.py`). Rerun it whenever either side's model, files
  or ONNX build change.
- [ ] Tune `search.semantic_min_score` (default 0.4 on bge-m3's scale; e5's
  0.82 no longer applies) and the fusion weights on the real catalog: search
  known "no match" queries (e.g. ball bearing, shorts) and known good cross-language queries on the test page, read each
  card's cosine, and set the floor between them. Confirm with
  `server/tools/eval.php` in hybrid mode. Too high loses cross-language recall;
  too low brings back unrelated neighbours.
- [ ] Tune `search.spec_weight` (default 6, between title 10 and description
  1) with `server/tools/eval.php` on real attribute / feature queries (brands,
  refresh rates, sizes). Check the build's malformed-`feature` warning count on
  the real export, and that `attributes` is not cut at 1024 bytes.
- [ ] Tune the M21 weights on real queries (`tag_weight`, `brand_weight`,
  `category_weight`, `brand_match_boost`, `category_match_boost`,
  `tag_match_boost`) with the eval harness and the score breakdown (`debug`);
  check that tags (noisy: duplicate and test names) help more than they hurt,
  that the brand pairs shoppers use across scripts are in `aliases.json`, and
  that the table and its FULLTEXT index fit `innodb_buffer_pool_size`.
- [ ] Tune the M23 settings on real queries (`search.soft_and_min_results`,
  `_MIN_COVERAGE`, `_PARTIAL_PENALTY`, `_CANDIDATE_CAP`, `search.collapse_weight`,
  `search.collapse_min_length`) with the eval harness (the seed set has negative
  and joined / separated cases) and the `debug` breakdown. Confirm after the
  rebuild + reload that `normalized_collapsed` is populated, and measure latency
  of a zero-hit multi-word query that has alias variants of very common words
  (the one soft-AND shape that exceeded the 200 ms budget in the CI-like guard).
- [ ] Keyboard-layout recovery covers the common US→Persian keys only (M2), and
  synonyms and spelling are untuned. Review `search_logs` zero-result queries
  after launch; name variants found there go into `pipeline/aliases.json`.
- [ ] Review the generated `synonyms.json` of the real build (M15 expands
  queries with it): a group joining unrelated categories through a shared junk
  `model` code would add their products to each other's results. Lower
  `search.synonyms_max_group_size`, or fix the `model` values, if so.

**Blocking: host capacity and latency**
- [ ] **Real latency on cPanel** for keyword-only and hybrid requests, measured
  with `latency_ms` in `search_logs` and end-to-end from the storefront, against
  the 200 ms budget. Hybrid now adds one cPanel→VPS round trip (network +
  bge-m3 query embedding, ~50–100 ms end to end on a dev VM, never measured
  between the real hosts). Pick `vps.timeout_ms` from the measured p95:
  too low turns slow VPS answers into keyword-only results (watch for
  `semantic tier unavailable (timeout)` in the PHP error log), too high lets a
  sick VPS slow every search. With the final target (the VPS as a self-hosted VM
  on the owner's ESXi server, reached over the LAN) the round trip should be
  much shorter than on the external test VPS; keep a sane timeout anyway, it is
  the safety net that turns a sick VM into keyword-only answers.
- [ ] **cPanel can reach the VPS.** Outbound HTTPS from the shared host to the
  VPS port (some hosts firewall outbound ports), the VPS firewall allows the
  host's real outbound IP, and PHP's curl extension is enabled. If the VM sits on
  a LAN behind the owner's ESXi server and cPanel is not on that LAN, cPanel needs
  a route to it (port forward or tunnel, TLS, the token); a LAN-only address is
  not reachable from a remote host. Check with `test.html` (hybrid badge) and
  the error log.
- [ ] **Zero-result query latency.** "Did you mean" now reads the bundle's
  `spellcheck.txt` instead of scanning the `products` table on each request
  (M6). On a synthetic 20k catalog (32k-term dictionary) on local MariaDB, a
  warm zero-result request took about **15–20 ms**; the same data on the old
  path took about **350–500 ms**. Check `latency_ms` for zero-result rows in
  `search_logs` on the host. Short Persian tokens (shorter than
  `innodb_ft_min_token_size`) use the LIKE fallback, about 150 ms on the same
  data.
- [ ] **Specs and the LIKE fallback (M13).** A query with a token shorter than
  `search.min_token_size` scans every row's title, specs and indexed
  description. On a synthetic 20k catalog with about 550 characters of specs
  per product, with the table in memory on local MariaDB 10.11, that path took
  about **220 ms** (about 105–120 ms without specs); with the table larger
  than the buffer pool (128 MB default) it was I/O-bound at 600–800 ms. The
  FULLTEXT path stayed at about 50–60 ms. Measure `latency_ms` for short-token
  queries on the host with the real specs size.
- [ ] **APCu availability.** Check `php -m | grep apcu` on the host (CLI and web
  SAPI can differ) and that `apc.shm_size` holds about 3 MB for the spellcheck
  dictionary. Without APCu, each cold worker parses the file once, which is
  slower but correct. (Since M18 no vector matrix is loaded in PHP.)
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
  `cdn.jsdelivr.net` requests, tested from inside Iran. (Since M18 no model,
  runtime or font is loaded by shoppers, so this should hold by construction;
  the former browser-model download and low-end-device checks no longer apply.)
- [ ] The OpenCart module renders real product cards for `product_ids`, sends
  `customer_id` as a string, and falls back to OpenCart's native search when the
  service fails.
- [ ] Uptime monitoring uses `GET /health` (not `HEAD`) and alerts on
  `status != ok` and on `product_count == 0`.
