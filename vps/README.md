# VPS vector service

A small, self-contained Python service for a 2–4 GB VPS. It holds the bge-m3
query model and the product vectors, and answers **query text → top-K
`{product_id, score}`**. cPanel keeps the keyword tier and stays the public,
same-origin endpoint; since M18 its `/search` calls this service
server-to-server (`SEARCH_VPS_URL` / `SEARCH_VPS_TOKEN` /
`SEARCH_VPS_TIMEOUT_MS`, see INTEGRATION.md, "Semantic tier: cPanel to VPS")
and falls back to keyword-only results whenever it is unreachable, slow or
failing. The service is never meant to be reachable from browsers.

Product vectors are still built **offline** on the GPU machine
(`pipeline/build.py --vps-out` / `pipeline/release.py --vps-out`) and uploaded
here.

```
vps/
  search_vectors/      the service (FastAPI, run as `python -m search_vectors`)
    app.py             HTTP API
    store.py           vector load + validation, cosine top-K, atomic reload
    embedder.py        ONNX (bge-m3) query embedder, deterministic mock for tests
    config.py          settings from the environment
    fetch_model.py     pinned + checksummed model download
  setup.sh             provisioning (Debian/Ubuntu, systemd, Caddy TLS proxy, ufw)
  search-vectors.service
  caddy.service        systemd unit for the pinned Caddy binary
  search-vectors.env.example
  tests/               pytest (mock embedder; real model behind a flag)
```

## HTTP API

All requests and responses are JSON. Every endpoint except `/health` needs
`Authorization: Bearer <VPS_TOKEN>`; a missing or wrong token gets `401`.

| Method + path | Body | Success | Errors |
|---|---|---|---|
| `GET /health` (open) | – | `200 {"ok":true,"model","dim","count","built_at"}` | `503` same shape with `"ok":false` while no vectors are loaded |
| `POST /search-vectors` | `{"q": "...", "limit"?: int, "min_score"?: float}` | `200 {"results":[{"product_id":int,"score":float}], "model", "took_ms"}` | `401`, `422` (blank `q`, `limit < 1`, `min_score` outside -1..1), `503` no vectors loaded |
| `POST /reload` | – | `200 {"ok":true,"count","model","built_at"}` | `401`, `409` reload already running, `422 {"ok":false,"error"}` invalid/mismatched upload, `500` disk swap failed |

`/search-vectors`:

- `q` is trimmed and cut to `VPS_MAX_QUERY_CHARS`, prefixed with
  `VPS_QUERY_PREFIX` (empty for bge-m3: its dense retrieval takes no
  instruction), embedded, and compared by cosine against **every** product
  vector (brute force; 20k × 1024 floats is ~80 MB and a few ms).
- `limit` defaults to `VPS_DEFAULT_LIMIT` and is capped at `VPS_MAX_LIMIT`.
- Results are best-first (ties by `product_id`). Neighbours below the floor
  are dropped, so a query with nothing close returns `"results": []`, not
  far-away products. The floor is the request's `min_score` when given (cPanel
  always sends its `SEARCH_SEMANTIC_MIN_SCORE`, so the relevance floor is
  tuned in one place), else `VPS_SEMANTIC_MIN_SCORE`.

```bash
curl -s https://vsearch.example.com/search-vectors \
  -H "Authorization: Bearer $VPS_TOKEN" -H 'Content-Type: application/json' \
  -d '{"q":"هدفون بی‌سیم","limit":20}'
# {"results":[{"product_id":2002,"score":0.71},...],"model":"BAAI/bge-m3","took_ms":31.4}
```

## Setup (fresh Debian 12 / Ubuntu 22.04+ VPS)

Needs Python 3.11+, ~1.1 GB RAM for the int8 model (fp32: ~1.5 GB) plus ~2×
the vectors file during a reload, and ~600 MB disk for the int8 model (fp32:
~2.3 GB).

**One prerequisite, outside the script:** create a DNS **A record** for the
name you will use (e.g. `vsearch.example.com`) pointing at the VPS's public IP.
`setup.sh` cannot create DNS, and Let's Encrypt needs it to resolve before it
can issue the certificate. Then:

```bash
git clone <this repo> && cd <repo>/vps
sudo ./setup.sh --domain vsearch.example.com
# optional: only the cPanel server may reach 443
sudo ./setup.sh --domain vsearch.example.com --allow-ip <CPANEL_OUTBOUND_IP>
```

`--domain` can also be given as `SEARCH_VPS_DOMAIN`, `--allow-ip` as
`SEARCH_VPS_ALLOW_IP`. When it finishes, the service answers at
`https://vsearch.example.com/` on 443 with a valid certificate; no manual
Caddy, TLS or firewall steps remain. Put that URL into cPanel's
`SEARCH_VPS_URL` and the `VPS_TOKEN` from `/etc/search-vectors.env` into
`SEARCH_VPS_TOKEN`.

Without `--domain`, the script prints the DNS instruction, skips the proxy and
firewall, and still brings the service up on `127.0.0.1:8600`; re-run with
`--domain` once the A record exists.

`setup.sh` (safe to re-run; it updates code and dependencies and keeps the
env file, token, model, vectors and the live certificate):

1. installs whichever of `python3`, `python3-venv`, `curl`, `tar`, `ufw` is
   missing (apt is not touched when nothing is missing, so an unreachable
   distro mirror does not block a re-run), creates the `searchvec` system user;
2. copies `search_vectors/` to `/opt/search-vectors/app` and installs
   `requirements.txt` into `/opt/search-vectors/venv` (CPU only, no torch);
3. writes `/etc/search-vectors.env` from `search-vectors.env.example` **once**,
   with a random 64-hex-character `VPS_TOKEN` (never overwritten on re-runs);
4. downloads the query model into `VPS_MODEL_DIR` (`fetch_model.py`: pinned
   Hugging Face commit, every file sha256-checked, no partial files);
5. installs and starts the `search-vectors` systemd unit (one worker, runs as
   `searchvec`, read-only system except `/var/lib/search-vectors`);
6. with `--domain`: downloads the official **Caddy static binary** over HTTPS
   from GitHub Releases, pinned to a version and **sha512** (the digest Caddy
   publishes; a mismatch aborts before anything is installed; no distro apt
   mirror involved), installs it to `/usr/local/bin/caddy` with the
   `caddy.service` unit, and writes `/etc/caddy/Caddyfile`:

   ```
   vsearch.example.com {
       reverse_proxy 127.0.0.1:8600
   }
   ```

   Caddy obtains and renews the Let's Encrypt certificate itself (state in
   `/var/lib/caddy`, kept across re-runs). The vector service stays bound to
   `127.0.0.1`;
7. with `--domain`: configures **ufw**: allows SSH (ports read from `sshd -T`,
   default 22), 80 (ACME challenge and redirect) and 443, explicitly denies
   the service port (8600), and enables ufw.

Re-running with another `--domain` or `--allow-ip` rewrites the Caddyfile and
the 443 rule in place and reloads Caddy (the 443 rule is removed and re-added,
so 443 blips for a moment). Re-running without `--domain` leaves an existing
proxy untouched. To change the pinned Caddy version, update `CADDY_VERSION`
and the two sha512 values in `setup.sh` together (from the release's
`caddy_<version>_checksums.txt`).

```bash
systemctl status search-vectors caddy
journalctl -u search-vectors -f
journalctl -u caddy -f                   # certificate issuance
curl -s http://127.0.0.1:8600/health     # 503 until the first vectors are loaded
curl -s https://vsearch.example.com/health
```

### Configuration (`/etc/search-vectors.env`)

| Variable | Default | Meaning |
|---|---|---|
| `VPS_TOKEN` | – (required, ≥16 chars) | Shared secret for everything but `/health`. The service refuses to start without it. |
| `VPS_HOST` / `VPS_PORT` | `127.0.0.1` / `8600` | Bind address (see "Keeping the model private"). |
| `VPS_SSL_CERTFILE` / `VPS_SSL_KEYFILE` | empty | Serve HTTPS directly (uvicorn). |
| `VPS_MODEL`, `VPS_MODEL_REVISION`, `VPS_MODEL_DIM`, `VPS_POOLING`, `VPS_QUERY_PREFIX` | `BAAI/bge-m3`, pinned commit, `1024`, `cls`, empty | Query model identity. **Must equal** `pipeline/config.py` `vps_model` (`SEARCH_VPS_*`); `/reload` rejects vectors whose `meta.json` differs. |
| `VPS_EMBEDDER` | `onnx` | `mock` only for tests (and only accepts mock-built vectors). |
| `VPS_MODEL_DIR`, `VPS_ONNX_FILE` | `/opt/search-vectors/model`, `onnx/model_quantized.onnx` | Model location; `onnx/model.onnx` = fp32 build (see "Model parity"). Re-run `setup.sh` after changing the file. |
| `VPS_MAX_TOKENS`, `VPS_THREADS` | `512`, `0` (all cores) | Tokenizer truncation; ONNX Runtime threads. |
| `VPS_DATA_DIR` | `/var/lib/search-vectors` | Holds `active/`, `incoming/`, `previous/`. |
| `VPS_SEMANTIC_MIN_SCORE` | `0.5` | Cosine floor for requests without `min_score` (cPanel always sends its own, `SEARCH_SEMANTIC_MIN_SCORE`, default 0.4). bge-m3 scores differ from e5's (e5's floor was 0.82); tune on the eval set. On the 6-product fixture, right single-word hits scored 0.42–0.49 (`تلویزیون` → the LG TV 0.42) while wrong ones reached 0.43, and multi-word hits 0.53–0.62: no floor separates short queries, which is why cPanel will fuse with keyword results. |
| `VPS_DEFAULT_LIMIT` / `VPS_MAX_LIMIT` | `100` / `500` | Result count. |
| `VPS_MAX_QUERY_CHARS` | `200` | Longer queries are cut. |

After editing: `sudo systemctl restart search-vectors`.

### Keeping the model private

Only cPanel should ever reach the service. `setup.sh --domain` already sets up
the locks that matter: TLS on 443 through Caddy and the bearer token, with the
service itself on `127.0.0.1` and its port denied by ufw.

- **Default:** 443 is open to the internet, protected by TLS and the bearer
  token (`/health` is public; everything else needs the token).
- **Stricter:** `--allow-ip <CPANEL_OUTBOUND_IP>` limits 443 to the cPanel
  server. Port 80 stays open on purpose: Let's Encrypt validates and renews
  over it, and it only serves Caddy's redirect. Use a stable IP: if the host's
  outbound IP changes, cPanel falls back to keyword-only search until you
  re-run with the new IP.
- **No proxy (advanced):** set `VPS_HOST` to the public IP and
  `VPS_SSL_CERTFILE`/`VPS_SSL_KEYFILE`, and firewall port 8600 yourself.

Find the cPanel server's outbound IP from cPanel ("Shared IP Address" /
"Server Information") or ask the host; shared hosts sometimes send outbound
traffic from a different IP than the site's. `/docs` and `/openapi.json` are
disabled. `/health` is open but reveals only the model name and vector count.

## Updating the product vectors

On the GPU machine, alongside the normal release:

```bash
EMBEDDER=real python pipeline/release.py --csv export.csv --out release.zip --vps-out ./vps_vectors
# or only the VPS vectors:
EMBEDDER=real python pipeline/build.py --csv export.csv --vps-out ./vps_vectors
```

`./vps_vectors/` holds `vectors.bin` (float32, count × 1024, L2-normalized
rows), `vectors.idx` (product ids, row order) and `meta.json`
(`model, revision, dim, pooling, query_prefix, passage_prefix, count, built_at,
checksum, embedder`). Upload and reload:

```bash
rsync -a --delete ./vps_vectors/ root@vps:/var/lib/search-vectors/incoming/
ssh root@vps chown -R searchvec: /var/lib/search-vectors/incoming
curl -s -X POST https://vsearch.example.com/reload -H "Authorization: Bearer $VPS_TOKEN"
# {"ok":true,"count":20000,"model":"BAAI/bge-m3","built_at":"..."}
```

`/reload` first loads and checks **everything** in `incoming/`: model, revision,
dim, pooling and query prefix against the service config, mock vs real
embedder, `count` vs file sizes and `vectors.idx` lines, the sha256 checksum,
unique integer ids, finite values and unit-length rows. Anything wrong → `422`,
and nothing changes (the old vectors keep serving; `incoming/` is left for
inspection). Only then it swaps: `active/ → previous/`, `incoming/ → active/`,
and the in-memory index is replaced by one reference swap, so a search never
sees a mix of two versions. A second reload while one runs gets `409`.

**Rollback (one step):** put the previous vectors back into `incoming/` and
reload:

```bash
ssh root@vps 'cp -a /var/lib/search-vectors/previous /var/lib/search-vectors/incoming'
curl -s -X POST .../reload -H "Authorization: Bearer $VPS_TOKEN"
```

On start the service serves `active/` (or `previous/` if a swap was interrupted
between its two renames). Without valid vectors it still starts: `/health` and
`/search-vectors` answer `503`, and cPanel keeps serving keyword results.

## Model parity (contract 2)

Products are embedded by `pipeline/embed.py` with sentence-transformers
(`BAAI/bge-m3`, pinned revision); queries here by the ONNX export of the same
weights (`Xenova/bge-m3`, pinned commit), with the same tokenizer, CLS pooling
and L2 normalization. Measured on the development machine (CPU, 4 cores) with
`tests/test_real_model.py`:

| `VPS_ONNX_FILE` | Cosine vs pipeline vectors (same text) | Query latency p50 / max | Peak RSS (model) |
|---|---|---|---|
| `onnx/model.onnx` (fp32) | 1.00000 (exact) | ~56 ms / ~210 ms | ~1.5 GB |
| `onnx/model_quantized.onnx` (int8, default) | min 0.984, mean 0.987 | ~24 ms / ~110 ms | ~1.1 GB |

Cosine top-K over 20,000 × 1024 vectors adds ~8 ms. Through HTTP on the same
(shared, noisy) VM, a query took 50–100 ms end to end with 20k vectors loaded.
Real VPS numbers need measuring there.

Ranking effect of the int8 drift, on a synthetic bilingual catalog of 1,536
passages and 70 queries (fp32 vs int8 query vectors against the same pipeline
vectors): top-10 overlap 93 %, top-50 overlap 93 %, top-1 agreement 79 % (the
synthetic catalog has 8 near-duplicate variants per product, so top-1 often
flips between near-ties); scores differ by 0.009 on average, 0.044 at most —
keep `VPS_SEMANTIC_MIN_SCORE` well clear of that margin. The int8 build's
dynamic quantization also depends on the batch (padding shifts a vector by up
to ~0.03 per component), so the service embeds exactly one query per call.

The int8 build is the default so the service fits a 2 GB VPS (add ~2× the
vectors file, ~160 MB for 20k products, during a reload); with 4 GB, fp32 gives
exact parity. Re-check after changing either side:Re-check after changing either side:

```bash
python -m search_vectors.fetch_model --out /tmp/bge-m3              # from vps/
VPS_REAL_MODEL_DIR=/tmp/bge-m3 VPS_REAL_PARITY=1 pytest vps/tests/test_real_model.py
```

The pipeline's `RealEmbedder` also refuses a model whose own pooling differs
from `SEARCH_VPS_POOLING`.

## Development

```bash
pip install -r vps/requirements-dev.txt -r pipeline/requirements-dev.txt
pytest vps/tests          # mock embedder, no model download
ruff check vps
```

Locally: `VPS_TOKEN=... VPS_EMBEDDER=mock VPS_DATA_DIR=./data python -m search_vectors`
from `vps/`.
