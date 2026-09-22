"""Tests for the keyword-tier builders (spellcheck, synonyms, keymap)."""

from __future__ import annotations

import importlib.util
from pathlib import Path

import config
from normalize import NORMALIZATION_VERSION

# The module is named keyword.py per the repo layout, which collides with the
# stdlib `keyword` (pytest pre-imports it, so it wins a plain `import keyword`).
# Load the local file directly by path to test the real module.
_spec = importlib.util.spec_from_file_location(
    "catalog_keyword", Path(__file__).resolve().parents[1] / "keyword.py"
)
assert _spec is not None and _spec.loader is not None
kw = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(kw)

# Bilingual rows sharing a model across languages (mirrors the real catalog).
ROWS = [
    {
        "title": "Apple iPhone 15 Pro Max",
        "description": "smartphone titanium body",
        "brand": "Apple",
        "category": "Mobile Phone",
        "model": "IP15PROMAX",
    },
    {
        "title": "گوشی موبایل اپل آیفون ۱۵",
        "description": "گوشی هوشمند اپل",
        "brand": "Apple",
        "category": "گوشی موبایل",
        "model": "IP15PROMAX",
    },
    {
        "title": "ASUS VivoBook Laptop",
        "description": "laptop with core i7",
        "brand": "ASUS",
        "category": "Laptop",
        "model": "X1504",
    },
    {
        "title": "لپ تاپ ایسوس",
        "description": "لپ تاپ ایسوس",
        "brand": "ASUS",
        "category": "لپ تاپ",
        "model": "X1504",
    },
]


def test_tokenize_matches_normalized_boundaries() -> None:
    assert kw.tokenize(kw.normalize("WH-1000XM5 test")) == ["wh1000xm5", "test"]
    assert kw.tokenize(kw.normalize("لپ تاپ")) == ["لپ", "تاپ"]


def test_build_spellcheck_counts_and_sorts() -> None:
    entries = kw.build_spellcheck(ROWS)
    freq = dict(entries)

    assert "iphone" in freq
    assert "laptop" in freq
    assert freq["apple"] >= 2  # brand on two rows plus the English title
    # Single-character tokens are excluded by the default min_length.
    assert all(len(token) >= 2 for token, _ in entries)
    # Sorted by descending frequency, then token.
    keys = [(-count, token) for token, count in entries]
    assert keys == sorted(keys)


def test_build_synonyms_groups_bilingual_categories() -> None:
    groups = kw.build_synonyms(ROWS)

    assert ["laptop", "لپ تاپ"] in groups
    assert ["mobile phone", "گوشی موبایل"] in groups
    assert all(len(group) > 1 for group in groups)


def test_build_keymap_round_trips() -> None:
    keymap = kw.build_keymap()

    assert keymap["en_to_fa"]["a"] == "ش"
    assert keymap["fa_to_en"]["ش"] == "a"
    assert len(keymap["en_to_fa"]) == 26


def test_spellcheck_lines_format() -> None:
    lines = kw.spellcheck_lines([("apple", 3), ("laptop", 1)])
    assert lines == ["apple\t3", "laptop\t1"]


def test_config_defaults_track_normalization_version() -> None:
    cfg = config.load()
    assert cfg["model"]["name"] == "intfloat/multilingual-e5-small"
    assert cfg["model"]["dim"] == 384
    assert cfg["model"]["normalization_version"] == NORMALIZATION_VERSION
    assert cfg["embedder"] == "mock"
