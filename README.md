# Product Search Service

A standalone two-tier product-search service for an OpenCart 2.0.3.1 storefront (~20,000 bilingual Persian/English products). The keyword tier (MySQL FULLTEXT, Persian normalization, typo and keyboard-layout tolerance, "did you mean", soft all-words matching, aliases) runs on **cPanel shared hosting** (PHP 8.x + LiteSpeed + MariaDB) with no daemons and no external search engines. An optional semantic tier (bge-m3 vectors on a small separate VM) adds cross-language recall; if it is missing or slow, the keyword tier still answers. Heavy work (embedding the catalog) runs offline, on a free Colab GPU.

## Documentation

| doc | what is in it |
| --- | --- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | The two tiers, repository layout, the three hard contracts, the semantic tier, development and tests, milestone status |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Every config key: server (`SEARCH_*`), pipeline, export (`OC_*`), VPS (`VPS_*`) |
| [docs/PIPELINE.md](docs/PIPELINE.md) | Offline build, the bundle format, the data model (specs, tags, brand, category, normalization, the M23 space-collapse field) and the OpenCart export (`db_export.py`) |
| [docs/SEARCH-BEHAVIOR.md](docs/SEARCH-BEHAVIOR.md) | How a query is matched, ranked and corrected: field weights, aliases, soft AND, SKU, "did you mean", the hybrid blend |
| [docs/ALIASES.md](docs/ALIASES.md) | Offline LLM alias generator: Persian spellings of English names, review, merge, rebuild |
| [docs/TRAINING-COLAB.md](docs/TRAINING-COLAB.md) | **Rebuild / retrain end to end on Google Colab** (export, upload, build, download), the recommended build path |
| [docs/DEPLOY.md](docs/DEPLOY.md) | One-time cPanel setup, every catalog update, upgrade notes, fallback, rollback, go-live checklist |
| [docs/SEARCH-API.md](docs/SEARCH-API.md) | HTTP endpoints, `logs.php`, the `debug` flag, eval harness, search test page, model-parity check |
| [INTEGRATION.md](INTEGRATION.md) | The storefront HTTP contract, the reference storefront snippet, the cPanel-to-VPS call |
| [vps/README.md](vps/README.md) | The VPS vector-service runbook |
| [CLAUDE.md](CLAUDE.md) | The implementation spec for Claude Code: contracts, milestones, workflow |

## Requirements

- **Server (runtime):** PHP 8.1+, PDO/MySQLi, MySQL/MariaDB, PHP's curl extension (to reach the VPS; without it search stays keyword-only). **Zero Composer runtime dependencies**, Composer is dev-only.
- **Build (offline):** Python 3.11+ and a GPU for the real embedding. Use a free Colab T4: [docs/TRAINING-COLAB.md](docs/TRAINING-COLAB.md). Tests use a deterministic mock embedder, so CI needs no GPU or model download.
- **Semantic tier (optional):** a VM with 2-4 GB RAM running the vector service, see [vps/README.md](vps/README.md).
- **Browser:** nothing. No model, runtime or font is loaded by shoppers.

## Quick start

1. **Export** the catalog where the OpenCart database is local: `python3 pipeline/db_export.py` writes `export.csv` ([docs/PIPELINE.md](docs/PIPELINE.md#exporting-from-opencart-db_exportpy)).
2. **Build** `release.zip` (server code + index bundle) and `vps_vectors/` with one command, on Colab ([docs/TRAINING-COLAB.md](docs/TRAINING-COLAB.md)) or any GPU machine:
   ```bash
   python pipeline/release.py --csv export.csv --out release.zip
   ```
3. **First deploy on cPanel:** create a database, extract `release.zip` into `~/search-service/server/` (outside the web root), point the document root at `server/public` (preferred: a subdomain whose Document Root is `.../server/public`; fallback: a symlink or the public files in a `public_html` subfolder plus `SEARCH_APP_BASE`), open `install.php`, fill in the form, then **delete `install.php`** ([docs/DEPLOY.md](docs/DEPLOY.md#layout)).

   ```
   ~/search-service/server/      app base: src/, config.php, data/, ...  (never web-accessible)
   └── public/                   the ONLY folder served (document root)
   ```
4. **Semantic tier (optional):** set up the VM and load `vps_vectors/` there ([vps/README.md](vps/README.md)), then enter its URL and token in the installer or `config.php`. Until then search is keyword-only.
5. **Storefront:** install the snippet from [INTEGRATION.md](INTEGRATION.md#storefront-reference). It sends only the query text.

Every later catalog update is: export, `release.py`, unzip on the host, one `curl` to reload ([docs/DEPLOY.md](docs/DEPLOY.md#every-catalog-update)).

## Basic usage

```bash
# search: ordered product ids (+ "did you mean")
curl -sS -X POST https://shop.example.com/search-api/search.php \
     -H 'Content-Type: application/json' -d '{"q":"far cry","limit":10}'

# monitoring: database reachability and live product count
curl -sS https://shop.example.com/search-api/health.php
```

On a subfolder deploy both forms work (`/search-api/search` and `/search-api/search.php`). Request and response shapes, every endpoint and the error codes: [docs/SEARCH-API.md](docs/SEARCH-API.md) and [INTEGRATION.md](INTEGRATION.md#http-contract). To try the service by hand, open `/search-api/test.html`.

## Development

```bash
composer install && vendor/bin/phpcs && vendor/bin/phpunit      # server (needs MariaDB for the DB tests)
pip install -r pipeline/requirements-dev.txt && ruff check pipeline vps && pytest
```

CI (`.github/workflows/ci.yml`) runs the same checks on every pull request. Details, including a local MariaDB for the database-backed tests: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#development). One PR per milestone, never push to `master`, never merge your own PR; see [CLAUDE.md](CLAUDE.md) §9-§11.
