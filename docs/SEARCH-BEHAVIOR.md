# Search behavior

How the keyword tier finds, ranks and corrects, and how the hybrid blend combines it with the semantic tier. Every knob named here is listed in [CONFIGURATION.md](CONFIGURATION.md). Data-side details (columns, normalization, the export) are in [PIPELINE.md](PIPELINE.md); the HTTP surface is in [SEARCH-API.md](SEARCH-API.md); the big picture is in [ARCHITECTURE.md](ARCHITECTURE.md).

## Keyword tier

**Keyword field weighting (`server/src/Keyword.php`).** Title and description
are scored separately so a product named by the query beats one whose long
description merely mentions it (on the real catalog, "دوال شاک" ranked PS5
bundles above the controllers, and "بلبرینگ" ranked case fans above bearings).
Each query token is credited to the best field it matched: `score =
(title_weight × title hits + desc_weight × other hits) / tokens`, plus
`phrase_bonus` when the tokens appear adjacent and in order in the title. Any
row with a title match ranks above every description-only row, whatever the
weights (description-only matches are kept, below); the weights order rows
inside those two bands. Title hits are word-prefix matches on the short title
only — the long description is never scanned per row. Knobs:
`SEARCH_TITLE_WEIGHT` (10), `SEARCH_DESC_WEIGHT` (1), `SEARCH_PHRASE_BONUS` (5);
starting points, to be tuned on the real catalog. Ranking-only: no bundle
rebuild or schema change.

<a id="all-terms"></a>**All words (M16).** A multi-word query matches only
products that hold **every** word somewhere in title, specs and description
combined: `کیبورد قرمز` returns keyboards whose title, colour attribute or
description says red, not black keyboards and not red mice. Alias variants
apply the rule per variant (the union of the variants' hits; a variant still
matches in the title only). The surviving products rank by the field
weights below: title band, then spec band (a spec match with no title
match), then description-only, then score. The phrase bonus puts a product
named `کیبورد قرمز` first. When **no** product holds every word, even after
the keyboard-layout / spelling recovery, the products holding the most words
are served instead (most words first, then the same bands), so the shopper
still sees something. While the keyword hits hold every word, the semantic
tier only reorders them: semantic-only neighbours are **not** appended below.
Embeddings put black keyboards and red mice close to "کیبورد قرمز" (on
`multilingual-e5-small`, 0.83–0.84 against a 0.82 floor on hand-written sample
passages; bge-m3's scale is lower but the proximity is the same), so without
that the one-word matches came back through the semantic tier. Single-word queries, SKU hits and the
partial fallback keep the additive neighbours. `SEARCH_REQUIRE_ALL_TERMS=0`
always serves partial matches (products holding every word still rank first,
also in the hybrid ranking, by their higher keyword score) with the additive neighbours. Server-only: no
bundle rebuild or schema change.

<a id="soft-and"></a>**Soft AND (M23).** Strict all-words returned nothing as
soon as one word matched no field: `مدرن وارفار` for the product titled `Call of
Duty Modern Warfare` (the transliteration `وارفار` is nowhere in the catalog).
Now, when fewer than `SEARCH_SOFT_AND_MIN_RESULTS` (default 3) products hold every
word (the literal query and its alias variants together), a multi-word query is
**topped up** with partial matches: products holding at least
`SEARCH_SOFT_AND_MIN_COVERAGE` (default 0.5) of a variant's words. Full-coverage
hits always come first (the ranking key is full coverage, then the share of words
held, then the usual title / spec bands), partial ones are tagged
`match_type: partial`, and their keyword score is scaled by
`SEARCH_SOFT_AND_PARTIAL_PENALTY` (default 0.5) so that in the hybrid blend they
stay weak: they need semantic support to clear `min_relevance`. With an alias
`["وارفار", "warfare"]` the variant `مدرن warfare` finds the Latin title by its
one word; with both words aliased the product is a **full**-coverage hit through
the variant `modern warfare`. Unchanged: queries with enough full-coverage hits
(`کیبورد قرمز` with three red keyboards gets no partial at all), one-word queries
(`بلبرینگ` with no match is still empty), and the "did you mean" recovery, which
counts only full-coverage hits (a typo whose other word matches plenty still gets
its correction, and an applied suggestion replaces the partial hits). The last
resort is unchanged too: when even the partial top-up finds nothing, products
holding any word are served (above). An alias variant's top-up only looks at the
rows holding a word it swapped in and scores at most
`SEARCH_SOFT_AND_CANDIDATE_CAP` (default 100) of them, the engine's best first, so
a swapped-in word that is nearly everywhere cannot cost a catalog scan per
variant; its swapped-in words and the variant itself must be FULLTEXT-sized (a
`ps 5`-style variant is not topped up). `SEARCH_SOFT_AND_MIN_RESULTS=0` turns
soft AND off (strict all-words). Server-only: no bundle rebuild or schema change.

<a id="aliases"></a>**Synonyms and aliases (M15).** A product listed under
one name form is also found by another. At search time the normalized query
is checked against two bundle files, both a JSON list of groups of
equivalent terms:

- `synonyms.json` — generated by `build.py` from the catalog (category labels
  in two languages that share a product model). Groups larger than
  `SEARCH_SYNONYMS_MAX_GROUP_SIZE` (default 4) are ignored as likely noise.
- `aliases.json` — **maintained by the shop owner** in
  `pipeline/aliases.json` (or the file `SEARCH_ALIASES_FILE` /
  `release.py --aliases FILE` names), normalized and shipped in the bundle.

```json
[
  ["gta", "grand theft auto", "جی تی ای"],
  ["ps5", "ps 5", "playstation 5", "پلی استیشن 5", "پلی‌استیشن 5"]
]
```

Each inner list is one group; any term may be a phrase, in any script, with
Persian or ASCII digits. Every term of a group finds the products named by any
other: "GTA V" (→ `gta 5`) is also searched as `grand theft auto 5` and
`جی تی ای 5`. Terms are matched as **whole words / whole phrases**, never
inside a word (`gta` does not touch `gtax`, and `auto` alone is not `grand
theft auto`), and a variant is matched in the product **title, tags, brand,
category and specs** (since M23; before, the title only), never the
description, so a description that merely mentions an alias never pulls that
product in, while a transliteration that sits in a tag or an attribute is
found. The literal query still searches title, specs and description as before;
the variants' hits merge into it by the same title / spec / description bands.
A query naming **several** aliased terms is also searched with all of them
swapped at once (M23: `مدرن وارفار` with `["مدرن", "modern"]` and
`["وارفار", "warfare"]` also searches `modern warfare`), after the
one-swap variants. A variant with a token shorter than a FULLTEXT token
(`ps 5`, a digit) cannot use the FULLTEXT index and keeps the **title-only**
scan of a covering title index (`idx_title_scan`), shared by all such variants;
`SEARCH_ALIAS_MAX_VARIANTS`
(default 6, the literal query included; 1 turns expansion off) caps how many
are tried. Brands go
in the same file, one group per brand: the **exact `oc_manufacturer.name`**
first (trailing space included, e.g. `"Nanoleaf "`), then the spellings
shoppers type (`["Samsung", "سامسونگ"]`), so the brand-match boost fires for a
Persian query. Keep one brand per group, and add a variant to its existing
group rather than a second group; the same Persian word may head several
brands (`سونی` is in both Sony groups) and each is tried as a variant. List every
spelling shoppers type: normalization removes the half-space, so `پلی‌استیشن`
and `پلی استیشن` are two different terms. To change the aliases: edit the
file, build a release, deploy and reload as for a catalog update. The build
and the reload both reject a malformed file (`invalid_aliases`), so a typo
never silently turns aliases off. Not solved by this: queries that describe a
need rather than name a product ("مانیتور مناسب کنسول"); aliases only fix
name / alias / form matching.

<a id="sku-search"></a>**SKU search (M12).** Shoppers can search by product
code. The SKU is stored raw (`sku`) and canonicalized (`normalized_sku`,
indexed): normalized like model codes, then every non-letter/non-digit is
removed, so `AB-12 34`, `ab.1234` and `AB1234` are the same code
(`Normalizer::normalizeSku` / `normalize_sku`, parity-tested on the shared
fixture). A product whose normalized SKU equals the query's ranks at the very
top, then SKU prefix matches (shortest code first), above every title match,
also in hybrid mode and with no "did you mean" on an exact hit. Prefix
matching needs at least `SEARCH_SKU_PREFIX_MIN_LENGTH` (default 4) characters
and a digit, so a word like "sony" never pins products whose code starts with
it. The normalized SKU is also appended to the title-weighted text, so a code
typed with other words, or a partial code, still matches through FULLTEXT
like any title word. Needs a rebuild + reload (new columns); until then the
SKU lookup is skipped and text search answers.

**"Did you mean" (`server/src/Speller.php`).** The speller runs only when a
query has fewer than `SEARCH_SUGGEST_MIN_RESULTS` keyword hits, and a
suggestion is returned only if it has more hits than the literal query (with no
literal hit, its results are served; see `did_you_mean_applied` in
INTEGRATION.md). Its vocabulary is the bundle's `spellcheck.txt`, built from
product titles, brand, category and model only (descriptions contribute rare
incidental words that won corrections on the real catalog), with one count per
product. Candidates must appear in at least `SEARCH_SUGGEST_MIN_FREQUENCY`
products and lie within `SEARCH_SUGGEST_MAX_DISTANCE` edits (1 edit for tokens
of 4 characters or fewer). A known word, however rare, is never corrected.
Bundles built before M9 still work, but their dictionary includes description
words; rebuild to get the high-signal one. The dictionary is cached
like the vectors: parsed once per PHP worker, and stored in APCu (about 3 MB for
32k terms) so a fresh worker skips the parse. The cache key is the file's
identity, so a reload is picked up by every worker, and `POST /reload` also
evicts the previous entry and warms the new one. Each term is pre-encoded one
byte per character so the edit distance runs in PHP's native `levenshtein()`,
with results identical to a multibyte Levenshtein. Only if `spellcheck.txt` is
missing does it fall back to scanning the `products` table, which is too slow
for the budget on a full catalog.

## Hybrid ranking

**Blended hybrid ranking (`server/src/Ranker.php`, M20).** Keyword hits no
longer form a tier above the semantic results. For every candidate (keyword
hits, plus the VPS neighbours unless the all-terms rule applies) the ranker
takes the field-weighted keyword score and the VPS cosine (0 if the VPS did not
return it), scales each to 0..1 by its maximum in the result set, and computes

    relevance = SEARCH_KEYWORD_WEIGHT * keyword_norm + SEARCH_SEMANTIC_WEIGHT * semantic_norm

Candidates with `relevance < SEARCH_MIN_RELEVANCE` are dropped, then light
in-stock / popularity boosts (`SEARCH_STOCK_BOOST`, `SEARCH_POPULARITY_BOOST`)
are applied **after** the floor, so they reorder but never rescue. Starting
points: **0.4 / 0.6 / 0.45**. The floor never drops a **solid** keyword match:
an exact SKU, or every query term matching in the product title / name, is
always kept and only ranked by the blend, so a product the VPS ranks outside
its top-K (or an empty / short VPS list) never loses its keyword results.
Only **weak** keyword hits (the query matched only in specs / description) and
semantic-only neighbours are subject to the floor. A weak hit is capped at 0.4,
below the floor, so "بلبرینگ" no longer returns power supplies whose specs
mention "ball bearing" (high keyword, ~zero cosine). Consequence: a query whose
hits are all weak and which the VPS finds nothing close to returns nothing.
Since M21 the same call also applies the brand / category / tag match boosts
(see [Tags, brand and category](PIPELINE.md#tags-brand-category)) and a hit with every term
in the title **or tags** counts as solid.
`SEARCH_SEMANTIC_TOP_K` defaults to 300 (VPS cap 500) so fewer legitimate
products are missed. These values are untested on the real catalog and **need
tuning on real queries**. Unchanged: exact-SKU hits are pinned first, the
`require_all_terms` candidate filter, and the VPS-down keyword-only fallback
(keyword order, no floor).

*Ranking boosts (hybrid mode only; keyword-only order comes from the field
weights above).* Applied after the relevance floor, multiplying the relevance
like stock and popularity: `score = relevance × (1 + stock + popularity +
brand + category + tag)`. A boost can reorder close candidates but never admits
a candidate the floor dropped, so a brand cannot flood unrelated results.

| config / env | default | fires when |
| --- | --- | --- |
| `brand_match_boost` / `SEARCH_BRAND_MATCH_BOOST` | 0.15 | every word of the product's brand is in the query **or in one of its alias / synonym variants**. A Persian query meets a Latin brand name through [`aliases.json`](#aliases): add `["سامسونگ", "samsung"]` for "اس اس دی سامسونگ" |
| `category_match_boost` / `SEARCH_CATEGORY_MATCH_BOOST` | 0.1 | every word of the product's category name is in the query (or a variant) |
| `tag_match_boost` / `SEARCH_TAG_MATCH_BOOST` | 0.1 | a query of at least `tag_match_min_tokens` (`SEARCH_TAG_MATCH_MIN_TOKENS`, 2) words is a contiguous phrase inside the product's tags. One word is excluded: the keyword tier already credits it through `tag_weight` (no double count) |

**Tuning.** Every knob above lives in `server/config.php` (`search` section) or
the matching `SEARCH_*` environment variable; a `config.php` copied before M9
that lacks the new keys uses the defaults shown in `config.example.php`. The
defaults are starting points: tune `semantic_min_score` and the weights against
a labeled eval set from real queries (`server/tools/eval.php`, which uses the
same wiring and config as `/search`).
