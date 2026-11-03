"""HTTP API: token auth, /search-vectors, /reload, /health."""

from __future__ import annotations

import logging
import re
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


# --- observability: request id, Server-Timing, inflight, one log line per request ---

def _req_lines(caplog: pytest.LogCaptureFixture) -> list[str]:
    return [r.getMessage() for r in caplog.records if r.getMessage().startswith("req ")]


def _field(line: str, name: str) -> str:
    return dict(pair.split("=", 1) for pair in line.split()[1:])[name]


def test_request_id_is_echoed_or_generated(served) -> None:
    echoed = served.post("/search-vectors", json={"q": "laptop"},
                         headers={**AUTH, "X-Request-Id": "abc-123_X.y"})
    assert echoed.headers["x-request-id"] == "abc-123_X.y"
    # Unusable ids (spaces, control characters, too long) are replaced, never echoed.
    for bad in ("", "has space", "a\tb", "x" * 65, "ключ"):
        got = served.get("/health", headers={"X-Request-Id": bad} if bad.isascii() else {})
        assert re.fullmatch(r"[0-9a-f]{32}", got.headers["x-request-id"]), bad


def test_server_timing_and_inflight_headers_on_search(served) -> None:
    response = served.post("/search-vectors", json={"q": "laptop"}, headers=AUTH)
    timing = response.headers["server-timing"]
    assert re.fullmatch(r"queue;dur=\d+\.\d\d, embed;dur=\d+\.\d\d, search;dur=\d+\.\d\d", timing)
    assert response.headers["x-vps-inflight"] == "1"
    # Not on routes that do no embedding, nor on a request that never reached the handler.
    assert "server-timing" not in served.get("/health").headers
    assert "server-timing" not in served.post("/search-vectors", json={}, headers=AUTH).headers
    assert "server-timing" not in served.post("/search-vectors", json={"q": "x"}).headers


def test_old_callers_are_unaffected(served) -> None:
    """No request id, no new headers needed: the body is exactly what it was."""
    body = served.post("/search-vectors", json={"q": "laptop"}, headers=AUTH).json()
    assert set(body) == {"results", "model", "took_ms"}


def test_one_structured_log_line_per_request_without_the_query(served, caplog) -> None:
    caplog.set_level(logging.INFO, logger="search_vectors")
    served.post("/search-vectors", json={"q": "secret shopper words"},
                headers={**AUTH, "X-Request-Id": "req-1"})
    served.get("/health", headers={"X-Request-Id": "req-2"})
    served.post("/search-vectors", json={"q": "x"}, headers={"X-Request-Id": "req-3"})  # 401

    lines = _req_lines(caplog)
    assert len(lines) == 3
    search, health, denied = lines
    assert _field(search, "id") == "req-1" and _field(search, "route") == "/search-vectors"
    assert _field(search, "status") == "200" and _field(search, "inflight") == "1"
    for name in ("total_ms", "queue_ms", "embed_ms", "search_ms", "rss_mb", "load1"):
        assert name in dict(p.split("=", 1) for p in search.split()[1:])
    assert float(_field(search, "total_ms")) >= float(_field(search, "embed_ms"))
    assert _field(health, "route") == "/health" and _field(health, "embed_ms") == "-"
    assert _field(denied, "status") == "401" and _field(denied, "queue_ms") == "-"
    assert all("secret shopper words" not in r.getMessage() for r in caplog.records)


def test_unknown_paths_are_logged_as_other(served, caplog) -> None:
    caplog.set_level(logging.INFO, logger="search_vectors")
    served.get("/wp-login.php?x=1")
    assert _field(_req_lines(caplog)[0], "route") == "other"
    assert _field(_req_lines(caplog)[0], "status") == "404"


def test_lifecycle_events_are_logged_with_timestamps(settings, caplog) -> None:
    caplog.set_level(logging.INFO, logger="search_vectors")
    write_vectors(settings.vectors_dir, settings, list(TEXTS), vectors_for(TEXTS))
    with TestClient(create_app(settings)) as client:
        new = {201: "lg oled tv"}
        write_vectors(settings.incoming_dir, settings, list(new), vectors_for(new))
        assert client.post("/reload", headers=AUTH).status_code == 200
        assert client.post("/reload", headers=AUTH).status_code == 422  # nothing left to load

    events = [r.getMessage() for r in caplog.records if r.getMessage().startswith("event=")]
    names = [e.split()[0] for e in events]
    assert names == ["event=startup", "event=model_loaded", "event=vectors_loaded",
                     "event=vectors_reload", "event=vectors_reload", "event=shutdown"]
    assert all(re.search(r" ts=\d{4}-\d\d-\d\dT[\d:.]+\+00:00", e) for e in events)
    assert "count=3" in events[2] and "ok=True" in events[3] and "count=1" in events[3]
    assert "ok=False" in events[4] and "reason=rejected" in events[4]


def test_inflight_counts_concurrent_requests(served, monkeypatch: pytest.MonkeyPatch) -> None:
    started, release = threading.Event(), threading.Event()
    from search_vectors import app as app_module

    real_top_k = app_module.top_k

    def slow_top_k(*args, **kwargs):
        started.set()
        release.wait(5)
        return real_top_k(*args, **kwargs)

    monkeypatch.setattr(app_module, "top_k", slow_top_k)
    first: dict = {}
    worker = threading.Thread(target=lambda: first.update(r=served.post(
        "/search-vectors", json={"q": "laptop"}, headers=AUTH)))
    worker.start()
    assert started.wait(5)
    second = served.get("/health")
    release.set()
    worker.join(5)
    assert second.headers["x-vps-inflight"] == "2"
    assert first["r"].headers["x-vps-inflight"] == "1"
