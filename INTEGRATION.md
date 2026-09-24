# Storefront integration

How an OpenCart 2.0.3.1 storefront talks to the search service. This covers the
HTTP contract, how the service reaches the VPS vector service for semantic
results, and a reference storefront snippet. OpenCart core is not modified. The
actual OpenCart module lives in a separate repository and follows this document.

Since M18 the browser runs **no model**: the storefront sends only the query
text. The cPanel service asks the VPS (`vps/`, bge-m3) for semantic neighbours
server-to-server and answers keyword-only whenever the VPS is not available.

- [Deployment shape](#deployment-shape)
- [HTTP contract](#http-contract): [`POST /search`](#post-search),
  [`GET /health`](#get-health), [`POST /reload`](#post-reload),
  [error codes](#error-codes)
- [Semantic tier: cPanel to VPS](#semantic-tier-cpanel-to-vps)
- [Storefront reference](#storefront-reference)
- [Changing the model](#changing-the-model)

---

## Deployment shape

```
~/search-service/server/            <- the repo's server/ directory (NOT web-exposed)
    config.php  data/  data_incoming/  src/  ...
~/public_html/                       <- the OpenCart storefront
    search-api/   -> symlink or copy of ~/search-service/server/public

VPS (vps/README.md)                  <- bge-m3 query model + product vectors
    POST /search-vectors             <- called by search.php only, never by browsers
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
- **The VPS is private.** Only the cPanel server calls it (token + firewall,
  see `vps/README.md`, "Keeping the model private"). Browsers never see its
  address or token.
- **Nothing to self-host in the browser.** No model, WebAssembly runtime or
  font is downloaded by shoppers. A `search-client/` folder left from before
  M18 is unused and can be deleted.

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
  "customer_id": "42",
  "limit": 20
}
```

| field | type | required | behaviour |
| --- | --- | --- | --- |
| `q` | string | yes | Raw query text as the shopper typed it. It is also what the service sends to the VPS for the semantic tier. A missing, non-string or blank `q` is treated as `""` and returns no results (the VPS is not asked). |
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
| `cosine_scores` | Only on a hybrid response, that is when the VPS answered (see below): the cosine similarity of each returned product to the query, as computed on the VPS, aligned by index with `product_ids`, rounded to 4 decimals, `null` for a product the VPS did not return (below the floor or outside its top-K). A diagnostic for tuning (the test page shows it). Its absence means the response is keyword-only. |
| `products` | Only with `"with_details": true`: `[{"id", "title", "url", "image", "price"}]` in the same order as `product_ids`, read from the `products` table (`title` is the stored title, `url`/`image` as exported, or joined to `storefront.store_base` / `image_base` when those are set in `config.php` and the value is relative, `price` a number). An id missing from the table is skipped. |

Semantics the storefront should know:

- A `q_vector` field sent by a pre-M18 storefront is ignored; the answer is
  the same as without it.
- **Keyword-only** (no VPS configured, or the VPS is unreachable, slower than
  `SEARCH_VPS_TIMEOUT_MS`, or answers with an error): FULLTEXT
  results, still `200`. Products whose title matches the query come first, then products
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
- **Hybrid** (the VPS answered): cosine neighbours below
  `SEARCH_SEMANTIC_MIN_SCORE` are dropped, and so are keyword hits that match
  only in the description (not the title or specs) with a cosine below it
  (one the VPS did not return counts as below it, unless the VPS returned a
  full top-K list, in which case it is kept). Every keyword hit ranks above every
  semantic-only product (title matches, then spec matches, then description-only matches); the semantic side reorders keyword hits among
  themselves and adds relevant products below them (weighted Reciprocal Rank
  Fusion, then in-stock and popularity boosts). When the keyword hits of a
  multi-word query hold every word, neighbours only reorder them and are not
  added below. A query with no keyword hit and
  no neighbour above the floor returns `count: 0`, so either response can come
  back empty. Show a "no results" state.
- Every request writes one row to `search_logs`; `had_vector` is `1` when
  the VPS answered (a hybrid response) and `0` otherwise. Logging is
  best-effort: a rejected log row (such as `q` longer than 512 characters)
  does not fail the search.

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

## Semantic tier: cPanel to VPS

`search.php` calls the VPS vector service (`vps/README.md`) server-to-server
with PHP's curl, once per search, after the keyword search:

```
POST {SEARCH_VPS_URL}/search-vectors
Authorization: Bearer {SEARCH_VPS_TOKEN}
Content-Type: application/json

{"q": "<raw query>", "limit": SEARCH_SEMANTIC_TOP_K, "min_score": SEARCH_SEMANTIC_MIN_SCORE}
-> 200 {"results": [{"product_id": 2002, "score": 0.71}, ...], "model": "BAAI/bge-m3", "took_ms": 31.4}
```

| setting (`config.php` `vps`, or env) | default | meaning |
| --- | --- | --- |
| `SEARCH_VPS_URL` | empty | Base URL of the VPS service (`https://vps.example.com:8600`). Empty = keyword-only search, nothing is called. |
| `SEARCH_VPS_TOKEN` | empty | The VPS's `VPS_TOKEN`. A secret: keep it in the environment or `config.php` (install.php asks for it). |
| `SEARCH_VPS_TIMEOUT_MS` | `300` | Budget for the whole call, connect included. A slower VPS means a keyword-only answer for that search. |
| `SEARCH_SEMANTIC_MIN_SCORE` | `0.4` | The cosine floor, sent as `min_score` and re-applied on cPanel. See below. |
| `SEARCH_SEMANTIC_TOP_K` | `100` | How many neighbours are asked for. |

- **Fallback.** Unreachable, timed out, any non-`200` (`401` wrong token,
  `503` no vectors loaded, …) or a malformed body: the search is answered
  keyword-only with `200` and never fails because of the VPS. Each such
  search writes one line to the PHP error log,
  `search: semantic tier unavailable (<reason>); served keyword-only results`
  (`<reason>` is `timeout`, `unreachable: …`, `http_<status>` or
  `malformed_response`), and its `search_logs` row has `had_vector = 0`.
- **Latency.** Keyword search plus the VPS round trip; a dead VPS costs at
  most `SEARCH_VPS_TIMEOUT_MS`. Keep the timeout well under the 200 ms budget
  plus network time on the real hosts (measure there).
- **The floor.** bge-m3 cosines sit far lower than e5's: the old 0.82 would
  drop nearly every bge-m3 neighbour. 0.4 is a starting point and **needs
  tuning on the real catalog** (eval harness, search logs). No floor separates
  short queries well (on the fixture, right one-word hits scored 0.42–0.49 and
  wrong ones up to 0.43): one-word precision comes from the keyword tier (all
  terms, aliases, synonyms) fused by RRF, and the floor mainly keeps far
  neighbours out.
- The VPS's own `VPS_SEMANTIC_MIN_SCORE` applies only to callers that send no
  `min_score`; cPanel always sends its floor.
- The product ids the VPS returns are checked against the live `products`
  table; ids it does not hold (vectors newer than the catalog) are dropped.

---

## Storefront reference

What the storefront does per search:

1. On submit, send `{q, customer_id}` (and optionally `limit`). The browser
   runs no model: the service adds semantic results itself when its VPS is
   available.
2. Render `product_ids` in order. When `did_you_mean` is present, show
   "Showing results for …" if `did_you_mean_applied`, otherwise "Did you
   mean …?".
3. If the search service itself fails (network error or non-`200`), redirect
   to OpenCart's native search, so the store's search box never breaks.

| situation | what is sent | result |
| --- | --- | --- |
| VPS configured and answering within `SEARCH_VPS_TIMEOUT_MS` | `q` | hybrid |
| No VPS configured, or VPS down, slow, or erroring | `q` | keyword-only (still `200`) |
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
  const SEARCH_URL = '/search-api/search.php';
  const LIMIT = 20;
  const MAX_QUERY_CHARS = 200;

  async function searchProducts(q, customerId) {
    // Only the query: the service adds semantic results server-side (VPS).
    const body = { q, limit: LIMIT };
    if (customerId) {
      body.customer_id = String(customerId);
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

- No model, runtime or font is loaded in the browser, and nothing is
  requested from any third-party host: the only request is the `POST` to
  `search.php` on the store's own domain.
- A storefront still built for M4–M17 (sending `q_vector`) keeps working:
  the field is ignored. Replace its snippet with this one **before** deleting
  the `search-client/` folder: the old snippet imports `embedder.js` from
  there, and without it the whole script (and the search box) stops.

---

## Changing the model

The semantic model lives on the VPS. Contract 2 (`CLAUDE.md`) is now between
`pipeline/embed.py` (product vectors, `SEARCH_VPS_MODEL*` / `SEARCH_VPS_POOLING`
/ prefixes in `pipeline/config.py`) and the VPS query embedder (`VPS_MODEL*`
/ `VPS_POOLING` / `VPS_QUERY_PREFIX` in `/etc/search-vectors.env`); the VPS's
`/reload` rejects vectors whose `meta.json` disagrees. Follow
`vps/README.md` ("Model parity", "Updating the product vectors"), then re-tune
`SEARCH_SEMANTIC_MIN_SCORE` for the new model's score range. Nothing changes
on cPanel or in the storefront.

`SEARCH_MODEL` / `SEARCH_MODEL_DIM` in `server/config.php` only describe the
bundle's `meta.json` for `/reload` (contract 3); `/search` does not read the
bundle's vectors.
