"""Build keyword-tier artifacts from the catalog: a spellcheck dictionary, a
synonym map, and the keyboard-layout map. Produced offline; consumed by the
server for "did you mean", query expansion, and keyboard-layout tolerance.

Nothing here needs a GPU or model download.
"""

from __future__ import annotations

from collections import Counter
from collections.abc import Iterable
from typing import Any

from normalize import normalize

# Text fields that contribute to the keyword dictionaries.
_TEXT_FIELDS = ("title", "description", "brand", "category", "model")

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
    """Return (token, frequency) pairs from the catalog, most frequent first.

    Tokens are normalized, so the dictionary matches normalized query tokens.
    """
    counts: Counter[str] = Counter()
    for row in rows:
        for field in _TEXT_FIELDS:
            for token in tokenize(normalize(str(row.get(field, "")))):
                if len(token) >= min_length:
                    counts[token] += 1

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


def build_keymap() -> dict[str, dict[str, str]]:
    """Return the bidirectional keyboard-layout map for the bundle."""
    en_to_fa = dict(PERSIAN_KEYBOARD)
    fa_to_en = {fa: en for en, fa in PERSIAN_KEYBOARD.items()}
    return {"en_to_fa": en_to_fa, "fa_to_en": fa_to_en}


def spellcheck_lines(entries: Iterable[tuple[str, int]]) -> list[str]:
    """Serialize spellcheck entries as "token<TAB>count" lines."""
    return [f"{token}\t{count}" for token, count in entries]
