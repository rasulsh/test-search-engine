"""One-command release: catalog export in -> a single deployable release.zip.

    python pipeline/release.py --csv export.csv --out release.zip [--no-model]
        [--desc-index-chars N] [--aliases aliases.json]

Builds the bundle with the real embedder and packs, relative to the host's
`server/` directory, everything the site needs:

    data_incoming/        the bundle (vectors, products.load.sql, meta.json, ...)
    bootstrap.php, config.example.php, src/, public/ (incl. public/install.php)
    db/schema.sql         read by install.php on the first deploy
    public/client/embedder.js
    public/client/{model,vendor,fonts}/   left out with --no-model

The browser assets are fetched into client/ on first use (the same pinned,
checksum-verified download as tools/fetch_web_model.py) and reused afterwards.

config.php is never packed, so extracting over the server keeps its config.
First deploy: extract, then open install.php. Updates: extract, then POST
reload.php?load=1 (see README).
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
import importlib.util
import tempfile
import zipfile
from collections.abc import Iterator
from pathlib import Path
from types import ModuleType

import build
import config as pipeline_config
from normalize import NORMALIZATION_VERSION

REPO_ROOT = Path(__file__).resolve().parents[1]
# config.example.php is the template for a first deploy; config.php is never packed.
SERVER_CODE = ("bootstrap.php", "config.example.php", "src", "public")
# Large browser assets fetched by tools/fetch_web_model.py; --no-model skips them
# for routine updates when the host already has them.
CLIENT_ASSETS = ("model", "vendor", "fonts")
CLIENT_CODE = ("embedder.js",)


def _load_fetch_web_model() -> ModuleType:
    # tools/ is not a package; load the script by path so there is one fetcher.
    spec = importlib.util.spec_from_file_location(
        "fetch_web_model", Path(_HERE) / "tools" / "fetch_web_model.py"
    )
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


fetch_web_model = _load_fetch_web_model()


def _files(root: Path, relative: str) -> Iterator[tuple[Path, str]]:
    path = root / relative
    if path.is_file():
        yield path, relative
        return
    for file in sorted(p for p in path.rglob("*") if p.is_file()):
        yield file, file.relative_to(root).as_posix()


def release_entries(
    bundle_dir: Path, server_dir: Path, client_dir: Path, with_model: bool
) -> list[tuple[Path, str]]:
    """(source file, path inside the zip) for every file the release carries."""
    entries = [(f, f"data_incoming/{f.name}") for f in sorted(bundle_dir.iterdir()) if f.is_file()]
    entries.append((build.SCHEMA_PATH, "db/schema.sql"))
    for relative in SERVER_CODE:
        for source, arcname in _files(server_dir, relative):
            # public/client on a dev machine is the (gitignored) local copy of
            # the browser assets; the release adds them from client/ below.
            if not arcname.startswith("public/client/"):
                entries.append((source, arcname))

    client_parts = CLIENT_CODE + (CLIENT_ASSETS if with_model else ())
    for relative in client_parts:
        if not (client_dir / relative).exists():
            raise FileNotFoundError(f"{client_dir / relative} is missing")
        entries += [(s, f"public/client/{a}") for s, a in _files(client_dir, relative)]

    if any(Path(arcname).name == "config.php" for _, arcname in entries):
        raise RuntimeError("config.php must never be packed")
    return entries


def write_zip(entries: list[tuple[Path, str]], out: Path) -> None:
    """Write via a temporary file so a failed run never leaves a partial zip."""
    out.parent.mkdir(parents=True, exist_ok=True)
    tmp = out.with_name(out.name + ".tmp")
    with zipfile.ZipFile(tmp, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for source, arcname in entries:
            archive.write(source, arcname)
    _os.replace(tmp, out)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build the bundle and pack release.zip.")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--csv", help="CSV export with the documented columns.")
    source.add_argument("--sql", help="SQL export (INSERT statements).")
    parser.add_argument("--out", default="release.zip", help="Output zip (default release.zip).")
    parser.add_argument("--no-model", action="store_true",
                        help="Leave out the browser model/runtime/fonts (about 150 MB) for a "
                             "routine update when the host already has them.")
    parser.add_argument("--desc-index-chars", type=int, metavar="N",
                        help="Leading description characters keyword-indexed (default "
                             "SEARCH_DESC_INDEX_CHARS or 800; 0 = whole description). "
                             "Build-time only: the server never re-indexes.")
    parser.add_argument("--aliases", help="Alias file shipped in the bundle (default "
                                          "SEARCH_ALIASES_FILE, else pipeline/aliases.json).")
    parser.add_argument("--mock", action="store_true",
                        help="Use the mock embedder (tests only; never deploy).")
    parser.add_argument("--server-dir", default=str(REPO_ROOT / "server"), help=argparse.SUPPRESS)
    parser.add_argument("--client-dir", default=str(REPO_ROOT / "client"), help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    config = pipeline_config.load()
    config["model"]["normalization_version"] = NORMALIZATION_VERSION
    # A release must carry real vectors whatever EMBEDDER says in the shell.
    config["embedder"] = "mock" if args.mock else "real"
    if args.desc_index_chars is not None:
        if args.desc_index_chars < 0:
            parser.error("--desc-index-chars must be 0 or more")
        config["build"]["desc_index_chars"] = args.desc_index_chars
    if args.aliases:
        if not Path(args.aliases).is_file():
            parser.error(f"alias file not found: {args.aliases}")
        config["build"]["aliases_file"] = args.aliases
    try:
        build._keyword.load_aliases(config["build"]["aliases_file"])
    except ValueError as exc:
        parser.error(str(exc))

    products = build.read_products(args.csv or args.sql)
    if not products:
        parser.error("no products found in the input")
    build.warn_malformed_features(products)

    client_dir = Path(args.client_dir)
    if not args.no_model:
        # Before the (slow) embedding run, so a failed download fails fast.
        try:
            fetched = fetch_web_model.fetch_all(client_dir)
        except (fetch_web_model.FetchError, OSError) as exc:
            print(f"release failed: could not fetch the browser model: {exc}\n"
                  "Check the network and retry, or pass --no-model if the host "
                  "already has the model.", file=_sys.stderr)
            return 1
        if fetched:
            print(f"Fetched {len(fetched)} browser asset file(s) into {client_dir}")

    out = Path(args.out).resolve()
    with tempfile.TemporaryDirectory() as tmp:
        meta = build.build_bundle(products, tmp, config)
        entries = release_entries(
            Path(tmp), Path(args.server_dir), client_dir, not args.no_model
        )
        write_zip(entries, out)

    print(f"Built {out.name}: {meta['count']} products, dim {meta['dim']}, "
          f"embedder {meta['embedder']}, {len(entries)} files"
          + (", without browser model" if args.no_model else ", with browser model"))
    print("Deploy: extract it in the service directory on the host (config.php is "
          "never in the zip).")
    print("  First deploy: open install.php in the browser and fill in the form.")
    print("  Updates: POST reload.php?load=1 with the X-Reload-Token header.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
