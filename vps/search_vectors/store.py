"""Product vectors: validated load, cosine top-K, atomic reload.

On disk (VPS_DATA_DIR): active/ is served, incoming/ is where a release is
uploaded, previous/ is the one-step rollback. A vectors directory holds
vectors.bin (little-endian float32, count x dim, rows L2-normalized),
vectors.idx (one product_id per line, row order) and meta.json — written by
pipeline/build.py --vps-out.
"""

from __future__ import annotations

import hashlib
import json
import logging
import shutil
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np

from .config import Settings

log = logging.getLogger("search_vectors")

UNIT_TOLERANCE = 1e-3


class BundleError(Exception):
    """A vectors directory that must not be served."""


class ReloadBusy(Exception):
    pass


@dataclass(frozen=True)
class Index:
    ids: np.ndarray  # (count,) int64
    vectors: np.ndarray  # (count, dim) float32, L2-normalized rows
    meta: dict[str, Any]


def load_vectors(path: Path, settings: Settings) -> Index:
    """Read and fully validate a vectors directory; raise BundleError otherwise."""
    try:
        meta = json.loads((path / "meta.json").read_text(encoding="utf-8"))
        raw = (path / "vectors.bin").read_bytes()
        idx_lines = (path / "vectors.idx").read_text(encoding="utf-8").splitlines()
    except (OSError, ValueError) as exc:
        raise BundleError(f"unreadable vectors in {path}: {exc}") from exc
    if not isinstance(meta, dict):
        raise BundleError("meta.json is not an object")

    expected = {
        "model": settings.model,
        "revision": settings.revision,
        "dim": settings.dim,
        "pooling": settings.pooling,
        "query_prefix": settings.query_prefix,
    }
    for key, value in expected.items():
        if meta.get(key) != value:
            raise BundleError(f"meta.json {key}={meta.get(key)!r}, service expects {value!r}")
    # Mock vectors are noise to a real model, and the reverse.
    if (meta.get("embedder") == "mock") != (settings.embedder == "mock"):
        raise BundleError(
            f"vectors built by the {meta.get('embedder')!r} embedder, service runs "
            f"{settings.embedder!r}"
        )

    count = meta.get("count")
    if not isinstance(count, int) or isinstance(count, bool) or count < 1:
        raise BundleError(f"meta.json count={count!r} is not a positive integer")
    if len(raw) != count * settings.dim * 4:
        raise BundleError(f"vectors.bin is {len(raw)} bytes, expected {count}x{settings.dim}x4")
    if hashlib.sha256(raw).hexdigest() != meta.get("checksum"):
        raise BundleError("vectors.bin checksum does not match meta.json")
    if len(idx_lines) != count:
        raise BundleError(f"vectors.idx has {len(idx_lines)} lines, meta.json count is {count}")
    try:
        ids = np.array([int(line) for line in idx_lines], dtype=np.int64)
    except ValueError as exc:
        raise BundleError(f"vectors.idx: {exc}") from exc
    if len(np.unique(ids)) != count:
        raise BundleError("vectors.idx has duplicate product ids")

    vectors = np.frombuffer(raw, dtype="<f4").reshape(count, settings.dim)
    if not np.isfinite(vectors).all():
        raise BundleError("vectors.bin has non-finite values")
    norms = np.linalg.norm(vectors, axis=1)
    if np.abs(norms - 1.0).max() > UNIT_TOLERANCE:
        raise BundleError("vectors.bin rows are not L2-normalized")
    return Index(ids, vectors.astype(np.float32, copy=False), meta)


def top_k(index: Index, query: np.ndarray, limit: int, min_score: float) -> list[tuple[int, float]]:
    """Global brute-force cosine top-`limit` at or above `min_score`, best
    first (ties by product id)."""
    scores = index.vectors @ np.asarray(query, dtype=np.float32)
    rows = np.flatnonzero(scores >= min_score)
    if len(rows) > limit:
        rows = rows[np.argpartition(-scores[rows], limit - 1)[:limit]]
    rows = rows[np.lexsort((index.ids[rows], -scores[rows]))]
    return [(int(index.ids[r]), float(scores[r])) for r in rows]


class Store:
    """Holds the served Index. Readers take one reference per request, so a
    reload swaps it whole: a search never sees half of two versions."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._index: Index | None = None
        self._reload_lock = threading.Lock()

    @property
    def index(self) -> Index | None:
        return self._index

    def load_active(self) -> None:
        """Startup: serve active/, or previous/ if a swap was interrupted
        between its two renames. Invalid or missing vectors leave the service
        up without vectors (/search-vectors answers 503)."""
        for path in (self._settings.vectors_dir, self._settings.previous_dir):
            if not path.exists():
                continue
            try:
                self._index = load_vectors(path, self._settings)
                log.info("serving %d vectors from %s", len(self._index.ids), path)
                return
            except BundleError as exc:
                log.error("not serving %s: %s", path, exc)
        log.warning("no valid vectors loaded; /search-vectors answers 503 until /reload")

    def reload(self) -> Index:
        """Validate incoming/ completely, then swap on disk (active -> previous,
        incoming -> active) and in memory. Any failure leaves both unchanged."""
        if not self._reload_lock.acquire(blocking=False):
            raise ReloadBusy("a reload is already running")
        try:
            s = self._settings
            if not s.incoming_dir.is_dir():
                raise BundleError(f"no vectors uploaded to {s.incoming_dir}")
            index = load_vectors(s.incoming_dir, s)
            if s.previous_dir.exists():
                shutil.rmtree(s.previous_dir)
            had_active = s.vectors_dir.exists()
            if had_active:
                s.vectors_dir.rename(s.previous_dir)
            try:
                s.incoming_dir.rename(s.vectors_dir)
            except OSError:
                if had_active:
                    s.previous_dir.rename(s.vectors_dir)
                raise
            self._index = index
            log.info("reloaded %d vectors built %s", len(index.ids), index.meta.get("built_at"))
            return index
        finally:
            self._reload_lock.release()
