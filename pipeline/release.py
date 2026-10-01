"""One-command release: catalog export in -> a single deployable release.zip.

    python pipeline/release.py --csv export.csv --out release.zip
        [--desc-index-chars N] [--aliases aliases.json] [--vps-out DIR | --no-vps]

Builds the bundle with the real embedder and packs, relative to the host's
`server/` directory, everything the site needs:

    data_incoming/        the bundle (vectors, products.load.sql, meta.json, ...)
    bootstrap.php, config.example.php, src/, public/ (incl. public/install.php)
    db/schema.sql         read by install.php on the first deploy

No browser model ships (M18): queries are embedded on the VPS.

The same run also writes the bge-m3 product vectors for the VPS vector service
(vps/README.md), from the same export (tags, brand and category included in the
embedded passages), to --vps-out DIR (default: vps_vectors/ next to the zip);
--no-vps skips them. They are not part of release.zip (a different host).

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
import tempfile
import zipfile
from collections.abc import Iterator
from pathlib import Path

import build
import config as pipeline_config
from normalize import NORMALIZATION_VERSION

REPO_ROOT = Path(__file__).resolve().parents[1]
# config.example.php is the template for a first deploy; config.php is never packed.
SERVER_CODE = ("bootstrap.php", "config.example.php", "src", "public")


def _files(root: Path, relative: str) -> Iterator[tuple[Path, str]]:
    path = root / relative
    if path.is_file():
        yield path, relative
        return
    for file in sorted(p for p in path.rglob("*") if p.is_file()):
        yield file, file.relative_to(root).as_posix()


def release_entries(bundle_dir: Path, server_dir: Path) -> list[tuple[Path, str]]:
    """(source file, path inside the zip) for every file the release carries."""
    entries = [(f, f"data_incoming/{f.name}") for f in sorted(bundle_dir.iterdir()) if f.is_file()]
    entries.append((build.SCHEMA_PATH, "db/schema.sql"))
    for relative in SERVER_CODE:
        for source, arcname in _files(server_dir, relative):
            # public/client is a leftover local copy of the pre-M18 browser model.
            if not arcname.startswith("public/client/"):
                entries.append((source, arcname))

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


def deploy_help(zip_path: Path, vps_dir: Path | None) -> str:
    """Copy-paste deploy steps for the artifacts this run produced. Placeholders
    in capitals are the operator's; nothing here is run by release.py."""
    lines = [
        "",
        f"Deploy 1/2 - cPanel ({zip_path.name}); config.php is never in the zip:",
        "  First deploy: extract it in the service directory, open install.php in the browser.",
        f"  Update: unzip -o {zip_path.name} -d SERVICE_DIR   (or use the File Manager)",
        '          curl -s -X POST "https://YOUR-SITE/SERVICE_PATH/reload.php?load=1" \\',
        '               -H "X-Reload-Token: $RELOAD_TOKEN"',
    ]
    if vps_dir is None:
        lines.append("Deploy 2/2 - VPS: skipped (--no-vps).")
    else:
        lines += [
            f"Deploy 2/2 - VPS vectors ({vps_dir}):",
            f"  rsync -a --delete {vps_dir}/ root@VPS:/var/lib/search-vectors/incoming/",
            "  ssh root@VPS chown -R searchvec: /var/lib/search-vectors/incoming",
            '  curl -s -X POST https://VPS_HOST/reload -H "Authorization: Bearer $VPS_TOKEN"',
        ]
    lines.append("Reload cPanel and the VPS from the same export; either order is safe.")

    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build the bundle and pack release.zip.")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--csv", help="CSV export with the documented columns.")
    source.add_argument("--sql", help="SQL export (INSERT statements).")
    parser.add_argument("--out", default="release.zip", help="Output zip (default release.zip).")
    # No-op since M18 (no browser model ships); accepted so old habits still work.
    parser.add_argument("--no-model", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--desc-index-chars", type=int, metavar="N",
                        help="Leading description characters keyword-indexed (default "
                             "SEARCH_DESC_INDEX_CHARS or 800; 0 = whole description). "
                             "Build-time only: the server never re-indexes.")
    parser.add_argument("--aliases", help="Alias file shipped in the bundle (default "
                                          "SEARCH_ALIASES_FILE, else pipeline/aliases.json).")
    vps = parser.add_mutually_exclusive_group()
    vps.add_argument("--vps-out", metavar="DIR",
                     help="Write the VPS product vectors (vps/README.md) to DIR (default: "
                          "vps_vectors/ next to the zip).")
    vps.add_argument("--no-vps", action="store_true",
                     help="Build only release.zip, not the VPS vectors.")
    parser.add_argument("--mock", action="store_true",
                        help="Use the mock embedder (tests only; never deploy).")
    parser.add_argument("--server-dir", default=str(REPO_ROOT / "server"), help=argparse.SUPPRESS)
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

    out = Path(args.out).resolve()
    with tempfile.TemporaryDirectory() as tmp:
        meta = build.build_bundle(products, tmp, config)
        entries = release_entries(Path(tmp), Path(args.server_dir))
        write_zip(entries, out)
    vps_dir = None if args.no_vps else Path(args.vps_out or out.parent / "vps_vectors").resolve()
    if vps_dir is not None:
        vps_meta = build.build_vps_vectors(products, vps_dir, config)
        print(f"Built VPS vectors in {vps_dir}: {vps_meta['count']} products, "
              f"{vps_meta['model']}, dim {vps_meta['dim']}, embedder {vps_meta['embedder']}")

    print(f"Built {out.name}: {meta['count']} products, dim {meta['dim']}, "
          f"embedder {meta['embedder']}, {len(entries)} files")
    print(deploy_help(out, vps_dir))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
