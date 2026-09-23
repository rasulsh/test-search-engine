# Storefront integration

How an OpenCart 2.0.3.1 storefront talks to the search service. This covers the
HTTP contract, a reference storefront snippet, and how to self-host the browser
embedding model. OpenCart core is not modified. The actual OpenCart module
lives in a separate repository and follows this document.

- [Deployment shape](#deployment-shape)
- [HTTP contract](#http-contract): [`POST /search`](#post-search),
  [`GET /health`](#get-health), [`POST /reload`](#post-reload),
  [error codes](#error-codes)
- [Storefront reference](#storefront-reference)
- [Self-hosting the model (no HuggingFace, no CDN)](#self-hosting-the-model-no-huggingface-no-cdn)
- [Changing the model](#changing-the-model)

---

## Deployment shape

```
~/search-service/server/            <- the repo's server/ directory (NOT web-exposed)
    config.php  data/  data_incoming/  src/  ...
~/public_html/                       <- the OpenCart storefront
    search-api/   -> symlink or copy of ~/search-service/server/public
    search-client/                   <- static browser assets (see below)
        embedder.js
        vendor/transformers.min.js, vendor/ort-wasm*.wasm
        model/intfloat/multilingual-e5-small/...
```

- **Same origin as the store.** The service sends no CORS headers and does not
  answer `OPTIONS` preflights. The storefront therefore calls it on the store's
  own domain, under a subdirectory such as `/search-api/`. A separate subdomain
  would need CORS support that does not exist yet.
- **Only `server/public` is web-exposed.** `config.php` (credentials, reload
  token), `data/` and `src/` stay outside `public_html`. The endpoint scripts
  locate `bootstrap.php` through their real path, so a symlinked `search-api/`
  works.
- **Call the `.php` files directly** (`/search-api/search.php`). They need no
  URL rewriting. The front controller (`index.php`) routes the pretty paths
  (`/search`, `/health`, `/reload`) only when the service is at the web root of
  its host, not under a subdirectory.
- **HTTPS.** The browser model cache (Cache API) only works in a secure
  context. Over plain HTTP, every visit would re-download the model.

The endpoint paths below are written as `/search`, `/health`, `/reload`. On a
subdirectory deploy, read them as `/search-api/search.php`, and so on.

## HTTP contract

All responses are JSON: `Content-Type: application/json; charset=utf-8`. Persian
text is returned unescaped (UTF-8), and `/` may be escaped as `\/`, which is
standard JSON. Every error body has the shape `{"error": "<code>"}`, plus extra
fields where noted.

### `POST /search`

Request headers: `Content-Type: application/json`.

```json
{
  "q": "لپ تاپ سبک",
  "q_vector": [0.0123, -0.0456, "... 384 floats ..."],
  "customer_id": "42",
  "limit": 20
}
```

| field | type | required | behaviour |
| --- | --- | --- | --- |
| `q` | string | yes | Raw query text as the shopper typed it. A missing or non-string `q` is treated as `""`, which has no keyword results. Don't send an empty query: with a `q_vector`, it would still return the nearest products. |
| `q_vector` | number[] | no | L2-normalized query embedding from `client/embedder.js` (length = configured dim, 384 by default). Absent, non-numeric, or wrong-length vectors are **ignored** and the request is answered keyword-only. Never an error. |
| `customer_id` | string | no | Stored in `search_logs` only. **Must be a JSON string.** A number is silently logged as `NULL`, so send `String(id)`. Longer than 64 characters: the search is answered but its log row is dropped. |
| `limit` | int | no | Maximum number of ids to return. Values below 1, or non-numeric values, are treated as 1. The default is `SEARCH_DEFAULT_LIMIT` (20). There is no server-side maximum, so the storefront should keep it at 50 or less. |
| `with_details` | bool | no | Only the JSON value `true` enables it (a string `"true"` or `1` is ignored). Adds a `products` array with display fields. Omit it and the response is exactly as below. Used by the search test page; the storefront renders from OpenCart and does not need it. |

Response `200`:

```json
{
  "query": { "raw": "لپ تاپ سبک", "normalized": "لپ تاپ سبک" },
  "did_you_mean": null,
  "did_you_mean_applied": false,
  "count": 18,
  "product_ids": [43, 46, 47, 44, 45]
}
```

| field | meaning |
| --- | --- |
| `query.raw` | `q` echoed back. |
| `query.normalized` | `q` after Persian normalization (the canonical rules, contract 1). |
| `did_you_mean` | `null`, or a keyboard-layout fix (`ئشزذخخن` → `macbook`) or spelling fix (`macbok` → `macbook`). Only tried when the literal query has fewer than `SEARCH_SUGGEST_MIN_RESULTS` (3) keyword hits, and only returned when the suggestion itself has **more** keyword hits than the literal query, so it never points to an empty result. It derives from shopper input, so render it as text, never as HTML. |
| `did_you_mean_applied` | `true` when the literal query matched nothing and the returned ids are for the suggestion: show "Showing results for …". `false` when the literal query had a few hits: the ids are for what the shopper typed, and the suggestion is only offered: show "Did you mean …?" linking to a search for it. |
| `count` | `product_ids.length`. |
| `product_ids` | Ordered OpenCart `product_id`s, best first. The service returns ids only; the storefront renders the products. |
| `cosine_scores` | Only on a hybrid response (see below): the cosine similarity of each returned product to the query, aligned by index with `product_ids`, rounded to 4 decimals, `null` for a product without a vector. A diagnostic for tuning (the test page shows it). |
| `products` | Only with `"with_details": true`: `[{"id", "title", "url", "image", "price"}]` in the same order as `product_ids`, read from the `products` table (`title` is the stored title, `url`/`image` as exported, or joined to `storefront.store_base` / `image_base` when those are set in `config.php` and the value is relative, `price` a number). An id missing from the table is skipped. |

Semantics the storefront should know:

- **Keyword-only** (no usable `q_vector`, or no bundle loaded): FULLTEXT
  results. Products whose title matches the query come first, then products
  that match in their specs (attributes and feature titles), then products
  that match only in their description. `count` can be `0`.
- A multi-word query returns only products holding **every** word (in title,
  specs and description combined): `کیبورد قرمز` is red keyboards, not all
  keyboards plus all red products. When no product holds every word, the
  products holding the most words are returned instead. Set
  `SEARCH_REQUIRE_ALL_TERMS=0` to always return partial matches.
- Only the first `SEARCH_DESC_INDEX_CHARS` (default 800) characters of each
  description are keyword-indexed; a word that appears only deeper in the
  description does not match.
- Name forms are matched across the bundle's synonyms and the shop's aliases
  (`gta 5` also finds "Grand Theft Auto V" and "جی تی ای ۵"), and standalone
  Roman numerals equal digits (`GTA V` = `GTA 5`, so `query.normalized` shows
  `gta 5`). The response shape is unchanged.
- **Hybrid** (`q_vector` present and a bundle loaded): cosine neighbours below
  `SEARCH_SEMANTIC_MIN_SCORE` are dropped, and so are keyword hits that match
  only in the description (not the title or specs) with a cosine below it. Every keyword hit ranks above every
  semantic-only product (title matches, then spec matches, then description-only matches); the semantic side reorders keyword hits among
  themselves and adds relevant products below them (weighted Reciprocal Rank
  Fusion, then in-stock and popularity boosts). When the keyword hits of a
  multi-word query hold every word, neighbours only reorder them and are not
  added below. A query with no keyword hit and
  no neighbour above the floor returns `count: 0`, so either response can come
  back empty. Show a "no results" state.
- Every request writes one row to `search_logs`. Logging is best-effort: a
  rejected log row (such as `q` longer than 512 characters) does not fail the
  search.

Errors: `400 invalid_json` (the body is not valid JSON, or is a bare string or number), `405
method_not_allowed` (not a `POST`), `500 internal_error` (such as a database
outage). On any non-`200`, fall back to OpenCart's native search. See the
snippet below.

### `GET /health`

No body. Use it for uptime monitoring.

```json
{ "status": "ok", "checks": { "database": true, "product_count": 18 } }
```

| status | HTTP | meaning |
| --- | --- | --- |
| `ok` | 200 | Database reachable. `product_count` is the live `products` row count. **`0` means no catalog has been loaded yet**, so also alert on `product_count == 0`. |
| `degraded` | 503 | Database unreachable: `{"database": false, "product_count": null}`. |

`405 method_not_allowed` for any method other than `GET`, **including `HEAD`**, so
configure the uptime monitor to use `GET`.

### `POST /reload`

Operator-only. It validates the staged bundle and swaps it in atomically. See
the README runbook for the steps before it.

Query parameter `load=1` (optional): before validating, load
`data_incoming/products.load.sql` into the `products_new` staging table from
PHP (the one-command release path). The compatibility check (model, dim,
normalization version) runs first, so an incompatible bundle is rejected before
anything is loaded; the load itself must succeed completely, and the usual
consistency checks then run on the loaded table, before any swap. Without
`load=1`, the operator must have loaded the staging table already (manual
fallback). The request may run for a while on a full catalog; it keeps going if
the client disconnects.

Authentication: send the token configured in `SEARCH_RELOAD_TOKEN`, either as
a header (preferred) or as a JSON body field. The header wins when both are
sent.

```bash
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     "https://shop.example.com/search-api/reload.php?load=1"
# or: -H 'Content-Type: application/json' -d '{"token":"..."}'
# manual fallback (staging already loaded): same URL without ?load=1
```

Success `200`:

```json
{ "ok": true, "count": 20000, "model": "intfloat/multilingual-e5-small", "dim": 384 }
```

After a success, the previous table is kept as `products_old` and the previous
bundle directory as `data_old`, for a one-step rollback. `data_incoming/` no
longer exists; the next upload recreates it.

Failures:

| HTTP | body | cause |
| --- | --- | --- |
| 401 | `{"error":"unauthorized"}` | Token missing or wrong. |
| 405 | `{"error":"method_not_allowed"}` | Not a `POST`. |
| 422 | `{"error":"invalid_bundle","reason":"…","details":{…}}` | Validation failed. **Nothing was swapped.** Reasons are listed below. |
| 500 | `{"error":"internal_error"}` | Unexpected failure (such as a database error). |
| 503 | `{"error":"reload_disabled"}` | No `SEARCH_RELOAD_TOKEN` configured, so reload is off. |

`invalid_bundle` reasons. `details` carries `expected` / `actual` values where
that helps.

| reason | fix |
| --- | --- |
| `missing_meta`, `invalid_meta` | `data_incoming/meta.json` is absent or not JSON. Re-upload the bundle. |
| `model_mismatch`, `dim_mismatch`, `normalization_version_mismatch` | The bundle was built with a different model, dim, or normalization rules than `server/config.php` (contract 3). Rebuild, or change config deliberately (see [Changing the model](#changing-the-model)). `normalization_version` itself follows the deployed code, except in a `config.php` from before M15.1, which needs a one-time edit (README, [normalization version upgrades](./README.md#upgrading-to-m15)). |
| `invalid_aliases`, `invalid_synonyms` | `data_incoming/aliases.json` or `synonyms.json` is not a JSON list of lists of strings (`details.file` names it). Fix `pipeline/aliases.json` (see README, [aliases](./README.md#aliases)) and rebuild. |
| `missing_index`, `missing_vectors` | `vectors.idx` / `vectors.bin` not uploaded. |
| `missing_staging_table` | `products_new` does not exist. Call with `?load=1`, or load `products.load.sql` into the database first. |
| `missing_load_sql` | `?load=1` was sent but `data_incoming/products.load.sql` is absent. Re-extract `release.zip` (or upload the file). |
| `staging_load_failed` | A statement of `products.load.sql` failed (`details.statement` is its number, `details.error` the database error), or the file ends mid-statement (truncated upload). The staging table may be partial; it is never swapped in. Re-extract the release, or on hosts with tight limits use the manual fallback load. |
| `unexpected_statement` | `products.load.sql` contains a statement that is not one `build.py` writes for `products_new` (`details.start` shows its beginning). Nothing after it ran. Rebuild the release; never hand-edit the file. |
| `count_mismatch` | `meta.count`, `vectors.idx` lines, and `products_new` rows disagree. Usually an incomplete SQL load: recreate `products_new` and reload the SQL. |
| `vectors_size_mismatch`, `checksum_mismatch` | `vectors.bin` is truncated or corrupted (such as an interrupted upload or FTP ASCII mode). Re-upload it in binary mode. |
| `directory_swap_failed` | Renaming `data_incoming` to `data` failed (permissions). The table swap was rolled back, so the service is unchanged. |

### Error codes

| code | HTTP | endpoints |
| --- | --- | --- |
| `invalid_json` | 400 | `/search` |
| `unauthorized` | 401 | `/reload` |
| `not_found` | 404 | front controller, unknown path |
| `method_not_allowed` | 405 | all |
| `invalid_bundle` | 422 | `/reload` |
| `internal_error` | 500 | `/search`, `/reload` |
| `reload_disabled` | 503 | `/reload` |

`/health` reports failure through `status: "degraded"` with HTTP 503, not
through an `error` code.

---

## Storefront reference

What the storefront does per search:

1. On first focus of the search box, start loading the embedder in the
   background. Never block a search on the model download.
2. On submit: if the model is ready, embed the query (with a time budget) and
   send `{q, q_vector, customer_id}`. Otherwise, or if embedding fails or is
   too slow, send `{q, customer_id}` only. Keyword search still works.
3. Render `product_ids` in order. When `did_you_mean` is present, show
   "Showing results for …" if `did_you_mean_applied`, otherwise "Did you
   mean …?".
4. If the search service itself fails (network error or non-`200`), redirect
   to OpenCart's native search, so the store's search box never breaks.

| situation | what is sent | result |
| --- | --- | --- |
| Model loaded, embeds within `EMBED_TIMEOUT_MS` | `q` + `q_vector` | hybrid |
| Model still downloading (first visit) | `q` | keyword-only |
| Model failed to load (404, blocked, no WASM, old browser) | `q` | keyword-only |
| Embedding slower than `EMBED_TIMEOUT_MS` | `q` | keyword-only |
| `navigator.connection.saveData` is on | `q` (model never loaded) | keyword-only |
| Search service down or non-`200` | redirect | OpenCart native search |

The snippet expects a form with a text input and two output elements. The
OpenCart module prints the logged-in customer id (or nothing) into
`data-customer-id`, and wires this into its own template or header search. The
product links use OpenCart's standard `product/product` route.

<!-- The block below is exercised by an end-to-end browser test; keep it runnable. -->
```html
<form id="search-form" data-customer-id="">
  <input id="search-input" type="search" name="search" autocomplete="off">
  <button type="submit">Search</button>
</form>
<p id="search-suggestion" hidden></p>
<ol id="search-results"></ol>

<script type="module">
  import * as transformers from '/search-client/vendor/transformers.min.js';
  import { QueryEmbedder } from '/search-client/embedder.js';

  const SEARCH_URL = '/search-api/search.php';
  const EMBED_TIMEOUT_MS = 1500;  // budget for one query embedding once the model is loaded
  const LIMIT = 20;
  const MAX_QUERY_CHARS = 200;

  // Everything is served from this domain: no request goes to huggingface.co or a CDN.
  transformers.env.allowRemoteModels = false;
  transformers.env.localModelPath = '/search-client/model/';
  // Absolute URL: the inference worker below cannot resolve a relative path.
  transformers.env.backends.onnx.wasm.wasmPaths = new URL('/search-client/vendor/', location.href).href;
  // Run inference in a Web Worker: keeps the page responsive and lets
  // EMBED_TIMEOUT_MS fire (on the main thread, WASM inference blocks the timer).
  transformers.env.backends.onnx.wasm.proxy = true;

  const embedder = new QueryEmbedder({ transformers });
  let modelReady = false;
  let modelLoading = null;

  function warmUp() {
    if (modelLoading || navigator.connection?.saveData) {
      return;
    }
    modelLoading = embedder.init().then(
      () => { modelReady = true; },
      (err) => { console.warn('search: embedder unavailable, using keyword-only search', err); },
    );
  }

  async function queryVector(q) {
    if (!modelReady) {
      warmUp();
      return null;  // never block a search on the model download
    }
    const timeout = new Promise((resolve) => setTimeout(resolve, EMBED_TIMEOUT_MS, null));
    return Promise.race([embedder.embed(q), timeout]).catch(() => null);
  }

  async function searchProducts(q, customerId) {
    const body = { q, limit: LIMIT };
    if (customerId) {
      body.customer_id = String(customerId);
    }
    const vector = await queryVector(q);
    if (vector) {
      body.q_vector = vector;
    }
    const res = await fetch(SEARCH_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error(`search service returned HTTP ${res.status}`);
    }
    return res.json();
  }

  function render(result) {
    const suggestion = document.getElementById('search-suggestion');
    suggestion.hidden = !result.did_you_mean;
    const label = result.did_you_mean_applied ? 'Showing results for' : 'Did you mean';
    suggestion.textContent = result.did_you_mean ? `${label}: ${result.did_you_mean}` : '';

    const list = document.getElementById('search-results');
    list.replaceChildren(...result.product_ids.map((id) => {
      const link = document.createElement('a');
      link.href = `index.php?route=product/product&product_id=${encodeURIComponent(id)}`;
      link.textContent = `Product ${id}`;  // the OpenCart module renders real product cards here
      const item = document.createElement('li');
      item.append(link);
      return item;
    }));
  }

  const form = document.getElementById('search-form');
  const input = document.getElementById('search-input');
  input.addEventListener('focus', warmUp, { once: true });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const q = input.value.trim().slice(0, MAX_QUERY_CHARS);
    if (!q) {
      return;
    }
    try {
      render(await searchProducts(q, form.dataset.customerId));
    } catch (err) {
      console.warn('search: service unavailable, falling back to OpenCart search', err);
      location.href = `index.php?route=product/search&search=${encodeURIComponent(q)}`;
    }
  });
</script>
```

Notes:

- `embedder.js` is used unchanged. The storefront injects its own configured
  transformers.js module (`new QueryEmbedder({ transformers })`), so the
  embedder's fallback `import('@xenova/transformers')` never runs in the
  browser, and no bundler or import map is needed.
- The model loads on first focus, not on page load, so visitors who never search
  download nothing. After the first download the browser keeps the files in its
  Cache API storage, and later visits load from disk.
- `EMBED_TIMEOUT_MS` is a starting point. Tune it from real devices (see the
  Go-Live checklist in the README).

---

## Self-hosting the model (no HuggingFace, no CDN)

By default, transformers.js downloads model files from `huggingface.co` and its
WebAssembly runtime from `cdn.jsdelivr.net`. Either may be blocked or throttled
for shoppers in Iran. The storefront snippet above therefore sets
`allowRemoteModels = false`, `localModelPath` and `wasmPaths`, and every file
comes from the store's own domain. Only the developer machine ever talks to
HuggingFace or npm.

### Which files

`client/embedder.js` asks transformers.js v2 for the model id
`intfloat/multilingual-e5-small`. With default options, transformers.js loads
the **quantized** ONNX file `onnx/model_quantized.onnx`. The
`intfloat/multilingual-e5-small` repository does **not** publish that file, so
loading it by id fails with `Could not locate file: …/onnx/model_quantized.onnx`.
Use the transformers.js conversion of the same weights from
[`Xenova/multilingual-e5-small`](https://huggingface.co/Xenova/multilingual-e5-small),
placed under the `intfloat/…` id path that `embedder.js` requests:

```
client/model/intfloat/multilingual-e5-small/     (gitignored)
    config.json
    tokenizer.json                (~17 MB)
    tokenizer_config.json
    special_tokens_map.json
    onnx/model_quantized.onnx     (~118 MB, int8)
```

On the developer machine:

```bash
REV=761b726dd34fb83930e26aab4e9ac3899aa1fa78   # pinned Xenova/multilingual-e5-small commit
DST=client/model/intfloat/multilingual-e5-small
mkdir -p "$DST/onnx"
for f in config.json tokenizer.json tokenizer_config.json special_tokens_map.json onnx/model_quantized.onnx; do
  curl -fL -o "$DST/$f" "https://huggingface.co/Xenova/multilingual-e5-small/resolve/$REV/$f"
done
```

The transformers.js runtime (pin the 2.x version `embedder.js` is used with):

```bash
npm --prefix client install @xenova/transformers@2.17.2   # also used by the parity check
# copy client/node_modules/@xenova/transformers/dist/{transformers.min.js,ort-wasm*.wasm}
# to public_html/search-client/vendor/
```

`python pipeline/tools/fetch_web_model.py` does all of the above in one step. It
downloads the same pinned files (model at the commit above, transformers.js
2.17.2, plus the Vazirmatn font for the test page), verifies each file's
checksum, and writes them to `client/model/`, `client/vendor/` and
`client/fonts/`. See the README, "Search test page".

The browser model is int8-quantized, while the offline pipeline embeds products
with the full-precision model. **Run the parity check before trusting Tier 2.**
It embeds `fixtures/parity_strings.json` with both implementations, using
exactly the files in `client/model/`:

```bash
EMBEDDER=real python pipeline/tools/model_parity.py    # expect PASS, cosine >= 0.99
```

During M5 verification this passed with a per-string cosine of 0.9956–0.9985
(quantized browser model against the full-precision pipeline).

### Uploading and serving

Upload `client/embedder.js`, `client/model/`, and the vendor files to
`public_html/search-client/` in binary mode, matching the layout under
[Deployment shape](#deployment-shape). Then, on the web server:

- `.wasm` must be served as `application/wasm`. LiteSpeed does this by default;
  otherwise add `AddType application/wasm .wasm`.
- Give `search-client/` long cache lifetimes (for example
  `Header set Cache-Control "public, max-age=31536000, immutable"` in its
  `.htaccess`). The files only change when the model changes, and then the
  directory is replaced.
- Enable compression for `.json`. `tokenizer.json` compresses from about 17 MB
  to a few MB. `.onnx` does not compress usefully.

### Verifying no third-party requests

Open the store in a browser with DevTools, then Network, with "Preserve log"
on. Focus the search box and run a search. Every request must go to the
store's own domain. There must be none to `huggingface.co` or
`cdn.jsdelivr.net`, and the `/search-api/search.php` request payload must
contain `q_vector` once the model has loaded.

## Changing the model

The model is a three-way contract (contract 2 in `CLAUDE.md`). To change it,
change all of these together, then rebuild and redeploy:

1. `SEARCH_MODEL`, `SEARCH_MODEL_REVISION`, `SEARCH_MODEL_DIM` for the
   pipeline and in `server/config.php`.
2. The `MODEL_ID` / `MODEL_REVISION` / `EMBEDDING_DIM` / `QUERY_PREFIX`
   constants in `client/embedder.js`. CI asserts that they match the pipeline
   and server defaults.
3. The self-hosted files under `client/model/<new id>/` and on the store.
4. Rebuild the bundle with `build.py`, then deploy it and call `/reload`. A
   bundle built with the old model is rejected (`model_mismatch` /
   `dim_mismatch`).
5. Rerun `pipeline/tools/model_parity.py`.
