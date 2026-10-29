# Search behavior

How the keyword tier finds, ranks and corrects, and how the hybrid blend combines it with the semantic tier. Every knob named here is listed in [CONFIGURATION.md](CONFIGURATION.md). Data-side details (columns, normalization, the export) are in [PIPELINE.md](PIPELINE.md); the HTTP surface is in [SEARCH-API.md](SEARCH-API.md); the big picture is in [ARCHITECTURE.md](ARCHITECTURE.md).

The order these stages run in `POST /search`: [RUNTIME-FLOW.md](RUNTIME-FLOW.md).

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
`search.title_weight` (10), `search.desc_weight` (1), `search.phrase_bonus` (5);
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
partial fallback keep the additive neighbours. `search.require_all_terms=0`
always serves partial matches (products holding every word still rank first,
also in the hybrid ranking, by their higher keyword score) with the additive neighbours. Server-only: no
bundle rebuild or schema change.

<a id="short-tokens"></a>**Short tokens (M44).** InnoDB's FULLTEXT cannot see a token shorter than
`search.min_token_size` (3 on shared cPanel), and the old answer, a `LIKE '%token%'` scan over title,
specs and description, matched inside other words and long numbers: `gta 2` returned two routers
(`GT-AX6000` holds "gta" as a prefix and the description holds "2"), `هرتز 54` matched "540". There is no
substring relevance path any more. In order:

1. **A short number joins the word before it** when that word is Latin or a word of one of the owner's
   aliases: `ps 4` is also searched as `ps4`, `gta 2` as `gta2`, `fc 26` as `fc26`; the other way round, a
   letter-digit name with a short number (`gta2`) is also searched spaced. These are ordinary alias
   variants (counted in `search.alias_max_variants`). Words that read like an alias term once the spaces are
   ignored meet it too: `play station 4` finds `playstation 4` and, through its alias group, `ps4`.
2. **Any other short token** (`2` after a Persian word, `rx`, `تی`) is matched only as a **whole word** of
   the title or the tags, never as a prefix or a substring and never in the description or the specs. With
   FULLTEXT-sized words in the query the engine finds the candidates and the short token is a whole-word
   condition on them; a query of short tokens only is one whole-word scan of the covering title index
   (`match_type: title`, all words required, no partial matches).
3. In the partial (any-terms) mode a token of exactly `search.min_token_size` letters counts as held only as a
   whole word (the engine's `gta*` would hold `gtax6000`); longer tokens stay prefixes.
4. `LIKE` remains only for SKU / model prefixes (`normalized_sku LIKE 'x%'`, not a relevance path) and as the
   prefilter of the covering-index scans, which a regex always decides.

Server-only; no rebuild. Product descriptions that only mention a short number no longer match it
(`playstation 5` finds a description mentioning it through `playstation`, not through `5`).

<a id="generic-tokens"></a>**Generic tokens and token weights (M44).** The build writes `token_df.json`:
for every token seen in at least two products' normalized titles or tags, the number of such products. At query
time a token's weight is `log(N / (1 + df)) / log(N)` (between 0.2 and 1; a token the file does not list weighs
the most) and a token held by more than `search.generic_df_ratio` (0.15) of the catalog, or listed in
`search.stopwords`, is **generic**: weight 0.1 and optional.

- A product has to hold every **non-generic** word (the all-words rule applies to them only), so a result can
  never qualify on generic words alone. `بازی یک راه خروج` no longer lists PS4 consoles that hold `بازی` and
  `یک`; a generic word the product holds adds a little score and coverage.
- The `coverage` the tester shows, the score and the order among partial matches are **weighted**: the held
  words' weight over the total weight (no single word weighs more than all the others together, so a rare word
  nothing stocks cannot sink every hit). A hit holding only generic words plus one rare word is not "coverage 1.00"
  any more; `گیفت کارت apple` lists the Apple card first and a card that holds `گیفت` only trails it as a weaker
  partial match. Whether a partial match is listed at all still counts the required words
  (`search.soft_and_min_coverage`).
- A number is never generic, however common (`4` in `پلی استیشن 4`): it is what tells `ps4` from `ps5`.
- A query of generic words only (`بازی خرید`) has **no keyword answer**; it falls back to the owner's intent map
  (the categories / tags it names, if the map lists the phrase) and to the semantic tier, never to a substring scan.
- A bundle without `token_df.json` has no frequencies: only the stopwords are generic and every other word weighs 1.
- Tuning: a word every product of a PlayStation shop carries (`پلی استیشن`) can pass `search.generic_df_ratio`; a query
  made of such words only has no keyword answer (the semantic tier and the intent map answer it). Raise the ratio, or
  remove the word's effect by adding it to a query with a number or a name, if that bites.

<a id="entities"></a>**Brand and platform recognition (M44).** The build writes `entities.json`: the catalog's brands
(normalized `brand`, with their product counts) and a short seed list of platforms (`ps5`, `ps4`, `ps3`,
`xbox_one`, `xbox_series`, `switch`, `pc`, each with its spellings). At query time `QueryEntities` finds, longest
phrase first and over the query and its alias variants, the brands and platforms the query names; the owner's alias
groups resolve them, so `شیامی` (an alias of `شیائومی` and `xiaomi`) is the brand `xiaomi` and `پلی استیشن 4` the
platform `ps4`. A used / new **condition** is not recognized: nothing in the catalog export reliably marks it.

Per candidate, the carried entity is +1 (the product's brand is the named one; its title, tags or category name the
named platform), another one is -1, none is 0. A dimension is **active** only if some candidate carries the entity
**and** holds the rest of the query in its title, tags, category or brand (a Xiaomi phone does not answer
`مانیتور شیامی`); otherwise nothing is lifted or lowered. In the hybrid ranking, after the floor, an active
dimension multiplies the score by `1 + search.entity_boost` (carries it) or `1 - search.entity_demote` (carries another);
nothing is removed. One more rule: a **solid** keyword hit of another brand than the named one is no longer exempt from
the relevance floor. Without the semantic tier the keyword hits are reordered the same way (carriers first,
the neutral ones next, the others last). When the brand is named and no candidate carries it, the brand is
reported as `brand_unmatched`: in the debug breakdown (`debug.entities`), on the admin tester page, in
`search_logs.brand_unmatched` and in the analytics page's "brand asked for, not in the results" table. Setting both
`entity_boost` and `entity_demote` to 0 (or `search.entities_enabled` to 0) turns it all off.

<a id="soft-and"></a>**Soft AND (M23).** Strict all-words returned nothing as
soon as one word matched no field: `مدرن وارفار` for the product titled `Call of
Duty Modern Warfare` (the transliteration `وارفار` is nowhere in the catalog).
Now, when fewer than `search.soft_and_min_results` (default 3) products hold every
word (the literal query and its alias variants together), a multi-word query is
**topped up** with partial matches: products holding at least
`search.soft_and_min_coverage` (default 0.5) of a variant's words. Full-coverage
hits always come first (the ranking key is full coverage, then the share of words
held, then the usual title / spec bands), partial ones are tagged
`match_type: partial`, and their keyword score is scaled by
`search.soft_and_partial_penalty` (default 0.5) so that in the hybrid blend they
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
`search.soft_and_candidate_cap` (default 100) of them, the engine's best first, so
a swapped-in word that is nearly everywhere cannot cost a catalog scan per
variant; its swapped-in words and the variant itself must be FULLTEXT-sized (a
`ps 5`-style variant is not topped up). `search.soft_and_min_results=0` turns
soft AND off (strict all-words). Server-only: no bundle rebuild or schema change.

<a id="aliases"></a>**Synonyms and aliases (M15).** A product listed under
one name form is also found by another. At search time the normalized query
is checked against two bundle files, both a JSON list of groups of
equivalent terms:

- `synonyms.json` — generated by `build.py` from the catalog (category labels
  in two languages that share a product model). Groups larger than
  `search.synonyms_max_group_size` (default 4) are ignored as likely noise. A
  group holding a term of `search.synonym_blocklist` (M43) is dropped at runtime: the
  way to remove a bad generated group, since the panel shows this file read-only and a
  rebuild would restore an edited copy. Owner aliases are never filtered.
- `aliases.json` — **maintained by the shop owner** in the admin panel's alias manager
  (stored in `server/storage/aliases.json`, M41; see [ALIASES.md](ALIASES.md)). Groups that share a
  term count as one group (M38), so listing `ps5` in two groups never strands a spelling.

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
`search.alias_max_variants`
(default 6, the literal query included; 1 turns expansion off) caps how many
are tried. Brands go
in the same file, one group per brand: the **exact `oc_manufacturer.name`**
first (trailing space included, e.g. `"Nanoleaf "`), then the spellings
shoppers type (`["Samsung", "سامسونگ"]`), so the brand-match boost fires for a
Persian query. Keep one brand per group, and add a variant to its existing
group rather than a second group; the same Persian word may head several
brands (`سونی` is in both Sony groups) and each is tried as a variant. List every
spelling shoppers type: normalization removes the half-space, so `پلی‌استیشن`
and `پلی استیشن` are two different terms. To bulk-generate Persian spellings of English names offline, see [ALIASES.md](ALIASES.md). To change the aliases: use the admin panel's
"نام‌های مستعار" page (since M41). The list lives in `server/storage/aliases.json`, not in the bundle: a save is
validated, written atomically, backed up, and live on the next search without a build or a reload. A malformed
file is refused at save time, so a typo never silently turns aliases off. Not solved by this: queries that describe a
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
matching needs at least `search.sku_prefix_min_length` (default 4) characters
and a digit, so a word like "sony" never pins products whose code starts with
it. The normalized SKU is also appended to the title-weighted text, so a code
typed with other words, or a partial code, still matches through FULLTEXT
like any title word. Needs a rebuild + reload (new columns); until then the
SKU lookup is skipped and text search answers.

**"Did you mean" (`server/src/Speller.php`).** (M44: a model name such as `redeption2` is the word `redeption` plus
the number `2`: the word is corrected and the number stays attached, `redeption2` -> `redemption2`; a word under 4
letters is left as typed (`fc27`, `ps4`), and a suggestion that would lose any digit of the query is dropped.) The speller runs only when a
query has fewer than `search.suggest_min_results` keyword hits, and a
suggestion is returned only if it has more hits than the literal query (with no
literal hit, its results are served; see `did_you_mean_applied` in
INTEGRATION.md). Its vocabulary is the bundle's `spellcheck.txt`, built from
product titles, brand and tags plus the tokens of the owner's aliases (descriptions,
categories and model codes contributed rare or generic words that won corrections
on the real catalog: `کالا`, `mwe`), with one count per product. Candidates must appear in at least `search.suggest_min_frequency`
products and lie within `search.suggest_max_distance` edits (default 1 since M43; it was 2, and about 17 of
the 40 most frequent suggestions were distance-2 jumps between unrelated words). The one exception: a Latin token
of 8 or more characters may take 2 edits when its first letter is kept (`kaybourd` → `keyboard`). A known word, however rare, is never corrected, and
neither is a token under 4 characters (a code such as `mw4`). Among candidates a
swapped neighbour pair (`battel` → `battle`) wins; a short token's candidate must
share its first letter (`gtavi` is not `atari`); what is left after dropping letters
from the token (`کالاف` → `کالا`) and the words of `search.suggest_blocklist` are
never offered. An owner alias written with other spacing (`رزیدنتاویل` for
`رزیدنت اویل`) is tried before any of this.

**Keyboard-layout fix.** The map is the standard Windows Persian layout (ISIRI 9147): the 26 letter keys plus the punctuation
keys `;`→ک `'`→گ `,`→و `[`→ج `]`→چ `\`→پ and Shift+C→ژ (`pipeline/keyword.py` `PERSIAN_KEYBOARD` and `Keymap.php` are
the same table, a test compares them). Arabic ي / ك are unified to ی / ک first. A word is remapped when it is letters and
those keys, optionally followed by digits (`gta6`, `سن۵`); a word with a hyphen or a leading digit (`HF-1502`) is a model code
and stays, and so do standalone numbers (`لفش ۶` → `gta ۶`, the normalizer folds the digit). A remap is accepted only if it has
more full-coverage hits than the literal query and a result names all its words in the title or tags. When it fails that test
the speller runs on the **remapped** text (not the gibberish) and its correction is accepted under the same test; the speller's
own rules (first letter of a short token, distance) are unchanged. When a remap or a spelling suggestion is applied (the
literal query had no full match), the VPS receives the applied text, so the hybrid results match the corrected query. The
result-cache key stays the literal normalized query (the corrected answer is a function of it).
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

<a id="intent-map"></a>**Intent map (M34).** Build-time and file-edited by design: the shipped `intent_map.json` may
be `{}` (no intent words), and there is no panel editor for it, unlike the aliases.
 Some words a shopper types are in none of a product's
title, tags, brand, category or specs, yet clearly point at a group of products: `فرمون`
(steering wheel) should lift the gaming wheels and accessories. The **intent map**
(`pipeline/intent_map.json`, shipped in the bundle as `intent_map.json`) is a small
owner-curated file, hand-editable like `aliases.json`, from an intent word or phrase to
category and / or tag names:

    {
      "فرمون": { "category": ["لوازم جانبی گیمینگ"], "tags": ["فرمان بازی"] },
      "شوتر":  { "tags": ["شوتر"] }
    }

At query time `IntentMap::resolve()` finds every key that occurs **whole** in the query (token
sequences, longest first, never substrings; at most `search.intent_max_terms`, default 4,
keys apply) and hands the mapped names to the ranker, which treats them as if the shopper had
typed that category / tag: products whose category is the mapped one (all words of the
product's category are in the mapped name) get the existing `category_match_boost`, products
whose tags contain the mapped tag phrase get `tag_match_boost` (one-word intent tags count,
since the shopper never typed them, so the keyword tier has not credited them). There is no
new ranking stage, knob or maths: like every match boost it applies **after** the relevance
floor, so it only reorders candidates the keyword and semantic tiers already returned and can
never admit a weak or floor-dropped hit. It applies in hybrid mode only; keyword-only order
(VPS down or not configured) is unchanged. Without the file, or with `{}`, nothing changes.

Keys must already be in **normalized** form (lower-case Latin, Persian ی/ک, ASCII digits, no
half-space or diacritics, single spaces; see [normalization](PIPELINE.md)); values are
normalized by the build. The build fails with a message naming the offending key if a key
would change under normalization, because such an entry would silently never match. A
present file that does not parse is rejected by `/reload` (`invalid_intent_map`). To change
the map: edit `pipeline/intent_map.json`, build a release, deploy and reload as for any
catalog update (`release.py --intent-map FILE` points at another file). Entries can be
discovered by hand from the zero-result queries (`logs-export.php`, M36); extending
`pipeline/gen_aliases.py` to propose `intent_map.json` entries from the catalog through its
LLM pass is a possible later step and is **not** part of M34.

<a id="off-topic"></a>**Off-topic queries return nothing, on purpose (M43).** The store sells gaming products and
gaming PCs only, and shoppers sometimes use the box like a web search (effervescent tablets, men's clothing,
weight-loss injections). Such a query should return **zero results and no "did you mean"**: a wrong suggestion
for an off-topic query is worse than none, which is why the speller no longer jumps two edits between
unrelated words. These zero-result searches are expected; they are not engine failures and are not a reason to
add aliases (the analytics zero-result list shows them beside real gaps; read it with that in mind).

<a id="letter-digit"></a>**Letter + digit names (M43).** `gta6` / `gta 6`, `fc27` / `fc 27`, `rtx4060` /
`rtx 4060` meet each other without changing normalization: the collapsed-name scan also runs for a collapsed
query of the shape two to five Latin letters then one to four digits even below `search.collapse_min_length`
(so a title written either way is found), and an alias group that lists one spelling also answers to the other
(`["gta 6", "جی تی ای 6"]` serves `gta6`; `["fc27", …]` serves `fc 27`). Other shapes are untouched.

**Blended hybrid ranking (`server/src/Ranker.php`, M20).** Keyword hits no
longer form a tier above the semantic results. For every candidate (keyword
hits, plus the VPS neighbours unless the all-terms rule applies) the ranker
takes the field-weighted keyword score and the VPS cosine (0 if the VPS did not
return it), scales each to 0..1 by its maximum in the result set, and computes

    relevance = search.keyword_weight * keyword_norm + search.semantic_weight * semantic_norm

Candidates with `relevance < search.min_relevance` are dropped, then light
in-stock / popularity boosts (`search.stock_boost`, `search.popularity_boost`)
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

**Facet queries (M28).** A genre / feature word such as "شوتر" is neither a
category nor a tag but an attribute *value* in the specs of hundreds of
products; as a spec-only match it would be weak and floored down to a handful.
A spec-only term therefore also counts toward solidity when it is **common**: at
least `search.facet_min_products` (default 20) products carry it in their specs
(a bounded `COUNT` per query term, cached for the request; no rebuild). A hit
whose every term is a name match or a common spec value is floor-exempt and
ranked by the blend plus the stock / popularity boosts, capped at `limit`.
A rare spec mention ("بلبرینگ", ~1 product) stays weak and is still floored, and
all-terms matching still applies ("کیبورد قرمز" returns only products holding
both words).
`search.semantic_top_k` defaults to 100 (VPS cap 500; it was 300 before M43). A broad query used to trail off into
hundreds of loosely related neighbours, so after the sort the **tail cap** (`search.tail_cap`, default 40)
keeps every solid keyword row but only the first 40 of the other rows (semantic-only neighbours and weak
keyword hits), best first, and only then is the `limit` slice applied (0 turns the cap off). These values are untested on the real catalog and **need
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
| `brand_match_boost` / `search.brand_match_boost` | 0.15 | every word of the product's brand is in the query **or in one of its alias / synonym variants**. A Persian query meets a Latin brand name through [`aliases.json`](#aliases): add `["سامسونگ", "samsung"]` for "اس اس دی سامسونگ" |
| `category_match_boost` / `search.category_match_boost` | 0.1 | every word of the product's category name is in the query (or a variant) |
| `tag_match_boost` / `search.tag_match_boost` | 0.1 | a query of at least `tag_match_min_tokens` (`search.tag_match_min_tokens`, 2) words is a contiguous phrase inside the product's tags. One word is excluded: the keyword tier already credits it through `tag_weight` (no double count) |

**Tuning.** Every knob above lives in `server/config.php` (`search` section) or
the matching `SEARCH_*` environment variable; a `config.php` copied before M9
that lacks the new keys uses the defaults shown in `config.example.php`. The
defaults are starting points: tune `semantic_min_score` and the weights against
a labeled eval set from real queries (`server/tools/eval.php`, which uses the
same wiring and config as `/search`).
