"""Offline bundle builder: catalog export in -> index bundle out.

    python pipeline/build.py --sql export.sql --out ./bundle
    python pipeline/build.py --csv export.csv --out ./bundle

Input is a simple, documented shape (NOT the raw OpenCart export); see README for
the columns and how to derive them from OpenCart. Output is the bundle the server
consumes (CLAUDE.md sec. 4): vectors.bin, vectors.idx, products.load.sql,
synonyms.json, aliases.json, spellcheck.txt, keymap.json, meta.json.
"""

from __future__ import annotations

# Running `python pipeline/build.py` puts this directory on sys.path[0], which
# would shadow the stdlib `keyword` module that `collections` imports during
# interpreter startup. Load the real stdlib keyword before any such import.
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
import hashlib
import html
import importlib.util
import io
import json
import re
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import phpserialize

import config as pipeline_config
from embed import create_embedder, embed_passages
from normalize import NORMALIZATION_VERSION, normalize, normalize_sku

# keyword.py shadows the stdlib `keyword` module (pre-imported under pytest), so
# load the local file by path rather than via a plain import.
_kw_spec = importlib.util.spec_from_file_location(
    "catalog_keyword", Path(__file__).with_name("keyword.py")
)
assert _kw_spec is not None and _kw_spec.loader is not None
_keyword = importlib.util.module_from_spec(_kw_spec)
_kw_spec.loader.exec_module(_keyword)

# Documented input columns build.py expects.
INPUT_COLUMNS = (
    "id", "title_fa", "title_en", "desc", "brand", "category",
    "model", "sku", "price", "stock", "url", "image", "popularity",
    "attributes", "feature",
)

# GROUP_CONCAT separator of the `attributes` export column (README, step 1).
ATTRIBUTE_SEPARATOR = " | "

_SERVER_COLUMNS = (
    "product_id", "title", "description", "normalized_title", "normalized_desc",
    "normalized_specs", "brand", "category", "model", "sku", "normalized_sku",
    "price", "stock", "url", "image", "popularity",
)

# The staging table is created from the live table's definition in the schema,
# so products.load.sql is self-contained and carries schema changes.
SCHEMA_PATH = Path(__file__).resolve().parents[1] / "db" / "schema.sql"


# --- Input parsing ---------------------------------------------------------

def read_products(path: str | Path) -> list[dict[str, Any]]:
    path = Path(path)
    text = path.read_text(encoding="utf-8")
    rows = parse_csv(text) if path.suffix.lower() == ".csv" else parse_sql(text)
    return [_clean_row(row) for row in rows]


_TEXT_COLUMNS = ("title_fa", "title_en", "desc", "brand", "category", "model", "sku")
_TAG = re.compile(r"<[^>]*>")
_SPACE = re.compile(r"\s+")  # str.isspace() set: ZWNJ is NOT whitespace, so it survives


def clean_text(value: str | None) -> str | None:
    """Visible text of an OpenCart field.

    OpenCart stores names and descriptions HTML-escaped, often twice (the editor
    emits `&quot;`, then OpenCart escapes it to `&amp;quot;`). Indexing that raw
    would put markup and entity names into FULLTEXT and the embeddings.
    """
    if value is None:
        return None
    text = str(value)
    for _ in range(3):
        decoded = html.unescape(text)
        if decoded == text:
            break
        text = decoded
    return _SPACE.sub(" ", _TAG.sub(" ", text)).strip()


def attribute_pairs(raw: str | None) -> list[tuple[str, str]]:
    """(name, value) pairs from the aggregated `attributes` export column
    ("name: value | name: value"). A part without ": " is a bare value."""
    pairs: list[tuple[str, str]] = []
    for part in str(raw or "").split(ATTRIBUTE_SEPARATOR):
        # The trailing space keeps a name with an empty value ("name:") a pair.
        name, sep, value = ((clean_text(part) or "") + " ").partition(": ")
        if not sep:
            name, value = "", name
        if value.strip():
            pairs.append((name.strip(), value.strip()))
    return pairs


def feature_titles(raw: str | None) -> list[str] | None:
    """Every "title" value in the PHP-serialized `feature` column.

    PHP serialize() counts string lengths in BYTES, so the text is parsed as
    UTF-8 bytes by a real unserializer. Returns [] for an empty field and None
    for a malformed one (wrong lengths, truncation, trailing data). A value
    whose quotes were HTML-escaped as a whole is retried unescaped.
    """
    text = str(raw or "").strip()
    if not text:
        return []
    for candidate in dict.fromkeys((text, html.unescape(text))):
        stream = io.BytesIO(candidate.encode("utf-8"))
        try:
            data = phpserialize.load(stream, decode_strings=True)
        except (ValueError, RecursionError):
            continue
        if stream.read(1):
            continue
        return [title for title in map(clean_text, _titles(data)) if title]
    return None


def _titles(data: Any) -> list[str]:
    if not isinstance(data, dict):
        return []
    found: list[str] = []
    for key, value in data.items():
        if key == "title" and isinstance(value, str):
            found.append(value)
        else:
            found.extend(_titles(value))
    return found


def compose_specs(product: dict[str, Any]) -> str:
    """High-signal spec text: attribute pairs, then feature titles."""
    pairs = [f"{name}: {value}" if name else value
             for name, value in attribute_pairs(product.get("attributes"))]
    return ATTRIBUTE_SEPARATOR.join(pairs + (feature_titles(product.get("feature")) or []))


def _clean_row(row: dict[str, Any]) -> dict[str, Any]:
    return {key: clean_text(val) if key in _TEXT_COLUMNS else val for key, val in row.items()}


def parse_csv(text: str) -> list[dict[str, Any]]:
    # Real HTML descriptions exceed the 128 KB default; sys.maxsize overflows a C long on Windows.
    try:
        csv.field_size_limit(_sys.maxsize)
    except OverflowError:
        csv.field_size_limit(2**31 - 1)
    return [dict(row) for row in csv.DictReader(io.StringIO(text))]


def parse_sql(sql: str) -> list[dict[str, Any]]:
    """Parse `INSERT INTO t (cols) VALUES (...), (...);` statements into rows.

    Quote-aware: handles '' escapes, commas/parens inside strings, NULL, and
    numeric literals. Only the columns named in each INSERT are used.
    """
    rows: list[dict[str, Any]] = []
    upper = sql.upper()
    cursor = 0
    while True:
        start = upper.find("INSERT INTO", cursor)
        if start == -1:
            break
        open_paren = sql.find("(", start)
        close_paren = sql.find(")", open_paren)
        columns = [c.strip().strip("`") for c in sql[open_paren + 1:close_paren].split(",")]
        values_at = upper.find("VALUES", close_paren) + len("VALUES")
        tuples, cursor = _scan_tuples(sql, values_at)
        for tup in tuples:
            if len(tup) != len(columns):
                raise ValueError(f"row has {len(tup)} values for {len(columns)} columns")
            rows.append(dict(zip(columns, tup, strict=True)))
    return rows


def _scan_tuples(sql: str, index: int) -> tuple[list[list[str | None]], int]:
    tuples: list[list[str | None]] = []
    length = len(sql)
    while index < length:
        while index < length and sql[index] in " \t\r\n,":
            index += 1
        if index >= length or sql[index] != "(":
            break
        tup, index = _scan_one_tuple(sql, index)
        tuples.append(tup)
    return tuples, index


def _scan_one_tuple(sql: str, index: int) -> tuple[list[str | None], int]:
    index += 1  # past '('
    values: list[str | None] = []
    chars: list[str] = []
    quoted = False
    in_string = False
    length = len(sql)
    while index < length:
        char = sql[index]
        if in_string:
            if char == "'":
                if index + 1 < length and sql[index + 1] == "'":
                    chars.append("'")
                    index += 2
                    continue
                in_string = False
                index += 1
                continue
            chars.append(char)
            index += 1
            continue
        if char == "'":
            in_string = True
            quoted = True
            index += 1
            continue
        if char in " \t\r\n":
            index += 1
            continue
        if char == ",":
            values.append(_finalize_field("".join(chars), quoted))
            chars, quoted = [], False
            index += 1
            continue
        if char == ")":
            values.append(_finalize_field("".join(chars), quoted))
            return values, index + 1
        chars.append(char)
        index += 1
    raise ValueError("unterminated VALUES tuple")


def _finalize_field(raw: str, quoted: bool) -> str | None:
    if quoted:
        return raw
    token = raw.strip()
    if token == "" or token.upper() == "NULL":
        return None
    return token


# --- Transform -------------------------------------------------------------

def compose_passage(
    product: dict[str, Any], desc_char_limit: int, passage_char_limit: int = 0
) -> str:
    """Bounded field embedded per product, in priority order: titles, brand,
    category, model, feature titles, attribute values, then a truncated
    description. At most passage_char_limit characters (0 = no cap) so it stays
    within the model's token limit: the description gives way first, then the
    tail of the specs. RAW text (not normalized), so the M4 client can embed
    the raw query without re-implementing the normalizer."""
    parts = [
        product.get("title_fa"),
        product.get("title_en"),
        product.get("brand"),
        product.get("category"),
        product.get("model"),
        *(feature_titles(product.get("feature")) or []),
        *(value for _, value in attribute_pairs(product.get("attributes"))),
    ]
    head = " ".join(str(p).strip() for p in parts if p is not None and str(p).strip())
    desc_chars = desc_char_limit
    if passage_char_limit > 0:
        cut = index_description(head, passage_char_limit)
        # A cut head leaves no room for the description, only a word gap.
        desc_chars = 0 if cut != head else min(desc_char_limit, passage_char_limit - len(head) - 1)
        head = cut
    desc = (str(product.get("desc") or ""))[:max(0, desc_chars)].strip()
    return " ".join(p for p in (head, desc) if p)


def index_description(description: str, max_chars: int) -> str:
    """Leading part of a description that is keyword-indexed.

    Cut back to whitespace so the last indexed token is never a fragment that a
    prefix match could hit (a mid-word cut may land after a diacritic or ZWNJ,
    which are not word characters). max_chars <= 0 keeps the whole text.
    """
    if max_chars <= 0 or len(description) <= max_chars:
        return description
    head = description[:max_chars]
    if not description[max_chars].isspace():
        head = re.sub(r"\S+$", "", head)
    return head.rstrip()


def to_server_row(product: dict[str, Any], desc_index_chars: int = 0) -> dict[str, Any]:
    title = f"{product.get('title_fa') or ''} {product.get('title_en') or ''}".strip()
    description = str(product.get("desc") or "")
    sku = str(product.get("sku") or "")
    normalized_sku = normalize_sku(sku)
    return {
        "product_id": int(product["id"]),
        "title": title,
        "description": description,
        # The SKU joins the title-weighted text so a partial code still finds
        # the product through FULLTEXT; exact/prefix hits rank first separately.
        "normalized_title": f"{normalize(title)} {normalized_sku}".strip(),
        "normalized_desc": normalize(index_description(description, desc_index_chars)),
        # Not capped like the description: specs are the high-signal text.
        "normalized_specs": normalize(compose_specs(product)),
        "brand": str(product.get("brand") or ""),
        "category": str(product.get("category") or ""),
        "model": str(product.get("model") or ""),
        "sku": sku,
        "normalized_sku": normalized_sku,
        "price": float(product.get("price") or 0),
        "stock": int(product.get("stock") or 0),
        "url": str(product.get("url") or ""),
        "image": str(product.get("image") or ""),
        "popularity": int(product.get("popularity") or 0),
    }


def staging_ddl(schema_sql: str, products_table: str, staging_table: str) -> str:
    """DROP + CREATE for the staging table, copied from the live table's
    definition in db/schema.sql. Comment lines are dropped so the statement can
    be split on ";" line ends without tracking comment syntax."""
    code = "\n".join(
        line for line in schema_sql.splitlines() if not line.strip().startswith("--")
    )
    match = re.search(
        rf"CREATE TABLE IF NOT EXISTS `?{re.escape(products_table)}`?\s*(\(.*?;)",
        code,
        re.DOTALL,
    )
    if match is None:
        raise ValueError(f"no CREATE TABLE for {products_table!r} in the schema")
    return (
        f"DROP TABLE IF EXISTS `{staging_table}`;\n"
        f"CREATE TABLE `{staging_table}` {match.group(1)}\n"
    )


def build_products_sql(
    server_rows: list[dict[str, Any]],
    staging_table: str,
    max_statement_bytes: int,
    ddl: str = "",
) -> str:
    """REPLACE statements for the staging table, each at most max_statement_bytes
    (a single oversized row still gets its own statement). One statement for a
    whole catalog exceeds a shared host's max_allowed_packet and fails the load.
    `ddl` (see staging_ddl) is emitted first, so the file recreates the table."""
    def quote(value: Any) -> str:
        if isinstance(value, (int, float)):
            return str(value)
        # MySQL treats backslash as an escape inside string literals by default.
        return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"

    header = (
        f"REPLACE INTO `{staging_table}`\n"
        "    (" + ", ".join(_SERVER_COLUMNS) + ")\nVALUES\n"
    )
    statements: list[str] = []
    batch: list[str] = []
    size = len(header.encode("utf-8"))
    for row in server_rows:
        tup = "    (" + ", ".join(quote(row[col]) for col in _SERVER_COLUMNS) + ")"
        tup_size = len(tup.encode("utf-8")) + 2  # ",\n" or ";\n"
        if batch and size + tup_size > max_statement_bytes:
            statements.append(header + ",\n".join(batch) + ";\n")
            batch, size = [], len(header.encode("utf-8"))
        batch.append(tup)
        size += tup_size
    if batch:
        statements.append(header + ",\n".join(batch) + ";\n")
    return "SET NAMES utf8mb4;\n\n" + (ddl + "\n" if ddl else "") + "\n".join(statements)


# --- Orchestration ---------------------------------------------------------

def warn_malformed_features(products: list[dict[str, Any]]) -> int:
    """Report (stderr) and return how many `feature` fields failed to parse.
    Those products are still indexed, just without their feature titles."""
    malformed = [str(p.get("id")) for p in products if feature_titles(p.get("feature")) is None]
    if malformed:
        print(f"Warning: {len(malformed)} products have a malformed `feature` field "
              f"(ids {', '.join(malformed[:10])}{', ...' if len(malformed) > 10 else ''}); "
              "their feature titles are not indexed.", file=_sys.stderr)
    return len(malformed)


def build_bundle(
    products: list[dict[str, Any]], out_dir: str | Path, config: dict[str, Any]
) -> dict[str, Any]:
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    # Read first: a malformed alias file fails the build before the slow embed.
    aliases = _keyword.load_aliases(config["build"].get("aliases_file", ""))

    dim = int(config["model"]["dim"])
    desc_limit = int(config["build"]["desc_char_limit"])
    passage_limit = int(config["build"].get("passage_char_limit", 0))
    products_table = config["build"]["products_table"]
    staging_table = f"{products_table}_new"

    desc_index_chars = int(config["build"].get("desc_index_chars", 0))
    server_rows = [to_server_row(p, desc_index_chars) for p in products]
    passages = [compose_passage(p, desc_limit, passage_limit) for p in products]

    embedder = create_embedder(config)
    vectors = embed_passages(embedder, passages).astype("<f4")
    if vectors.shape != (len(products), dim):
        raise ValueError(f"vectors shape {vectors.shape} != ({len(products)}, {dim})")

    vector_bytes = vectors.tobytes()
    (out / "vectors.bin").write_bytes(vector_bytes)
    (out / "vectors.idx").write_text(
        "".join(f"{row['product_id']}\n" for row in server_rows), encoding="utf-8"
    )
    (out / "products.load.sql").write_text(
        build_products_sql(
            server_rows,
            staging_table,
            int(config["build"]["max_statement_bytes"]),
            staging_ddl(SCHEMA_PATH.read_text(encoding="utf-8"), products_table, staging_table),
        ),
        encoding="utf-8",
    )
    (out / "synonyms.json").write_text(
        json.dumps(_keyword.build_synonyms(server_rows), ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    (out / "aliases.json").write_text(
        json.dumps(aliases, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    (out / "spellcheck.txt").write_text(
        "\n".join(_keyword.spellcheck_lines(_keyword.build_spellcheck(server_rows))) + "\n",
        encoding="utf-8",
    )
    (out / "keymap.json").write_text(
        json.dumps(_keyword.build_keymap(), ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    meta = {
        "model": config["model"]["name"],
        "revision": config["model"]["revision"],
        "dim": dim,
        "normalization_version": int(config["model"]["normalization_version"]),
        "count": len(products),
        "built_at": datetime.now(UTC).isoformat(),
        "checksum": hashlib.sha256(vector_bytes).hexdigest(),
        "embedder": config["embedder"],
    }
    (out / "meta.json").write_text(
        json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    return meta


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build the search index bundle.")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--sql", help="Path to a SQL export (INSERT statements).")
    source.add_argument("--csv", help="Path to a CSV export with the documented columns.")
    parser.add_argument("--out", required=True, help="Output bundle directory.")
    parser.add_argument("--aliases", help="Alias file (default SEARCH_ALIASES_FILE, else "
                                          "pipeline/aliases.json).")
    args = parser.parse_args(argv)

    config = pipeline_config.load()
    config["model"]["normalization_version"] = NORMALIZATION_VERSION
    if args.aliases:
        if not Path(args.aliases).is_file():
            parser.error(f"alias file not found: {args.aliases}")
        config["build"]["aliases_file"] = args.aliases
    try:
        _keyword.load_aliases(config["build"]["aliases_file"])
    except ValueError as exc:
        parser.error(str(exc))
    products = read_products(args.sql or args.csv)
    if not products:
        parser.error("no products found in the input")

    warn_malformed_features(products)
    meta = build_bundle(products, args.out, config)
    print(f"Built bundle: {meta['count']} products, dim {meta['dim']}, embedder {meta['embedder']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
