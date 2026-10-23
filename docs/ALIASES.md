# Alias generator (M25)

`pipeline/gen_aliases.py` fills `pipeline/aliases.json` (the equivalence groups
of [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#aliases)) with the Persian spellings
shoppers type for English catalog names, so "مدرن وارفار" finds "Modern Warfare".
It is **offline and prep-time only**: search never calls an LLM, and the runtime
just reads the richer `aliases.json` after the next rebuild. The tool proposes;
a human approves.

## Configuration

Any OpenAI-compatible `/chat/completions` endpoint (env, see `.env.example`):

| Key | Default | Meaning |
|---|---|---|
| `ALIAS_LLM_BASE_URL` | – | e.g. `https://api.openai.com/v1` |
| `ALIAS_LLM_MODEL` | – | model name |
| `ALIAS_LLM_API_KEY` | – | bearer key (never committed; CI uses a mock client) |
| `ALIAS_LLM_BATCH_SIZE` | 40 | names per request |
| `ALIAS_MAX_VARIANTS` | 4 | Persian variants kept per name |
| `ALIAS_MAX_VARIANT_CHARS` | 40 | longer variants are dropped |
| `ALIAS_MAX_NAME_CHARS` | 60 | longer input names are skipped |

The prompt lives in `pipeline/gen_aliases_prompt.txt`: transliterations and
well-known Persian forms, not translations; strict JSON out.

## Flow

1. **Generate candidates** from the export (distinct `brand` and `title_en`),
   optionally plus zero-result queries (a text file, one query per line, mined
   from `search_logs` where `result_count = 0`, see [SEARCH-API.md](SEARCH-API.md)):

   ```
   python pipeline/gen_aliases.py --csv export.csv [--queries zero_results.txt] [--limit N]
   ```

   Writes `pipeline/aliases.generated.json` (`[english, persian, ...]` groups).
   `aliases.json` is not touched. Pure-numeric, Persian-only, one-character and
   duplicate (after normalization) names are skipped. LLM output is validated:
   it must be a JSON object, variants must be strings with Persian letters
   within the length cap, empties and duplicates (after normalization, also of
   the English name) are dropped, at most `ALIAS_MAX_VARIANTS` are kept. A bad
   batch reply is reported on stderr and skipped.
2. **Review** the candidate file by hand: delete wrong groups or variants.
   A wrong alias pulls unrelated products, so check short or generic terms.
3. **Merge** the reviewed file:

   ```
   python pipeline/gen_aliases.py --merge [pipeline/aliases.generated.json] [--aliases FILE]
   ```

   Groups are de-duplicated by normalized form (the pipeline `normalize`, so
   "سوني" and "سونی" are one term); a candidate sharing a term with an existing
   group extends it, the rest are appended. Existing groups keep their order and
   spelling. The result is re-checked with the build's alias loader.
4. **Rebuild and reload** as usual (`release.py`, [DEPLOY.md](DEPLOY.md)); the
   next bundle ships the merged `aliases.json`.

Zero-result queries are a recurring input: rerun steps 1-4 now and then with
fresh queries from the log; already-known groups merge to no change.

## The zero-result loop (M36)

The shoppers' own zero-result searches are the best source of missing aliases
(`رزیدنت اویل`, `پی اس فایو`, `ولورینگ`). Two pieces close the loop:

1. **Export** them on the cPanel host (the CLI reads the same config / database as
   the app; see [SEARCH-API.md](SEARCH-API.md#log-export)):
   `php server/tools/logs-export.php --zero-only --grouped --window 30d --out zero.csv`
   writes one row per distinct normalized query (`normalized_q,searches,last_seen`,
   most searched first, UTF-8). Copy `zero.csv` to the build machine (it holds
   shoppers' search text, so treat it as private).
2. **Ask for alias candidates** from that file instead of the catalog:
   `python pipeline/gen_aliases.py --queries-file zero.csv` (add `--csv export.csv`
   to also generate the catalog's Persian spellings in the same run; `--limit N`
   caps how many queries are asked about, the file is already ordered by
   searches). For each query the model is asked, with
   `pipeline/gen_aliases_queries_prompt.txt`, for the **other forms of the same
   thing**: the official English name and other Persian spellings or spacing, never
   a related product, and it skips what it does not recognize. Persian-only queries
   are kept (unlike catalog names, which need Latin letters). Each answer becomes a
   candidate group `[query, form, ...]` in `pipeline/aliases.generated.json`; the
   validation is the one above, except that a form may be in either script.

Then the usual steps: **review** (delete anything wrong, especially generic
words), `--merge`, rebuild, deploy and reload.

```
php server/tools/logs-export.php --zero-only --grouped --window 30d --out zero.csv   # on the cPanel host
python pipeline/gen_aliases.py --queries-file zero.csv        # -> aliases.generated.json
# review the candidates by hand, then:
python pipeline/gen_aliases.py --merge                        # -> aliases.json
python pipeline/release.py --csv export.csv --out release.zip # rebuild
# deploy + reload (docs/DEPLOY.md)
```

A query that is really a product the shop does not sell, or a category or
feature word, will not (and should not) get an alias; for those the answer is the
catalog or the [intent map](SEARCH-BEHAVIOR.md#intent-map), which is curated by
hand (a later extension of this generator could propose intent-map entries). The
rate to watch is the zero-result rate on the [analytics
dashboard](SEARCH-API.md#analytics): it should fall after each cycle.
