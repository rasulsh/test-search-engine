"""build.py --vps-out / release.py --vps-out: the VPS product-vector files."""

from __future__ import annotations

import hashlib
import json
import sys
import types
import zipfile
from pathlib import Path

import numpy as np
import pytest

import build
import config as pipeline_config
import embed
import release
from embed import MockEmbedder

REPO_ROOT = Path(__file__).resolve().parents[2]
INPUT_SQL = REPO_ROOT / "fixtures" / "products.input.sql"


@pytest.fixture()
def mock_config(monkeypatch: pytest.MonkeyPatch) -> dict:
    monkeypatch.setenv("EMBEDDER", "mock")
    return pipeline_config.load()


def test_vps_vectors_format(tmp_path: Path, mock_config: dict) -> None:
    products = build.read_products(INPUT_SQL)
    meta = build.build_vps_vectors(products, tmp_path, mock_config)
    model = mock_config["vps_model"]

    raw = (tmp_path / "vectors.bin").read_bytes()
    vectors = np.frombuffer(raw, dtype="<f4").reshape(len(products), model["dim"])
    np.testing.assert_allclose(np.linalg.norm(vectors, axis=1), 1.0, atol=1e-5)
    ids = (tmp_path / "vectors.idx").read_text(encoding="utf-8").splitlines()
    assert ids == [str(int(p["id"])) for p in products]
    assert json.loads((tmp_path / "meta.json").read_text(encoding="utf-8")) == meta
    assert meta == {
        "model": "BAAI/bge-m3",
        "revision": model["revision"],
        "dim": 1024,
        "pooling": "cls",
        "query_prefix": "",
        "passage_prefix": "",
        "count": len(products),
        "built_at": meta["built_at"],
        "checksum": hashlib.sha256(raw).hexdigest(),
        "embedder": "mock",
    }
    # Rows embed the same passage text as the cPanel bundle, with the VPS
    # model's (empty) passage prefix.
    passage = build.compose_passage(products[0], int(mock_config["build"]["desc_char_limit"]),
                                    int(mock_config["build"]["passage_char_limit"]))
    np.testing.assert_array_equal(vectors[0], MockEmbedder(1024).embed([passage])[0])


def test_vps_vectors_are_deterministic(tmp_path: Path, mock_config: dict) -> None:
    products = build.read_products(INPUT_SQL)
    build.build_vps_vectors(products, tmp_path / "a", mock_config)
    build.build_vps_vectors(products, tmp_path / "b", mock_config)
    for name in ("vectors.bin", "vectors.idx"):
        assert (tmp_path / "a" / name).read_bytes() == (tmp_path / "b" / name).read_bytes()


def test_build_cli_vps_out_only(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("EMBEDDER", "mock")
    assert build.main(["--sql", str(INPUT_SQL), "--vps-out", str(tmp_path / "vps")]) == 0
    assert sorted(p.name for p in (tmp_path / "vps").iterdir()) == [
        "meta.json", "vectors.bin", "vectors.idx"]
    with pytest.raises(SystemExit):
        build.main(["--sql", str(INPUT_SQL)])


def test_release_writes_vps_vectors_outside_the_zip(tmp_path: Path) -> None:
    code = release.main(["--sql", str(INPUT_SQL), "--out", str(tmp_path / "r.zip"),
                         "--no-model", "--mock", "--vps-out", str(tmp_path / "vps")])
    assert code == 0
    meta = json.loads((tmp_path / "vps" / "meta.json").read_text(encoding="utf-8"))
    assert meta["model"] == "BAAI/bge-m3" and meta["embedder"] == "mock"
    with zipfile.ZipFile(tmp_path / "r.zip") as archive:
        bundle_meta = json.loads(archive.read("data_incoming/meta.json"))
    # The cPanel bundle keeps its own (browser) model.
    assert bundle_meta["model"] == pipeline_config.load()["model"]["name"]


class _OldPooling:  # sentence-transformers < 6
    def __init__(self, mode: str) -> None:
        self._mode = mode

    def get_pooling_mode_str(self) -> str:
        return self._mode


class _NewPooling:  # sentence-transformers 6+
    def __init__(self, mode: str) -> None:
        self.pooling_mode = mode


def _fake_sentence_transformers(
    monkeypatch: pytest.MonkeyPatch, pooling: object
) -> None:
    class SentenceTransformer(list):
        def __init__(self, name: str, revision: str) -> None:
            super().__init__([object(), pooling])

    module = types.ModuleType("sentence_transformers")
    module.SentenceTransformer = SentenceTransformer
    monkeypatch.setitem(sys.modules, "sentence_transformers", module)


def test_real_embedder_checks_model_pooling(monkeypatch: pytest.MonkeyPatch) -> None:
    config = {"embedder": "real", "model": {}, "vps_model": pipeline_config.load()["vps_model"]}
    for pooling in (_OldPooling, _NewPooling):
        _fake_sentence_transformers(monkeypatch, pooling("cls"))
        assert embed.create_embedder(config, config["vps_model"]).dim == 1024
        _fake_sentence_transformers(monkeypatch, pooling("mean"))
        with pytest.raises(ValueError, match="pools"):
            embed.create_embedder(config, config["vps_model"])
