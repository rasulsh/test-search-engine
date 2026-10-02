"""Export the OpenCart catalog straight from its database to build.py's CSV.

    python pipeline/db_export.py --out export.csv
        [--host H] [--port P] [--user U] [--name DB] [--prefix oc_] [--language-id N]

Why not phpMyAdmin's CSV export: the serialized `feature` column holds raw
quotes next to multi-line HTML descriptions, which desyncs phpMyAdmin's CSV
quoting (24,700 rows parsed as ~7,000). Here the same csv module writes the file
and build.py reads it, so every field round-trips.

Connection settings come from the environment (a `.env` file in the current
directory is read first and never overrides real variables):
OC_DB_HOST, OC_DB_PORT, OC_DB_USER, OC_DB_PASSWORD, OC_DB_NAME, plus optional
OC_DB_PREFIX (default "oc_") and OC_LANGUAGE_ID (default 2). The password is
never accepted on the command line. Needs `pip install pymysql`.

Shop conventions (this store is not stock OpenCart): only Persian is installed,
`meta_title` holds the secondary product name (title_en), and products must have
status = 1 and accept_status = '0' in store 0.
"""

from __future__ import annotations

# Same stdlib `keyword` shadowing guard as build.py (this directory is
# sys.path[0] when run as a script).
import os as _os
import sys as _sys

_HERE = _os.path.dirname(_os.path.abspath(__file__))
if _sys.path and _os.path.abspath(_sys.path[0]) == _HERE:
    _sys.path.pop(0)
    import importlib

    importlib.import_module("keyword")
    _sys.path.insert(0, _HERE)

import argparse
import csv
import os
import re
from collections.abc import Iterable, Iterator, Mapping
from pathlib import Path
from typing import Any

# build.py's documented input order; test_db_export asserts they stay equal.
COLUMNS = (
    "id", "title_fa", "title_en", "desc", "brand", "category",
    "model", "sku", "price", "stock", "url", "image", "popularity",
    "attributes", "feature", "tags",
)

# MariaDB enforces max_statement_time; MySQL's max_execution_time errors there.
SESSION_SETUP = (
    "SET SESSION max_statement_time = 0",
    "SET SESSION group_concat_max_len = 1000000",
)

PROGRESS_EVERY = 2000
_PREFIX = re.compile(r"^[A-Za-z0-9_]*$")


def load_dotenv(path: str | Path = ".env") -> None:
    """Read KEY=VALUE lines into os.environ without overriding what is set."""
    file = Path(path)
    if not file.is_file():
        return
    for line in file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def build_query(prefix: str = "oc_", language_id: int = 2) -> str:
    """The catalog SELECT. The prefix is validated (it is interpolated into
    identifiers); the language id is an int; every other value is a literal."""
    if not _PREFIX.match(prefix):
        raise ValueError(f"invalid table prefix: {prefix!r}")
    lang = int(language_id)
    px = prefix
    return f"""
SELECT
    p.product_id                                              AS id,
    COALESCE(d.name, '')                                      AS title_fa,
    COALESCE(d.meta_title, '')                                AS title_en,
    COALESCE(d.description, '')                               AS `desc`,
    COALESCE(m.name, '')                                      AS brand,
    COALESCE((
        SELECT LEFT(GROUP_CONCAT(DISTINCT c.name
                                 ORDER BY pc.category_id SEPARATOR ' / '), 255)
        FROM {px}product_to_category pc
        JOIN {px}category_description c
          ON c.category_id = pc.category_id AND c.language_id = {lang}
        WHERE pc.product_id = p.product_id
    ), '')                                                    AS category,
    COALESCE(p.model, '')                                     AS model,
    COALESCE(p.sku, '')                                       AS sku,
    p.price                                                   AS price,
    p.quantity                                                AS stock,
    CONCAT('index.php?route=product/product&product_id=', p.product_id) AS url,
    COALESCE(p.image, '')                                     AS image,
    p.viewed                                                  AS popularity,
    COALESCE((
        SELECT GROUP_CONCAT(CONCAT_WS(': ', ad.name, pa.text) SEPARATOR ' | ')
        FROM {px}product_attribute pa
        JOIN {px}attribute_description ad
          ON ad.attribute_id = pa.attribute_id AND ad.language_id = {lang}
        WHERE pa.product_id = p.product_id AND pa.language_id = {lang}
    ), '')                                                    AS attributes,
    COALESCE(p.feature, '')                                   AS feature,
    COALESCE((
        SELECT GROUP_CONCAT(DISTINCT t.name SEPARATOR ' ')
        FROM {px}product_tag pt
        JOIN {px}tag t ON t.tag_id = pt.tag_id
        WHERE pt.product_id = p.product_id
    ), '')                                                    AS tags
FROM {px}product p
JOIN {px}product_to_store ps ON ps.product_id = p.product_id AND ps.store_id = 0
LEFT JOIN {px}product_description d
       ON d.product_id = p.product_id AND d.language_id = {lang}
LEFT JOIN {px}manufacturer m ON m.manufacturer_id = p.manufacturer_id
WHERE p.status = 1
  AND p.accept_status = '0'
ORDER BY p.product_id
""".strip()


def write_csv(rows: Iterable[Iterable[Any]], out: Any, progress: bool = True) -> int:
    """RFC 4180 rows (header first) to the open text file `out`; returns the
    number of data rows. NULL becomes an empty field."""
    writer = csv.writer(out, lineterminator="\r\n")
    writer.writerow(COLUMNS)
    count = 0
    for row in rows:
        writer.writerow(["" if value is None else value for value in row])
        count += 1
        if progress and count % PROGRESS_EVERY == 0:
            print(f"  {count} rows...", flush=True)
    return count


def _stream(connection: Any, query: str) -> Iterator[tuple[Any, ...]]:
    with connection.cursor() as cursor:
        for statement in SESSION_SETUP:
            cursor.execute(statement)
        cursor.execute(query)
        yield from cursor


def connect(settings: Mapping[str, Any]) -> Any:
    import pymysql
    from pymysql.cursors import SSCursor

    return pymysql.connect(
        host=settings["host"], port=int(settings["port"]), user=settings["user"],
        password=settings["password"], database=settings["name"], charset="utf8mb4",
        cursorclass=SSCursor,  # server-side cursor: rows stream, none are buffered
    )


def export(settings: Mapping[str, Any], out_path: Path) -> int:
    query = build_query(settings["prefix"], settings["language_id"])
    connection = connect(settings)
    try:
        # newline="" lets csv control line endings, so embedded newlines survive.
        with out_path.open("w", encoding="utf-8", newline="") as out:
            return write_csv(_stream(connection, query), out)
    finally:
        connection.close()


def main(argv: list[str] | None = None) -> int:
    load_dotenv()
    env = os.environ.get
    parser = argparse.ArgumentParser(description="Export the OpenCart catalog to build.py's CSV.")
    parser.add_argument("--out", default="export.csv", help="Output CSV (default export.csv).")
    parser.add_argument("--host", default=env("OC_DB_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(env("OC_DB_PORT", "3306")))
    parser.add_argument("--user", default=env("OC_DB_USER"))
    parser.add_argument("--name", default=env("OC_DB_NAME"), help="Database name.")
    parser.add_argument("--prefix", default=env("OC_DB_PREFIX", "oc_"), help="Table prefix.")
    parser.add_argument("--language-id", type=int, default=int(env("OC_LANGUAGE_ID", "2")))
    args = parser.parse_args(argv)

    password = env("OC_DB_PASSWORD")
    if not args.user or not args.name or password is None:
        parser.error("set OC_DB_USER, OC_DB_PASSWORD and OC_DB_NAME (or --user / --name)")
    try:
        build_query(args.prefix, args.language_id)
    except ValueError as exc:
        parser.error(str(exc))

    settings = {"host": args.host, "port": args.port, "user": args.user, "password": password,
                "name": args.name, "prefix": args.prefix, "language_id": args.language_id}
    print(f"Exporting {args.name} on {args.host}:{args.port} to {args.out} ...", flush=True)
    count = export(settings, Path(args.out))
    print(f"Wrote {count} rows to {args.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
