"""M23: the space-collapsed identity column (title, brand, tags) of the load file."""

from __future__ import annotations

import json
from pathlib import Path

import build
from normalize import NORMALIZATION_VERSION

CONFIG = {
    "embedder": "mock",
    "keyword": {},
    "model": {"name": "m", "revision": "r", "dim": 8,
              "normalization_version": NORMALIZATION_VERSION},
    "build": {"products_table": "products", "desc_char_limit": 300,
              "passage_char_limit": 1000, "max_statement_bytes": 1_000_000},
}


def test_server_row_collapses_title_brand_and_tags() -> None:
    row = build.to_server_row({
        "id": "1", "title_fa": "کنسول", "title_en": "Far Cry 6", "brand": "Ubi Soft",
        "tags": "Far Cry Primal",
    })

    # Title (Persian + English as in normalized_title), brand, tags: each collapsed,
    # space-separated so the parts stay apart.
    assert row["normalized_collapsed"] == "کنسولfarcry6 ubisoft farcryprimal"
    # The spaced text the collapsed match is verified against is untouched.
    assert row["normalized_title"] == "کنسول far cry 6"


def test_identity_composition_matches_the_shared_fixture() -> None:
    # The PHP loader (ProductLoader::collapsedIdentity) is checked against the same cases.
    fixture = json.loads(
        (Path(__file__).resolve().parents[2] / "fixtures" / "normalization_cases.json")
        .read_text(encoding="utf-8")
    )
    for case in fixture["identity_cases"]:
        got = build.collapsed_identity(case["title"], case["brand"], case["tags"])
        assert got == case["expected"], case["name"]


def test_empty_parts_leave_no_stray_separators() -> None:
    assert build.to_server_row({"id": "2", "title_en": "Plain"})["normalized_collapsed"] == "plain"
    assert build.to_server_row({"id": "3"})["normalized_collapsed"] == ""


def test_long_identity_is_cut_to_the_column_width() -> None:
    value = build.collapsed_identity("Title", "Brand", " ".join(["word"] * 400))

    assert len(value) == build.COLLAPSED_MAX_CHARS == 700
    assert value.startswith("title brand wordword")


def test_column_width_matches_the_schema() -> None:
    schema = build.SCHEMA_PATH.read_text(encoding="utf-8")

    assert f"normalized_collapsed VARCHAR({build.COLLAPSED_MAX_CHARS})" in schema
    assert "KEY idx_collapsed_scan (normalized_collapsed, popularity)" in schema


def test_load_file_carries_the_column_and_the_staging_ddl(tmp_path: Path) -> None:
    sql = (
        "INSERT INTO p (`id`, `title_fa`, `title_en`, `desc`, `brand`, `category`, `tags`) VALUES\n"
        "  (1, 'کنسول', 'Far Cry 6', 'd', 'Ubisoft', 'Games', 'Far Cry Primal');\n"
    )
    export = tmp_path / "export.sql"
    export.write_text(sql, encoding="utf-8")

    meta = build.build_bundle(build.read_products(export), tmp_path / "out", CONFIG)

    load = (tmp_path / "out" / "products.load.sql").read_text(encoding="utf-8")
    assert "normalized_collapsed" in load.split("VALUES")[0]
    assert "'کنسولfarcry6 ubisoft farcryprimal'" in load
    assert "normalized_collapsed VARCHAR(700)" in load  # staging table DDL carries it
    assert "idx_collapsed_scan" in load
    # A derived column: normalization rules and the embedded text did not change.
    assert meta["normalization_version"] == NORMALIZATION_VERSION


def test_collapsed_text_is_not_part_of_the_embedded_passage() -> None:
    product = {"title_en": "Far Cry 6", "brand": "Ubisoft", "tags": "Far Cry Primal"}
    row = build.to_server_row({"id": "1", **product})

    # Vectors (and so the model contract) are untouched by the new keyword column.
    assert row["normalized_collapsed"] not in build.compose_passage(product, 0, 0)
