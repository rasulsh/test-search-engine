"""Tests for db_export.py: query shape, CSV round trip through build.py, env
handling. No database: the cursor is a stub."""

from __future__ import annotations

import io
from pathlib import Path
from typing import Any

import pytest

import build
import db_export


def test_columns_match_builds_documented_input() -> None:
    assert db_export.COLUMNS == build.INPUT_COLUMNS


def test_query_shape_for_this_shop() -> None:
    sql = db_export.build_query("oc_", 2)
    assert "d.meta_title" in sql and "AS title_en" in sql  # secondary name, no English row
    assert "language_id = 2" in sql and "language_id = @" not in sql
    assert "p.status = 1" in sql and "p.accept_status = '0'" in sql
    assert "ps.store_id = 0" in sql and "oc_product_to_store" in sql
    assert "GROUP_CONCAT(DISTINCT t.name SEPARATOR ' ')" in sql
    assert "oc_product_tag" in sql and "oc_tag " in sql
    assert "LEFT(GROUP_CONCAT(DISTINCT c.name" in sql and "255)" in sql
    assert "COALESCE(p.feature, '') " in sql  # raw, untouched
    # Aliases follow build.py's column order.
    aliases = [part.split("AS ")[-1].strip(" ,`") for part in sql.split("\n") if " AS " in part]
    assert [a for a in aliases if a in db_export.COLUMNS] == list(db_export.COLUMNS)


def test_query_prefix_and_language_are_validated() -> None:
    assert "shop_product_tag" in db_export.build_query("shop_", 3)
    assert "language_id = 3" in db_export.build_query("shop_", 3)
    with pytest.raises(ValueError):
        db_export.build_query("oc_; DROP TABLE x;--", 2)


class _Cursor:
    def __init__(self, rows: list[tuple[Any, ...]], log: list[str]) -> None:
        self.rows, self.log = rows, log

    def __enter__(self) -> _Cursor:
        return self

    def __exit__(self, *exc: object) -> None:
        return None

    def execute(self, sql: str) -> None:
        self.log.append(sql)

    def __iter__(self):  # type: ignore[no-untyped-def]
        return iter(self.rows)


class _Connection:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self.rows, self.log, self.closed = rows, [], False

    def cursor(self) -> _Cursor:
        return _Cursor(self.rows, self.log)

    def close(self) -> None:
        self.closed = True


def _rows() -> list[tuple[Any, ...]]:
    feature = 'a:1:{i:0;a:1:{s:5:"title";s:9:"a "quote" ";}}'  # raw quotes
    desc = '<p>line one\r\nline two, with "quotes" and, commas</p>\n<p>more</p>'
    return [
        (1, "گوشی سامسونگ", "Samsung Galaxy", desc, "سامسونگ", "موبایل", "A1", "SKU1",
         "100.0000", 5, "index.php?route=product/product&product_id=1", "a.jpg", 7,
         "رنگ: مشکی | حافظه: 128", feature, "tag1 tag2"),
        (2, "x", None, "", "", "", "M", "", "1", 0, "u", "", 0, "", "", ""),
    ]


def test_export_streams_with_session_setup_and_round_trips(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    connection = _Connection(_rows())
    monkeypatch.setattr(db_export, "connect", lambda settings: connection)
    out = tmp_path / "export.csv"
    settings = {"prefix": "oc_", "language_id": 2}

    assert db_export.export(settings, out) == 2
    assert connection.closed
    assert connection.log[0] == "SET SESSION max_statement_time = 0"
    assert connection.log[1] == "SET SESSION group_concat_max_len = 1000000"
    assert not any("max_execution_time" in line for line in connection.log)

    # build.py reads back exactly what was written, quotes and newlines included.
    raw = out.read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf") and raw.count(b"\r\n") >= 3
    products = build.read_products(out)
    assert [p["id"] for p in products] == ["1", "2"]
    assert products[0]["feature"] == _rows()[0][14]
    assert products[0]["tags"] == "tag1 tag2" and products[1]["title_en"] == ""


def test_write_csv_prints_progress(capsys: pytest.CaptureFixture[str]) -> None:
    rows = ((i,) + ("",) * 15 for i in range(db_export.PROGRESS_EVERY + 1))
    assert db_export.write_csv(rows, io.StringIO()) == db_export.PROGRESS_EVERY + 1
    assert f"{db_export.PROGRESS_EVERY} rows" in capsys.readouterr().out


def test_main_requires_credentials_from_env_never_hardcoded(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    for key in ("OC_DB_USER", "OC_DB_PASSWORD", "OC_DB_NAME"):
        monkeypatch.delenv(key, raising=False)
    monkeypatch.chdir(tmp_path)  # no stray .env
    with pytest.raises(SystemExit) as exc:
        db_export.main(["--out", str(tmp_path / "x.csv")])
    assert exc.value.code == 2


def test_main_reads_env_and_dotenv(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    for key in ("OC_DB_USER", "OC_DB_PASSWORD", "OC_DB_NAME", "OC_DB_HOST", "OC_DB_PORT"):
        monkeypatch.delenv(key, raising=False)
    (tmp_path / ".env").write_text(
        "# c\nOC_DB_USER=u\nOC_DB_PASSWORD='p w'\nOC_DB_NAME=shop\nOC_DB_PORT=3307\n",
        encoding="utf-8",
    )
    monkeypatch.setenv("OC_DB_HOST", "db.example")  # real env wins over .env
    monkeypatch.chdir(tmp_path)
    seen: dict[str, Any] = {}

    def fake_export(settings: dict[str, Any], out: Path) -> int:
        seen.update(settings)
        return 0

    monkeypatch.setattr(db_export, "export", fake_export)
    assert db_export.main(["--out", "o.csv"]) == 0
    assert seen == {"host": "db.example", "port": 3307, "user": "u", "password": "p w",
                    "name": "shop", "prefix": "oc_", "language_id": 2}
    monkeypatch.undo()


def test_low_row_count_guard(capsys: pytest.CaptureFixture[str]) -> None:
    text = "id,x\n" + "".join(f"{i},a\n" for i in range(1, 51))
    assert build.warn_low_row_count(text, 50) is False
    assert build.warn_low_row_count(text, 7) is True
    assert "db_export.py" in capsys.readouterr().err
    assert build.warn_low_row_count("id,x\n1,a\n", 0) is False  # tiny files stay quiet
