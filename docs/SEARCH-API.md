# Search API, tooling and test page

The HTTP surface of the service and the read-only tools around it. The exact request and response JSON, status codes and error reasons are in [`INTEGRATION.md`](../INTEGRATION.md#http-contract). How results are ranked is in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md).

## HTTP endpoints

| endpoint | purpose |
| --- | --- |
| `POST /search` | `{q, customer_id?, limit?, with_details?, debug?}`, returns ordered `product_ids` + `did_you_mean` / `did_you_mean_applied` (plus `cosine_scores` on hybrid responses, and `products` display fields only with `"with_details": true`). Keyword tier always; hybrid when the configured VPS answers in time. |
| `GET /health` | Database reachability + live product count, for monitoring. |
| `GET /logs.php` | M21, read-only, off until `SEARCH_LOGS_TOKEN` is set. Recent searches (newest first, paginated), the zero-result ones, or the slowest; see [Search logs and ranking debug](#search-logs-and-ranking-debug). |
| `POST /reload` | Token-protected (`X-Reload-Token`). Validates the staged bundle and swaps it in atomically (then flushes the result cache when one is configured, reporting `cache_flushed`). With `?load=1` it first loads `data_incoming/products.load.sql` into the staging table itself. |

The exact request/response JSON, headers, status codes, and every error and
`invalid_bundle` reason are in [`INTEGRATION.md`](../INTEGRATION.md#http-contract).
On a subdirectory deploy, call the scripts directly (`/search-api/search.php`,
and so on). The front controller's pretty paths only route at a web root.

## Search logs and ranking debug

<a id="logs-debug"></a>**Search logs and ranking debug (M21).** Every `/search`
writes exactly one `search_logs` row: `ts`, `raw_q`, `normalized_q`,
`had_vector`, `result_count`, `top_ids` (the first ten), `customer_id`,
`latency_ms`, `did_you_mean` (the suggestion offered or applied), `tier`
(`keyword_only` or `hybrid`; `hybrid` = the VPS answered in time) and, with the
optional [result cache](DEPLOY.md#result-cache-optional-redis-m26) (M26), `cache_hit`
(`1` = answered from Redis; `tier` and `had_vector` then repeat what the cached answer used). Over-long
queries are truncated to 512 characters, and a failed write (missing table, a
rejected value) is logged with `error_log` and **never** fails the search.

`public/logs.php` (`GET /logs.php`, English UI) shows the table read-only:
**Recent** (newest first), **Zero results** (the gaps worth fixing: add aliases,
tags or synonyms for them) and **Slowest**, paginated
(`logs.page_size`, 50). It is off until `SEARCH_LOGS_TOKEN` is set. Send
the token in the `X-Logs-Token` header or open `/logs.php?token=…` (a form asks
for it otherwise); a URL token ends up in the browser history and the host's
access log, so prefer the header and rotate the token if a link leaks. The page
sends `no-store`, `noindex` and a restrictive CSP, escapes every value, and runs
only `SELECT`s.

`POST /search` with `"debug": 1` and the header `X-Debug-Token:
$SEARCH_DEBUG_TOKEN` (off until that is set; `403 debug_forbidden` otherwise)
adds `debug: {tier, settings, results[]}` to the response: per result its rank,
keyword hit (match type, score, whether the title / a structured field / a name
matched), whether it was pinned by an exact SKU, and, for hybrid results, the
blend: raw and normalized keyword score, cosine and normalized cosine, the
blended relevance, every boost (`stock`, `popularity`, `brand`, `category`,
`tag`) and the final score. The breakdown is computed for that response only
and is never written to the log. The search test page shows it under each card
when opened as `test.html?debug` and given the token.

## Eval harness

`server/tools/eval.php` runs the labeled queries in `fixtures/eval_queries.json`
through the real search pipeline and reports precision@k / recall@k so search
changes are measurable:

```bash
php server/tools/eval.php --k=5 [--queries=<path>] [--min-recall=<float>]
```

The seed labels are the true **bilingual** relevant sets, so the keyword tier
alone recovers the in-language half (recall ≈ 0.5 on the seed); closing the
cross-language gap is Tier 2's job and needs the real model. Grow the set from
real logs. With `SEARCH_VPS_URL` / `SEARCH_VPS_TOKEN` set, every query also goes
through the VPS exactly as `/search` does, so the report measures the hybrid
tier; the header says how many queries the VPS answered.

## Offline model-parity check

Model parity now lives on the VPS side: the VPS's ONNX query embedder against
`pipeline/embed.py`'s bge-m3 product vectors, measured with
`vps/tests/test_real_model.py` (see [`vps/README.md`](../vps/README.md),
"Model parity"). The browser check (`model_parity.py`) is gone with the
browser model.

## Search test page

`server/public/test.html` is a standalone Persian (RTL) search page for trying
the live service: type a query, see result cards (image, title, price), the
result count, a clickable "did you mean", and the round-trip time. It sends only
`{q, limit, with_details}` — **no model runs in the browser** (M18). A badge
says whether the answer was hybrid (the VPS answered) or keyword-only (no VPS
configured, or it was down or slow); in hybrid mode each card shows the cosine
similarity the VPS computed (small, muted), which is how to pick
`search.semantic_min_score`. An empty result shows a "no results" message.
Clicking a card opens the product in a new tab.

It makes **no third-party requests**: the page and its search request are
served from the service's own directory, and every path is relative, so the
page works under any subdirectory. If the service itself fails, an error notice
is shown. Open `https://shop.example.com/search-api/test.html`; nothing else
needs uploading. A `client/` folder left next to it from before M18 (model,
runtime, font) is unused and can be deleted.

## Store links and prices

The service only knows the product `url` and `image` exactly as exported, for
example `index.php?route=product/product&product_id=42` and `catalog/x.jpg`. The
page resolves relative values against the **store root, taken as the parent of
the page's directory** (`../`), and images against `../image/`, which matches
OpenCart's layout when the service is at `/<store>/search-api/`. If yours
differs, set `store_base` / `image_base` in `config.php` (the installer asks
for them): the server then returns absolute links, which the page uses as is. Prices
are shown as stored, with no currency label. Set `PRICE_SUFFIX` to add one.

The page is public wherever `server/public` is. It reads nothing the search
endpoint does not already expose, but delete it or protect the directory if you
don't want it reachable in production.
