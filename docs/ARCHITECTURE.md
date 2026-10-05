# Architecture

Authoritative design, contracts and the milestone plan for implementers live in [`CLAUDE.md`](../CLAUDE.md). This file is the human-readable overview. Search behavior is in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md), the data side in [PIPELINE.md](PIPELINE.md), keys in [CONFIGURATION.md](CONFIGURATION.md).

## Overview

Two tiers, degrading gracefully:

- **Tier 1 — keyword (always on, server-side).** MySQL FULLTEXT + Persian
  normalization + typo/keyboard-layout tolerance + "did you mean". Works with
  zero ML and never breaks.
- **Tier 2 — semantic (additive, M18).** cPanel sends the query text
  server-to-server to a small VPS service ([`vps/README.md`](../vps/README.md))
  that embeds it with bge-m3 and returns the top-K `{product_id, score}` by
  cosine over precomputed product vectors; cPanel merges them with the keyword
  hits. If the VPS is not configured, down, slow or failing, Tier 1 still
  answers (and the degradation is logged).

Heavy work (product embedding) runs **offline** on a GPU machine (the
recommended one is a free Colab T4, see [TRAINING-COLAB.md](TRAINING-COLAB.md))
and produces a static index *bundle* for cPanel plus the product vectors for
the VPS (`build.py --vps-out`). **Query embedding happens on the VPS** — never
in the browser and never on cPanel. The cPanel server only serves requests:
keyword search, one bounded HTTP call to the VPS, and the merge.

**Deployment target.** During testing the VPS was an external host, which is why
the cPanel-to-VPS call is framed around a short timeout. The final target is a
self-hosted VM on the owner's own ESXi server on the LAN, well-resourced, so
that call is expected to be fast and reliable there. The safety net stays
regardless: `SEARCH_VPS_TIMEOUT_MS` (default 300) bounds the call and any
failure serves keyword-only results.

## Repository layout

```
README.md       Short onboarding and the documentation index
INTEGRATION.md  Storefront HTTP contract and reference snippet
CLAUDE.md       Implementation spec for Claude Code (contracts, milestones, workflow)
docs/           Topic documentation (this folder)
db/             SQL schema (products + FULLTEXT, search_logs)
pipeline/       Offline build pipeline (Python 3.11+, GPU or mock)
server/         cPanel runtime (PHP 8.1+, no framework)
vps/            VPS vector service (bge-m3 query embedding + cosine top-K; see vps/README.md)
fixtures/       Shared test fixtures (normalization cases, eval set, parity strings)
```

## The three contracts

Breaking any of these produces silently wrong results; each is enforced by tests
(details in [`CLAUDE.md`](../CLAUDE.md) §3).

1. **Normalization parity.** `pipeline/normalize.py` and `server/src/Normalizer.php`
   produce identical output; both suites run `fixtures/normalization_cases.json`
   (since M23 including the space-collapse cases). Bump `normalization_version`
   in both when a rule changes.
2. **Model parity.** Product vectors (`pipeline/embed.py`, `--vps-out`) and the
   VPS query embedder use the same model, revision, pooling, prefixes and
   L2-normalization; the VPS `/reload` rejects mismatched vectors.
3. **Bundle compatibility.** `meta.json` carries `{model, revision, dim,
   normalization_version, count, built_at, checksum}`; `POST /reload` rejects a
   bundle whose `model`, `dim` or `normalization_version` differs from
   `server/config.php`.

## Semantic tier (Tier 2)

Tier 2 adds cross-language recall on top of keyword search. It is purely
additive: keyword-only requests are unchanged.

**Query embedding and cosine top-K — on the VPS (M18).** `search.php` POSTs
`{q, limit: SEARCH_SEMANTIC_TOP_K, min_score: SEARCH_SEMANTIC_MIN_SCORE}` to
`SEARCH_VPS_URL` + `/search-vectors` with the bearer token
(`server/src/VpsClient.php`, curl). The VPS embeds the raw query with bge-m3
and scans every product vector (global brute force, which is what delivers
cross-language recall; ~8 ms for 20k × 1024 there) and returns the neighbours
at or above the floor, best first. cPanel re-applies the floor, drops ids the
`products` table does not hold, and fuses the rest with the keyword hits
(`server/src/Ranker.php`). The whole call is bounded by
`SEARCH_VPS_TIMEOUT_MS` (default 300, connect included). Unreachable, timed
out, non-`200` or malformed: the request is answered keyword-only, logs
`search: semantic tier unavailable (<reason>); served keyword-only results` to
the PHP error log, and writes `had_vector = 0` in `search_logs`. Nothing about
the VPS is visible to browsers. Contract details: INTEGRATION.md, "Semantic
tier: cPanel to VPS".

**Relevance floor.** The nearest vectors of a query with no relevant product
are still unrelated items (on the real catalog, "ball bearing" returned case
fans). Neighbours with cosine below `SEARCH_SEMANTIC_MIN_SCORE` are dropped,
and a query with no keyword hit and nothing above the floor returns an empty
result instead of `limit` far neighbours. The default is **0.4, on bge-m3's
scale** (M18): e5's old 0.82 would drop nearly every bge-m3 neighbour, and a
`config.php` written before M18 still says 0.82, so change it there when
turning the VPS on. 0.4 is a starting point that **needs tuning on the real
catalog** (eval harness + search logs): on the 6-product fixture, right
one-word hits scored 0.42–0.49 and wrong ones up to 0.43, so no floor
separates short queries. One-word precision comes from the keyword tier (all
terms, aliases, synonyms) blended with the cosine (below); the floor mainly
keeps far neighbours out. Hybrid responses carry `cosine_scores`
(`null` for a product the VPS did not return) so the test page can show each
result's similarity while tuning.

**Reader tolerance.** A semantic hit whose `product_id` is missing from the
`products` table (e.g. VPS vectors reloaded before the cPanel catalog) is
dropped rather than surfaced, and products the VPS has no vector for remain
keyword-only — so updating one host before the other degrades instead of
breaking. Update the cPanel catalog and the VPS vectors from the same export.

The hybrid ranking itself (blend, floor, boosts) is in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#hybrid-ranking).

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

## Milestone status

- [x] **M0** — Scaffold: structure, `.gitignore`, CI, config examples, README.
- [x] **M1** — Keyword backbone (schema, loader, FULLTEXT + LIKE fallback, `/health`).
- [x] **M2** — Persian normalization (parity), typo/keymap tolerance, "did you mean", logging, `POST /search`.
- [x] **M3** — Offline pipeline (`build.py`, `embed.py` mock+real), bundle + `meta.json`, atomic `POST /reload`.
- [x] **M4** — Semantic tier: browser query embedder, cosine top-K + caching, RRF hybrid + business boosts, `/search` vector path, latency guard, eval harness (browser embedder and PHP cosine replaced by the VPS in M18).
- [x] **M5** — Integration + docs: `INTEGRATION.md` (HTTP contract, storefront reference, model self-hosting), operator runbook, Go-Live checklist.
- [ ] **M6** — Follow-up: "did you mean" served from the bundle's `spellcheck.txt` (cached per worker + APCu, refreshed on `/reload`); zero-result latency guard.
- [ ] **M8** — Search test page (`server/public/test.html`), opt-in `with_details` on `/search`, `fetch_web_model.py` for self-hosted browser assets.
- [ ] **M9** — Relevance tuning: semantic cosine floor (empty result instead of far neighbours), keyword-first hybrid fusion, frequency-gated "did you mean" from high-signal fields, cosine score + "no results" state on the test page.
- [ ] **M10** — Keyword relevance by field: title vs description scored separately (configurable weights), title phrase bonus, title matches always above description-only matches (also in the hybrid merge); keyword latency guard.
- [ ] **M11** — Keyword index covers only the first `desc_index_chars` of each description (requires a rebuild); with semantic results, description-only keyword hits below the semantic floor are dropped.
- [ ] **M12** — SKU search (exact/prefix SKU matches ranked first, SKU in the title-weighted text), `accept_status = '0'` export filter, one-command `release.py` (+ `release.bat`) producing `release.zip`, and `reload.php?load=1` loading the staging table in PHP before the atomic swap.
- [ ] **M13** — Specs field: product attributes and PHP-serialized `feature` titles in a FULLTEXT-indexed `normalized_specs` column (`spec_weight`, ranked between title and description-only matches, also in hybrid mode), and in the embedded passage within `SEARCH_PASSAGE_CHAR_LIMIT`.
- [ ] **M14** — Self-contained release (browser model included by default, `--no-model` for routine updates, `db/schema.sql` packed) and a web installer, `public/install.php`: validates the form, tests the DB connection, creates the schema, writes `config.php` (never overwriting one), loads the staged catalog, then refuses to run again. `store_base` / `image_base` config for absolute `with_details` links.
- [ ] **M15** — Name / alias / form matching: standalone Roman numerals → digits (normalization version 3), query-side expansion from `synonyms.json` and an owner-maintained `aliases.json` (whole terms, title-only variants), `desc_index_chars` default 800.
- [ ] **M14.1** — `release.py` downloads the browser assets itself when `client/` lacks them (cached; `--no-model` skips), so one command builds a complete release. `desc_index_chars` leaves the installer and server config and becomes `release.py --desc-index-chars`.
- [ ] **M15.1** — `normalization_version` in `config.php` (and the installer prefill) defaults to the code's `Normalizer::VERSION`, and the pipeline always stamps its own `NORMALIZATION_VERSION`, so a rules bump reloads with no config edit.
- [ ] **M17** — VPS vector service (`vps/`): bge-m3 (ONNX, CPU) query embedding, `POST /search-vectors` global cosine top-K with a score floor, token auth, validated atomic `POST /reload`, `GET /health`, `setup.sh` + systemd; `build.py` / `release.py --vps-out` produce its bge-m3 product vectors.
- [ ] **M16** — Multi-word queries require every word across title, specs and description (per alias variant), ranked title band, spec band (no title match), description-only, then score; best-partial fallback when nothing holds every word; semantic neighbours not appended to all-words hits (`SEARCH_REQUIRE_ALL_TERMS`, default on).
- [ ] **M18** — Semantic tier from the VPS: `/search` POSTs the query server-to-server to the VPS `/search-vectors` (`SEARCH_VPS_URL` / `_TOKEN` / `_TIMEOUT_MS`, `min_score` = the cPanel floor, now 0.4 for bge-m3), RRF-merges the neighbours as before, and falls back to logged keyword-only results when the VPS is off, down, slow or failing. The browser model is gone (`client/`, `fetch_web_model.py`, the model in `release.zip`, `q_vector`); the test page and storefront snippet send only `{q}`.
- [ ] **M20** — Blended hybrid ranking: relevance = keyword_weight × keyword_norm + semantic_weight × semantic_norm with a combined floor (`SEARCH_KEYWORD_WEIGHT` / `_SEMANTIC_WEIGHT` / `_MIN_RELEVANCE`); replaces the keyword-first tiers, RRF and the description-only gate. Server-only.
- [ ] **M21** — Catalog tags (`oc_tag` / `oc_product_tag`, exported as `tags`) end to end: `normalized_tags` in the FULLTEXT index (weighted just below the title) and the embedding passage, learned by "did you mean"; brand and category as searched fields (`normalized_brand`, `normalized_category`) with config-driven weights; brand / category / tag-phrase ranking boosts after the floor; `search_logs` gains `did_you_mean` and `tier`; read-only token-protected `logs.php` (recent, zero-result, slowest); token-guarded `debug` score breakdown on `/search`; `release.py` also builds the VPS vectors and prints the deploy commands for both hosts. `normalization_version`, model and dim unchanged.
- [ ] **M23** — Recall: collapsed names (`normalized_collapsed`, `Normalizer::collapse` / `normalize.collapse` with a shared parity fixture; a schema change, rebuild + reload) so `farcry` = `far cry` = `Far Cry`, `dualsense` = `dual sense`; soft AND (full-coverage hits first, partial-coverage top-up when fewer than `SEARCH_SOFT_AND_MIN_RESULTS`, penalized, server-only); alias variants matched in title, tags, brand, category and specs (FULLTEXT-sized tokens) and an all-terms-swapped variant for multi-alias queries; eval cases (negative queries supported).
- [ ] **M22** — Offline build: `pipeline/db_export.py` exports the catalog straight from the OpenCart DB (server-side cursor, MariaDB `max_statement_time`, this shop's single-language / `meta_title` query) as the recommended step 1, phpMyAdmin CSV demoted to a warned fallback, plus a low-row-count guard in `build.py`; `RealEmbedder` picks cuda/cpu, fp16 on GPU, a configurable batch size and shows progress (`SEARCH_EMBED_*`, [PIPELINE.md](PIPELINE.md#where-to-build-gpu) and [TRAINING-COLAB.md](TRAINING-COLAB.md)); `db_export.py` stays Python 3.6-compatible (it runs on the cPanel server); `release.py` builds the unread cPanel `vectors.bin` with the mock embedder (`SEARCH_BUNDLE_EMBEDDER`). No contract, model, dim or normalization change.
- [ ] **M24** — Documentation restructure: the README is a short onboarding with a documentation index; the technical detail lives in `docs/` (this folder), including the new Colab rebuild / retrain runbook `TRAINING-COLAB.md`. Docs only, no behavior change.
- [ ] **M25** — Offline alias generator: `pipeline/gen_aliases.py` asks an OpenAI-compatible LLM for the Persian spellings of the catalog's English brand / title names, writes candidates for human review and `--merge`s the reviewed ones into `aliases.json` ([ALIASES.md](ALIASES.md)). Prep-time only; the search path is unchanged.
- [ ] **M26** — Optional Redis result cache for `/search` (`Cache.php`, `RedisClient.php`, config `redis`, `vps/setup.sh --redis`): off by default, any Redis error is a miss, `/reload` flushes it, `search_logs.cache_hit`. Redis runs on the VPS; cPanel runs only the in-repo RESP client.


## Contributing

- One PR per milestone, in order; each ships with tests and passes CI.
- Never commit or push to `main`/`master`; never merge your own PR.
- Conventional Commits; PSR-12 (PHP) and ruff-clean (Python); English only.

See `CLAUDE.md` §9–§11 for the full workflow and coding standards.
