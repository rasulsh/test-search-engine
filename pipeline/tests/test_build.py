"""Tests for build.py: input parsing, transforms, and bundle format."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path

import numpy as np
import pytest

import build
from normalize import NORMALIZATION_VERSION, normalize

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"


def _config(dim: int = 384) -> dict:
    return {
        "embedder": "mock",
        "model": {
            "name": "intfloat/multilingual-e5-small",
            "revision": "main",
            "dim": dim,
            "normalization_version": NORMALIZATION_VERSION,
        },
        "build": {"products_table": "products", "desc_char_limit": 300,
                  "desc_index_chars": 400, "max_statement_bytes": 1_000_000},
    }


def test_parse_sql_reads_rows_and_edge_cases() -> None:
    rows = build.parse_sql(INPUT_SQL.read_text(encoding="utf-8"))

    assert len(rows) == 4
    assert rows[0]["id"] == "2001"
    assert rows[0]["title_en"] == "Apple iPhone 15 Pro"
    # Apostrophe ('' escape) and comma inside a quoted string are preserved.
    assert rows[1]["desc"] == "world's best noise cancelling, over-ear"
    # NULL becomes None.
    assert rows[3]["image"] is None


def test_parse_csv() -> None:
    csv_text = "id,title_fa,title_en,desc,brand,category,model,price,stock,url,image,popularity\n"
    csv_text += "1,فارسی,English,d,B,C,M,9.99,3,/u,/i,5\n"
    rows = build.parse_csv(csv_text)
    assert rows == [{
        "id": "1", "title_fa": "فارسی", "title_en": "English", "desc": "d",
        "brand": "B", "category": "C", "model": "M", "price": "9.99",
        "stock": "3", "url": "/u", "image": "/i", "popularity": "5",
    }]


def test_parse_csv_accepts_field_larger_than_default_limit() -> None:
    # Real OpenCart HTML descriptions exceed Python's default 131072-byte field limit.
    big = "<p>" + "x" * 200_000 + "</p>"
    csv_text = "id,desc\n" + f'1,"{big}"\n'
    rows = build.parse_csv(csv_text)
    assert rows == [{"id": "1", "desc": big}]


def test_clean_text_decodes_opencart_escaping_and_strips_markup() -> None:
    # OpenCart stores HTML-escaped HTML, sometimes double-escaped by the editor.
    raw = (
        "&lt;p&gt;Apple Cinema 30&amp;quot;&lt;/p&gt;\n\t"
        "&lt;b&gt;Laptops &amp;amp; Notebooks&lt;/b&gt;"
    )
    assert build.clean_text(raw) == 'Apple Cinema 30" Laptops & Notebooks'
    # ZWNJ is part of Persian words, not whitespace; it must survive cleaning.
    assert build.clean_text("هدفون&nbsp;بی‌سیم") == "هدفون بی‌سیم"
    assert build.clean_text(None) is None


def test_read_products_cleans_text_columns_only(tmp_path: Path) -> None:
    export = tmp_path / "export.csv"
    export.write_text(
        "id,title_fa,title_en,desc,brand,category,model,price,stock,url,image,popularity\n"
        '7,گوشی,"Cinema 30&quot;","&lt;p&gt;big&lt;/p&gt;",A &amp; B,C,M1,9,1,'
        '"index.php?route=product/product&amp;product_id=7",/i,0\n',
        encoding="utf-8",
    )
    [row] = build.read_products(export)
    assert row["title_en"] == 'Cinema 30"'
    assert row["desc"] == "big"
    assert row["brand"] == "A & B"
    assert row["url"] == "index.php?route=product/product&amp;product_id=7"  # not a text column


def test_compose_passage_uses_raw_text() -> None:
    product = {"title_fa": "آیفون", "title_en": "iPhone", "brand": "Apple",
               "category": "Mobile", "model": "IP15", "desc": "x" * 500}
    passage = build.compose_passage(product, desc_char_limit=10)

    assert "آیفون" in passage  # RAW (alef madda not folded)
    assert "iPhone" in passage
    assert passage.count("x") == 10  # description truncated


def test_to_server_row_combines_titles_and_normalizes() -> None:
    row = build.to_server_row({
        "id": "5", "title_fa": "گوشی آیفون", "title_en": "iPhone", "desc": "D",
        "brand": "Apple", "category": "Mobile", "model": "IP15",
        "price": "1199.0", "stock": "8", "url": "/u", "image": "/i", "popularity": "9",
    })

    assert row["product_id"] == 5
    assert row["title"] == "گوشی آیفون iPhone"
    assert row["normalized_title"] == normalize("گوشی آیفون iPhone")
    assert "ایفون" in row["normalized_title"]  # folded in normalized copy
    assert row["price"] == 1199.0


def test_to_server_row_stores_sku_and_indexes_it_with_the_title() -> None:
    row = build.to_server_row({"id": "5", "title_en": "Sony Headphones", "sku": "SNY-XM5 BLK"})

    assert row["sku"] == "SNY-XM5 BLK"  # raw, for display
    assert row["normalized_sku"] == "snyxm5blk"  # separators and spaces stripped
    assert row["normalized_title"] == "sony headphones snyxm5blk"
    # No SKU: the title text is unchanged.
    assert build.to_server_row({"id": "6", "title_en": "Sony"})["normalized_title"] == "sony"
    assert build.to_server_row({"id": "6", "title_en": "Sony"})["normalized_sku"] == ""


def test_parse_sql_reads_sku_and_null_sku() -> None:
    rows = build.read_products(INPUT_SQL)
    assert rows[0]["sku"] == "APL-IP15P-128"
    assert rows[3]["sku"] is None
    assert build.to_server_row(rows[3])["sku"] == ""


def test_staging_ddl_copies_the_live_definition_without_comments() -> None:
    schema = build.SCHEMA_PATH.read_text(encoding="utf-8")
    ddl = build.staging_ddl(schema, "products", "products_new")

    assert ddl.startswith("DROP TABLE IF EXISTS `products_new`;\nCREATE TABLE `products_new` (")
    assert ddl.count(";") == 2  # exactly two statements
    assert "--" not in ddl
    for column in build._SERVER_COLUMNS:
        assert f"\n    {column} " in ddl
    assert "KEY idx_normalized_sku (normalized_sku)" in ddl
    assert "FULLTEXT KEY ft_normalized" in ddl
    assert "search_logs" not in ddl
    with pytest.raises(ValueError):
        build.staging_ddl(schema, "missing_table", "missing_table_new")


def test_products_sql_recreates_staging_before_the_rows(tmp_path: Path) -> None:
    build.build_bundle(build.read_products(INPUT_SQL), tmp_path, _config())
    sql = (tmp_path / "products.load.sql").read_text(encoding="utf-8")

    drop = sql.index("DROP TABLE IF EXISTS `products_new`;")
    create = sql.index("CREATE TABLE `products_new`")
    assert sql.startswith("SET NAMES utf8mb4;")
    assert drop < create < sql.index("REPLACE INTO `products_new`")
    assert "'APL-IP15P-128', 'aplip15p128'" in sql


def test_index_description_cuts_at_a_word_boundary() -> None:
    assert build.index_description("alpha beta gamma", 8) == "alpha"  # "bet" dropped
    assert build.index_description("alpha beta gamma", 10) == "alpha beta"  # ends on a word
    assert build.index_description("alpha beta", 100) == "alpha beta"
    assert build.index_description("alpha beta", 0) == "alpha beta"  # 0 disables the cap
    assert build.index_description("x" * 50, 10) == ""  # no whitespace to cut back to
    # A cut after a ZWNJ (not a word character) still drops the fragment.
    assert build.index_description("aaa bbb\u200ccc", 8) == "aaa"


def test_to_server_row_indexes_only_the_leading_description() -> None:
    desc = "Silent case fan with RGB lighting. " + "filler " * 80 + "ball bearing motor"
    product = {"id": "7", "title_fa": "", "title_en": "Case Fan", "desc": desc}

    row = build.to_server_row(product, desc_index_chars=400)

    assert row["description"] == desc  # full text kept for display
    assert "rgb" in row["normalized_desc"]
    assert "bearing" not in row["normalized_desc"]  # deep spec text not indexed
    assert len(row["normalized_desc"]) <= 400
    assert "bearing" in build.to_server_row(product, desc_index_chars=0)["normalized_desc"]


def _row(product_id: int, title_en: str = "", desc: str = "") -> dict:
    return build.to_server_row({"id": str(product_id), "title_fa": "", "title_en": title_en,
                                "desc": desc, "brand": "", "category": "", "model": "",
                                "price": "0", "stock": "0", "url": "", "image": "",
                                "popularity": "0"})


def test_build_products_sql_targets_staging_and_escapes() -> None:
    sql = build.build_products_sql([_row(1, "O'Brien", "ends with \\")], "products_new", 10_000)
    assert "REPLACE INTO `products_new`" in sql
    assert "O''Brien" in sql  # single quote escaped
    # A raw trailing backslash would escape the closing quote and break the load.
    assert "'ends with \\\\'" in sql


def test_build_products_sql_splits_statements_under_the_byte_cap() -> None:
    rows = [_row(i, desc="x" * 200) for i in range(50)]
    cap = 2_000
    sql = build.build_products_sql(rows, "products_new", cap)

    statements = [s.strip() for s in sql.split(";\n") if s.strip().startswith("REPLACE INTO")]
    assert len(statements) > 1
    assert all(len((s + ";\n").encode("utf-8")) <= cap for s in statements)
    # Every row lands exactly once, in order.
    ids = [int(t.split("(", 1)[1].split(",", 1)[0]) for s in statements
           for t in s.split("VALUES\n", 1)[1].split(",\n")]
    assert ids == list(range(50))


def test_build_products_sql_oversized_row_gets_its_own_statement() -> None:
    sql = build.build_products_sql([_row(1, desc="y" * 5_000), _row(2)], "products_new", 1_000)
    assert sql.count("REPLACE INTO") == 2


def test_build_bundle_format_and_determinism(tmp_path: Path) -> None:
    products = build.read_products(INPUT_SQL)
    config = _config()

    meta = build.build_bundle(products, tmp_path / "b1", config)

    assert meta["count"] == 4
    assert meta["dim"] == 384
    assert meta["normalization_version"] == NORMALIZATION_VERSION
    assert meta["embedder"] == "mock"

    for name in ("vectors.bin", "vectors.idx", "products.load.sql",
                 "synonyms.json", "spellcheck.txt", "keymap.json", "meta.json"):
        assert (tmp_path / "b1" / name).exists()

    raw = (tmp_path / "b1" / "vectors.bin").read_bytes()
    assert len(raw) == 4 * 384 * 4
    assert meta["checksum"] == hashlib.sha256(raw).hexdigest()

    vectors = np.frombuffer(raw, dtype="<f4").reshape(4, 384)
    np.testing.assert_allclose(np.linalg.norm(vectors, axis=1), np.ones(4), atol=1e-6)

    idx_lines = (tmp_path / "b1" / "vectors.idx").read_text().split()
    assert idx_lines == ["2001", "2002", "2003", "2004"]
    assert len(idx_lines) == meta["count"]

    # The suggestion dictionary comes from titles/brand/category/model only.
    spell = dict(
        line.split("\t") for line in
        (tmp_path / "b1" / "spellcheck.txt").read_text(encoding="utf-8").splitlines()
    )
    assert "vivobook" in spell and "sony" in spell
    assert "flagship" not in spell and "webos" not in spell  # description-only words

    synonyms = json.loads((tmp_path / "b1" / "synonyms.json").read_text())
    assert isinstance(synonyms, list)

    # normalized_desc in the load file honours desc_index_chars.
    capped = build.build_bundle(products, tmp_path / "b3", {**config, "build": {
        **config["build"], "desc_index_chars": 10}})
    load_sql = (tmp_path / "b3" / "products.load.sql").read_text(encoding="utf-8")
    assert capped["checksum"] == meta["checksum"]  # embeddings unaffected by the cap
    full_sql = (tmp_path / "b1" / "products.load.sql").read_text(encoding="utf-8")
    assert full_sql.count("titanium") == 2  # description + normalized_desc
    assert load_sql.count("titanium") == 1  # only in the display description now

    # Deterministic: a second build yields the same vectors.
    meta2 = build.build_bundle(products, tmp_path / "b2", config)
    assert meta2["checksum"] == meta["checksum"]


LOAD_SAMPLE = REPO_ROOT / "fixtures" / "products.load.sample.sql"
LOAD_SAMPLE_HEADER = (
    "-- GENERATED from fixtures/products.input.sql by build.py's load-file writer\n"
    "-- (statement cap 700 bytes, so rows span several statements). The PHP\n"
    "-- StagingLoader tests load it. Regenerate after a format or schema change:\n"
    "--   REGENERATE_FIXTURES=1 pytest -k load_sample\n"
)


def _load_sample() -> str:
    rows = [build.to_server_row(p, 400) for p in build.read_products(INPUT_SQL)]
    schema = build.SCHEMA_PATH.read_text(encoding="utf-8")
    ddl = build.staging_ddl(schema, "products", "products_new")
    return LOAD_SAMPLE_HEADER + build.build_products_sql(rows, "products_new", 700, ddl)


def test_committed_load_sample_matches_the_writer() -> None:
    # The PHP loader is tested against this file, so it must be exactly what
    # build.py emits today.
    if os.environ.get("REGENERATE_FIXTURES") == "1":
        LOAD_SAMPLE.write_text(_load_sample(), encoding="utf-8", newline="\n")
    assert LOAD_SAMPLE.read_text(encoding="utf-8") == _load_sample()
    assert _load_sample().count("REPLACE INTO") > 1
