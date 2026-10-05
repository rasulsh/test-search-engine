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
