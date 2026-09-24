"""Tests for release.py: what release.zip carries, and what it never does."""

from __future__ import annotations

import json
import subprocess
import sys
import zipfile
from pathlib import Path

import pytest

import release
from normalize import NORMALIZATION_VERSION

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"
BUNDLE_FILES = ("vectors.bin", "vectors.idx", "products.load.sql", "synonyms.json",
                "aliases.json", "spellcheck.txt", "keymap.json", "meta.json")


@pytest.fixture()
def dev_tree(tmp_path: Path) -> Path:
    """A server/ tree as on a developer machine, with the files that must never
    ship (config.php, live data, a leftover pre-M18 browser-model copy)."""
    server = tmp_path / "server"
    for rel in ("bootstrap.php", "config.php", "config.example.php", "src/Keyword.php",
                "public/index.php", "public/reload.php", "public/test.html", "public/install.php",
                "public/client/model/stale.onnx", "data/vectors.bin",
                "data_incoming/old.txt", "tests/KeywordTest.php", "tools/eval.php"):
        (server / rel).parent.mkdir(parents=True, exist_ok=True)
        (server / rel).write_text(rel, encoding="utf-8")
    return server


def _release(tmp_path: Path, tree: Path, *extra: str) -> list[str]:
    out = tmp_path / "out" / "release.zip"
    code = release.main(["--sql", str(INPUT_SQL), "--out", str(out), "--mock",
                         "--server-dir", str(tree), *extra])
    assert code == 0
    with zipfile.ZipFile(out) as archive:
        assert archive.testzip() is None
        return sorted(archive.namelist())


def test_release_packs_bundle_under_data_incoming_and_the_server_code(
    tmp_path: Path, dev_tree: Path
) -> None:
    names = _release(tmp_path, dev_tree)

    assert sorted(n for n in names if n.startswith("data_incoming/")) == sorted(
        f"data_incoming/{f}" for f in BUNDLE_FILES
    )
    for code in ("bootstrap.php", "src/Keyword.php", "public/index.php",
                 "public/reload.php", "public/test.html"):
        assert code in names
    # Never shipped: the server's own config, live data, dev-only files.
    assert not any(n.endswith("config.php") for n in names)
    assert "config.example.php" in names  # install.php's template
    assert not any(n.startswith(("data/", "tests/", "tools/")) for n in names)
    assert "data_incoming/old.txt" not in names
    assert not (tmp_path / "out" / "release.zip.tmp").exists()
    # First deploy needs nothing else: the web installer, its template, the schema.
    assert {"public/install.php", "config.example.php", "db/schema.sql"} <= set(names)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        schema = archive.read("db/schema.sql").decode("utf-8")
    assert schema == (REPO_ROOT / "db" / "schema.sql").read_text(encoding="utf-8")


def test_release_ships_no_browser_model(tmp_path: Path, dev_tree: Path) -> None:
    # M18: queries are embedded on the VPS; a leftover local copy never ships,
    # and --no-model is still accepted (a no-op) for existing scripts.
    for extra in ((), ("--no-model",)):
        names = _release(tmp_path, dev_tree, *extra)
        assert not any(n.startswith("public/client/") for n in names)
        assert not any(n.endswith((".onnx", "transformers.min.js", "embedder.js")) for n in names)


def test_desc_index_chars_is_a_release_option(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("SEARCH_DESC_INDEX_CHARS", "400")
    seen: dict[str, int] = {}

    def fake_build(products, out_dir, config):  # type: ignore[no-untyped-def]
        seen["chars"] = config["build"]["desc_index_chars"]
        raise SystemExit(0)

    monkeypatch.setattr(release.build, "build_bundle", fake_build)
    with pytest.raises(SystemExit):
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", "--no-model",
                      "--desc-index-chars", "120"])
    assert seen["chars"] == 120
    with pytest.raises(SystemExit):
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", "--no-model"])
    assert seen["chars"] == 400
    with pytest.raises(SystemExit) as exc:
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", "--no-model",
                      "--desc-index-chars", "-1"])
    assert exc.value.code == 2


def test_release_bundle_is_the_built_bundle(tmp_path: Path, dev_tree: Path) -> None:
    _release(tmp_path, dev_tree)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        meta = json.loads(archive.read("data_incoming/meta.json"))
        load_sql = archive.read("data_incoming/products.load.sql").decode("utf-8")
    assert meta["count"] == 6 and meta["embedder"] == "mock"
    assert "CREATE TABLE `products_new`" in load_sql  # self-contained staging load


def test_release_stamps_the_code_version_whatever_the_environment_says(
    tmp_path: Path, dev_tree: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    # M15.1: the server's config tracks Normalizer::VERSION, so the release must
    # carry the code's version, never a stale SEARCH_NORMALIZATION_VERSION.
    monkeypatch.setattr(release, "NORMALIZATION_VERSION", NORMALIZATION_VERSION + 1)
    monkeypatch.setenv("SEARCH_NORMALIZATION_VERSION", str(NORMALIZATION_VERSION))
    _release(tmp_path, dev_tree)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        meta = json.loads(archive.read("data_incoming/meta.json"))
    assert meta["normalization_version"] == NORMALIZATION_VERSION + 1


def test_release_ships_the_alias_file(tmp_path: Path, dev_tree: Path) -> None:
    aliases = tmp_path / "my_aliases.json"
    aliases.write_text('[["GTA", "Grand Theft Auto"]]', encoding="utf-8")
    _release(tmp_path, dev_tree, "--aliases", str(aliases))
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        shipped = json.loads(archive.read("data_incoming/aliases.json"))
    assert shipped == [["gta", "grand theft auto"]]


def test_release_rejects_a_missing_or_malformed_alias_file(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    broken = tmp_path / "aliases.json"
    broken.write_text('[["gta", "grand theft auto"],]', encoding="utf-8")
    for path in (tmp_path / "absent.json", broken):
        with pytest.raises(SystemExit) as exc:
            release.main(["--sql", str(INPUT_SQL), "--out", str(tmp_path / "r.zip"),
                          "--no-model", "--aliases", str(path)])
        assert exc.value.code == 2
    assert "invalid JSON" in capsys.readouterr().err
    assert not (tmp_path / "r.zip").exists()


def test_release_defaults_to_the_real_embedder(monkeypatch: pytest.MonkeyPatch) -> None:
    # EMBEDDER=mock in the shell must not leak mock vectors into a release.
    monkeypatch.setenv("EMBEDDER", "mock")
    seen: dict[str, str] = {}

    def fake_build(products, out_dir, config):  # type: ignore[no-untyped-def]
        seen["embedder"] = config["embedder"]
        raise SystemExit(0)

    monkeypatch.setattr(release.build, "build_bundle", fake_build)
    with pytest.raises(SystemExit):
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", "--no-model"])
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
    assert "src/Installer.php" in names and "public/install.php" in names
    assert "db/schema.sql" in names and "data_incoming/meta.json" in names
    assert not any(n.endswith("config.php") for n in names)
    assert not any(n.startswith("public/client/") for n in names)
    # The printed hint is the real flow, without stale example paths.
    assert "install.php" in result.stdout and "reload.php?load=1" in result.stdout
    assert "~/search-service" not in result.stdout and "<shop>" not in result.stdout


def test_windows_wrapper_forwards_arguments() -> None:
    bat = (REPO_ROOT / "pipeline" / "release.bat").read_text(encoding="utf-8")
    assert 'python "%~dp0release.py" %*' in bat
    assert "exit /b %ERRORLEVEL%" in bat
