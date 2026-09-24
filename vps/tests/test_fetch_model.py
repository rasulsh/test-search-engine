"""fetch_model: pinned files, checksum verification, no partial files."""

from __future__ import annotations

import hashlib
import io
from pathlib import Path

import pytest

from search_vectors import fetch_model

FILES = {"config.json": b"{}", "tokenizer.json": b"tok", "onnx/m.onnx": b"weights"}


@pytest.fixture()
def served(monkeypatch: pytest.MonkeyPatch) -> dict:
    content = dict(FILES)
    monkeypatch.setitem(fetch_model.MODELS, "Test/model", {
        "repo": "Test/conv", "revision": "abc",
        "files": {n: hashlib.sha256(FILES[n]).hexdigest()
                  for n in ("config.json", "tokenizer.json")},
        "onnx": {"onnx/m.onnx": {"onnx/m.onnx": hashlib.sha256(FILES["onnx/m.onnx"]).hexdigest()}},
    })
    calls = []

    def urlopen(url: str, timeout: int):
        calls.append(url)
        return io.BytesIO(content[url.rsplit("/abc/", 1)[1]])

    monkeypatch.setattr(fetch_model.urllib.request, "urlopen", urlopen)
    return {"content": content, "calls": calls}


def test_downloads_pinned_files_then_reuses_them(tmp_path: Path, served: dict) -> None:
    fetched = fetch_model.fetch("Test/model", "onnx/m.onnx", tmp_path)
    assert sorted(fetched) == sorted(FILES)
    assert served["calls"][0] == "https://huggingface.co/Test/conv/resolve/abc/config.json"
    assert (tmp_path / "onnx" / "m.onnx").read_bytes() == b"weights"
    assert fetch_model.fetch("Test/model", "onnx/m.onnx", tmp_path) == []


def test_checksum_mismatch_leaves_no_file(tmp_path: Path, served: dict) -> None:
    served["content"]["onnx/m.onnx"] = b"tampered"
    with pytest.raises(SystemExit, match="sha256"):
        fetch_model.fetch("Test/model", "onnx/m.onnx", tmp_path)
    assert not (tmp_path / "onnx" / "m.onnx").exists()
    assert not list((tmp_path / "onnx").glob("*.part"))


def test_unpinned_model_or_file_is_refused(tmp_path: Path, served: dict) -> None:
    with pytest.raises(SystemExit, match="no pinned"):
        fetch_model.fetch("Other/model", "onnx/m.onnx", tmp_path)
    with pytest.raises(SystemExit, match="no pinned"):
        fetch_model.fetch("Test/model", "onnx/other.onnx", tmp_path)


def test_default_model_is_pinned_for_both_builds() -> None:
    entry = fetch_model.MODELS["BAAI/bge-m3"]
    assert set(entry["onnx"]) == {"onnx/model_quantized.onnx", "onnx/model.onnx"}
    for digest in [*entry["files"].values(),
                   *(d for files in entry["onnx"].values() for d in files.values())]:
        assert len(digest) == 64
