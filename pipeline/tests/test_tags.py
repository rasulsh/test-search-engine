"""M21: store tags (oc_tag via oc_product_tag), brand and category as searchable
fields: cleaned at build time, normalized with the shared rules, embedded early
in the passage and learned by the spellcheck dictionary."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

import build
from normalize import normalize

REPO_ROOT = Path(__file__).resolve().parents[2]
CASES = json.loads(
    (REPO_ROOT / "fixtures" / "normalization_cases.json").read_text(encoding="utf-8")
)


@pytest.mark.parametrize("raw, expected", [
    (None, ""),
    ("", ""),
    ("   ", ""),
    ("  Assassins   Creed\tOrigins\n", "Assassins Creed Origins"),
    # 1-char tokens go, but a lone digit is part of a name ("Fallout 4").
    ("a Fallout 4 - &amp; x", "Fallout 4"),
    ("<b>No</b> Mans Sky", "No Mans Sky"),
    ("&amp;amp;", ""),
    ("NULL", ""),  # phpMyAdmin writes a SQL NULL as this text in CSV
    ("null", ""),
    ("Null Pointer", "Null Pointer"),  # only a bare NULL is empty
])
def test_clean_tags(raw: str | None, expected: str) -> None:
    assert build.clean_tags(raw) == expected


def test_clean_tags_keeps_repeated_words_so_adjacent_tags_stay_phrases() -> None:
    assert build.clean_tags("Assassins Creed Origins Assassins Creed Odyssey") == (
        "Assassins Creed Origins Assassins Creed Odyssey"
    )


def test_server_row_emits_normalized_tags_brand_and_category() -> None:
    row = build.to_server_row({
        "id": "7", "title_fa": "بازی", "title_en": "AC Origins", "brand": "Ubisoft",
        "category": "Games ", "tags": "Assassins  Creed Origins x كامپيوتری",
    })

    assert row["normalized_tags"] == "assassins creed origins کامپیوتری"
    assert row["normalized_brand"] == "ubisoft"
    assert row["normalized_category"] == "games"
    assert "normalized_tags" in build._SERVER_COLUMNS
    # No tags column in the export (an older query): empty, never an error.
    assert build.to_server_row({"id": "8"})["normalized_tags"] == ""


def test_tag_normalization_uses_the_shared_rules() -> None:
    # Parity (contract 1): tags go through the same normalize() as every field,
    # so the PHP suite's identical fixture cases (NormalizerParityTest) cover them.
    cases = [c for c in CASES["cases"] if c["name"].startswith("tags:")]
    assert len(cases) >= 3
    for case in cases:
        assert build.to_server_row({"id": "1", "tags": case["input"]})["normalized_tags"] == (
            normalize(build.clean_tags(case["input"]))
        )
        assert normalize(case["input"]) == case["expected"]


def test_tags_sit_early_in_the_passage_before_specs_and_description() -> None:
    product = {"title_fa": "فا", "title_en": "En", "tags": "Franchise Name", "brand": "Br",
               "category": "Cat", "model": "Mo", "attributes": "k: attrvalue",
               "desc": "description"}
    passage = build.compose_passage(product, desc_char_limit=300)

    words = ("En", "Franchise Name", "Br", "Cat", "Mo", "attrvalue", "description")
    order = [passage.index(w) for w in words]
    assert order == sorted(order)
    # A passage without tags is unchanged by the new field.
    del product["tags"]
    assert "Franchise" not in build.compose_passage(product, desc_char_limit=300)


def test_tags_survive_the_passage_cap_ahead_of_specs() -> None:
    product = {"title_en": "T", "tags": "Franchise", "attributes": "k: " + "v" * 500}
    passage = build.compose_passage(product, desc_char_limit=0, passage_char_limit=40)

    assert "Franchise" in passage and len(passage) <= 40


def test_sql_export_with_tags_column_flows_to_the_load_file(tmp_path: Path) -> None:
    sql = (
        "INSERT INTO p (`id`, `title_fa`, `title_en`, `desc`, `brand`, `category`, `tags`) VALUES\n"
        "  (1, 'بازی', 'AC Origins', 'd', 'Ubisoft', 'Games', 'Assassins Creed Origins test'),\n"
        "  (2, 'x', 'Plain', 'd', '', '', NULL);\n"
    )
    path = tmp_path / "export.sql"
    path.write_text(sql, encoding="utf-8")
    products = build.read_products(path)
    config = {"embedder": "mock", "keyword": {},
              "model": {"name": "m", "revision": "r", "dim": 8, "normalization_version": 3},
              "build": {"products_table": "products", "desc_char_limit": 300,
                        "passage_char_limit": 1000, "max_statement_bytes": 1_000_000}}
    meta = build.build_bundle(products, tmp_path / "out", config)

    load = (tmp_path / "out" / "products.load.sql").read_text(encoding="utf-8")
    assert "'assassins creed origins test'" in load
    assert "normalized_tags" in load.split("VALUES")[0]
    spell = (tmp_path / "out" / "spellcheck.txt").read_text(encoding="utf-8")
    assert "assassins\t" in spell and "origins\t" in spell  # did-you-mean learns franchises
    assert meta["count"] == 2


def test_changing_tags_changes_the_embedding_checksum_but_not_the_model(tmp_path: Path) -> None:
    config = {"embedder": "mock", "keyword": {},
              "model": {"name": "m", "revision": "r", "dim": 8, "normalization_version": 3},
              "build": {"products_table": "products", "desc_char_limit": 300,
                        "passage_char_limit": 1000, "max_statement_bytes": 1_000_000}}
    base = {"id": "1", "title_en": "Game"}
    plain = build.build_bundle([base], tmp_path / "a", config)
    tagged = build.build_bundle([{**base, "tags": "Franchise"}], tmp_path / "b", config)

    assert plain["checksum"] != tagged["checksum"]
    for key in ("model", "dim", "normalization_version"):  # /reload compatibility keys
        assert plain[key] == tagged[key]
