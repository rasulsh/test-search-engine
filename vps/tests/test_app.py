"""HTTP API: token auth, /search-vectors, /reload, /health."""

from __future__ import annotations

import threading

import numpy as np
import pytest
from conftest import DIM, TOKEN, make_settings, unit, write_vectors
from fastapi.testclient import TestClient

from search_vectors import store as store_module
from search_vectors.app import create_app
from search_vectors.embedder import MockEmbedder

AUTH = {"Authorization": f"Bearer {TOKEN}"}
TEXTS = {101: "apple iphone 15 pro", 102: "sony wireless headphones", 103: "asus vivobook laptop"}


def vectors_for(texts: dict[int, str]) -> np.ndarray:
    return MockEmbedder(DIM).embed(list(texts.values()))


@pytest.fixture()
def served(settings):
    write_vectors(settings.vectors_dir, settings, list(TEXTS), vectors_for(TEXTS))
    with TestClient(create_app(settings)) as client:
        yield client


def test_health_is_open_and_reports_vectors(served) -> None:
    response = served.get("/health")
    assert response.status_code == 200
    assert response.json() == {"ok": True, "model": "BAAI/bge-m3", "dim": DIM, "count": 3,
                               "built_at": "2026-01-01T00:00:00+00:00"}


def test_health_is_503_without_vectors(settings) -> None:
    with TestClient(create_app(settings)) as client:
        response = client.get("/health")
    assert response.status_code == 503
    assert response.json()["ok"] is False and response.json()["count"] == 0


@pytest.mark.parametrize("headers", [
    {},
    {"Authorization": "Bearer wrong-token-0123456789"},
    {"Authorization": TOKEN},
    {"Authorization": f"Basic {TOKEN}"},
    {"X-Search-Token": TOKEN},
])
def test_token_required(served, headers) -> None:
    for path, body in (("/search-vectors", {"q": "laptop"}), ("/reload", None)):
        response = served.post(path, json=body, headers=headers)
        assert response.status_code == 401, path
        assert response.headers["www-authenticate"] == "Bearer"


def test_search_returns_top_k_with_scores(served) -> None:
    response = served.post("/search-vectors", json={"q": "  sony wireless headphones "},
                           headers=AUTH)
    assert response.status_code == 200
    body = response.json()
    assert body["model"] == "BAAI/bge-m3"
    assert body["results"][0] == {"product_id": 102, "score": pytest.approx(1.0, abs=1e-5)}
    assert len(body["results"]) == 3
    scores = [r["score"] for r in body["results"]]
    assert scores == sorted(scores, reverse=True)


def test_search_applies_limit_and_max_limit(served) -> None:
    one = served.post("/search-vectors", json={"q": "laptop", "limit": 1}, headers=AUTH)
    assert len(one.json()["results"]) == 1
    capped = served.post("/search-vectors", json={"q": "laptop", "limit": 10_000}, headers=AUTH)
    assert capped.status_code == 200 and len(capped.json()["results"]) == 3


def test_floor_drops_low_cosine_results(tmp_path) -> None:
    settings = make_settings(tmp_path / "data", VPS_SEMANTIC_MIN_SCORE="0.99")
    write_vectors(settings.vectors_dir, settings, list(TEXTS), vectors_for(TEXTS))
    with TestClient(create_app(settings)) as client:
        exact = client.post("/search-vectors", json={"q": "apple iphone 15 pro"}, headers=AUTH)
        other = client.post("/search-vectors", json={"q": "unrelated words"}, headers=AUTH)
    assert [r["product_id"] for r in exact.json()["results"]] == [101]
    assert other.json()["results"] == []


def test_request_min_score_overrides_the_service_floor(tmp_path) -> None:
    settings = make_settings(tmp_path / "data", VPS_SEMANTIC_MIN_SCORE="0.99")
    write_vectors(settings.vectors_dir, settings, list(TEXTS), vectors_for(TEXTS))
    with TestClient(create_app(settings)) as client:
        loose = client.post("/search-vectors", json={"q": "apple iphone 15 pro", "min_score": -1},
                            headers=AUTH).json()
        default = client.post("/search-vectors", json={"q": "apple iphone 15 pro"},
                              headers=AUTH).json()
    assert len(loose["results"]) == 3
    assert [r["product_id"] for r in default["results"]] == [101]


def test_query_prefix_is_applied(tmp_path) -> None:
    settings = make_settings(tmp_path / "data", VPS_QUERY_PREFIX="query: ")
    texts = {1: "query: laptop", 2: "laptop"}
    write_vectors(settings.vectors_dir, settings, list(texts), vectors_for(texts))
    with TestClient(create_app(settings)) as client:
        body = client.post("/search-vectors", json={"q": "laptop"}, headers=AUTH).json()
    assert body["results"][0]["product_id"] == 1


@pytest.mark.parametrize("body", [{}, {"q": ""}, {"q": "   "}, {"q": "x", "limit": 0},
                                  {"q": 5}, {"q": "x", "limit": "many"},
                                  {"q": "x", "min_score": 2}, {"q": "x", "min_score": "high"}])
def test_bad_requests_are_422(served, body) -> None:
    assert served.post("/search-vectors", json=body, headers=AUTH).status_code == 422


def test_search_is_503_without_vectors(settings) -> None:
    with TestClient(create_app(settings)) as client:
        response = client.post("/search-vectors", json={"q": "laptop"}, headers=AUTH)
    assert response.status_code == 503


def test_reload_swaps_and_serves_new_vectors(served, settings) -> None:
    new = {201: "lg oled tv"}
    write_vectors(settings.incoming_dir, settings, list(new), vectors_for(new),
                  built_at="2026-02-02T00:00:00+00:00")
    response = served.post("/reload", headers=AUTH)
    assert response.status_code == 200
    assert response.json() == {"ok": True, "count": 1, "model": "BAAI/bge-m3",
                               "built_at": "2026-02-02T00:00:00+00:00"}
    body = served.post("/search-vectors", json={"q": "lg oled tv"}, headers=AUTH).json()
    assert [r["product_id"] for r in body["results"]] == [201]
    assert served.get("/health").json()["count"] == 1


def test_reload_rejects_mismatch_and_keeps_serving(served, settings) -> None:
    write_vectors(settings.incoming_dir, settings, [9], unit(np.ones((1, DIM))),
                  model="intfloat/multilingual-e5-small")
    response = served.post("/reload", headers=AUTH)
    assert response.status_code == 422
    assert response.json()["ok"] is False and "model" in response.json()["error"]
    body = served.post("/search-vectors", json={"q": "sony wireless headphones"},
                       headers=AUTH).json()
    assert body["results"][0]["product_id"] == 102


def test_reload_without_upload_is_422(served) -> None:
    assert served.post("/reload", headers=AUTH).status_code == 422


def test_searches_during_reload_see_old_or_new_never_mixed(
    served, settings, monkeypatch: pytest.MonkeyPatch
) -> None:
    """While a reload is validating, the old vectors keep answering; after it,
    only the new ones do."""
    new = {301: "sony wireless headphones"}
    write_vectors(settings.incoming_dir, settings, list(new), vectors_for(new))
    validating, release = threading.Event(), threading.Event()
    real_load = store_module.load_vectors

    def slow_load(path, s):
        index = real_load(path, s)
        validating.set()
        release.wait(5)
        return index

    monkeypatch.setattr(store_module, "load_vectors", slow_load)
    result = {}
    worker = threading.Thread(target=lambda: result.update(r=served.post("/reload", headers=AUTH)))
    worker.start()
    assert validating.wait(5)
    during = served.post("/search-vectors", json={"q": "sony wireless headphones"},
                         headers=AUTH).json()
    busy = served.post("/reload", headers=AUTH)
    release.set()
    worker.join(5)

    assert [r["product_id"] for r in during["results"]][0] == 102
    assert busy.status_code == 409
    assert result["r"].status_code == 200
    after = served.post("/search-vectors", json={"q": "sony wireless headphones"},
                        headers=AUTH).json()
    assert [r["product_id"] for r in after["results"]] == [301]


def test_docs_and_openapi_are_not_exposed(served) -> None:
    for path in ("/docs", "/redoc", "/openapi.json"):
        assert served.get(path).status_code == 404


def test_config_refuses_missing_or_short_token(tmp_path) -> None:
    for token in ("", "short"):
        with pytest.raises(ValueError, match="VPS_TOKEN"):
            make_settings(tmp_path, VPS_TOKEN=token)


@pytest.mark.parametrize(("key", "value"), [("VPS_POOLING", "max"), ("VPS_EMBEDDER", "real"),
                                            ("VPS_DEFAULT_LIMIT", "0"),
                                            ("VPS_DEFAULT_LIMIT", "99")])
def test_config_rejects_invalid_values(tmp_path, key, value) -> None:
    with pytest.raises(ValueError):
        make_settings(tmp_path, **{key: value})
