"""Canonical Persian/English text normalization for the search pipeline.

This module MUST stay byte-for-byte identical in output to
server/src/Normalizer.php (contract 1). Both are driven by the same rules and
verified against fixtures/normalization_cases.json. Bump NORMALIZATION_VERSION
in BOTH files whenever the rules change; build.py stamps it into meta.json and
the server's config defaults to Normalizer::VERSION, so no config edit follows.

Rules (applied in this order):
  1. ZWNJ (U+200C, the Persian half-space) becomes a space; other zero-width
     characters and bidi / format marks (U+200E, U+200F, U+202A..U+202E,
     U+2066..U+2069) are removed.
  2. Remove Arabic diacritics (harakat) and tatweel.
  3. Unify Arabic letters to Persian (yeh, kaf, hamza forms, teh marbuta).
  4. Fold Persian and Arabic-Indic digits to ASCII 0-9.
  5. Lowercase (affects Latin only).
  6. Canonicalize model names: drop -, _, ., / between ASCII alphanumerics.
  7. Collapse whitespace to single ASCII spaces and trim.
  8. Roman numerals ii..ix become digits ("gta vi" -> "gta 6"): only a whole
     token, and only right after a token holding a letter ("gta vi", "a7 iv";
     not "12 vi", not at the start). "v" and "x" are never mapped (a variant
     letter, a dimension, a unit); "i" is never mapped (the word).

collapse() is a derived form, not a rule of normalize(): the normalized text with
every non-letter/non-digit removed ("far cry" -> "farcry"). normalize() output,
and so NORMALIZATION_VERSION, is unchanged by it.
"""

from __future__ import annotations

import re

NORMALIZATION_VERSION = 4

_ZERO_WIDTH = [chr(0x200B), chr(0x200D), chr(0xFEFF), chr(0x200E), chr(0x200F)] + [
    chr(cp) for cp in (*range(0x202A, 0x202F), *range(0x2066, 0x206A))
]

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

# 7. Whitespace characters folded to a plain ASCII space, ZWNJ (the Persian
#    half-space) included: it separates the two words of a compound.
_WHITESPACE = [
    chr(0x200C),
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
#    [\p{L}\p{N}]. Only ii..ix: "v" and "x" are never numerals.
_ROMAN = {"ii": "2", "iii": "3", "iv": "4", "vi": "6", "vii": "7", "viii": "8", "ix": "9"}
_ROMAN_TOKEN = re.compile(r"^([\W_]*)(viii|vii|iii|iv|vi|ix|ii)([\W_]*)$")


def normalize(text: str | None) -> str:
    """Return the canonical normalized form of ``text``."""
    tokens = _canonical(text).split(" ")
    after_word = False
    for i, token in enumerate(tokens):
        match = _ROMAN_TOKEN.match(token) if after_word else None
        if match:
            tokens[i] = match.group(1) + _ROMAN[match.group(2)] + match.group(3)
        # The original token decides, so "gta vi ii" maps both.
        after_word = any(ch.isalpha() for ch in token)

    return " ".join(tokens)


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


def collapse(text: str | None) -> str:
    """Space-collapsed form: the normalized text with every non-letter/non-digit
    removed, so "Far Cry 5", "far-cry 5" and "farcry5" are one string. Searches
    spelling a name joined or separated meet through it. Mirrors
    Normalizer::collapse (letters/digits as in the shared tokenizer)."""
    return "".join(ch for ch in normalize(text) if ch.isalnum())
