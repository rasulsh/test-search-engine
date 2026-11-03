"""HTTP API. Run with `python -m search_vectors` (one worker; see __main__.py).

    GET  /health           open: readiness, model, vector count
    POST /search-vectors   token: {q, limit?, min_score?} -> {results: [{product_id, score}]}
    POST /reload           token: validate + atomically swap in incoming/

Observability (no behaviour change for a caller that ignores it): every response echoes X-Request-Id
(the caller's, or a generated one) and carries X-Vps-Inflight (requests in flight when this one
arrived); /search-vectors also sends Server-Timing "queue;dur=..., embed;dur=..., search;dur=..."
(milliseconds).
Each request writes ONE journal line `req id=... route=... status=... total_ms=... queue_ms=...
embed_ms=... search_ms=... inflight=... rss_mb=... load1=...`; startup, model load, vector load and
reload write `event=... ts=...` lines. Queries are never logged.
"""

from __future__ import annotations

import hmac
import logging
import os
import re
import time
import uuid
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import UTC, datetime
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from . import config
from .config import Settings
from .embedder import Embedder, create_embedder
from .store import BundleError, ReloadBusy, Store, top_k

log = logging.getLogger("search_vectors")

REQUEST_ID = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
ROUTES = ("/health", "/search-vectors", "/reload")


def fields(**values: Any) -> str:
    """`key=value` pairs for one log line; None is "-". Values never contain spaces."""
    return " ".join(f"{key}={'-' if value is None else value}" for key, value in values.items())


def event(name: str, **values: Any) -> None:
    """A lifecycle line with its own UTC timestamp (a copied journal line keeps it)."""
    stamp = datetime.now(UTC).isoformat(timespec="milliseconds")
    log.info("event=%s ts=%s %s", name, stamp, fields(**values))


def process_stats() -> tuple[float | None, float | None]:
    """(resident memory in MB, 1-minute load average); None where the platform has no answer."""
    rss = None
    try:
        with open("/proc/self/statm", encoding="ascii") as statm:
            rss = round(int(statm.read().split()[1]) * os.sysconf("SC_PAGE_SIZE") / 1048576, 1)
    except (OSError, ValueError, IndexError):
        pass
    try:
        load = round(os.getloadavg()[0], 2)
    except (OSError, AttributeError):
        load = None
    return rss, load


def ms_since(start: float) -> float:
    return round((time.perf_counter() - start) * 1000, 2)


class SearchRequest(BaseModel):
    q: str = Field(min_length=1)
    limit: int | None = Field(default=None, ge=1)
    # The caller's relevance floor (cPanel's semantic_min_score); the service's
    # own VPS_SEMANTIC_MIN_SCORE applies when it is absent.
    min_score: float | None = Field(default=None, ge=-1, le=1)


def create_app(settings: Settings | None = None, embedder: Embedder | None = None) -> FastAPI:
    settings = settings or config.load()
    store = Store(settings)
    state: dict[str, Embedder] = {}

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        # Loaded once per process; a model that fails to load stops the service.
        event("startup", embedder=settings.embedder, model=settings.model)
        started = time.perf_counter()
        state["embedder"] = embedder or create_embedder(settings)
        event("model_loaded", ms=ms_since(started))
        started = time.perf_counter()
        store.load_active()
        index = store.index
        event("vectors_loaded", ms=ms_since(started),
              count=None if index is None else len(index.ids),
              built_at=None if index is None else index.meta.get("built_at"))
        yield
        event("shutdown")

    # No /docs or /openapi.json: nothing about the service is public but /health.
    app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

    inflight = {"now": 0}

    @app.middleware("http")
    async def observe(request: Request, call_next: Any) -> Any:
        arrived = time.perf_counter()
        inflight["now"] += 1
        concurrent = inflight["now"]
        given = request.headers.get("x-request-id", "")
        request_id = given if REQUEST_ID.match(given) else uuid.uuid4().hex
        request.state.arrived = arrived
        request.state.timings = {}
        status = 500
        try:
            response = await call_next(request)
            status = response.status_code
            response.headers["X-Request-Id"] = request_id
            response.headers["X-Vps-Inflight"] = str(concurrent)
            timings = request.state.timings
            if "queue" in timings:
                response.headers["Server-Timing"] = ", ".join(
                    f"{name};dur={timings[name]:.2f}"
                    for name in ("queue", "embed", "search") if name in timings
                )
            return response
        finally:
            inflight["now"] -= 1
            timings = request.state.timings
            rss, load = process_stats()
            path = request.url.path
            log.info("req %s", fields(
                id=request_id, route=path if path in ROUTES else "other",
                method=request.method, status=status, total_ms=ms_since(arrived),
                queue_ms=timings.get("queue"), embed_ms=timings.get("embed"),
                search_ms=timings.get("search"), inflight=concurrent, rss_mb=rss, load1=load,
            ))

    def require_token(authorization: str = Header(default="")) -> None:
        scheme, _, token = authorization.partition(" ")
        if scheme.lower() != "bearer" or not hmac.compare_digest(
            token.encode("utf-8"), settings.token.encode("utf-8")
        ):
            raise HTTPException(401, "missing or wrong token",
                                headers={"WWW-Authenticate": "Bearer"})

    @app.get("/health")
    def health() -> JSONResponse:
        index = store.index
        body = {
            "ok": index is not None and "embedder" in state,
            "model": settings.model,
            "dim": settings.dim,
            "count": 0 if index is None else len(index.ids),
            "built_at": None if index is None else index.meta.get("built_at"),
        }
        return JSONResponse(body, status_code=200 if body["ok"] else 503)

    @app.post("/search-vectors", dependencies=[Depends(require_token)])
    def search_vectors(request: SearchRequest, http_request: Request) -> dict:
        timings = http_request.state.timings
        # From arrival to here: the body parse and the wait for a free worker thread.
        timings["queue"] = ms_since(http_request.state.arrived)
        index = store.index
        if index is None:
            raise HTTPException(503, "no product vectors loaded")
        started = time.perf_counter()
        text = request.q.strip()[: settings.max_query_chars]
        if not text:
            raise HTTPException(422, "q is blank")
        # One text per call: batching would shift the int8 model's output.
        embedding = time.perf_counter()
        query = state["embedder"].embed([settings.query_prefix + text])[0]
        timings["embed"] = ms_since(embedding)
        limit = min(request.limit or settings.default_limit, settings.max_limit)
        floor = settings.min_score if request.min_score is None else request.min_score
        searching = time.perf_counter()
        hits = top_k(index, query, limit, floor)
        timings["search"] = ms_since(searching)
        return {
            "results": [{"product_id": pid, "score": round(score, 6)} for pid, score in hits],
            "model": settings.model,
            "took_ms": round((time.perf_counter() - started) * 1000, 2),
        }

    @app.post("/reload", dependencies=[Depends(require_token)])
    def reload() -> JSONResponse:
        started = time.perf_counter()
        try:
            index = store.reload()
        except ReloadBusy as exc:
            event("vectors_reload", ok=False, reason="busy", ms=ms_since(started))
            return JSONResponse({"ok": False, "error": str(exc)}, status_code=409)
        except BundleError as exc:
            log.error("reload rejected: %s", exc)
            event("vectors_reload", ok=False, reason="rejected", ms=ms_since(started))
            return JSONResponse({"ok": False, "error": str(exc)}, status_code=422)
        except OSError as exc:
            log.exception("reload failed while swapping directories")
            event("vectors_reload", ok=False, reason="swap_failed", ms=ms_since(started))
            return JSONResponse({"ok": False, "error": f"swap failed: {exc}"}, status_code=500)
        event("vectors_reload", ok=True, count=len(index.ids), built_at=index.meta.get("built_at"),
              ms=ms_since(started))
        return JSONResponse({
            "ok": True,
            "count": len(index.ids),
            "model": index.meta["model"],
            "built_at": index.meta.get("built_at"),
        })

    return app
