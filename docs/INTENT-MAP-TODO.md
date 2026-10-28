# Intent map: candidates for the owner (M44)

`pipeline/intent_map.json` is `{}` on purpose. The intent map ([SEARCH-BEHAVIOR.md](SEARCH-BEHAVIOR.md#intent-map)) maps
a word the shopper types to **category** and / or **tag** names that already exist in the catalog, and the names are
only known from the live export (`pipeline/db_export.py` output), which is not in this repository. Nothing was invented.

Candidate keys, from the real queries that returned nothing or the wrong thing (`بازی تفنگی` returned 0 results):

| key (normalized form) | looks for |
| --- | --- |
| `تفنگی` | shooter games |
| `شوتر` | shooter games |
| `ماشین سواری` | racing games |
| `فوتبال` | football games |
| `دخترانه` | girls' games / accessories |
| `انیمه` | anime-themed products |
| `ترسناک` | horror games |
| `دونفره` | two-player / co-op games |
| `کودک` | children's games |

How to fill it (the format is `{"تفنگی": {"category": ["..."], "tags": ["..."]}}`, keys already normalized, at most a
handful of names per key):

1. In the admin panel's analytics, look at the categories and tags of the products that *should* answer each key (the
   tester page lists a result's id; the category and tag names are in the export's `category` and `tags` columns).
2. Copy the names **exactly** as the export has them; the build normalizes them and `build.py` fails on a key that is not
   in normalized form.
3. Edit `pipeline/intent_map.json`, build, upload, `/reload`.

An intent word is a **boost** on products the keyword and semantic tiers already returned (M34); only a query made of
generic words alone (`بازی`, `بازی خرید`) uses the map to *list* the category's products (M44). `بازی تفنگی` therefore
needs the semantic tier to return the shooter games at all (it returned nothing above `search.semantic_min_score`): the
map then lifts the right category among them. If the semantic tier stays empty for it, add the key as an alias of the
tag the shooters carry instead.
