"""pipeline/tools/fetch_web_model.py: pinned, verified, correctly placed assets.

No network: a fake fetcher serves synthetic tarballs/files, so CI checks the
integrity checks, the output layout the test page expects, and the model-parity
guard (the fetched model must be the configured one at the configured dim).
"""

from __future__ import annotations

import base64
import hashlib
import importlib.util
import io
import json
import re
import tarfile
from pathlib import Path

import pytest

import config as pipeline_config

REPO_ROOT = Path(__file__).resolve().parents[2]
TEST_PAGE = REPO_ROOT / "server" / "public" / "test.html"

_spec = importlib.util.spec_from_file_location(
    "fetch_web_model", REPO_ROOT / "pipeline" / "tools" / "fetch_web_model.py"
)
assert _spec is not None and _spec.loader is not None
fwm = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(fwm)


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


def _model_files(model_id: str, dim: int) -> dict[str, bytes]:
    return {
        "config.json": json.dumps({"_name_or_path": model_id, "hidden_size": dim}).encode(),
        "tokenizer.json": b"{}",
        "onnx/model_quantized.onnx": b"onnx-bytes",
    }


def _install_fake_model(monkeypatch, model_id: str, files: dict[str, bytes]) -> None:
    monkeypatch.setitem(
        fwm.WEB_MODELS,
        model_id,
        {
            "repo": "Example/conv",
            "revision": "abc123",
            "files": {rel: hashlib.sha256(data).hexdigest() for rel, data in files.items()},
        },
    )


def test_tarball_members_land_at_their_targets(tmp_path: Path) -> None:
    blob = _tarball({"package/dist/a.js": b"js", "package/dist/b.wasm": b"\0asm"})
    spec = {
        "url": "https://example/pkg.tgz",
        "integrity": _sri(blob),
        "files": {"package/dist/a.js": "vendor/a.js", "package/dist/b.wasm": "vendor/b.wasm"},
    }
    calls: list[str] = []

    def fetch(url: str) -> bytes:
        calls.append(url)
        return blob

    fwm.fetch_tarball(spec, tmp_path, fetch, force=False)
    assert (tmp_path / "vendor/a.js").read_bytes() == b"js"
    assert (tmp_path / "vendor/b.wasm").read_bytes() == b"\0asm"

    # Idempotent: present files are not downloaded again.
    assert fwm.fetch_tarball(spec, tmp_path, fetch, force=False) == []
    assert len(calls) == 1


def test_tarball_integrity_mismatch_writes_nothing(tmp_path: Path) -> None:
    blob = _tarball({"package/x.js": b"x"})
    spec = {"url": "u", "integrity": _sri(b"other"), "files": {"package/x.js": "vendor/x.js"}}
    with pytest.raises(fwm.FetchError, match="integrity"):
        fwm.fetch_tarball(spec, tmp_path, lambda _: blob, force=False)
    assert not (tmp_path / "vendor").exists()


def test_model_files_are_pinned_and_placed_under_model_id(tmp_path, monkeypatch) -> None:
    files = _model_files("org/model-x", 8)
    _install_fake_model(monkeypatch, "org/model-x", files)
    urls: list[str] = []

    def fetch(url: str) -> bytes:
        urls.append(url)
        return files[url.split("/resolve/abc123/", 1)[1]]

    fwm.fetch_model("org/model-x", 8, tmp_path, fetch, force=False)
    model_dir = tmp_path / "model" / "org/model-x"
    assert (model_dir / "onnx/model_quantized.onnx").read_bytes() == b"onnx-bytes"
    assert all(u.startswith("https://huggingface.co/Example/conv/resolve/abc123/") for u in urls)

    # Verified files already on disk are skipped.
    urls.clear()
    fwm.fetch_model("org/model-x", 8, tmp_path, fetch, force=False)
    assert urls == []


def test_model_checksum_mismatch_is_rejected(tmp_path, monkeypatch) -> None:
    files = _model_files("org/model-x", 8)
    _install_fake_model(monkeypatch, "org/model-x", files)
    with pytest.raises(fwm.FetchError, match="sha256 mismatch"):
        fwm.fetch_model("org/model-x", 8, tmp_path, lambda _: b"tampered", force=False)


@pytest.mark.parametrize(
    ("name", "dim", "error"),
    [("other/model", 8, "conversion of"), ("org/model-x", 16, "hidden_size")],
)
def test_model_must_match_configured_name_and_dim(tmp_path, monkeypatch, name, dim, error) -> None:
    files = _model_files(name, dim)
    _install_fake_model(monkeypatch, "org/model-x", files)
    with pytest.raises(fwm.FetchError, match=error):
        fwm.fetch_model(
            "org/model-x", 8, tmp_path, lambda url: files[url.split("/abc123/", 1)[1]], False
        )


def test_unknown_model_is_refused(tmp_path: Path) -> None:
    with pytest.raises(fwm.FetchError, match="no pinned browser conversion"):
        fwm.fetch_model("nobody/unknown", 8, tmp_path, lambda _: b"", force=False)


def test_configured_model_has_a_pinned_browser_conversion() -> None:
    model = pipeline_config.load()["model"]
    spec = fwm.WEB_MODELS[model["name"]]
    assert re.fullmatch(r"[0-9a-f]{40}", spec["revision"]), "pin a commit, not a branch"
    assert "onnx/model_quantized.onnx" in spec["files"]
    assert all(re.fullmatch(r"[0-9a-f]{64}", h) for h in spec["files"].values())


def test_test_page_references_exactly_the_fetched_layout() -> None:
    page = TEST_PAGE.read_text("utf-8")
    produced = set(fwm.TRANSFORMERS_TARBALL["files"].values()) | set(
        fwm.FONT_TARBALL["files"].values()
    )
    assert "vendor/transformers.min.js" in produced
    assert "fonts/Vazirmatn-Variable.woff2" in produced
    assert "./client/fonts/Vazirmatn-Variable.woff2" in page
    assert "new URL('./client/', location.href)" in page
    for rel in ("vendor/transformers.min.js", "embedder.js", "model/", "vendor/"):
        assert f"'{rel}', CLIENT_BASE" in page, rel
    assert fwm.DEFAULT_OUT == REPO_ROOT / "client"


def test_test_page_is_same_origin_and_relative() -> None:
    page = TEST_PAGE.read_text("utf-8")
    # No absolute or protocol-relative URL anywhere: no CDN, font host, or HF.
    assert not re.search(r"https?://", page)
    assert not re.search(r"""["'(]//[a-z]""", page, re.IGNORECASE)
    assert "const SEARCH_URL = './search.php';" in page
    assert "allowRemoteModels = false" in page
    assert "with_details: true" in page
    assert "link.rel = 'noopener'" in page and "link.target = '_blank'" in page
