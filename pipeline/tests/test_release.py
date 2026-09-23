"""Tests for release.py: what release.zip carries, and what it never does."""

from __future__ import annotations

import base64
import functools
import hashlib
import io
import json
import subprocess
import sys
import tarfile
import zipfile
from pathlib import Path

import pytest

import config as pipeline_config
import release

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"
BUNDLE_FILES = ("vectors.bin", "vectors.idx", "products.load.sql", "synonyms.json",
                "spellcheck.txt", "keymap.json", "meta.json")


def _tarball(members: dict[str, bytes]) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as archive:
        for name, data in members.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
    return buffer.getvalue()


def _sri(data: bytes) -> str:
    return "sha512-" + base64.b64encode(hashlib.sha512(data).digest()).decode()


@pytest.fixture()
def fetches(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    """Serve small stand-ins for the pinned browser assets instead of the network;
    returns the URLs requested so tests can assert what was (not) downloaded."""
    fwm = release.fetch_web_model
    model = pipeline_config.load()["model"]
    runtime = _tarball({"package/dist/transformers.min.js": b"runtime"})
    font = _tarball({"package/fonts/v.woff2": b"font"})
    model_files = {
        "config.json": json.dumps(
            {"_name_or_path": model["name"], "hidden_size": int(model["dim"])}
        ).encode(),
        "onnx/model_quantized.onnx": b"onnx",
    }
    served = {
        "https://example/runtime.tgz": runtime,
        "https://example/font.tgz": font,
        **{f"https://huggingface.co/Example/conv/resolve/abc/{rel}": data
           for rel, data in model_files.items()},
    }
    monkeypatch.setattr(fwm, "TRANSFORMERS_TARBALL", {
        "url": "https://example/runtime.tgz", "integrity": _sri(runtime),
        "files": {"package/dist/transformers.min.js": "vendor/transformers.min.js"},
    })
    monkeypatch.setattr(fwm, "FONT_TARBALL", {
        "url": "https://example/font.tgz", "integrity": _sri(font),
        "files": {"package/fonts/v.woff2": "fonts/Vazirmatn-Variable.woff2"},
    })
    monkeypatch.setitem(fwm.WEB_MODELS, model["name"], {
        "repo": "Example/conv", "revision": "abc",
        "files": {rel: hashlib.sha256(data).hexdigest() for rel, data in model_files.items()},
    })
    calls: list[str] = []

    def fetch(url: str) -> bytes:
        calls.append(url)
        return served[url]

    monkeypatch.setattr(fwm, "fetch_all", functools.partial(fwm.fetch_all, fetch=fetch))
    return calls


@pytest.fixture()
def dev_tree(tmp_path: Path, fetches: list[str]) -> tuple[Path, Path]:
    """A server/ + client/ tree as on a developer machine, with the files that
    must never ship (config.php, live data, local browser-asset copy). The
    browser assets are not fetched yet."""
    server = tmp_path / "server"
    for rel in ("bootstrap.php", "config.php", "config.example.php", "src/Keyword.php",
                "public/index.php", "public/reload.php", "public/test.html", "public/install.php",
                "public/client/model/stale.onnx", "data/vectors.bin",
                "data_incoming/old.txt", "tests/KeywordTest.php", "tools/eval.php"):
        (server / rel).parent.mkdir(parents=True, exist_ok=True)
        (server / rel).write_text(rel, encoding="utf-8")
    client = tmp_path / "client"
    for rel in ("embedder.js", "tools/embed_queries.mjs"):
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
    names = _release(tmp_path, dev_tree, "--no-model")

    assert sorted(n for n in names if n.startswith("data_incoming/")) == sorted(
        f"data_incoming/{f}" for f in BUNDLE_FILES
    )
    for code in ("bootstrap.php", "src/Keyword.php", "public/index.php",
                 "public/reload.php", "public/test.html", "public/client/embedder.js"):
        assert code in names
    # Never shipped: the server's own config, live data, dev-only files.
    assert not any(n.endswith("config.php") for n in names)
    assert "config.example.php" in names  # install.php's template
    assert not any(n.startswith(("data/", "tests/", "tools/")) for n in names)
    assert "data_incoming/old.txt" not in names
    # --no-model: no browser model or runtime.
    assert not any(n.startswith(("public/client/model/", "public/client/vendor/",
                                 "public/client/fonts/")) for n in names)
    assert not (tmp_path / "out" / "release.zip.tmp").exists()


def test_release_is_self_contained_by_default(
    tmp_path: Path, dev_tree: tuple[Path, Path], fetches: list[str]
) -> None:
    # No prior fetch_web_model.py run: the release downloads the assets itself.
    names = _release(tmp_path, dev_tree)

    model_dir = f"public/client/model/{pipeline_config.load()['model']['name']}"
    assert f"{model_dir}/onnx/model_quantized.onnx" in names
    assert f"{model_dir}/config.json" in names
    assert "public/client/vendor/transformers.min.js" in names
    assert "public/client/fonts/Vazirmatn-Variable.woff2" in names
    assert "public/client/embedder.js" in names
    assert len(fetches) == 4
    assert "public/client/model/stale.onnx" not in names  # local copy never packed
    assert not any(n.startswith("public/client/tools/") for n in names)
    assert not any(n.endswith("config.php") for n in names)
    # First deploy needs nothing else: the web installer, its template, the schema.
    assert {"public/install.php", "config.example.php", "db/schema.sql"} <= set(names)
    with zipfile.ZipFile(tmp_path / "out" / "release.zip") as archive:
        schema = archive.read("db/schema.sql").decode("utf-8")
        assert archive.read(f"{model_dir}/onnx/model_quantized.onnx") == b"onnx"
    assert schema == (REPO_ROOT / "db" / "schema.sql").read_text(encoding="utf-8")


def test_fetched_assets_are_cached_between_releases(
    tmp_path: Path, dev_tree: tuple[Path, Path], fetches: list[str]
) -> None:
    first = _release(tmp_path, dev_tree)
    assert fetches
    fetches.clear()

    assert _release(tmp_path, dev_tree) == first
    assert fetches == []


def test_no_model_release_never_fetches(
    tmp_path: Path, dev_tree: tuple[Path, Path], fetches: list[str]
) -> None:
    _release(tmp_path, dev_tree, "--no-model")
    assert fetches == []
    assert not (dev_tree[1] / "model").exists()


def test_failed_fetch_fails_the_release_before_building(
    tmp_path: Path, dev_tree: tuple[Path, Path], monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    fwm = release.fetch_web_model

    def offline(url: str) -> bytes:
        raise OSError("network unreachable")

    monkeypatch.setattr(fwm, "fetch_all", functools.partial(fwm.fetch_all.func, fetch=offline))
    monkeypatch.setattr(release.build, "build_bundle", lambda *a: pytest.fail("built"))
    out = tmp_path / "release.zip"
    code = release.main(["--sql", str(INPUT_SQL), "--out", str(out), "--mock",
                         "--server-dir", str(dev_tree[0]), "--client-dir", str(dev_tree[1])])
    assert code == 1
    assert not out.exists()
    err = capsys.readouterr().err
    assert "network unreachable" in err and "--no-model" in err


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
        release.main(["--sql", str(INPUT_SQL), "--out", "unused.zip", "--no-model"])
    assert seen["embedder"] == "real"


def test_real_repository_release_via_the_cli(tmp_path: Path) -> None:
    # Runs the script as an operator would, against the real server/ tree.
    out = tmp_path / "release.zip"
    # --no-model: CI has no fetched browser assets.
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "pipeline" / "release.py"),
         "--sql", str(INPUT_SQL), "--out", str(out), "--mock", "--no-model"],
        capture_output=True, text=True, check=False, cwd=tmp_path,
    )
    assert result.returncode == 0, result.stderr
    with zipfile.ZipFile(out) as archive:
        names = archive.namelist()
    assert "src/StagingLoader.php" in names and "public/reload.php" in names
    assert "src/Installer.php" in names and "public/install.php" in names
    assert "db/schema.sql" in names and "data_incoming/meta.json" in names
    assert not any(n.endswith("config.php") for n in names)
    # The printed hint is the real flow, without stale example paths.
    assert "install.php" in result.stdout and "reload.php?load=1" in result.stdout
    assert "~/search-service" not in result.stdout and "<shop>" not in result.stdout


def test_windows_wrapper_forwards_arguments() -> None:
    bat = (REPO_ROOT / "pipeline" / "release.bat").read_text(encoding="utf-8")
    assert 'python "%~dp0release.py" %*' in bat
    assert "exit /b %ERRORLEVEL%" in bat
