# Evaluation

How to measure a search change before it reaches shoppers: a **golden set** of real queries with the products that
are right for them, scored by `tools/eval/run_eval.php`. (`server/tools/eval.php` is the older fixture harness, precision /
recall over `fixtures/eval_queries.json`; this one is for the owner's real queries.)

## The golden file

`tools/eval/golden.csv` (copy `tools/eval/golden.sample.csv`, whose ids are fake):

```
query,relevant_ids,note
ps5 slim,101;102,two right answers
clothes shirt,,off-topic: nothing should come back
```

`relevant_ids` are `;`-separated product ids; an empty cell means *nothing should come back* (the off-topic queries
that must stay at zero results). Lines starting with `#` before the header are comments. Aim at 150-200 queries.

### Building it from the logs

The quick way is the two helper scripts below ([Labeling helper](#labeling-helper-excel)); this is what they automate.

1. **Top queries.** Export the grouped search log ([SEARCH-API.md](SEARCH-API.md#log-export)):
   `php server/tools/logs-export.php --grouped --window 30d --out top.csv`. Take the 100 most searched queries.
2. **Silent failures.** Open the analytics page, card *شکست‌های بی‌صدا*: queries that returned results but get (almost) no
   clicks, each with the title of the first result, so you can see at a glance what the shopper was shown. These are the
   most valuable rows: the answer looks like a hit and is not. Note that clicks are under-counted until the mobile theme
   sends them to `POST /events`; judge the first result's title, not only the click rate.
3. **Zero-result queries** worth keeping (`--zero-only`): add the ones the store should answer with the right product
   ids, and the off-topic ones (clothes, tablets...) with an empty `relevant_ids`.
4. For each query, look the right products up in the store (or in the panel's tester page: every row shows the product id)
   and write their ids. Keep the real note ("shown a Nintendo card for an Apple query").

## Running it

```bash
php tools/eval/run_eval.php --golden=tools/eval/golden.csv
```

It builds the same `SearchController` `/search` uses (database and VPS settings from `server/config.php` or
`SEARCH_CONFIG_FILE`) and asks for `limit = depth` (default 300) **without logging and without the result cache**, so a run
never touches `search_logs`; the metrics score the first `k` (default 10) of that list. Without a VPS URL it measures the keyword tier only; with one, the hybrid answer, as shoppers get it.

The report: **success@k** (a right product in the top k), **MRR** (mean of 1 / rank of the first right product),
**nDCG@k** (binary relevance), the **zero-result rate**, and per query the rank of the first right product (`-` = missed,
`FP` = an off-topic query that returned something). The run is saved as `tools/eval/runs/eval-YYYYMMDD-HHMMSS.json`
(`--out-dir` changes the folder, `--no-save` skips it).

### Depth vs k

The OpenCart storefront asks `/search` with `limit=300` and shows that list. The order of the first page depends on the
requested limit: the hybrid blend normalizes keyword and cosine scores over, and ranks, only the keyword candidates that fit
in `limit`, so a product that is nearest by meaning but low by keyword is outside a `limit=10` pool and unfairly low (or
absent), while at 300 it can lead. `server/tests/SearchDepthTest.php` pins it (60 tied `ps4` titles plus one far neighbour:
the top 10 differ between limit 10 and 300; keyword-only they do not).

So the tools separate two numbers:

- **depth**: what is requested from `/search`. `--depth=300` by default, the constant `GoldenEval::STOREFRONT_DEPTH`
  (documented as what the OpenCart storefront sends). Change it only if the storefront's limit changes.
- **k**: how much of the ranked list is scored (`--k=10`). Metrics are computed over the first k only.

The admin tester page uses the same depth and shows the first 10 results (a link shows 50). `--depth` must be at least `--k`.
The saved JSON records both (`depth`, `k`). `--compare` refuses two runs of different depth; a run saved before `depth`
existed counts as depth = k, so rerun the baseline once.

### Comparing two runs

```bash
php tools/eval/run_eval.php --compare tools/eval/runs/eval-before.json tools/eval/runs/eval-after.json
```

Prints the metric deltas, then every query whose first right product moved down or disappeared (**REGRESSED**) and every
one that moved up (**improved**), with the ranks before and after.

## Labeling helper (Excel)

Runs on the **repo checkout** (it needs `server/config.php` for the database and, optionally, the VPS), **not on cPanel**:
`tools/eval/*` is not in `release.zip`. Use a copy of the catalog database the search runs on.

1. Export the grouped log (on the host, or any machine that reaches the log database):
   `php server/tools/logs-export.php --grouped --window 30d --out top.csv`. The columns are `normalized_q, searches, last_seen`,
   most searched first.
2. `php tools/eval/make_candidates.php --queries=top.csv --n=150 --k=10 --out=candidates.csv` takes the first 150 distinct
   queries (queries that normalize equally count once), searches each at the storefront depth and writes the first k results,
   one row per result: `query, rank, product_id, title, relevant, note`. A query with no result gets one row with the title
   `(no results)` so it can be marked off-topic. UTF-8 with a BOM, so Excel shows Persian correctly.
3. **Excel step.** Open `candidates.csv`. In `relevant` type `1` for each right product of a query and `0` for the wrong
   ones; leave a query blank to skip it. For an off-topic query (nothing should come back) mark every row `0` and write
   `off` in `note`. Save as **CSV UTF-8** (comma or semicolon separated both work). Do not reorder columns or rename headers
   (extra columns whose name starts with `note` are read too).
4. `php tools/eval/finish_golden.php --in=candidates.csv --out=tools/eval/golden.csv`: `1` rows become the query's
   `relevant_ids` (`;` separated, in sheet order); all-`0` plus the word `off` becomes an off-topic row (empty `relevant_ids`);
   anything else is skipped and listed on screen with the reason (no mark, all 0 without `off`, no 1 and some rows blank).
   A mark other than `1`, `0` or empty stops with the row number. It refuses to overwrite an existing `--out` without
   `--force`. The golden format `run_eval.php` reads is unchanged.

Judge the product, not the rank: the sheet shows what the search returns today, so a right product that is missing from
the top k is not in the sheet; add its id to the golden file's `relevant_ids` by hand (look it up in the store).

## Habit

Run it before and after every change to the bundle, the aliases, the intent map or a search knob, and keep the JSON of the
runs you compare. The hybrid numbers need the VPS reachable from the machine that runs the tool; the keyword-only numbers
do not.
