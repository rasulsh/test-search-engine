"""Contract 2 end to end with the mock embedder: vectors from
pipeline/build.py --vps-out load into the VPS, and a product's own passage text
as the query finds it first. Defaults on both sides must agree."""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

import build
import config as pipeline_config
from search_vectors import config as vps_config
from search_vectors.app import create_app
from search_vectors.store import load_vectors

REPO_ROOT = Path(__file__).resolve().parents[2]
TOKEN = "contract-token-0123456789"


def test_model_defaults_agree() -> None:
    pipeline = pipeline_config.load()["vps_model"]
    vps = vps_config.load({"VPS_TOKEN": TOKEN})
    assert (pipeline["name"], pipeline["revision"], pipeline["dim"], pipeline["pooling"],
            pipeline["query_prefix"]) == (vps.model, vps.revision, vps.dim, vps.pooling,
                                          vps.query_prefix)


def test_pipeline_vectors_serve_on_the_vps(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("EMBEDDER", "mock")
    config = pipeline_config.load()
    products = build.read_products(REPO_ROOT / "fixtures" / "products.input.sql")
    data = tmp_path / "data"
    meta = build.build_vps_vectors(products, data / "incoming", config)
    assert meta["count"] == len(products) and meta["dim"] == 1024

    settings = vps_config.load({"VPS_TOKEN": TOKEN, "VPS_EMBEDDER": "mock",
                                "VPS_DATA_DIR": str(data), "VPS_SEMANTIC_MIN_SCORE": "0.5"})
    index = load_vectors(data / "incoming", settings)
    assert index.ids.tolist() == [int(p["id"]) for p in products]

    passage = build.compose_passage(products[1], int(config["build"]["desc_char_limit"]),
                                    int(config["build"]["passage_char_limit"]))
    with TestClient(create_app(settings)) as client:
        auth = {"Authorization": f"Bearer {TOKEN}"}
        assert client.post("/reload", headers=auth).status_code == 200
        body = client.post("/search-vectors", json={"q": passage}, headers=auth).json()
    # Mock vectors are random for different texts, so only the exact passage
    # clears the floor.
    assert body["results"] == [{"product_id": int(products[1]["id"]),
                                "score": pytest.approx(1.0, abs=1e-5)}]
