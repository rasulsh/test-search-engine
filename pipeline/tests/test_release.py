"""Tests for release.py: what release.zip carries, and what it never does."""

from __future__ import annotations

import json
import subprocess
import sys
import zipfile
from pathlib import Path

import pytest

import config as pipeline_config
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
                "data_incoming/old.txt", "tests/KeywordTest.php", "tools/eval.php",
                "tools/config-check.php"):
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
    assert not any(n.startswith(("data/", "tests/")) for n in names)
    # The one tool shipped: the config doctor, run after unzipping.
    assert [n for n in names if n.startswith("tools/")] == ["tools/config-check.php"]
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


def _seen_embedders(monkeypatch: pytest.MonkeyPatch, *args: str) -> dict[str, str]:
    seen: dict[str, str] = {}

    def fake_bundle(products, out_dir, config):  # type: ignore[no-untyped-def]
        seen["bundle"] = config["embedder"]
        return {"count": 0, "dim": 0, "embedder": config["embedder"]}

    def fake_vps(products, out_dir, config):  # type: ignore[no-untyped-def]
        seen["vps"] = config["embedder"]
        return {"count": 0, "model": "m", "dim": 0, "embedder": config["embedder"]}

    monkeypatch.setattr(release.build, "build_bundle", fake_bundle)
    monkeypatch.setattr(release.build, "build_vps_vectors", fake_vps)
    monkeypatch.setattr(release, "release_entries", lambda *a: [])
    monkeypatch.setattr(release, "write_zip", lambda *a: None)
    release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", *args])
    return seen


def test_release_vps_vectors_are_real_and_bundle_vectors_mock(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # EMBEDDER=mock in the shell must not leak mock vectors into the VPS; the
    # unused e5 bundle vectors default to the mock (M22).
    monkeypatch.setenv("EMBEDDER", "mock")
    monkeypatch.delenv("SEARCH_BUNDLE_EMBEDDER", raising=False)
    assert _seen_embedders(monkeypatch) == {"bundle": "mock", "vps": "real"}


def test_release_bundle_embedder_can_be_real(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("SEARCH_BUNDLE_EMBEDDER", "real")
    assert _seen_embedders(monkeypatch) == {"bundle": "real", "vps": "real"}


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


def test_one_command_builds_the_zip_and_the_vps_vectors(tmp_path: Path, dev_tree: Path) -> None:
    # M21: the VPS vectors come from the same run and export (so tags, brand and
    # category reach both tiers), next to the zip unless --vps-out says otherwise.
    _release(tmp_path, dev_tree)

    vps = tmp_path / "out" / "vps_vectors"
    assert sorted(p.name for p in vps.iterdir()) == ["meta.json", "vectors.bin", "vectors.idx"]
    vps_meta = json.loads((vps / "meta.json").read_text(encoding="utf-8"))
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        meta = json.loads(archive.read("data_incoming/meta.json"))
        bundle_ids = archive.read("data_incoming/vectors.idx").decode("utf-8")
        assert not any("vps" in n for n in archive.namelist())
    assert vps_meta["count"] == meta["count"] == 6
    assert (vps / "vectors.idx").read_text() == bundle_ids  # the same products, same row order


def test_no_vps_skips_the_vectors_and_the_flags_conflict(tmp_path: Path, dev_tree: Path) -> None:
    _release(tmp_path, dev_tree, "--no-vps")
    assert not (tmp_path / "out" / "vps_vectors").exists()

    with pytest.raises(SystemExit) as exc:
        _release(tmp_path, dev_tree, "--no-vps", "--vps-out", str(tmp_path / "x"))
    assert exc.value.code == 2


def test_release_prints_copy_paste_deploy_steps_for_both_targets(
    tmp_path: Path, dev_tree: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _release(tmp_path, dev_tree)
    text = capsys.readouterr().out

    assert "reload.php?load=1" in text and "X-Reload-Token" in text  # cPanel /reload
    assert "rsync -a --delete" in text and "/var/lib/search-vectors/incoming/" in text
    assert "https://VPS_HOST/reload" in text and "Authorization: Bearer $VPS_TOKEN" in text

    _release(tmp_path, dev_tree, "--no-vps")
    assert "skipped (--no-vps)" in capsys.readouterr().out


def test_release_bundle_stays_reload_compatible_with_the_live_server(
    tmp_path: Path, dev_tree: Path
) -> None:
    # M21 adds fields, not rules: the keys /reload checks (model, dim,
    # normalization_version) are what config.example.php and the pre-M21
    # releases carry; only count and checksum differ between catalogs.
    _release(tmp_path, dev_tree)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        meta = json.loads(archive.read("data_incoming/meta.json"))
    config = pipeline_config.load()["model"]

    assert (meta["model"], meta["dim"], meta["revision"]) == (
        config["name"], config["dim"], config["revision"])
    assert meta["normalization_version"] == NORMALIZATION_VERSION == 3
