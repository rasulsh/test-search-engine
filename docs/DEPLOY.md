# Deploy and update runbook

Everything to put a build on the servers, update it, upgrade across milestones, roll back, and sign off before going live. To produce the build artifacts (`release.zip` for cPanel, `vps_vectors/` for the VPS) use [TRAINING-COLAB.md](TRAINING-COLAB.md); the export that feeds it is described in [PIPELINE.md](PIPELINE.md#exporting-from-opencart-db_exportpy); the VPS side is [`vps/README.md`](../vps/README.md).

## Deploy / update runbook

The runbook has four machines' worth of steps. The **OpenCart database**
provides the export. A **GPU machine**, in practice a free Colab T4
([TRAINING-COLAB.md](TRAINING-COLAB.md)), builds the release. The **cPanel
host** serves it and the **vector service VM** (the VPS, finally a self-hosted
VM on the owner's ESXi server on the LAN) holds the semantic vectors. Nothing
on cPanel runs as a daemon or needs root.
Placeholders: `shop.example.com`, `~/search-service`, database `cpuser_search`.
Replace them with yours.

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
   CORS support. See [INTEGRATION.md, Deployment shape](../INTEGRATION.md#deployment-shape).
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
5. **Delete `install.php`.** Once `config.php` exists it refuses to do
   anything (`403`, "already installed"), so a later release that re-extracts
   it is harmless, but removing it keeps the surface small.
   `curl -sS https://shop.example.com/search-api/health.php` should now report
   `product_count` equal to the catalog size. Set `SEARCH_MIN_TOKEN_SIZE` in
   `config.php` if the host's `innodb_ft_min_token_size` is not 3
   (`SHOW VARIABLES LIKE 'innodb_ft_min_token_size'`).
6. **Storefront.** Install the storefront snippet through the OpenCart module
   ([INTEGRATION.md, Storefront reference](../INTEGRATION.md#storefront-reference)).
   It sends only the query; nothing model-related is uploaded to the store.

**Without the installer** (or to change settings later): `config.php` is a
copy of `config.example.php` with the defaults after each `?:` (or in each
`$setting(...)`) edited; every key is described in `.env.example`, and
`SEARCH_*` environment variables still override the file where the host
supports them. Import `db/schema.sql` yourself in that case.

### Every catalog update

The whole update is three commands: export, `release`, and one `curl` after
unzipping on the host.

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
# -> Built release.zip: <count> products, dim 384, embedder mock, <n> files
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
release. `--aliases FILE` ships another [alias file](SEARCH-BEHAVIOR.md#aliases) instead of
`pipeline/aliases.json`.

On Windows: `pipeline\release.bat --csv export.csv --out release.zip`
(same arguments). The command embeds the VPS vectors with the **real** model
(whatever `EMBEDDER` says; `--mock` exists for tests only), builds the cPanel
bundle's `vectors.bin` with the mock embedder (see below) and packs one
`release.zip`, laid out relative to the host's `server/` directory:

| in the zip | what |
| --- | --- |
| `data_incoming/` | the bundle: `vectors.bin`, `vectors.idx`, `products.load.sql`, `meta.json`, `spellcheck.txt`, `synonyms.json`, `aliases.json`, `keymap.json` |
| `bootstrap.php`, `src/`, `public/` | the server code (including `public/install.php` and `public/test.html`) |
| `config.example.php`, `db/schema.sql` | the installer's config template and schema |

`config.php` is **never** in the zip, so unzipping never overwrites the
server's configuration. Tests, tools, and local data are not packed either.
**The cPanel `vectors.bin` is a mock (M22).** `/search` has not read it since
M18; `/reload` only checks `meta.json` (model, dim, normalization version),
the file size and the checksum, and those all hold for deterministic mock vectors
of the configured dim. Embedding 20,000 products with e5 just to produce an
unused file doubled the build time, so `release.py` uses `SEARCH_BUNDLE_EMBEDDER`
(default `mock`; `meta.json` says `"embedder": "mock"`). Set it to `real` to get
real e5 vectors. The VPS vectors are always real (its `/reload` refuses mock
ones). `build.py` run directly still follows `EMBEDDER`.

`SEARCH_MODEL`, `SEARCH_MODEL_REVISION` and `SEARCH_MODEL_DIM` must match
`server/config.php` (the reload rejects a mismatch). New config keys come with
defaults, so an older `config.php` keeps working.

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
# {"ok":true,"count":20000,"model":"intfloat/multilingual-e5-small","dim":384}

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

To use the new tooling add to `config.php` (or the environment) a
`SEARCH_LOGS_TOKEN` and, for the score breakdown, a `SEARCH_DEBUG_TOKEN`: long
random values, different from each other and from the reload token. New
ranking keys (`tag_weight`, `brand_weight`, `category_weight`,
`brand_match_boost`, `category_match_boost`, `tag_match_boost`,
`tag_match_min_tokens`) default in code, so an older `config.php` keeps
working; see `config.example.php` to change them. Add the brand pairs your
shoppers type in the other script to `pipeline/aliases.json` before building
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

Settings added (all optional): `SEARCH_SOFT_AND_MIN_RESULTS` (3),
`SEARCH_SOFT_AND_MIN_COVERAGE` (0.5), `SEARCH_SOFT_AND_PARTIAL_PENALTY` (0.5),
`SEARCH_SOFT_AND_CANDIDATE_CAP` (100), `SEARCH_COLLAPSE_WEIGHT` (9),
`SEARCH_COLLAPSE_MIN_LENGTH` (5). A `config.php` written before M23 lacks them and
falls back on those defaults; copy them from `config.example.php` to change them.

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
- [ ] Tune `SEARCH_SEMANTIC_MIN_SCORE` (default 0.4 on bge-m3's scale; e5's
  0.82 no longer applies) and the fusion weights on the real catalog: search
  known "no match" queries (e.g. ball bearing, shorts) and known good cross-language queries on the test page, read each
  card's cosine, and set the floor between them. Confirm with
  `server/tools/eval.php` in hybrid mode. Too high loses cross-language recall;
  too low brings back unrelated neighbours.
- [ ] Tune `SEARCH_SPEC_WEIGHT` (default 6, between title 10 and description
  1) with `server/tools/eval.php` on real attribute / feature queries (brands,
  refresh rates, sizes). Check the build's malformed-`feature` warning count on
  the real export, and that `attributes` is not cut at 1024 bytes.
- [ ] Tune the M21 weights on real queries (`tag_weight`, `brand_weight`,
  `category_weight`, `brand_match_boost`, `category_match_boost`,
  `tag_match_boost`) with the eval harness and the score breakdown (`debug`);
  check that tags (noisy: duplicate and test names) help more than they hurt,
  that the brand pairs shoppers use across scripts are in `aliases.json`, and
  that the table and its FULLTEXT index fit `innodb_buffer_pool_size`.
- [ ] Tune the M23 settings on real queries (`SEARCH_SOFT_AND_MIN_RESULTS`,
  `_MIN_COVERAGE`, `_PARTIAL_PENALTY`, `_CANDIDATE_CAP`, `SEARCH_COLLAPSE_WEIGHT`,
  `SEARCH_COLLAPSE_MIN_LENGTH`) with the eval harness (the seed set has negative
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
  `SEARCH_SYNONYMS_MAX_GROUP_SIZE`, or fix the `model` values, if so.

**Blocking: host capacity and latency**
- [ ] **Real latency on cPanel** for keyword-only and hybrid requests, measured
  with `latency_ms` in `search_logs` and end-to-end from the storefront, against
  the 200 ms budget. Hybrid now adds one cPanel→VPS round trip (network +
  bge-m3 query embedding, ~50–100 ms end to end on a dev VM, never measured
  between the real hosts). Pick `SEARCH_VPS_TIMEOUT_MS` from the measured p95:
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
  `SEARCH_MIN_TOKEN_SIZE` scans every row's title, specs and indexed
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
