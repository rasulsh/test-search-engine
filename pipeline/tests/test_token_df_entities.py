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
