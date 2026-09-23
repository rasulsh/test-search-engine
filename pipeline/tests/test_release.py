"""Tests for release.py: what release.zip carries, and what it never does."""

from __future__ import annotations

import json
import subprocess
import sys
import zipfile
from pathlib import Path

import pytest

import release

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"
BUNDLE_FILES = ("vectors.bin", "vectors.idx", "products.load.sql", "synonyms.json",
                "spellcheck.txt", "keymap.json", "meta.json")


@pytest.fixture()
def dev_tree(tmp_path: Path) -> tuple[Path, Path]:
    """A server/ + client/ tree as on a developer machine, with the files that
    must never ship (config.php, live data, local browser-asset copy)."""
    server = tmp_path / "server"
    for rel in ("bootstrap.php", "config.php", "config.example.php", "src/Keyword.php",
                "public/index.php", "public/reload.php", "public/test.html",
                "public/client/model/stale.onnx", "data/vectors.bin",
                "data_incoming/old.txt", "tests/KeywordTest.php", "tools/eval.php"):
        (server / rel).parent.mkdir(parents=True, exist_ok=True)
        (server / rel).write_text(rel, encoding="utf-8")
    client = tmp_path / "client"
    for rel in ("embedder.js", "tools/embed_queries.mjs", "model/m/onnx/model_quantized.onnx",
                "vendor/transformers.min.js", "fonts/Vazirmatn-Variable.woff2"):
        (client / rel).parent.mkdir(parents=True, exist_ok=True)
        (client / rel).write_text(rel, encoding="utf-8")
    return server, client


def _release(tmp_path: Path, tree: tuple[Path, Path], *extra: str) -> list[str]:
    out = tmp_path / "out" / "release.zip"
    code = release.main(["--sql", str(INPUT_SQL), "--out", str(out), "--mock",
                         "--server-dir", str(tree[0]), "--client-dir", str(tree[1]), *extra])
    assert code == 0
    with zipfile.ZipFile(out) as archive:
        assert archive.testzip() is None
        return sorted(archive.namelist())


def test_release_packs_bundle_under_data_incoming_and_the_server_code(
    tmp_path: Path, dev_tree: tuple[Path, Path]
) -> None:
    names = _release(tmp_path, dev_tree)

    assert sorted(n for n in names if n.startswith("data_incoming/")) == sorted(
        f"data_incoming/{f}" for f in BUNDLE_FILES
    )
    for code in ("bootstrap.php", "src/Keyword.php", "public/index.php",
                 "public/reload.php", "public/test.html", "public/client/embedder.js"):
        assert code in names
    # Never shipped: the server's own config, live data, dev-only files.
    assert not any(n.endswith("config.php") for n in names)
    assert "config.example.php" in names  # first-deploy template only
    assert not any(n.startswith(("data/", "tests/", "tools/")) for n in names)
    assert "data_incoming/old.txt" not in names
    # Routine release: no browser model or runtime.
    assert not any(n.startswith(("public/client/model/", "public/client/vendor/",
                                 "public/client/fonts/")) for n in names)
    assert not (tmp_path / "out" / "release.zip.tmp").exists()


def test_with_model_adds_the_browser_assets(tmp_path: Path, dev_tree: tuple[Path, Path]) -> None:
    names = _release(tmp_path, dev_tree, "--with-model")

    assert "public/client/model/m/onnx/model_quantized.onnx" in names
    assert "public/client/vendor/transformers.min.js" in names
    assert "public/client/fonts/Vazirmatn-Variable.woff2" in names
    assert "public/client/model/stale.onnx" not in names  # local copy never packed
    assert not any(n.startswith("public/client/tools/") for n in names)
    assert not any(n.endswith("config.php") for n in names)


def test_with_model_fails_without_fetched_assets(
    tmp_path: Path, dev_tree: tuple[Path, Path]
) -> None:
    server, client = dev_tree
    for part in ("model", "vendor", "fonts"):
        for f in (client / part).rglob("*"):
            if f.is_file():
                f.unlink()
    (client / "model" / "m" / "onnx").rmdir()
    (client / "model" / "m").rmdir()
    (client / "model").rmdir()
    out = tmp_path / "release.zip"
    with pytest.raises(FileNotFoundError, match="fetch_web_model"):
        release.main(["--sql", str(INPUT_SQL), "--out", str(out), "--mock", "--with-model",
                      "--server-dir", str(server), "--client-dir", str(client)])
    assert not out.exists()


def test_release_bundle_is_the_built_bundle(tmp_path: Path, dev_tree: tuple[Path, Path]) -> None:
    _release(tmp_path, dev_tree)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        meta = json.loads(archive.read("data_incoming/meta.json"))
        load_sql = archive.read("data_incoming/products.load.sql").decode("utf-8")
    assert meta["count"] == 6 and meta["embedder"] == "mock"
    assert "CREATE TABLE `products_new`" in load_sql  # self-contained staging load


def test_release_defaults_to_the_real_embedder(monkeypatch: pytest.MonkeyPatch) -> None:
    # EMBEDDER=mock in the shell must not leak mock vectors into a release.
    monkeypatch.setenv("EMBEDDER", "mock")
    seen: dict[str, str] = {}

    def fake_build(products, out_dir, config):  # type: ignore[no-untyped-def]
        seen["embedder"] = config["embedder"]
        raise SystemExit(0)

    monkeypatch.setattr(release.build, "build_bundle", fake_build)
    with pytest.raises(SystemExit):
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip"])
    assert seen["embedder"] == "real"


def test_real_repository_release_via_the_cli(tmp_path: Path) -> None:
    # Runs the script as an operator would, against the real server/ tree.
    out = tmp_path / "release.zip"
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "pipeline" / "release.py"),
         "--sql", str(INPUT_SQL), "--out", str(out), "--mock"],
        capture_output=True, text=True, check=False, cwd=tmp_path,
    )
    assert result.returncode == 0, result.stderr
    with zipfile.ZipFile(out) as archive:
        names = archive.namelist()
    assert "src/StagingLoader.php" in names and "public/reload.php" in names
    assert "data_incoming/meta.json" in names
    assert not any(n.endswith("config.php") for n in names)
    assert "reload.php?load=1" in result.stdout


def test_windows_wrapper_forwards_arguments() -> None:
    bat = (REPO_ROOT / "pipeline" / "release.bat").read_text(encoding="utf-8")
    assert 'python "%~dp0release.py" %*' in bat
    assert "exit /b %ERRORLEVEL%" in bat
