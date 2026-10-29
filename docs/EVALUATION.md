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
`SEARCH_CONFIG_FILE`) and asks for `limit = k` (default 10) **without logging and without the result cache**, so a run never
touches `search_logs`. Without a VPS URL it measures the keyword tier only; with one, the hybrid answer, as shoppers get it.

The report: **success@k** (a right product in the top k), **MRR** (mean of 1 / rank of the first right product),
**nDCG@k** (binary relevance), the **zero-result rate**, and per query the rank of the first right product (`-` = missed,
`FP` = an off-topic query that returned something). The run is saved as `tools/eval/runs/eval-YYYYMMDD-HHMMSS.json`
(`--out-dir` changes the folder, `--no-save` skips it).

### Comparing two runs

```bash
php tools/eval/run_eval.php --compare tools/eval/runs/eval-before.json tools/eval/runs/eval-after.json
```

Prints the metric deltas, then every query whose first right product moved down or disappeared (**REGRESSED**) and every
one that moved up (**improved**), with the ranks before and after.

## Habit

Run it before and after every change to the bundle, the aliases, the intent map or a search knob, and keep the JSON of the
runs you compare. The hybrid numbers need the VPS reachable from the machine that runs the tool; the keyword-only numbers
do not.
