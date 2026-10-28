# Aliases: the admin alias manager and the generator

The owner's aliases (the equivalence groups of [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#aliases): `["gta",
"grand theft auto", "جی تی ای"]`) are edited **live in the admin panel** (`admin.php?p=aliases`, since M41, see
[ADMIN.md](ADMIN.md#alias-manager-m41)). The list is `server/storage/aliases.json` (`paths.storage`): it survives
every `/reload`, is never part of the bundle and needs no rebuild. A saved change is live on the next search and the
result-cache key carries the file's version, so no cached answer from before the edit is served.

`pipeline/aliases.json` is **only the seed for a new install**: the first visit to the alias manager copies the live
bundle's `aliases.json` into storage when storage has none. After that the pipeline file is not read by the
service, a rebuild does not touch the live aliases, and an edit made to the pipeline file or to the bundle's copy
has no effect. It is also the target of `gen_aliases.py --merge` (below), whose output you paste into the panel.

## Day to day: the alias manager

1. Open `?p=aliases` (login required). **Groups** lists, searches and paginates all groups; add, edit or delete one.
   Each term shows its normalized form and the merged group when overlapping groups are joined (groups that share a
   term count as one, so listing `ps5` in two groups never strands a spelling); "count" computes the products one term
   matches, "test" opens the search tester.
2. **Errors block a save**; **warnings** (a very short term, a term in another group, one that matches more than
   `admin.alias_warn_matches` products, one in `search.suggest_blocklist`) need a confirm. A save is refused as stale
   when the file changed meanwhile.
3. **Backups**: every save keeps the previous file (newest `admin.alias_backups`), with a diff and a one-click restore.
   **Advanced** edits the whole file as JSON with the same checks and offers a download.
4. A letter-digit name needs only **one** spelling in a group: `["gta 6", "جی تی ای 6"]` also serves `gta6`, and
   `["fc27", "اف سی 27"]` also serves `fc 27` (M43).
5. A hand-typed term is normalized like a query (Arabic ي/ك, ZWNJ, capitals, digits), so it matches. Since
   normalization version 4 a lone `v` / `x` is not a number, so a title that ends in one needs a group of its own:
   `["gta 5", "gta v", "grand theft auto 5", "grand theft auto v", …]`.

Aliases are only ever **added** by the owner. A bad group that the build derives from the catalog (`synonyms.json`,
shown read-only on `?p=synonyms`) is removed with `search.synonym_blocklist` in `config.php`, not here; owner aliases
are never filtered by it.

## The generator (offline, prep-time only)

`pipeline/gen_aliases.py` proposes alias groups: the Persian spellings shoppers type for English catalog names, so
"مدرن وارفار" finds "Modern Warfare". Search never calls an LLM; the tool proposes and a human approves. Its output is
**pasted into the panel** (Advanced tab, or added group by group), not rebuilt into the service.

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
3. **Merge** the reviewed file into a seed copy (optional): `python pipeline/gen_aliases.py --merge
   [pipeline/aliases.generated.json] [--aliases FILE]`. Groups are de-duplicated by normalized form (the pipeline
   `normalize`, so "سوني" and "سونی" are one term); a candidate sharing a term with an existing group extends it, the
   rest are appended. Existing groups keep their order and spelling.
4. **Apply in the panel**: copy the groups you accepted into the alias manager (the Advanced tab takes the whole merged
   JSON; export the live file first with its download button and merge into *that*, since the pipeline copy is only a
   seed and may be older). No rebuild, no reload: the next search sees it.

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

Then the usual steps: **review** (delete anything wrong, especially generic words), merge, and apply in the panel.

```
php server/tools/logs-export.php --zero-only --grouped --window 30d --out zero.csv   # on the cPanel host
python pipeline/gen_aliases.py --queries-file zero.csv        # -> aliases.generated.json
# review the candidates by hand, then paste the accepted groups into admin.php?p=aliases (Advanced tab)
```

**Off-topic queries are expected zero results.** The store sells gaming products and gaming PCs only; a shopper who
types "effervescent tablets" or "men's clothing" should get nothing and no suggestion
([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#off-topic)). They show up in this list but are not gaps and get no alias.

A query that is really a product the shop does not sell, or a category or
feature word, will not (and should not) get an alias; for those the answer is the
catalog or the [intent map](SEARCH-BEHAVIOR.md#intent-map), which is curated by
hand (a later extension of this generator could propose intent-map entries). The
rate to watch is the zero-result rate on the [analytics
dashboard](SEARCH-API.md#analytics): it should fall after each cycle.

## Seed groups

The seed groups for the zero-result queries seen in the first real logs (`مافیا`, `ولورینگ`, `گادوار`, `رزیدنت اویل`,
`مورتال`, `ردد`, `کالاف`, `شیفتر`, `فرمون`, `گوست`, `زلدا`, `ویچر`, `اساسین`, `ماینکرافت`, `آیینه`, `بازی اکانتی`,
`پی اس فایو`, `جی تی ای شیش`, …) are in `pipeline/aliases.json`, which seeds a new install. They were **not** checked
against the live catalog: confirm each with the zero-result list of the panel's analytics page after a week, and prefer
removing a group that pulls unrelated products over keeping it.
