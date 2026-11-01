"""M44: token_df.json (query token weights) and entities.json (brand / platform lexicons)."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path

import build
from normalize import normalize

_spec = importlib.util.spec_from_file_location(
    "catalog_keyword", Path(__file__).resolve().parents[1] / "keyword.py"
)
assert _spec is not None and _spec.loader is not None
kw = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(kw)

REPO_ROOT = Path(__file__).resolve().parents[2]


def _rows() -> list[dict]:
    products = [
        {"title": "بازی GTA V", "tags": "gta", "brand": "Rockstar"},
        {"title": "بازی FIFA 24", "tags": "fifa ea", "brand": "EA Sports"},
        {"title": "بازی Red Dead Redemption 2", "tags": "", "brand": "Rockstar"},
        {"title": "کنسول بازی", "tags": "", "brand": ""},
        {"title": "ماوس", "tags": "", "brand": "12345"},
    ]
    return [
        {
            "normalized_title": normalize(p["title"]),
            "normalized_tags": normalize(p["tags"]),
            "brand": p["brand"],
        }
        for p in products
    ]


def test_token_df_counts_products_not_occurrences() -> None:
    data = kw.build_token_df(_rows())

    assert data["version"] == kw.TOKEN_DF_VERSION
    assert data["n"] == 5
    # "gta" is in the title and the tags of one product: counted once; so are tokens seen once.
    assert "gta" not in data["df"]
    assert data["df"]["بازی"] == 4
    assert all(count >= kw.TOKEN_DF_MIN_PRODUCTS for count in data["df"].values())


def test_token_df_keeps_a_token_seen_twice_across_title_and_tags() -> None:
    rows = [
        {"normalized_title": "gta v", "normalized_tags": "gta"},
        {"normalized_title": "x", "normalized_tags": "gta"},
    ]

    assert kw.build_token_df(rows)["df"] == {"gta": 2}


def test_entities_brands_are_normalized_counted_and_digits_only_ones_skipped() -> None:
    data = kw.build_entities(_rows())

    assert data["version"] == kw.ENTITIES_VERSION
    assert data["brands"] == [{"name": "rockstar", "count": 2}, {"name": "ea sports", "count": 1}]


def test_entities_platforms_are_the_normalized_seed_list() -> None:
    data = kw.build_entities([])

    assert set(data["platforms"]) == set(kw.PLATFORM_SEEDS)
    assert "playstation 4" in data["platforms"]["ps4"]
    assert "پلی استیشن 4" in data["platforms"]["ps4"]
    assert len(data["platforms"]) <= 10, "the platform list is a short fixed seed"


def test_the_bundle_ships_both_files_and_versions_them(tmp_path: Path) -> None:
    products = build.read_products(REPO_ROOT / "fixtures" / "products.input.sql")
    config = {
        "embedder": "mock",
        "model": {"normalization_version": 4},
        "build": {"products_table": "products", "desc_char_limit": 300,
                  "desc_index_chars": 400, "max_statement_bytes": 1_000_000},
    }

    meta = build.build_bundle(products, tmp_path, config)

    assert meta["token_df_version"] == kw.TOKEN_DF_VERSION
    assert meta["entities_version"] == kw.ENTITIES_VERSION
    df = json.loads((tmp_path / "token_df.json").read_text(encoding="utf-8"))
    entities = json.loads((tmp_path / "entities.json").read_text(encoding="utf-8"))
    assert df["n"] == meta["count"] == len(products)
    assert entities["version"] == kw.ENTITIES_VERSION
    assert {b["name"] for b in entities["brands"]} >= {"sony"}


def _row(title: str, brand: str) -> dict:
    return {"normalized_title": normalize(title), "normalized_tags": "", "brand": brand}


def _flags(rows: list[dict]) -> dict[str, bool]:
    return {b["name"]: b.get("ambiguous", False) for b in kw.build_entities(rows)["brands"]}


def test_a_seed_colour_or_common_word_brand_is_ambiguous() -> None:
    flags = _flags([_row("x", "Blue"), _row("y", "Sony"), _row("z", "آبی"), _row("w", "Pro")])

    assert flags == {"blue": True, "sony": False, normalize("آبی"): True, "pro": True}


def test_a_brand_is_ambiguous_when_other_brands_titles_hold_its_phrase_often() -> None:
    # Not a seed word: only the other-brand count (5 >= max(5, 2 * 1)) flags it.
    five = [_row(f"Astro Bot {i}", "Sony") for i in range(5)]
    assert _flags([_row("Astro headset", "Astro"), *five])["astro"]
    # 4 other-brand products are below the floor of 5.
    assert not _flags([_row("Astro headset", "Astro"), *five[:4]])["astro"]
    # The threshold scales with the brand's own count: 2 own products need 5 others, 3 need 6.
    own = [_row(f"Astro headset {i}", "Astro") for i in range(3)]
    assert not _flags([*own, *[_row(f"Astro Bot {i}", "Sony") for i in range(5)]])["astro"]
    assert _flags([*own, *[_row(f"Astro Bot {i}", "Sony") for i in range(6)]])["astro"]


def test_a_phrase_match_is_whole_words_and_ignores_the_brands_own_products() -> None:
    rows = [_row("Astroboy figure", "Sony")] * 6 + [_row("Astro A50", "Astro")] * 2

    assert not _flags(rows)["astro"], "'astroboy' is not the whole word 'astro'"
    assert not _flags([_row("Dobe stand", "Dobe")] * 9)["dobe"], "own products never count"


def test_ambiguous_key_is_absent_when_false() -> None:
    entries = kw.build_entities([_row("a", "Sony")])["brands"]

    assert entries == [{"name": "sony", "count": 1}]


# --- used condition (entities.json "used") -------------------------------------


def _product_rows(products: list[dict]) -> list[dict]:
    return [build.to_server_row(p) for p in products]


def test_used_ids_come_from_the_name_and_from_meta_title() -> None:
    rows = _product_rows([
        {"id": 7, "title_fa": "کنسول پلی استیشن 4 کارکرده", "title_en": ""},
        # meta_title is exported as title_en
        {"id": 3, "title_fa": "کنسول پلی استیشن 4", "title_en": "PS4 Slim کارکرده"},
        {"id": 5, "title_fa": "کنسول پلی استیشن 4", "title_en": "PS4 Slim"},
        {"id": 9, "title_fa": "بازی کارکردهای جدید", "title_en": ""},  # a different word
    ])

    data = kw.build_entities(rows)

    assert data["version"] == 2, "the version stays 2: `used` is an optional key"
    assert data["used"] == [3, 7]


def test_used_accepts_the_spaced_and_zwnj_spellings() -> None:
    rows = _product_rows([
        {"id": 1, "title_fa": "هدست کار کرده", "title_en": ""},
        {"id": 2, "title_fa": "هدست کار‌کرده", "title_en": ""},
        {"id": 3, "title_fa": "هدست آکبند", "title_en": ""},
    ])

    assert kw.build_entities(rows)["used"] == [1, 2]


def test_used_is_an_empty_list_when_nothing_is_used_and_ignores_rows_without_an_id() -> None:
    rows = _product_rows([{"id": 1, "title_fa": "ماوس", "title_en": ""}])
    assert kw.build_entities(rows)["used"] == []
    assert kw.build_entities([_row("ماوس کارکرده", "")])["used"] == []
