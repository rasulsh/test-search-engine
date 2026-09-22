# Product Search Service

A standalone two-tier product-search service for an OpenCart 2.0.3.1 storefront
(~20,000 bilingual Persian/English products). Designed to run on **cPanel shared
hosting** (PHP 8.x + LiteSpeed + MariaDB) with no daemons and no external search
engines.

> This is the implementation repository. Authoritative design, contracts, and the
> milestone plan live in [`CLAUDE.md`](./CLAUDE.md). The storefront-side HTTP
> contract will be documented in `INTEGRATION.md` (added in M5).

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
db/         SQL schema (products + FULLTEXT, search_logs)          [M1]
pipeline/   Offline build pipeline (Python 3.11+, GPU or mock)     [M3]
server/     cPanel runtime (PHP 8.1+, no framework)                [M1+]
client/     Browser query embedder (transformers.js / ONNX)        [M4]
fixtures/   Shared test fixtures (normalization cases, eval set)   [M1+]
```

Directories are scaffolded now; files land in the milestone shown in brackets.

## Requirements

- **Server (runtime):** PHP 8.1+, PDO/MySQLi, MySQL/MariaDB. **Zero Composer
  runtime dependencies** — Composer is dev-only.
- **Pipeline (offline):** Python 3.11+. Real embedding needs a GPU; tests use a
  deterministic mock embedder, so CI needs no GPU or model download.
- **Dev tooling:** Composer (PHPUnit, PHP_CodeSniffer), Python (pytest, ruff).

## Configuration

All model names, dimensions, thresholds, and credentials are read from config —
never hardcoded.

- Server: copy `server/config.example.php` to `server/config.php`, or supply the
  environment variables it reads (see `.env.example`).
- The embedding model, revision, and dimension are parameterized and shared
  across server, pipeline, and client (parity contracts — see `CLAUDE.md` §3).

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

- `GET /health` — returns `{ "status": "ok|degraded", "checks": { "database":
  bool, "product_count": int|null } }` (200 when the database is reachable, 503
  otherwise). Served via the `server/public` front controller; `/health.php` is
  also reachable directly for hosts without URL rewriting.

`POST /search` and `POST /reload` arrive in later milestones.

## Deploy / update flow

The rebuild-and-reload flow (export → build bundle → upload → atomic swap via
token-protected `POST /reload`) is documented here as milestones land. See
`CLAUDE.md` §6. _TODO: expand in M3._

## Milestone status

- [x] **M0** — Scaffold: structure, `.gitignore`, CI, config examples, README.
- [x] **M1** — Keyword backbone (schema, loader, FULLTEXT + LIKE fallback, `/health`).
- [ ] **M2** — Persian normalization + typo/keymap + "did you mean" + logging.
- [ ] **M3** — Offline pipeline + bundle + `meta.json` + atomic `/reload`.
- [ ] **M4** — Semantic tier (client embedder, cosine top-K, hybrid ranker).
- [ ] **M5** — Integration + docs (`INTEGRATION.md`, finalized README).

## Contributing

- One PR per milestone, in order; each ships with tests and passes CI.
- Never commit or push to `main`/`master`; never merge your own PR.
- Conventional Commits; PSR-12 (PHP) and ruff-clean (Python); English only.

See `CLAUDE.md` §9–§11 for the full workflow and coding standards.
