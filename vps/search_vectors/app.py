"""HTTP API. Run with `python -m search_vectors` (one worker; see __main__.py).

    GET  /health           open: readiness, model, vector count
    POST /search-vectors   token: {q, limit?, min_score?} -> {results: [{product_id, score}]}
    POST /reload           token: validate + atomically swap in incoming/
"""

from __future__ import annotations

import hmac
import logging
import time
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from . import config
from .config import Settings
from .embedder import Embedder, create_embedder
from .store import BundleError, ReloadBusy, Store, top_k

log = logging.getLogger("search_vectors")


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
        state["embedder"] = embedder or create_embedder(settings)
        store.load_active()
        yield

    # No /docs or /openapi.json: nothing about the service is public but /health.
    app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

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
    def search_vectors(request: SearchRequest) -> dict:
        index = store.index
        if index is None:
            raise HTTPException(503, "no product vectors loaded")
        started = time.perf_counter()
        text = request.q.strip()[: settings.max_query_chars]
        if not text:
            raise HTTPException(422, "q is blank")
        # One text per call: batching would shift the int8 model's output.
        query = state["embedder"].embed([settings.query_prefix + text])[0]
        limit = min(request.limit or settings.default_limit, settings.max_limit)
        floor = settings.min_score if request.min_score is None else request.min_score
        hits = top_k(index, query, limit, floor)
        return {
            "results": [{"product_id": pid, "score": round(score, 6)} for pid, score in hits],
            "model": settings.model,
            "took_ms": round((time.perf_counter() - started) * 1000, 2),
        }

    @app.post("/reload", dependencies=[Depends(require_token)])
    def reload() -> JSONResponse:
        try:
            index = store.reload()
        except ReloadBusy as exc:
            return JSONResponse({"ok": False, "error": str(exc)}, status_code=409)
        except BundleError as exc:
            log.error("reload rejected: %s", exc)
            return JSONResponse({"ok": False, "error": str(exc)}, status_code=422)
        except OSError as exc:
            log.exception("reload failed while swapping directories")
            return JSONResponse({"ok": False, "error": f"swap failed: {exc}"}, status_code=500)
        return JSONResponse({
            "ok": True,
            "count": len(index.ids),
            "model": index.meta["model"],
            "built_at": index.meta.get("built_at"),
        })

    return app
