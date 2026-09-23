"""Canonical Persian/English text normalization for the search pipeline.

This module MUST stay byte-for-byte identical in output to
server/src/Normalizer.php (contract 1). Both are driven by the same rules and
verified against fixtures/normalization_cases.json. Bump NORMALIZATION_VERSION
in BOTH files whenever the rules change.

Rules (applied in this order):
  1. Remove zero-width characters (incl. ZWNJ / half-space).
  2. Remove Arabic diacritics (harakat) and tatweel.
  3. Unify Arabic letters to Persian (yeh, kaf, hamza forms, teh marbuta).
  4. Fold Persian and Arabic-Indic digits to ASCII 0-9.
  5. Lowercase (affects Latin only).
  6. Canonicalize model names: drop -, _, ., / between ASCII alphanumerics.
  7. Collapse whitespace to single ASCII spaces and trim.
  8. Standalone Roman numerals ii..x become digits ("gta vi" -> "gta 6"): only a
     whole token (no letter or digit on either side), and not right after a
     number token and a space, where "x" / "v" are a dimension or a unit
     ("2 x 4", "12 v"; "a7 iv" still maps). "i" is never mapped (the word).
"""

from __future__ import annotations

import re

NORMALIZATION_VERSION = 3

# 1. Zero-width characters, including ZWNJ (U+200C, the Persian half-space).
_ZERO_WIDTH = ["​", "‌", "‍", "﻿"]

# 2. Arabic diacritics (harakat U+064B..U+0652, maddah U+0653, hamza above
#    U+0654), superscript alef (U+0670), and tatweel/kashida (U+0640).
_DIACRITICS = [chr(cp) for cp in range(0x064B, 0x0655)] + ["ٰ", "ـ"]

# 3. Arabic -> Persian letter unification (alef forms folded to bare alef).
_LETTER_MAP = {
    "ي": "ی",  # Arabic yeh -> Persian yeh
    "ى": "ی",  # alef maksura -> Persian yeh
    "ئ": "ی",  # yeh with hamza -> Persian yeh
    "ك": "ک",  # Arabic kaf -> Persian keheh
    "ة": "ه",  # teh marbuta -> heh
    "ۀ": "ه",  # heh with yeh above -> heh
    "آ": "ا",  # alef with madda -> alef
    "أ": "ا",  # alef with hamza above -> alef
    "إ": "ا",  # alef with hamza below -> alef
    "ٱ": "ا",  # alef wasla -> alef
    "ؤ": "و",  # waw with hamza -> waw
}

# 4. Digit folding.
_DIGIT_MAP: dict[str, str] = {}
for _i in range(10):
    _DIGIT_MAP[chr(0x06F0 + _i)] = str(_i)  # Persian digits
    _DIGIT_MAP[chr(0x0660 + _i)] = str(_i)  # Arabic-Indic digits

# 7. Whitespace characters folded to a plain ASCII space.
_WHITESPACE = [
    "\t", "\n", "\x0b", "\x0c", "\r", "\xa0", " ",
    " ", " ", " ", " ", " ", " ",
    " ", " ", " ", " ", " ",
    " ", " ", " ", " ", "　",
]

# Single translation table for the per-character rules (1-4, 7).
_TRANSLATION: dict[int, str | None] = {}
for _ch in _ZERO_WIDTH + _DIACRITICS:
    _TRANSLATION[ord(_ch)] = None
for _src, _dst in _LETTER_MAP.items():
    _TRANSLATION[ord(_src)] = _dst
for _src, _dst in _DIGIT_MAP.items():
    _TRANSLATION[ord(_src)] = _dst
for _ch in _WHITESPACE:
    _TRANSLATION[ord(_ch)] = " "

_MODEL_SEPARATORS = re.compile(r"(?<=[0-9a-z])[-_./]+(?=[0-9a-z])")

# 8. [^\W_] is a letter or digit (str.isalnum), the same class as the server's
#    [\p{L}\p{N}]; longest numerals first in the alternation. A preceding
#    number token is matched (group 1) rather than looked behind for, as a
#    lookbehind cannot be variable-width; such a match is left unchanged.
_ROMAN = {"ii": "2", "iii": "3", "iv": "4", "v": "5", "vi": "6",
          "vii": "7", "viii": "8", "ix": "9", "x": "10"}
_ROMAN_NUMERAL = re.compile(
    r"(?<![^\W_])([0-9]+ )?(viii|vii|iii|ix|iv|vi|ii|v|x)(?![^\W_])"
)


def _roman(match: re.Match[str]) -> str:
    return match.group(0) if match.group(1) else _ROMAN[match.group(2)]


def normalize(text: str | None) -> str:
    """Return the canonical normalized form of ``text``."""
    return _ROMAN_NUMERAL.sub(_roman, _canonical(text))


def _canonical(text: str | None) -> str:
    """Rules 1-7: everything but the Roman numerals, which a code never holds."""
    if not text:
        return ""

    text = text.translate(_TRANSLATION)
    text = text.lower()
    text = _MODEL_SEPARATORS.sub("", text)
    text = re.sub(r" +", " ", text)

    return text.strip()


def normalize_sku(sku: str | None) -> str:
    """Canonical SKU: normalized text (without the Roman-numeral rule: "X 12"
    is a code, not "10 12") with every non-letter/non-digit removed, so
    "AB-12 34", "ab.1234" and "AB1234" are one code. Mirrors
    Normalizer::normalizeSku (letters/digits as in the shared tokenizer)."""
    return "".join(ch for ch in _canonical(sku) if ch.isalnum())
