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
"""

from __future__ import annotations

import re

NORMALIZATION_VERSION = 2

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


def normalize(text: str | None) -> str:
    """Return the canonical normalized form of ``text``."""
    if not text:
        return ""

    text = text.translate(_TRANSLATION)
    text = text.lower()
    text = _MODEL_SEPARATORS.sub("", text)
    text = re.sub(r" +", " ", text)

    return text.strip()
