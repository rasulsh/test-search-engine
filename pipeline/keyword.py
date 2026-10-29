"""Build keyword-tier artifacts from the catalog: a spellcheck dictionary, a
synonym map, and the keyboard-layout map. Produced offline; consumed by the
server for "did you mean", query expansion, and keyboard-layout tolerance.

Nothing here needs a GPU or model download.
"""

from __future__ import annotations

import json
from collections import Counter
from collections.abc import Iterable
from pathlib import Path
from typing import Any

from normalize import normalize

# Fields that feed the "did you mean" dictionary: the product names shoppers
# type. Descriptions are excluded (rare incidental words won corrections); so are
# categories and model codes, whose generic words ("کالا") and near-miss codes
# ("mwe") were offered as corrections for unrelated typos. Tags (M21) are
# franchise / alternate product names, so the suggester learns them too.
SPELLCHECK_FIELDS = ("title", "brand", "tags")

# Each token of an owner alias counts as at least this many products: the owner
# wrote it as a name shoppers use, whether or not a title spells it that way. The
# server applies it (Speller::ALIAS_TOKEN_COUNT) to the live alias file; keep the two equal.
ALIAS_TOKEN_COUNT = 5

# Persian (standard) keyboard layout: the Persian letter produced by each US-QWERTY
# key. Used to recover queries typed with the wrong keyboard layout.
PERSIAN_KEYBOARD: dict[str, str] = {
    "q": "ض", "w": "ص", "e": "ث", "r": "ق", "t": "ف", "y": "غ", "u": "ع",
    "i": "ه", "o": "خ", "p": "ح", "a": "ش", "s": "س", "d": "ی", "f": "ب",
    "g": "ل", "h": "ا", "j": "ت", "k": "ن", "l": "م", "z": "ظ", "x": "ط",
    "c": "ز", "v": "ر", "b": "ذ", "n": "د", "m": "ئ",
}


def tokenize(normalized_text: str) -> list[str]:
    """Split normalized text into tokens on non-alphanumeric boundaries.

    Mirrors the server tokenizer (Keyword.php) so both agree on token edges.
    """
    tokens: list[str] = []
    current: list[str] = []
    for ch in normalized_text:
        if ch.isalnum():
            current.append(ch)
        elif current:
            tokens.append("".join(current))
            current = []
    if current:
        tokens.append("".join(current))
    return tokens


def build_spellcheck(
    rows: Iterable[dict[str, Any]],
    min_length: int = 2,
    min_count: int = 1,
) -> list[tuple[str, int]]:
    """Return (token, product frequency) pairs, most frequent first.

    Frequency is the number of products whose high-signal fields contain the
    token (once per product), which is what the server's minimum-frequency gate
    compares against. Tokens are normalized, so the dictionary matches
    normalized query tokens.
    """
    counts: Counter[str] = Counter()
    for row in rows:
        tokens: set[str] = set()
        for field in SPELLCHECK_FIELDS:
            tokens.update(tokenize(normalize(str(row.get(field) or ""))))
        counts.update(token for token in tokens if len(token) >= min_length)

    return sorted(
        ((tok, n) for tok, n in counts.items() if n >= min_count),
        key=lambda item: (-item[1], item[0]),
    )


def build_synonyms(rows: Iterable[dict[str, Any]]) -> list[list[str]]:
    """Derive synonym groups from the catalog structure.

    Products that share a `model` but carry different-language `category` labels
    make those labels synonyms (e.g. "mobile phone" == "گوشی موبایل"). Uses
    union-find over categories linked by a shared model. Returns groups with more
    than one member, each sorted, groups sorted for determinism.
    """
    parent: dict[str, str] = {}

    def find(x: str) -> str:
        parent.setdefault(x, x)
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x

    def union(a: str, b: str) -> None:
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[min(ra, rb)] = max(ra, rb)
            parent[max(ra, rb)] = min(ra, rb)

    model_to_categories: dict[str, set[str]] = {}
    for row in rows:
        model = normalize(str(row.get("model", "")))
        category = normalize(str(row.get("category", "")))
        if not model or not category:
            continue
        model_to_categories.setdefault(model, set()).add(category)

    for categories in model_to_categories.values():
        members = sorted(categories)
        for other in members[1:]:
            union(members[0], other)

    groups: dict[str, set[str]] = {}
    for category in parent:
        groups.setdefault(find(category), set()).add(category)

    return sorted(
        sorted(members) for members in groups.values() if len(members) > 1
    )


def load_aliases(path: str | Path) -> list[list[str]]:
    """Read the shop owner's alias file (a JSON list of groups, each a list of
    equivalent product-name forms) and return its groups normalized for the
    bundle. A missing file means no aliases; malformed JSON or shape raises
    ValueError so a typo fails the build instead of dropping every alias.
    Terms that normalize to nothing and duplicates are dropped, and so is a
    group left with fewer than two terms."""
    path = Path(path)
    if not path.is_file():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"{path}: invalid JSON: {exc}") from exc
    if not isinstance(data, list):
        raise ValueError(f"{path}: expected a list of groups")

    groups: list[list[str]] = []
    for number, group in enumerate(data, start=1):
        if not isinstance(group, list) or not all(isinstance(t, str) for t in group):
            raise ValueError(f"{path}: group {number} must be a list of strings")
        terms = list(dict.fromkeys(" ".join(tokenize(normalize(t))) for t in group))
        terms = [t for t in terms if t]
        if len(terms) > 1:
            groups.append(terms)
    return groups


def load_intent_map(path: str | Path) -> dict[str, dict[str, list[str]]]:
    """Read the shop owner's intent map (docs/SEARCH-BEHAVIOR.md, intent map): a
    JSON object from an intent term or phrase to {"category": [...], "tags": [...]}.
    A missing file means no intents. Keys must already be in normalized form (the
    server looks them up in the normalized query, so a raw key would never match
    and the entry would silently do nothing): a key that changes under
    normalization, malformed JSON or a bad shape raises ValueError and fails the
    build. Values are normalized the same way; empty ones are dropped."""
    path = Path(path)
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"{path}: invalid JSON: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError(f"{path}: expected an object of intent terms")

    intents: dict[str, dict[str, list[str]]] = {}
    for key, entry in data.items():
        canonical = " ".join(tokenize(normalize(key)))
        if not canonical or key != canonical:
            raise ValueError(
                f"{path}: key {key!r} is not in normalized form (use {canonical!r})"
            )
        if not isinstance(entry, dict) or not set(entry) <= {"category", "tags"} or not entry:
            raise ValueError(f"{path}: {key!r} must be an object with category and/or tags")
        resolved: dict[str, list[str]] = {}
        for field in ("category", "tags"):
            values = entry.get(field, [])
            if not isinstance(values, list) or not all(isinstance(v, str) for v in values):
                raise ValueError(f"{path}: {key!r}.{field} must be a list of strings")
            names = list(dict.fromkeys(" ".join(tokenize(normalize(v))) for v in values))
            names = [n for n in names if n]
            if names:
                resolved[field] = names
        if not resolved:
            raise ValueError(f"{path}: {key!r} maps to no category or tag")
        intents[key] = resolved
    return intents


# token_df.json / entities.json format versions; the server (TokenStats::VERSION,
# QueryEntities::VERSION) rejects a file of another version, and meta.json records both.
TOKEN_DF_VERSION = 1
ENTITIES_VERSION = 1

# A token seen in fewer products is left out of token_df.json: an absent token weighs as the rarest.
TOKEN_DF_MIN_PRODUCTS = 2

# Gaming platforms a query can name: key -> spellings (normalized here). The server adds the
# owner's alias groups of every spelling at query time, so these only need the canonical names.
# Short on purpose.
PLATFORM_SEEDS: dict[str, list[str]] = {
    "ps5": ["ps5", "playstation 5", "پلی استیشن 5"],
    "ps4": ["ps4", "playstation 4", "پلی استیشن 4"],
    "ps3": ["ps3", "playstation 3", "پلی استیشن 3"],
    "xbox_one": ["xbox one", "ایکس باکس وان"],
    "xbox_series": ["xbox series", "ایکس باکس سری"],
    "switch": ["switch", "nintendo switch", "نینتندو سوییچ"],
    "pc": ["pc"],
}


def build_token_df(rows: Iterable[dict[str, Any]]) -> dict[str, Any]:
    """Per-token document frequency over the normalized titles and tags: the number of products
    containing the token (once per product). The server weights a query token by its inverse
    document frequency and treats a token held by more than search.generic_df_ratio of the
    catalog as generic."""
    counts: Counter[str] = Counter()
    total = 0
    for row in rows:
        total += 1
        counts.update(
            set(tokenize(str(row.get("normalized_title") or "")))
            | set(tokenize(str(row.get("normalized_tags") or "")))
        )
    return {
        "version": TOKEN_DF_VERSION,
        "n": total,
        "df": {t: n for t, n in sorted(counts.items()) if n >= TOKEN_DF_MIN_PRODUCTS},
    }


def build_entities(rows: Iterable[dict[str, Any]]) -> dict[str, Any]:
    """The brands the catalog carries (normalized `brand` values, with their product counts) and
    the platform seeds. Owner aliases ("شیامی" = "xiaomi") are applied by the server at query
    time: they live in server/storage/aliases.json, not in the bundle (M41). No used / new
    condition is derived: nothing in the export reliably marks it."""
    counts: Counter[str] = Counter()
    for row in rows:
        brand = " ".join(tokenize(normalize(str(row.get("brand") or ""))))
        if len(brand) >= 2 and not brand.isdigit():
            counts[brand] += 1
    platforms = {
        key: list(dict.fromkeys(" ".join(tokenize(normalize(term))) for term in terms))
        for key, terms in PLATFORM_SEEDS.items()
    }
    return {
        "version": ENTITIES_VERSION,
        "brands": [
            {"name": name, "count": n}
            for name, n in sorted(counts.items(), key=lambda item: (-item[1], item[0]))
        ],
        "platforms": platforms,
    }


def build_keymap() -> dict[str, dict[str, str]]:
    """Return the bidirectional keyboard-layout map for the bundle."""
    en_to_fa = dict(PERSIAN_KEYBOARD)
    fa_to_en = {fa: en for en, fa in PERSIAN_KEYBOARD.items()}
    return {"en_to_fa": en_to_fa, "fa_to_en": fa_to_en}


def spellcheck_lines(entries: Iterable[tuple[str, int]]) -> list[str]:
    """Serialize spellcheck entries as "token<TAB>count" lines."""
    return [f"{token}\t{count}" for token, count in entries]
