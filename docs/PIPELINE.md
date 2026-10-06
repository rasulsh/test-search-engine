# Offline pipeline, bundle and data model

Everything that happens before the server sees data: the export from OpenCart, normalization, the fields `build.py` derives, and the bundle it writes. To run a build end to end on a GPU, follow [TRAINING-COLAB.md](TRAINING-COLAB.md); to ship it, [DEPLOY.md](DEPLOY.md). Search-time behavior of these fields is in [SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md); keys are in [CONFIGURATION.md](CONFIGURATION.md).

## Offline pipeline & the bundle

Heavy work runs offline (a GPU machine, in practice Colab); the server only consumes the
static **bundle** it produces.

**`build.py` input** — a simple, documented shape (NOT the raw OpenCart export).
Provide a `.sql` (INSERT statements) or `.csv` with these columns:

| column | notes |
| --- | --- |
| `id` | product id (integer, primary key) |
| `title_fa`, `title_en` | Persian and English titles |
| `desc` | description (truncated for the embedding input) |
| `brand`, `category`, `model` | facets; `model` also feeds catalog synonyms |
| `sku` | optional product code; exact and prefix SKU queries rank first (see [SKU search](SEARCH-BEHAVIOR.md#sku-search)) |
| `price`, `stock`, `popularity` | numeric |
| `url`, `image` | display fields |
| `attributes` | optional; `name: value` pairs joined with ` \| ` (the export's `GROUP_CONCAT`), see [Specs](#specs-field) |
| `feature` | optional; `oc_product.feature` exactly as stored (PHP `serialize()` output), see [Specs](#specs-field) |
| `tags` | optional; the product's `oc_tag.name` values (via `oc_product_tag`) joined with a space, see [Tags, brand and category](#tags-brand-category) |

Deriving it from **OpenCart 2.0.3.1** is step 1 of the
[catalog update](DEPLOY.md#every-catalog-update); the export tool and query are
[below](#exporting-from-opencart-db_exportpy). `build.py` decodes the HTML
escaping OpenCart stores in names and descriptions and strips the markup, so the
export can be taken from the OpenCart tables as-is.

**Bundle output** (`build.py --csv export.csv --out ./bundle`): `vectors.bin`
(little-endian float32, `count × dim`, L2-normalized), `vectors.idx` (one
`product_id` per line, row order), `products.load.sql` (drops and recreates the
`products_new` staging table from the `products` definition in `db/schema.sql`,
so schema changes such as the `sku` and `normalized_specs` columns reach the live table through the
swap with no manual `ALTER`; then its rows, normalized columns from the canonical Normalizer,
split into statements of at most `SEARCH_LOAD_MAX_STATEMENT_BYTES`, 1 MB by
default, so each fits a shared host's `max_allowed_packet`),
`synonyms.json`, `aliases.json` (the owner's [alias file](SEARCH-BEHAVIOR.md#aliases),
normalized), `spellcheck.txt`, `keymap.json`, and `meta.json`
(`{model, revision, dim, normalization_version, count, built_at, checksum, embedder}`).
M21 added the `normalized_tags`, `normalized_brand` and `normalized_category`
columns to `products.load.sql`; `model`, `dim` and `normalization_version` in
`meta.json` are unchanged, so both `/reload`s (cPanel and VPS) accept the new
bundle and only `count` / `checksum` differ.

**Embedding contract (model parity, contract 2).** The semantic tier's model
is bge-m3 on the VPS: `build.py --vps-out` embeds products with
`SEARCH_VPS_MODEL*` / `SEARCH_VPS_POOLING` / prefixes, and the VPS embeds
queries with the same model, revision, pooling and prefix (`vps/README.md`,
"Model parity"); its `/reload` refuses vectors whose `meta.json` differs. The
cPanel bundle's own `vectors.bin` (e5, `"passage: "` prefix) is still built
and validated on `/reload` (contract 3) but is no longer read by `/search`
(M18). The passage is a bounded composed field, in priority order
`title_fa title_en tags brand category model` (tags early since M21), the
feature titles, the attribute values, then the truncated `desc` (at most
`SEARCH_DESC_CHAR_LIMIT`), all within
`SEARCH_PASSAGE_CHAR_LIMIT` characters (default 1000): the description gives
way first, then the tail of the specs. With the real e5 tokenizer a
1000-character passage measured at most 349 tokens on realistic text and 417
on a digit-heavy worst case, under the model's 512. It is embedded from
**raw** text (not the keyword-normalized text), so the VPS can embed the raw
query without re-implementing the normalizer.

## Where to build (GPU)

bge-m3 (560M parameters) is large: on a CPU the catalog takes about an hour, and
an entry GPU without tensor cores (a GTX 1650, say) takes hours. The recommended
build machine is a **free Google Colab T4**, which embeds the full ~24.7k catalog
in about 3 minutes: follow [TRAINING-COLAB.md](TRAINING-COLAB.md), also the
runbook for any later retrain. A local GPU works but is slow without tensor
cores. If you do build locally, the machine needs the CUDA build of torch (a
plain `pip install torch` is often CPU-only on Windows):

```bash
pip install torch --index-url https://download.pytorch.org/whl/cu124   # cu121 if cu124 fails
python -c "import torch; print(torch.cuda.is_available(), torch.cuda.get_device_name(0))"
```

If it prints `False`, torch is the CPU build (reinstall as above, adding
`--force-reinstall`) or the NVIDIA driver is too old for the wheel. With `True`,
`SEARCH_EMBED_DEVICE=auto` picks `cuda`, fp16 is on for CUDA, and
the run prints `Embedder: BAAI/bge-m3 on cuda, fp16=on, batch_size=16` followed
by a progress bar. Lower `SEARCH_EMBED_BATCH_SIZE` (8, 4) on an out-of-memory
error; force `SEARCH_EMBED_DEVICE=cpu` or `SEARCH_EMBED_FP16=off` to compare.
Asking for `cuda` when torch has none is an error, not a silent CPU run.

fp16 only affects the forward pass: the vectors are cast back to float32 and
L2-normalized exactly as before, and the model, revision, pooling and prefixes
(contract 2) are unchanged. Half precision perturbs each unit vector by about
1e-3; the bound is unit-tested on random vectors, but compare real results (the
eval harness, the test page) after the first GPU build.

## Normalization and derived fields

Normalization rules, the parity contract and `normalization_version` are in
[ARCHITECTURE.md](ARCHITECTURE.md#the-three-contracts). Two rule-adjacent
features:

<a id="roman-numerals"></a>**Roman numerals (M15, normalization version 3).**
Product names mix "GTA V" and "GTA 5", "Diablo IV" and "Diablo 4". The
canonical normalizer (both `normalize.py` and `Normalizer.php`, parity-tested)
maps a **standalone** Roman numeral `ii`..`x` to its digits, so either form
matches the other: `GTA VI` → `gta 6`, `Sony A7 IV` → `sony a7 4`. It is
deliberately narrow: only a whole token (no letter or digit on either side,
so `xbox`, `vivo`, `mix`, `x2`, `markiv` are untouched), never `i` (the
English word), and never right after a number and a space, where `x` / `v`
are a dimension or a unit (`1920 x 1080`, `12 V` stay as they are). The SKU
canonicalization skips this rule (`X 12` stays the code `x12`). Known trade-off:
a standalone `x` or `v` that is really a letter becomes a number on both the
product and the query side (`Xbox Series X` → `xbox series 10`), which still
matches itself. Needs a rebuild + reload (see [upgrading](DEPLOY.md#upgrading-to-m15)).

<a id="collapsed-names"></a>**Collapsed names (M23).** `farcry` and `far cry`,
`dualsense` and `dual sense`, `پلی استیشن` and `پلی‌استیشن` are one name, but
token matching splits them. `normalized_collapsed` holds the title, brand and
tags with every non-letter / non-digit removed (`Normalizer::collapse`, parity
tested; the normalized text itself, and so `normalization_version`, is
unchanged), and the query with its spaces removed is substring-matched against
it, then verified to start at a word of the spaced fields (so `far cry` does not
match `sofar crystal`). A hit is a name match scored `search.collapse_weight`
(default 9, just under `title_weight`): in the title band when the name is in the
title, in the tag / brand band otherwise. It is an **additional candidate
source**: a product the token match already found keeps its own hit, and the
scan is skipped when the page is already full of title hits that outrank it (a
broad word). Collapsed queries shorter than `search.collapse_min_length` (default
5) characters are not tried, `search.collapse_weight=0` turns it off. **Needs a
rebuild + reload** (new column `normalized_collapsed VARCHAR(700)` and covering
index `idx_collapsed_scan`); until then the live table has no column and `/search`
runs without it.

## Searched fields

<a id="specs-field"></a>**Specs field (M13).** Product attributes and the
`feature` list are high-signal text that shoppers search for ("ASUS ROG",
"540 هرتز"). `build.py` composes them into `normalized_specs` (FULLTEXT-indexed
with the title and description): the attribute `name: value` pairs, then every
`title` in the PHP-serialized `feature` column, joined with ` | ` and
normalized like every other column. The description length cap does **not**
apply to specs. `feature` is parsed by a real PHP unserializer
(`phpserialize`) over the UTF-8 bytes, because PHP counts string lengths in
bytes and Persian characters take two; a malformed value (wrong lengths,
truncated, trailing data) is skipped, and the build prints a warning naming the
product ids. Each query token not in the title but in the specs earns
`search.spec_weight` (default 6; title 10, description 1): `score =
(title_weight × title hits + spec_weight × spec hits + desc_weight × other
hits) / tokens`. Every spec match ranks above every description-only match and
below every title match in keyword-only results, whatever the weights; in hybrid
results this keyword score is one input of the blended relevance (M20, below).
The weight is a starting point and needs tuning on the real catalog. Needs a
rebuild + reload (new column and FULLTEXT index); until then the live table has
no specs and search runs on title and description as before.

<a id="tags-brand-category"></a>**Tags, brand and category (M21).** Three more
searched fields, wired through every layer rather than added as a lone column.

*Where tags come from.* The store's custom tables, not
`oc_product_description.tag`: `oc_tag(tag_id, name, meta_description,
meta_keyword)` and the join `oc_product_tag(product_id, tag_id)`. The export
adds one correlated-subquery column, `tags` (the distinct `oc_tag.name` values
of the product joined with a space, see the export section below). Tags are often franchise
or alternate product names ("Assassins Creed Origins", "No Mans Sky"), so they
are a high-value relevance signal, but the table is noisy (duplicate names,
test rows such as "test"). `build.py` cleans at build time: trims, collapses
whitespace, drops empties and 1-character tokens (a lone digit stays: "Fallout
4"). Because the export joins names with a space, tag boundaries are gone, so
the cleaner does not de-duplicate tokens (that would break the adjacent words
of a multi-word tag); the export's `DISTINCT` already removes repeated names.

*What changed:*

| layer | change |
| --- | --- |
| schema | `normalized_tags`, `normalized_brand`, `normalized_category` columns, in the FULLTEXT index `(title, tags, brand, category, specs, desc)` |
| `normalize.py` / `Normalizer.php` | tags use the **same** rules (shared fixture cases `tags:*`); `normalization_version` is **not** bumped (no rule changed) |
| `build.py` | normalizes tags, brand and category into the load file; the embedding passage is `title tags brand category model features attributes desc` (tags early) |
| `keyword.py` | tag words feed the `spellcheck.txt` dictionary, so "did you mean" learns franchise names (synonyms stay model/category based: tags carry no language pairing) |
| `Keyword.php` | each query token not in the title but in tags / brand / category / specs earns that field's weight: `search.tag_weight` 8 (just below the title's 10), `search.brand_weight` 7, `search.spec_weight` 6, `search.category_weight` 5, description 1; a match in any of them (none in the title) ranks above every description-only row. A hit with every term in title **or tags** is a *name* match (`name_all`), solid for the hybrid floor |
| `Ranker.php` | three small boosts after the floor, below |

Needs a rebuild + reload (new columns and index); until then the live table has
none of them and `/search` runs as before (the keyword tier and the signals
query fall back on the missing columns). Brand and category match their whole
exported name, so a category exported as "A / B" (several categories joined)
matches only when all its words are in the query. All weights are starting
points that need tuning on the real catalog.

**Description index cap and gate (M11).** Only the first
`SEARCH_DESC_INDEX_CHARS` (default 800 since M15, 400 before; or
`release.py --desc-index-chars N`; cut back to a word boundary) characters
of each cleaned description go into `normalized_desc`, the FULLTEXT column; the
full description is still stored in `description` for display. Deep spec text
("ball bearing" in a case fan's specs) therefore no longer matches, and the
FULLTEXT index over long HTML-derived descriptions shrinks. This is a
**build-time** setting: rebuild the bundle and reload for it to take effect.
Since M20 there is no description-only gate: a description-only hit with no
semantic support falls below the blended relevance floor (below). M15 raised the
default from 400 to 800: attributes and feature titles are now indexed in full
as specs. Keyword-only requests (no VPS answer) see more description-only
matches at the bottom of the list.

## Exporting from OpenCart (`db_export.py`)

**1. Export from OpenCart 2.0.3.1** to `build.py`'s input columns, straight
from the database with the repo's export tool (recommended):

```bash
pip install "pymysql<1.1"                # once, on the machine that reaches the DB
cp .env.example .env                      # then fill the OC_DB_* lines (.env is gitignored)
python pipeline/db_export.py --out export.csv
# Exporting shop on 127.0.0.1:3306 to export.csv ...
#   2000 rows... (progress)
# Wrote 24700 rows to export.csv
```

**Python 3.6 constraint.** `db_export.py` runs on the cPanel server, beside the
OpenCart database (it is not reachable from the GPU machine), and that host has
Python 3.6.8. The file is therefore deliberately 3.6-compatible: no `from
__future__ import annotations`, `list[str]` / `X | None` annotations or other
3.7+ features, and `pymysql<1.1` (1.1 dropped 3.6). Keep it that way; a test
(`test_db_export_py36.py`, using `vermin` and, when present, `python3.6`)
fails otherwise. Copy the resulting `export.csv` to the machine that builds the release (Colab: [TRAINING-COLAB.md](TRAINING-COLAB.md));
the rest of the pipeline needs modern Python (3.11+).

`OC_DB_HOST`, `OC_DB_PORT`, `OC_DB_USER`, `OC_DB_PASSWORD` and `OC_DB_NAME` come
from the environment (or `.env`); `--host/--port/--user/--name/--prefix/--language-id`
override them, and the password is never taken from the command line. It streams
the result with a server-side cursor, runs `SET SESSION max_statement_time = 0`
(MariaDB's statement timeout; `max_execution_time` is MySQL-only and fails on
MariaDB) and `group_concat_max_len = 1000000`, and writes RFC 4180 UTF-8 CSV with
Python's `csv` module, the same library `build.py` reads it with, so every
field round-trips. `build.py` also warns when a CSV parses into far fewer rows
than it has id-led lines.

The query it runs is the one below, for **this shop** (not stock OpenCart): only
Persian is installed (`language_id = 2`, no English language row), and the
secondary product name `title_en` is `oc_product_description.meta_title`, which
the shop repurposes. Adjust the `oc_` prefix (`OC_DB_PREFIX`) or language id
(`OC_LANGUAGE_ID`) if yours differ; look the id up with
`SELECT language_id, code FROM oc_language;`. The same SQL, for reference or for
the phpMyAdmin fallback:

```sql
SET SESSION group_concat_max_len = 1000000;   -- default 1024 bytes truncates attributes
SET @fa := 2;   -- the installed (Persian) language_id

SELECT
    p.product_id                                              AS id,
    COALESCE(d.name, '')                                      AS title_fa,
    COALESCE(d.meta_title, '')                                AS title_en,
    COALESCE(d.description, '')                               AS `desc`,
    COALESCE(m.name, '')                                      AS brand,
    COALESCE((
        SELECT LEFT(GROUP_CONCAT(DISTINCT c.name
                                 ORDER BY pc.category_id SEPARATOR ' / '), 255)
        FROM oc_product_to_category pc
        JOIN oc_category_description c
          ON c.category_id = pc.category_id AND c.language_id = @fa
        WHERE pc.product_id = p.product_id
    ), '')                                                    AS category,
    COALESCE(p.model, '')                                     AS model,
    COALESCE(p.sku, '')                                       AS sku,
    p.price                                                   AS price,
    p.quantity                                                AS stock,
    CONCAT('index.php?route=product/product&product_id=', p.product_id) AS url,
    COALESCE(p.image, '')                                     AS image,
    p.viewed                                                  AS popularity,
    COALESCE((
        SELECT GROUP_CONCAT(CONCAT_WS(': ', ad.name, pa.text) SEPARATOR ' | ')
        FROM oc_product_attribute pa
        JOIN oc_attribute_description ad
          ON ad.attribute_id = pa.attribute_id AND ad.language_id = @fa
        WHERE pa.product_id = p.product_id AND pa.language_id = @fa
    ), '')                                                    AS attributes,
    COALESCE(p.feature, '')                                   AS feature,
    COALESCE((
        SELECT GROUP_CONCAT(DISTINCT t.name SEPARATOR ' ')
        FROM oc_product_tag pt
        JOIN oc_tag t ON t.tag_id = pt.tag_id
        WHERE pt.product_id = p.product_id
    ), '')                                                    AS tags
FROM oc_product p
JOIN oc_product_to_store ps ON ps.product_id = p.product_id AND ps.store_id = 0
LEFT JOIN oc_product_description d
       ON d.product_id = p.product_id AND d.language_id = @fa
LEFT JOIN oc_manufacturer m ON m.manufacturer_id = p.manufacturer_id
WHERE p.status = 1
  AND p.accept_status = '0'
ORDER BY p.product_id;
```

Notes on the query:
- Only enabled products (`status = 1`) in the default store **that are
  customer-facing (`accept_status = '0'`)** are exported. `accept_status` is a
  column this shop added to `oc_product`; it is not in stock OpenCart 2.0.3.1.
  Products with any other value (pending review, rejected) never reach search.
  Disabled or non-accepted products drop out of search at the next reload.
  `build.py` applies no filter of its own: it indexes exactly the rows it is
  given, so the filter lives in this query.
- `sku` is OpenCart's `oc_product.sku`. Empty is fine; those products just
  have no SKU match. See [SKU search](SEARCH-BEHAVIOR.md#sku-search).
- `title_en` is `meta_title` (this shop's secondary name) and `desc`,
  `category` and `attributes` are Persian only; there is no English language row.
  `category` is every category the product is in, capped at 255 characters to fit
  the `products.category` column.
- `COALESCE` keeps NULLs out of the export. phpMyAdmin writes a SQL NULL as the
  literal text `NULL` in CSV, which would otherwise be indexed as a word.
- `popularity` uses `viewed`. Replace it with a sales count if you have a
  better signal.
- `attributes` is every attribute of the product as `name: value`, joined with
  ` | `, with the names in Persian (`@fa`, language_id 2 here). `oc_attribute_description`
  is joined on the Persian name only; `pa.text` is whatever value is stored for
  the product. `GROUP_CONCAT` stops at `group_concat_max_len` bytes (1024 by
  default), which is why the first line raises it (`db_export.py` does this
  itself); in phpMyAdmin run it together with the `SELECT`, like the `@fa` line. If many `attributes` values in
  `export.csv` end abruptly at about 1024 bytes, the `SET` was not applied.
- `feature` is this shop's `oc_product.feature` column (not in stock OpenCart),
  exported untouched: `build.py` unserializes it (see [Specs](#specs-field)).
  Do not edit or re-encode it; PHP's byte counts must stay valid.
- `tags` (M21) is the product's tag names from this shop's custom tables:
  `oc_tag.name` through `oc_product_tag`, a correlated subquery so it cannot
  multiply rows with the `attributes` subquery. It needs the `SET SESSION
  group_concat_max_len` line like `attributes`. The `COALESCE` keeps products
  without tags out of phpMyAdmin's literal `NULL` (`build.py` also treats a
  bare `NULL` as empty). `oc_tag` is language-agnostic and noisy (about 2,200
  rows, duplicate names, test rows); the cleaning is described under
  [Tags, brand and category](#tags-brand-category). An older export without the
  column still builds (no tags).
- HTML escaping (`&lt;p&gt;`, `&amp;quot;`) and tags are left as stored.
  `build.py` decodes and strips them.

**Fallback: phpMyAdmin CSV.** Only if `db_export.py` cannot reach the database:
run the query above in phpMyAdmin (SQL tab), then **Export** under "Query
results operations", format CSV, tick **"Put columns names in the first row"**,
keep the defaults (`"` enclosure, `"` escape) and save it as `export.csv`, UTF-8.
**Warning:** `feature` holds a serialized value with raw quotes next to
multi-line HTML descriptions, and phpMyAdmin's CSV quoting can desync on it
(24,700 database rows were parsed as about 7,000 on this catalog). Compare the
row count `build.py` reports with `SELECT COUNT(*)` and treat a gap, or its
low-row-count warning, as a corrupt file. Use CSV, not phpMyAdmin's SQL export:
`build.py`'s SQL reader understands `''` quoting, not the backslash escapes
(`\'`) that phpMyAdmin and mysqldump write.
