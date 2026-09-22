"""Tests for build.py: input parsing, transforms, and bundle format."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import numpy as np

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
        "build": {"products_table": "products", "desc_char_limit": 300},
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


def test_build_products_sql_targets_staging_and_escapes() -> None:
    sql = build.build_products_sql(
        [build.to_server_row({"id": "1", "title_fa": "", "title_en": "O'Brien",
                              "desc": "", "brand": "", "category": "", "model": "",
                              "price": "0", "stock": "0", "url": "", "image": "",
                              "popularity": "0"})],
        "products_new",
    )
    assert "REPLACE INTO `products_new`" in sql
    assert "O''Brien" in sql  # single quote escaped


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

    synonyms = json.loads((tmp_path / "b1" / "synonyms.json").read_text())
    assert isinstance(synonyms, list)

    # Deterministic: a second build yields the same vectors.
    meta2 = build.build_bundle(products, tmp_path / "b2", config)
    assert meta2["checksum"] == meta["checksum"]
