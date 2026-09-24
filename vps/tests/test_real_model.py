"""Real bge-m3 checks, off by default (CI has no model). Run on a machine with
the ONNX build (python -m search_vectors.fetch_model --out DIR):

    VPS_REAL_MODEL_DIR=DIR pytest vps/tests/test_real_model.py
    VPS_REAL_MODEL_DIR=DIR VPS_REAL_PARITY=1 pytest vps/tests/test_real_model.py

VPS_REAL_PARITY=1 also loads the pipeline's sentence-transformers model
(downloads BAAI/bge-m3, ~2.3 GB) to check contract 2 numerically and to run
pipeline vectors -> VPS search end to end. VPS_ONNX_FILE picks the build.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient

from search_vectors import config as vps_config
from search_vectors.app import create_app
from search_vectors.embedder import OnnxEmbedder

MODEL_DIR = os.environ.get("VPS_REAL_MODEL_DIR")
PARITY = os.environ.get("VPS_REAL_PARITY") == "1"
ONNX_FILE = os.environ.get("VPS_ONNX_FILE", "onnx/model_quantized.onnx")
# fp32 matches sentence-transformers exactly; int8 measured min 0.984 (README).
PARITY_MIN_COSINE = 0.999 if ONNX_FILE == "onnx/model.onnx" else 0.975
TOKEN = "real-model-token-0123456789"
REPO_ROOT = Path(__file__).resolve().parents[2]
STRINGS = json.loads((REPO_ROOT / "fixtures" / "parity_strings.json").read_text("utf-8"))[
    "queries"
]

pytestmark = pytest.mark.skipif(not MODEL_DIR, reason="VPS_REAL_MODEL_DIR not set")


def _settings(**env: str) -> vps_config.Settings:
    return vps_config.load({"VPS_TOKEN": TOKEN, "VPS_MODEL_DIR": str(MODEL_DIR),
                            "VPS_ONNX_FILE": ONNX_FILE, **env})


@pytest.fixture(scope="module")
def onnx() -> OnnxEmbedder:
    return OnnxEmbedder.load(_settings())


def _one_by_one(onnx: OnnxEmbedder, texts: list[str]) -> np.ndarray:
    # As the service embeds: one query per call. The int8 build's dynamic
    # quantization scales over the whole (padded) batch, so batching shifts it.
    return np.vstack([onnx.embed([t]) for t in texts])


def test_onnx_embeds_unit_vectors_of_the_configured_dim(onnx: OnnxEmbedder) -> None:
    vectors = _one_by_one(onnx, STRINGS)
    assert vectors.shape == (len(STRINGS), 1024)
    np.testing.assert_allclose(np.linalg.norm(vectors, axis=1), 1.0, atol=1e-5)
    np.testing.assert_array_equal(_one_by_one(onnx, STRINGS), vectors)


def test_cross_language_neighbours(onnx: OnnxEmbedder) -> None:
    fa, en, other = onnx.embed(["هدفون بی‌سیم", "wireless headphones", "refrigerator"])
    assert float(fa @ en) > float(fa @ other)


@pytest.mark.skipif(not PARITY, reason="VPS_REAL_PARITY=1 not set")
def test_query_vectors_match_pipeline_passage_vectors(onnx: OnnxEmbedder) -> None:
    import config as pipeline_config
    from embed import create_embedder, embed_passages

    config = pipeline_config.load()
    config["embedder"] = "real"
    model = config["vps_model"]
    reference = embed_passages(create_embedder(config, model), STRINGS, model["passage_prefix"])
    queries = _one_by_one(onnx, [model["query_prefix"] + s for s in STRINGS])
    cosines = (reference * queries).sum(axis=1)
    assert cosines.min() >= PARITY_MIN_COSINE, cosines


@pytest.mark.skipif(not PARITY, reason="VPS_REAL_PARITY=1 not set")
def test_real_pipeline_vectors_served_end_to_end(tmp_path: Path, monkeypatch) -> None:
    import build
    import config as pipeline_config

    monkeypatch.setenv("EMBEDDER", "real")
    products = build.read_products(REPO_ROOT / "fixtures" / "products.input.sql")
    build.build_vps_vectors(products, tmp_path / "incoming", pipeline_config.load())

    settings = _settings(VPS_DATA_DIR=str(tmp_path), VPS_SEMANTIC_MIN_SCORE="0")
    auth = {"Authorization": f"Bearer {TOKEN}"}
    with TestClient(create_app(settings)) as client:
        assert client.post("/reload", headers=auth).status_code == 200

        def first(q: str) -> int:
            body = client.post("/search-vectors", json={"q": q}, headers=auth).json()
            return body["results"][0]["product_id"]

        assert first("هدفون بی‌سیم با حذف نویز") == 2002
        assert first("laptop") == 2003
        assert first("تلویزیون") == 2004
