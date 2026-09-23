"""Tests for the M13 specs field: attributes + PHP-serialized feature titles."""

from __future__ import annotations

from pathlib import Path

import pytest

import build
from normalize import normalize

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"

# oc_product.feature as stored: string lengths count UTF-8 BYTES, not characters.
FEATURE = (
    'a:4:{i:0;a:1:{s:5:"title";s:30:"کیفیت تصویر 2K HDR10";}'
    'i:1;a:1:{s:5:"title";s:43:"نرخ نوسازی تصویر 540 هرتز";}'
    'i:2;a:1:{s:5:"title";s:51:"زمان پاسخ‌دهی 0.02 میلی ثانیه";}'
    'i:3;a:1:{s:5:"title";s:32:"پنل ۲۷ اینچ Tandem OLED";}}'
)
TITLES = [
    "کیفیت تصویر 2K HDR10",
    "نرخ نوسازی تصویر 540 هرتز",
    "زمان پاسخ‌دهی 0.02 میلی ثانیه",  # ZWNJ kept in the raw title
    "پنل ۲۷ اینچ Tandem OLED",
]


def _serialize(titles: list[str], length=lambda s: len(s.encode("utf-8"))) -> str:
    items = "".join(
        f'i:{i};a:1:{{s:5:"title";s:{length(t)}:"{t}";}}' for i, t in enumerate(titles)
    )
    return f"a:{len(titles)}:{{{items}}}"


def test_feature_fixture_deserializes_to_all_four_titles() -> None:
    assert build.feature_titles(FEATURE) == TITLES
    assert _serialize(TITLES) == FEATURE  # the fixture really is byte-counted


def test_character_counted_lengths_are_rejected_not_misread() -> None:
    # A char-count "parser" would accept this; PHP's byte counts would not.
    assert build.feature_titles(_serialize(TITLES, length=len)) is None


@pytest.mark.parametrize("raw", [
    FEATURE[:-1],                                   # truncated
    FEATURE + "junk",                               # trailing data
    'a:1:{i:0;a:1:{s:5:"title";s:99:"broken";}}',   # wrong length
    'O:8:"stdClass":1:{s:5:"title";s:1:"x";}',      # PHP object
    "NULL",                                         # phpMyAdmin's NULL text
    "plain text, not serialized",
])
def test_malformed_feature_is_ignored(raw: str) -> None:
    assert build.feature_titles(raw) is None


def test_empty_feature_and_non_title_values() -> None:
    assert build.feature_titles(None) == []
    assert build.feature_titles("   ") == []
    assert build.feature_titles("a:0:{}") == []
    assert build.feature_titles('s:5:"title";') == []  # a scalar, no array
    # Empty titles, non-string titles and other keys are skipped; nesting is walked.
    raw = ('a:3:{i:0;a:1:{s:5:"title";s:0:"";}i:1;a:2:{s:5:"title";i:5;s:4:"icon";s:1:"x";}'
           'i:2;a:1:{s:5:"group";a:1:{s:5:"title";s:6:"nested";}}}')
    assert build.feature_titles(raw) == ["nested"]


def test_feature_titles_are_html_decoded() -> None:
    # OpenCart escapes form input before the module serializes it.
    assert build.feature_titles(_serialize(["27&quot; &amp; HDR"])) == ['27" & HDR']
    # The whole serialized value escaped after serialization is retried unescaped.
    assert build.feature_titles(FEATURE.replace('"', "&quot;")) == TITLES


def test_attribute_pairs() -> None:
    raw = "برند: ASUS ROG | اندازه: &lt;b&gt;۲۷ اینچ&lt;/b&gt; | فقط مقدار | خالی: "
    assert build.attribute_pairs(raw) == [
        ("برند", "ASUS ROG"), ("اندازه", "۲۷ اینچ"), ("", "فقط مقدار"),
    ]
    assert build.attribute_pairs(None) == []
    assert build.attribute_pairs("") == []


def test_specs_are_attribute_pairs_then_feature_titles_uncapped() -> None:
    long_value = "مشخصات " * 200  # far beyond desc_index_chars
    product = {"id": "9", "title_en": "Monitor", "desc": "d",
               "attributes": f"برند: ASUS ROG | توضیح: {long_value}", "feature": FEATURE}

    specs = build.compose_specs(product)
    row = build.to_server_row(product, desc_index_chars=10)

    assert specs.startswith("برند: ASUS ROG | توضیح: مشخصات")
    assert specs.endswith(" | ".join(TITLES))
    assert row["normalized_specs"] == normalize(specs)
    assert "540 هرتز" in row["normalized_specs"] and "asus rog" in row["normalized_specs"]
    assert len(row["normalized_specs"]) > 1000  # description cap not applied
    assert build.to_server_row({"id": "1"})["normalized_specs"] == ""


def test_passage_prioritises_titles_and_specs_over_the_description() -> None:
    product = {"title_fa": "مانیتور", "title_en": "Monitor", "brand": "ASUS",
               "attributes": "برند: ASUS ROG", "feature": FEATURE, "desc": "x" * 500}

    passage = build.compose_passage(product, desc_char_limit=300, passage_char_limit=1000)
    head = "مانیتور Monitor ASUS " + " ".join(TITLES) + " ASUS ROG"
    assert passage.startswith(head)  # raw feature titles, then attribute VALUES
    assert "برند" not in passage  # attribute names are not embedded
    assert passage.count("x") == 300

    # Tight cap: the description gives way first, the passage stays bounded.
    tight = build.compose_passage(product, desc_char_limit=300, passage_char_limit=len(head) + 11)
    assert tight == head + " " + "x" * 10
    # Specs longer than the cap are cut at a word boundary, no description left.
    short = build.compose_passage(product, desc_char_limit=300, passage_char_limit=40)
    assert len(short) <= 40 and "x" not in short and head.startswith(short)
    # 0 disables the cap (desc_char_limit still applies).
    assert build.compose_passage(product, 300, 0).count("x") == 300


def test_bundle_carries_specs_and_reports_malformed_features(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    products = build.read_products(INPUT_SQL)
    assert build.warn_malformed_features(products) == 1
    assert "(ids 2006)" in capsys.readouterr().err

    rows = {int(p["id"]): build.to_server_row(p, 400) for p in products}
    assert "نرخ نوسازی تصویر 540 هرتز" in rows[2005]["normalized_specs"]
    assert rows[2005]["normalized_specs"].startswith("برند: asus rog | ")
    assert rows[2006]["normalized_specs"] == "طول: 2 متر"  # malformed feature dropped
    assert rows[2001]["normalized_specs"] == ""  # NULL columns

    config = {"embedder": "mock",
              "model": {"name": "m", "revision": "r", "dim": 8, "normalization_version": 2},
              "build": {"products_table": "products", "desc_char_limit": 300,
                        "passage_char_limit": 1000, "max_statement_bytes": 1_000_000}}
    build.build_bundle(products, tmp_path, config)
    load_sql = (tmp_path / "products.load.sql").read_text(encoding="utf-8")
    assert "normalized_specs MEDIUMTEXT" in load_sql
    fulltext = "FULLTEXT KEY ft_normalized (normalized_title, normalized_specs, normalized_desc)"
    assert fulltext in load_sql
    assert "'" + rows[2005]["normalized_specs"] + "'" in load_sql
