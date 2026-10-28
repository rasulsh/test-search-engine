# Search API, tooling and test page

The HTTP surface of the service and the read-only tools around it. The exact request and response JSON, status codes and error reasons are in [`INTEGRATION.md`](../INTEGRATION.md#http-contract). How results are ranked is in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md).

The full request path behind `POST /search`: [RUNTIME-FLOW.md](RUNTIME-FLOW.md).

## HTTP endpoints

| endpoint | purpose |
| --- | --- |
| `POST /search` | `{q, customer_id?, session_id?, source?, page?, limit?, with_details?, debug?}`, returns a `search_uuid`, ordered `product_ids` + `did_you_mean` / `did_you_mean_applied` (plus `cosine_scores` on hybrid responses, and `products` display fields only with `"with_details": true`). Keyword tier always; hybrid when the configured VPS answers in time. |
| `POST /events` | Log v2 result tracking: `{search_uuid, product_id, position, action}` with `action` `click` / `dym_click` (cart and order were dropped in M43); `204` on success. Feeds the engagement figures of the analytics page. See [INTEGRATION.md](../INTEGRATION.md#post-events). |
| `GET /health` | Database reachability + live product count, for monitoring. |
| `GET /logs.php` | Moved into the admin panel in M39: redirects to `admin.php?p=logs` (login required). See [ADMIN.md](ADMIN.md) and [Search logs and ranking debug](#search-logs-and-ranking-debug). |
| `POST /reload` | Token-protected (`X-Reload-Token`). Validates the staged bundle and swaps it in atomically (then flushes the result cache when one is configured, reporting `cache_flushed`). With `?load=1` it first loads `data_incoming/products.load.sql` into the staging table itself. Only one reload or rollback runs at a time (`409 reload_busy` for a second, M40); the admin panel's operations page uses the same code and lock ([ADMIN.md](ADMIN.md)). |

The exact request/response JSON, headers, status codes, and every error and
`invalid_bundle` reason are in [`INTEGRATION.md`](../INTEGRATION.md#http-contract).
Both forms work, at a web root or under a subfolder: `/search-api/search` and
`/search-api/search.php` (and so on); `public/.htaccess` routes the former.

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

The admin panel's **logs** page (`admin.php?p=logs`, Persian; since M39 behind the panel login, the old
`logs.php` and its token only redirect there, see [ADMIN.md](ADMIN.md)) shows the table read-only:
**Recent** (newest first), **Zero results** (the gaps worth fixing: add aliases,
tags or synonyms for them) and **Slowest**, paginated
(`logs.page_size`, 50). The page sends `no-store`, `noindex` and a restrictive CSP, escapes every value, and runs
only `SELECT`s.

`POST /search` with `"debug": 1` and the header `X-Debug-Token:
$SEARCH_DEBUG_TOKEN` (off until that is set; `403 debug_forbidden` otherwise)
adds `debug: {tier, settings, entities, results[]}` to the response (`entities`, M44: `null`, or `{brands, platforms, active, brand_unmatched}` when the query names a brand / platform; `coverage` is weighted by token rarity): per result its rank,
keyword hit (match type, score, whether the title / a structured field / a name
matched), whether it was pinned by an exact SKU, and, for hybrid results, the
blend: raw and normalized keyword score, cosine and normalized cosine, the
blended relevance, every boost (`stock`, `popularity`, `brand`, `category`,
`tag`) and the final score. The breakdown is computed for that response only
and is never written to the log. The search test page shows it under each card
when opened as `test.html?debug` and given the token.

## Search analytics

<a id="analytics"></a>The **analytics** page (M31, `admin.php?p=analytics` since M39; `analytics.php` and `/analytics`
only redirect there) turns the
same `search_logs` table into read-only aggregates, with no schema change beyond
one index (`idx_ts_result`, see [DEPLOY.md](DEPLOY.md#upgrading-to-m31)). It sits behind the
**admin panel login** (no token any more; `logs.token` is unused, see [ADMIN.md](ADMIN.md)):
`?format=json` answers `401 {"error":"unauthorized"}` without a session, and the JSON itself is
byte-identical to what `analytics.php?format=json` returned. It sends `no-store`, `noindex`,
`X-Frame-Options` and a CSP, escapes every value and runs only `SELECT`s.
`customer_id` and `raw_q` stay admin-only behind the login; the dashboard adds
no new PII surface (it shows queries, never customer ids).

| parameter | values | meaning |
| --- | --- | --- |
| `window` | `24h`, `7d` (default), `30d`, `90d`, `all` | the time range, counted back from the database clock; an unknown value means `7d` |
| `format` | `json` | the same data as JSON instead of the HTML dashboard |
| `compare` | `1` | adds `compare`: the headline figures against the previous window of the same length (not for `all`) |

**Log-v2 keys (added beside the older ones, which are unchanged).** Flagged probes
(`is_suspicious = 1`) count nowhere; a metric that needs a v2 column reads only
`log_version = 2` rows; "unique" metrics also leave out repeats (`is_duplicate = 1`).
Before `db/migrations/2026-10-24_search_logs_v2.sql` ran (or the logger added the
columns) the report carries `log_v2: {available: false}` and none of the keys below.

| key | content |
| --- | --- |
| `log_v2` | `{available, rows}` |
| `unique` | `{searches, sessions, searches_per_session}` |
| `zero_result` | `{rate, queries[]}`: rate on unique searches, top zero-result queries by normalized form |
| `engagement` | `{tracking, searches, ctr, no_click_rate, mean_click_position, mrr}` over unique searches with results; `tracking` is false until a click is recorded. Old `cart` / `order` rows in `search_events` are ignored (M43) |
| `latency_cold` | cache misses only: `{count, p50, p95, max, stages_p95: {keyword, vector, spell}, slowest[]}` (top 20) |
| `tier_timeline` | `{hours: [{bucket, searches, hybrid, hybrid_share}], outages[]}`: hybrid share per hour, and runs of busy hours with only keyword-only answers (a vector outage) |
| `capped_share` | `{searches, capped, share}`: lists that reached `search.result_cap` |
| `did_you_mean` | the old `{total, suggested, share}` plus `{shown, shown_rate, clicked, ctr, zero_result_suggestions, top[]}` (`top`: query → suggestion pairs with shown / clicked counts) |
| `alias_candidates` | `[{query, then, pairs, clicks}]`: a zero-result query followed within 60 s in the same session by a different query that had results, ranked by clicks on the follow-up, then frequency |
| `silent_failures` | `[{query, searches, clicks, ctr, avg_results, top_id, top_title}]`: frequent (5+) queries with results and a click-through of at most 2 %; empty while no click is tracked. `top_id` / `top_title` (M44): the first result of the query's latest search and its title (`null` / `""` when the product is gone), so the answer can be judged at a glance. Clicks are under-counted until the storefront sends them |
| `brand_unmatched` | `[{brand, searches, example, last_seen}]` (M44): brands a query named that none of the returned candidates carried ("asked for, not stocked"); from `search_logs.brand_unmatched`, empty without the column |
| `demand_gap` | zero-result queries that appear in no alias candidate (likely products missing from the catalog) |
| `trending` | `[{query, searches, previous, growth, growth_pct}]` against the previous window |
| `suspicious` | `{count, samples[]}`: the probes the service refused |
| `compare` | with `compare=1`: `{previous_since, previous_until, metrics: {name: {current, previous, change, change_pct}}}` for searches, unique searches, zero rate, CTR, cold p95, hybrid share and cache hit rate |

Sample (abridged; the numbers are illustrative, the shape is real):

```json
{
  "window": "7d", "since": "2026-10-17 12:00:00",
  "summary": {"total": 12840, "zero_results": 1020, "zero_rate": 7.9, "avg_results": 41.2},
  "log_v2": {"available": true, "rows": 9120},
  "unique": {"searches": 7410, "sessions": 3120, "searches_per_session": 2.31},
  "zero_result": {"rate": 8.4, "queries": [{"query": "beamng.drive", "searches": 9, "last_seen": "2026-10-23 21:02:11"}]},
  "engagement": {"tracking": true, "searches": 6790, "ctr": 61.3, "no_click_rate": 38.7, "mean_click_position": 3.42,
                 "mrr": 0.4127},
  "latency_cold": {"count": 2210, "p50": 38, "p95": 410, "max": 12840,
                   "stages_p95": {"keyword": 120, "vector": 280, "spell": 45},
                   "slowest": [{"query": "very long pasted text ...", "max_ms": 12840, "searches": 1}]},
  "tier_timeline": {"hours": [{"bucket": "2026-10-22 18:00", "searches": 41, "hybrid": 0, "hybrid_share": 0.0}],
                    "outages": [{"from": "2026-10-22 18:00", "to": "2026-10-22 18:00", "hours": 1, "searches": 41}]},
  "capped_share": {"searches": 9120, "capped": 3, "share": 0.0},
  "did_you_mean": {"total": 12840, "suggested": 610, "share": 4.8, "shown": 402, "shown_rate": 5.4, "clicked": 96,
                   "ctr": 23.9, "zero_result_suggestions": 2,
                   "top": [{"query": "gtavi", "suggestion": "atari", "shown": 7, "clicked": 0}]},
  "alias_candidates": [{"query": "ولورینگ", "then": "wolvering", "pairs": 4, "clicks": 3}],
  "silent_failures": [{"query": "gtavi", "searches": 7, "clicks": 0, "ctr": 0.0, "avg_results": 34.0, "top_id": 1001, "top_title": "Atari Flashback 11"}],
  "brand_unmatched": [{"brand": "xiaomi", "searches": 4, "example": "مانیتور شیامی", "last_seen": "2026-10-27 18:02:11"}],
  "demand_gap": [{"query": "phasmophobia", "searches": 6, "last_seen": "2026-10-23 16:40:02"}],
  "trending": [{"query": "gta 6", "searches": 44, "previous": 12, "growth": 32, "growth_pct": 266.7}],
  "suspicious": {"count": 2, "samples": [{"ts": "2026-10-22 03:14:15", "query": "1 AND extractvalue(1,concat(0x7e,..."}]},
  "compare": {"previous_since": "2026-10-10 12:00:00", "previous_until": "2026-10-17 12:00:00",
              "metrics": {"searches": {"current": 12840, "previous": 11100, "change": 1740, "change_pct": 15.7}}}
}
```

The HTML dashboard (M35; Persian, right-to-left and Vazirmatn since M38) is a shell that draws the report client-side and
**refreshes itself**: every `logs.refresh_seconds` (default 30; `0` turns it off) it
re-fetches `?format=json` from the same origin and re-renders, with no server push.
It makes **no third-party request**: the charts are hand-rolled inline SVG, the page
runs one script under a per-response CSP nonce (`script-src 'nonce-…'`,
`connect-src 'self'`), and the token is never written into the page (the script reuses
the page's own query string, so the session cookie is the only credential). Since M38 it is `lang="fa" dir="rtl"`: every UI string lives in one PHP array
(`server/src/AnalyticsStrings.php`, edit the wording there, no logic), every card and tile has a "؟"
help panel (what it measures, how it is calculated, how to read it, what to do, and the data the
storefront must send) and a "راهنما" section explains the main metrics and the log v1 / v2 difference.
Fonts are self-hosted (`public/fonts/vazirmatn/`, OFL, `font-src 'self'`); a header button toggles
light / dark (default: the browser's choice, remembered in `localStorage`). Dates show the stored
Gregorian value with its Jalali equivalent (`Intl`, no library), unshifted, beside the database
clock's UTC offset (`search_logs.ts` is written by the database in its own session zone, which the app
never sets). `?format=json` is untouched (`fixtures/analytics_report.golden.json` guards it).
Reports that stay empty until the storefront sends `session_id` (unique searches, searches per
session, alias candidates) or click events (click-through, MRR, silent failures) say so.
Screenshots: [light](img/analytics-fa-light.png), [dark](img/analytics-fa-dark.png). It shows

- tiles: searches, zero-result rate, cache hit rate, hybrid share, average and p95
  latency, repeated-query share;
- charts: volume, zero-result rate trend, latency trend (average and p95), a
  weekday × hour heatmap, a query-length histogram, tier and cache splits (hover any
  mark for its numbers);
- sortable tables (click a header): top queries, zero-result queries, top brands and
  top categories, with a window selector and a filter box that narrows them
  client-side.

Trends are per day (per hour for `24h`) and read at most 366 daily buckets, so
`all` never scans years of logs for a chart. The weekday × hour heatmap and the
"Busiest hours" times are in the **database clock**, like `since`.

### Reading the dashboard

- **Volume** shows when shoppers search; a sudden drop to zero is an outage on the
  storefront side before it is a search problem.
- **Zero-result rate** is the headline quality number. A step up after a deploy
  means a regression or a catalog change; a slow drift up means new product words
  the aliases do not cover. Pair it with the *Zero-result queries* table, the gap
  list, and close gaps with [aliases or the intent map](SEARCH-BEHAVIOR.md#intent-map).
  The trend counts **unique first-page searches only** (not repeated submits, not paging requests): each bucket
  carries its sample size `n`, `low_confidence` (fewer than 30 searches), `partial` (the still-running last bucket) and
  `zero_rate_ma`, the rate pooled over the last 7 buckets. The chart draws the moving average as the main line, the
  raw values faint, low-confidence points hollow and the partial bucket dashed. Off-topic queries (the store sells
  gaming products only) are **expected** zero results, not engine failures
  ([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#off-topic)).
- **Latency** is average (blue) and p95 (orange) per period: a p95 that climbs while
  the average stays flat is the VPS timing out for some searches (check the
  hybrid share and the cache hit rate next to it).
- **Busiest hours** is for scheduling reloads and maintenance in the quiet cells.
- **Query length**: many one-word queries lean on the keyword tier; a growing 4+ word
  share means shoppers describe needs, where the semantic tier matters most.
- **Repeated queries** is the share of distinct queries searched more than once in the
  window (`repeat_queries.rate`; `search_share` is the same over all searches): a high
  value is what makes the result cache worthwhile.
- **Top brands / categories** count a search for a catalog brand or category when every
  word of its (normalized) name is in the query, so they only see names typed in the
  catalog's script; a Persian query for a Latin brand is counted once the alias file
  makes the query match.

`?format=json` returns (one object; lists hold at most 20 rows):

```json
{
  "window": "7d", "since": "2026-09-29 10:00:00",
  "summary": {"total": 812, "zero_results": 37, "zero_rate": 4.6, "avg_results": 11.2},
  "cache": {"total": 812, "hits": 240, "misses": 572, "hit_rate": 29.6},
  "tier": {"total": 812, "keyword_only": 90, "hybrid": 722, "hybrid_share": 88.9,
           "with_vector": 722, "vector_share": 88.9},
  "latency": {"count": 812, "avg": 61.3, "p50": 48, "p95": 140, "max": 420},
  "did_you_mean": {"total": 812, "suggested": 29, "share": 3.6},
  "top_queries": [{"query": "far cry", "searches": 41, "last_seen": "2026-10-05 21:40:02"}],
  "zero_result_queries": [{"query": "فارکرای ۶", "searches": 5, "last_seen": "2026-10-05 18:12:44"}],
  "volume": [{"bucket": "2026-10-04", "searches": 118}],
  "zero_rate_trend": [{"bucket": "2026-10-04", "n": 118, "zero_results": 5, "zero_rate": 4.2, "zero_rate_ma": 5.1,
                       "low_confidence": false, "partial": false}],
  "latency_trend": [{"bucket": "2026-10-04", "searches": 118, "avg": 58.1, "p95": 131}],
  "hourly_heatmap": [{"weekday": 0, "hour": 21, "searches": 33}],
  "query_length": [{"tokens": 1, "searches": 301}, {"tokens": 2, "searches": 402}, {"tokens": 3, "searches": 70},
                   {"tokens": 4, "searches": 30}, {"tokens": 5, "searches": 9}],
  "top_brands_categories": {"brands": [{"name": "samsung", "searches": 52}],
                            "categories": [{"name": "ssd", "searches": 61}]},
  "repeat_queries": {"distinct": 301, "repeated": 74, "rate": 24.6, "searches": 812,
                     "repeated_searches": 585, "search_share": 72.0},
  "refresh_seconds": 30
}
```

The M35 keys (`zero_rate_trend` onwards) are **additive**: the M31 keys keep their names and
shape. `hourly_heatmap` lists only the cells that have searches (`weekday` 0 = Monday ..
6 = Sunday), `query_length` always has the five buckets (`5` = five or more words),
`top_brands_categories` is empty when the catalog table cannot be read, and
`refresh_seconds` echoes `logs.refresh_seconds` for the page. The trend p95 and the
nearest-rank latency use window functions (MariaDB 10.2+ / MySQL 8); on an older
database `latency_trend` is simply `[]` (one `error_log` line per request) and every other
section still renders. With the dashboard
open the report is computed every `refresh_seconds` for its window: on a very large log,
raise `logs.refresh_seconds` or set it to `0`.

`since` is `null` for `all`. Percentiles are nearest-rank over the window.
**Grouping caveat:** queries are grouped by their *normalized* form (the raw text
when that is empty), so spelling variants that normalize alike merge, but
cross-script aliases (`far cry` and `فارکرای`) stay separate rows. That is
expected, and read next to the [alias file](SEARCH-BEHAVIOR.md#aliases) it is a
signal: two rows for one product means an alias is missing.

**Reading the numbers.**
- *Zero-result rate rising*, or a frequent query in the zero-result list: shoppers
  ask for something the catalog or the matching does not cover. Add an alias or
  tag for a name variant, check the catalog for a missing product, and re-read
  the list after the next reload. A one-off typo is noise; repeats are the gap. A query about something the
  store does not sell at all (medicine, clothing) is an expected zero result, not a gap.
- *Cache hit rate low* (with the cache on): queries rarely repeat inside the TTL
  (`redis.ttl`), the prefix keeps being flushed, or Redis is failing (look for
  `error_log` lines); a near-zero rate with Redis off is normal. Latency is read
  with the hit rate: hits answer in a few ms, so a high rate pulls the average
  down while p95 shows the misses.
- *Hybrid share low*: the VPS is slow, down or not configured (every miss of the
  semantic tier is a keyword-only answer); compare `vector_share` and the VPS's
  own health.
- *p95 latency near the 800 ms target*: look at the Slowest view of the logs page
  for the queries behind it.
- *Did-you-mean share high*: many typos; check the suggestions are accepted
  (the storefront applies them) and that the spellcheck dictionary is current.

## Log export

<a id="log-export"></a>`php server/tools/logs-export.php` (M36) exports `search_logs` to a file for the
[zero-result loop](ALIASES.md#the-zero-result-loop-m36). It is a **CLI tool**, run on the
cPanel host like `tools/config-check.php` (it reads the same `config.php` and
database, needs no token, and answers `404` if ever requested over HTTP). It ships in
`release.zip`.

| option | meaning |
| --- | --- |
| `--out PATH` | **required**; the file to write. Its directory must exist, and it is **refused under `server/public`** (the web root): the file holds shoppers' queries |
| `--window 24h\|7d\|30d\|90d\|all` | time range counted back from the database clock (same as the analytics page); default all time |
| `--since`, `--until` | an alternative to `--window`: `YYYY-MM-DD` or `"YYYY-MM-DD HH:MM:SS"`, `--until` exclusive; not combinable with `--window` |
| `--zero-only` | only searches with `result_count = 0` |
| `--grouped` | one row per distinct normalized query instead of raw rows |
| `--limit N` | at most N rows (raw: the newest N; grouped: the N most searched) |
| `--format csv\|json` | default `csv` |

Raw rows (newest first): `ts, raw_q, normalized_q, had_vector, result_count, latency_ms,
did_you_mean, tier, cache_hit` (columns a pre-M21 / M26 table lacks are left out).
`--grouped` rows: `normalized_q, searches, last_seen` (the raw text when the normalized
form is empty), most searched first. **`customer_id` and `top_ids` are never exported.**
CSV is written with PHP's `fputcsv` (RFC 4180 quoting, `"` doubled, LF line ends) as
UTF-8 **without** a BOM, so Persian text, commas, quotes and newlines round-trip;
`pipeline/gen_aliases.py` also reads a BOM. Exit status: `0` success, `2` usage error,
`1` any other failure (a one-line reason on stderr). It only runs `SELECT`s.

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
