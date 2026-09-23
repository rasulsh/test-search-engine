"""Tests for the keyword-tier builders (spellcheck, synonyms, keymap)."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path

import pytest

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
    assert freq["apple"] == 2  # one count per product (brand + title on row 1)
    # Single-character tokens are excluded by the default min_length.
    assert all(len(token) >= 2 for token, _ in entries)
    # Sorted by descending frequency, then token.
    keys = [(-count, token) for token, count in entries]
    assert keys == sorted(keys)


def test_build_spellcheck_uses_high_signal_fields_only() -> None:
    freq = dict(kw.build_spellcheck(ROWS))

    # Description-only words ("smartphone", "titanium", "هوشمند") never become
    # suggestions; title/brand/category/model words do.
    for description_only in ("smartphone", "titanium", "body", "core", "هوشمند"):
        assert description_only not in freq
    for high_signal in ("iphone", "apple", "mobile", "x1504", "ip15promax", "vivobook"):
        assert high_signal in freq


def test_build_spellcheck_counts_each_product_once() -> None:
    freq = dict(kw.build_spellcheck(ROWS))

    # "laptop" is in row 3's title AND category: one product, frequency 1.
    assert freq["laptop"] == 1
    # "لپ" is in row 4's title and category (and description): still 1.
    assert freq["لپ"] == 1
    # "asus" is the brand of two products.
    assert freq["asus"] == 2


def test_build_synonyms_groups_bilingual_categories() -> None:
    groups = kw.build_synonyms(ROWS)

    assert ["laptop", "لپ تاپ"] in groups
    assert ["mobile phone", "گوشی موبایل"] in groups
    assert all(len(group) > 1 for group in groups)


def test_load_aliases_normalizes_and_drops_empty_groups(tmp_path: Path) -> None:
    path = tmp_path / "aliases.json"
    path.write_text(json.dumps([
        ["GTA", "Grand Theft Auto", "جی تی ای", "gta"],
        ["PS-5", "پلی‌استیشن ۵", "PlayStation V"],
        ["lonely", "---"],
        [],
    ], ensure_ascii=False), encoding="utf-8")

    assert kw.load_aliases(path) == [
        ["gta", "grand theft auto", "جی تی ای"],
        ["ps5", "پلیاستیشن 5", "playstation 5"],
    ]


def test_load_aliases_missing_file_means_none(tmp_path: Path) -> None:
    assert kw.load_aliases(tmp_path / "absent.json") == []


@pytest.mark.parametrize("content", [
    '[["gta", "grand theft auto"],]',
    '{"gta": ["grand theft auto"]}',
    '["gta", "grand theft auto"]',
    '[["gta", 5]]',
])
def test_load_aliases_rejects_malformed_files(tmp_path: Path, content: str) -> None:
    path = tmp_path / "aliases.json"
    path.write_text(content, encoding="utf-8")
    with pytest.raises(ValueError, match="aliases.json"):
        kw.load_aliases(path)


def test_shipped_alias_file_is_valid() -> None:
    groups = kw.load_aliases(Path(config.load()["build"]["aliases_file"]))
    assert ["gta", "grand theft auto", "جی تی ای"] in groups


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


def test_config_default_desc_index_chars(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("SEARCH_DESC_INDEX_CHARS", raising=False)
    assert config.load()["build"]["desc_index_chars"] == 800
    monkeypatch.setenv("SEARCH_DESC_INDEX_CHARS", "400")
    assert config.load()["build"]["desc_index_chars"] == 400
