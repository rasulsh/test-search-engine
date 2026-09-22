# CLAUDE.md — Search Service Implementation Guide

Instructions for Claude Code to implement this project end to end. Read this file
fully before writing any code. Follow it as the single source of truth. When a
detail is missing, choose the simplest option consistent with the constraints
below and note it in the PR description — do not add scope.

---

## 1. What this is

A standalone product-search service for an OpenCart 2.0.3.1 storefront
(~20,000 bilingual Persian/English products). Two-tier search:

- **Tier 1 — keyword (always on, server-side):** MySQL FULLTEXT + Persian
  normalization + typo tolerance + keyboard-layout tolerance + "did you mean".
  This tier must work with zero ML and never breaks.
- **Tier 2 — semantic (additive enhancement):** cosine similarity over
  precomputed product vectors. If Tier 2 is unavailable, Tier 1 still answers.

Heavy processing (product embedding) runs **offline** on the developer's GPU
machine. The **cPanel** server only serves requests: keyword search plus cosine
math in PHP over precomputed vectors. **Query embedding happens off-server, in
the browser (WASM).** The server never runs an embedding model.

### Hard constraints (do not violate)
- Target host is **cPanel shared hosting** (PHP 8.x + LiteSpeed + MariaDB) under
  CloudLinux LVE limits. **No long-running daemons, no root, no background
  services.** Therefore: **no Meilisearch, no Qdrant, no Redis, no Elasticsearch,
  no Python service on the server.**
- Search latency budget: **under 200 ms** server-side per request.
- The server runs **plain PHP, no framework** (no Laravel/Symfony). A tiny
  front controller only. Runtime must have **zero Composer runtime
  dependencies**; Composer is dev-only (tests, lint).
- Everything the server needs is static data produced offline (an index
  "bundle") plus MySQL rows.

### Out of scope (do NOT build now)
- Admin panel / product-management UI (deferred).
- OpenCart core changes. The storefront integration is a separate OC module in
  another repo; here you deliver only the client embedder and a documented HTTP
  contract.
- Any ANN engine, vector daemon, or GPU dependency on the server.

---

## 2. Repository structure

Keep it shallow. Small, single-purpose files. No deep trees, no speculative
abstraction layers.

```
.
├── CLAUDE.md
├── README.md
├── INTEGRATION.md                # HTTP contract + storefront wiring
├── .gitignore
├── .github/workflows/ci.yml
├── db/
│   └── schema.sql                # products (+FULLTEXT), search_logs
├── pipeline/                     # OFFLINE, developer GPU machine (Python 3.11+)
│   ├── build.py                  # orchestrator: SQL in -> bundle out
│   ├── normalize.py              # Persian normalization (canonical rules)
│   ├── embed.py                  # loads model, embeds products (GPU or mock)
│   ├── keyword.py                # builds synonyms + spellcheck dict from catalog
│   ├── config.py
│   ├── requirements.txt
│   └── tests/
├── server/                       # cPanel (PHP 8.x, no framework)
│   ├── public/
│   │   ├── index.php             # front controller / router
│   │   ├── search.php            # POST /search
│   │   ├── health.php            # GET /health
│   │   └── reload.php            # POST /reload (token-protected, atomic swap)
│   ├── src/
│   │   ├── Normalizer.php         # MUST mirror pipeline/normalize.py exactly
│   │   ├── Keyword.php            # FULLTEXT + fuzzy + keymap + did-you-mean
│   │   ├── Vectors.php            # load vectors.bin, cosine top-K + re-rank
│   │   ├── Ranker.php             # hybrid merge + business ranking
│   │   ├── Logger.php             # search logs
│   │   └── Db.php                 # thin PDO wrapper
│   ├── config.php                # reads from env; config.example.php committed
│   ├── data/                      # active bundle (gitignored)
│   └── tests/
├── client/
│   ├── embedder.js               # transformers.js wrapper, SAME model as pipeline
│   └── model/                    # ONNX model assets (gitignored, documented)
└── fixtures/
    ├── products.sample.sql       # tiny catalog for tests
    ├── normalization_cases.json  # shared parity fixture (both test suites read it)
    └── eval_queries.json         # labeled queries for precision/recall
```

---

## 3. The three hard contracts

Breaking any of these produces silently wrong results. Enforce each with a test.

1. **Normalization parity.** `pipeline/normalize.py` and
   `server/src/Normalizer.php` must produce identical output for the same input
   (ZWNJ/half-space, ی/ک unification, digit folding, diacritic removal, model-name
   canonicalization, whitespace). Both test suites load
   `fixtures/normalization_cases.json` and assert equality. Bump
   `normalization_version` in both when rules change.
2. **Model parity.** `pipeline/embed.py` (product vectors) and
   `client/embedder.js` (query vectors) must use the same model, revision,
   pooling, and L2-normalization. Vectors from different models are not
   comparable.
3. **Bundle compatibility.** `meta.json` carries `{model, revision, dim,
   normalization_version, count, built_at, checksum}`. `POST /reload` must
   reject a bundle whose `model`, `dim`, or `normalization_version` does not
   match `server/config.php`.

---

## 4. Data contract (the bundle)

`pipeline/build.py` outputs a directory the server consumes:

- `vectors.bin` — little-endian float32, row-major, `count × dim`, each row
  L2-normalized at build time.
- `vectors.idx` — UTF-8 text, one `product_id` per line, line order == row order
  in `vectors.bin`.
- `products.load.sql` — INSERT/REPLACE rows for the `products` table (normalized
  title/desc + brand, category, model, price, stock, url, image, popularity).
- `synonyms.json`, `spellcheck.txt`, `keymap.json`.
- `meta.json` — see contract 3.

The server reads `vectors.bin` as packed floats (`unpack`), not JSON. Cache the
parsed vectors in APCu when available; degrade gracefully when not.

---

## 5. Runtime flow (`POST /search`)

Request: `{ "q": string, "q_vector"?: number[dim], "customer_id"?: string,
"limit"?: int }`

1. Normalize `q` (Normalizer.php).
2. Keyboard-layout fix + spell-correct -> keyword query; compute "did you mean".
3. Tier 1: FULLTEXT search -> candidates with keyword scores.
4. Tier 2 (only if `q_vector` present and bundle loaded):
   - Global cosine top-K over `vectors.bin` (brute force, O(count·dim)).
   - This is what delivers cross-language recall; keep it, do not replace it with
     candidate-only re-rank.
   - Benchmark it. If it cannot meet the latency budget on realistic data,
     fall back to re-ranking Tier 1 candidates and record the limitation in the
     PR. Do NOT introduce a daemon or external engine.
5. Ranker.php: hybrid merge (keyword + semantic) + business ranking
   (stock, popularity).
6. Logger.php: write one row to `search_logs`
   (ts, raw_q, norm_q, had_vector, result_count, top_ids, customer_id,
   latency_ms).
7. Return ordered `product_id`s + the "did you mean" suggestion.

If `q_vector` is absent, return Tier 1 results (keyword-only). Never error the
whole request because Tier 2 is unavailable.

---

## 6. Update / deploy flow (documented in README, not automated on server)

1. Export products from OpenCart DB to `export.sql`.
2. `python pipeline/build.py --sql export.sql --out ./bundle`
3. Upload `./bundle` to `server/data_incoming/`; load `products.load.sql` into a
   `products_new` staging table.
4. `POST /reload` with the token: validate `meta.json`; then swap atomically —
   `rename(data_incoming -> data)` and
   `RENAME TABLE products TO products_old, products_new TO products`.
   A rebuild must never serve a half-updated index. Keep the previous version for
   one-step rollback.

---

## 7. Tech + config

- Server: PHP 8.1+, PDO/MySQLi, no framework. Dev-only: PHPUnit, php-cs-fixer or
  PHP_CodeSniffer (PSR-12).
- Pipeline: Python 3.11+, `sentence-transformers`/`transformers`, `numpy`. Real
  embedding needs a GPU; tests use a deterministic **mock embedder** (config
  `EMBEDDER=mock|real`) so CI needs no GPU or model download.
- Client: `transformers.js` (Xenova) running an ONNX model in-browser.
- Default model: `intfloat/multilingual-e5-small` (dim 384). **Parameterize it**
  in `pipeline/config.py`, `server/config.php`, and `client/embedder.js` — it is
  pending a Persian-quality test and may change. Never hardcode the model,
  dim, or thresholds; read them from config.
- Config via env; commit `config.example.php` and `.env.example`. Never commit
  secrets, `data/`, bundles, or model files.

---

## 8. Testing requirements

Every PR ships with tests and passes CI. No PR merges with failing or missing
tests for the code it adds.

- **Pipeline (pytest):** SQL parsing on the sample fixture; normalization units;
  bundle format (dim, count, L2-normalization) with the mock embedder;
  determinism.
- **Server (PHPUnit):** Normalizer; Keyword (against a seeded test DB from
  `db/schema.sql` + `fixtures/products.sample.sql`); Vectors cosine correctness
  and top-K; Ranker merge; `/search` and `/health`; `/reload` atomicity and
  meta-mismatch rejection.
- **Parity test (both suites):** load `fixtures/normalization_cases.json`, assert
  Python and PHP normalization outputs are identical.
- **Latency guard:** synthesize a `20000 × dim` vector file, assert the PHP
  cosine top-K path completes under a fixed threshold. CI hardware differs from
  cPanel — treat this as a regression guard, not an absolute SLA, and note the
  measured number in the PR.
- **Eval harness:** a script that runs `fixtures/eval_queries.json` (labeled
  query -> expected product_ids) and reports precision@k / recall@k, so search
  changes are measurable. Seed small; it will grow from real logs.

---

## 9. Implementation workflow — milestones (one PR per milestone)

Deliver in this order. Each milestone is a small, reviewable PR that builds on
merged predecessors. Do not batch milestones into one PR. Stop after each PR and
wait for review before starting the next.

- **M0 — Scaffold:** structure, `.gitignore`, CI, `config.example.php`,
  `.env.example`, README skeleton. No logic.
- **M1 — Keyword backbone:** `db/schema.sql`, product loader, FULLTEXT search,
  `/health`. Delivers usable keyword search with no ML. Tests.
- **M2 — Persian + typo + logging:** `Normalizer.php` + `normalize.py` (parity),
  `keyword.py` (synonyms + spellcheck dict), keymap tolerance, "did you mean",
  `Logger.php`. Tests + parity test.
- **M3 — Offline pipeline:** `build.py`, `embed.py` (mock + real), bundle output,
  `meta.json`, `/reload` atomic swap. Tests.
- **M4 — Semantic tier:** `client/embedder.js`, `Vectors.php` cosine top-K,
  `Ranker.php` hybrid, `/search` vector path. Tests + latency guard + eval
  harness.
- **M5 — Integration + docs:** `INTEGRATION.md` (HTTP contract + minimal
  storefront JS with keyword-only fallback), finalized `README.md`.

**Definition of Done (per PR):** code + tests pass in CI; docs updated;
KISS respected (no unused abstraction); PR description complete; no secrets or
data files committed.

---

## 10. Git / branch / PR workflow (mandatory)

- **Never commit or push to `main` or `master`. Never merge your own PRs.**
- One branch per task: `feat/<milestone>-<slug>`, `test/…`, `docs/…`, `fix/…`,
  `chore/…`.
- Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
- Open a PR targeting `main` with `gh pr create`. Keep PRs small and focused.
- Never force-push shared branches; never rewrite `main` history.
- CI (`.github/workflows/ci.yml`) runs on every PR: PHPUnit, pytest, and linters.
  A PR that fails CI is not ready.
- `.gitignore` must exclude: `vendor/`, `node_modules/`, `__pycache__/`,
  `server/data/`, `server/data_incoming/`, bundles, `client/model/`, `.env`,
  local config.

### PR description template
```
## What
<one-paragraph summary of the change>

## Why
<which milestone / contract this advances>

## How to test
<commands to run tests locally; expected result>

## Checklist
- [ ] Tests added and passing in CI
- [ ] Docs updated (README / INTEGRATION / CLAUDE if needed)
- [ ] No secrets, data files, or model binaries committed
- [ ] KISS: no unnecessary files or abstraction
- [ ] Targets main from a feature branch; not merged
```

---

## 11. Coding standards

- PHP: PSR-12, PHP 8.1+, strict types, PDO with prepared statements.
- Python: PEP 8, type hints, `ruff`/`flake8` clean.
- **All shell scripts and all code (comments, identifiers, output) in English
  only.** No Persian inside scripts or terminal output.
- Minimal comments — explain "why", never restate "what".
- Small files, flat structure, no dependency the task does not require.
- Read config; never hardcode model names, dimensions, thresholds, table names,
  or credentials.