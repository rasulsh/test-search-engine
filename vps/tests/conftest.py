"""Shared helpers: settings pointing at a temp data dir, and a vectors writer
producing the exact format of pipeline/build.py --vps-out."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import numpy as np
import pytest

from search_vectors import config
from search_vectors.config import Settings

TOKEN = "test-token-0123456789abcdef"
DIM = 8


def make_settings(data_dir: Path, **env: str) -> Settings:
    base = {
        "VPS_TOKEN": TOKEN,
        "VPS_EMBEDDER": "mock",
        "VPS_MODEL_DIM": str(DIM),
        "VPS_DATA_DIR": str(data_dir),
        "VPS_SEMANTIC_MIN_SCORE": "-1",
        "VPS_DEFAULT_LIMIT": "10",
        "VPS_MAX_LIMIT": "50",
    }
    return config.load({**base, **env})


def unit(rows: np.ndarray) -> np.ndarray:
    rows = np.asarray(rows, dtype=np.float32)
    return (rows / np.linalg.norm(rows, axis=1, keepdims=True)).astype("<f4")


def write_vectors(
    path: Path, settings: Settings, ids: list[int], vectors: np.ndarray, **meta: object
) -> None:
    path.mkdir(parents=True, exist_ok=True)
    raw = np.asarray(vectors, dtype="<f4").tobytes()
    (path / "vectors.bin").write_bytes(raw)
    (path / "vectors.idx").write_text("".join(f"{i}\n" for i in ids), encoding="utf-8")
    full = {
        "model": settings.model,
        "revision": settings.revision,
        "dim": settings.dim,
        "pooling": settings.pooling,
        "query_prefix": settings.query_prefix,
        "passage_prefix": "",
        "count": len(ids),
        "built_at": "2026-01-01T00:00:00+00:00",
        "checksum": hashlib.sha256(raw).hexdigest(),
        "embedder": settings.embedder,
        **meta,
    }
    (path / "meta.json").write_text(json.dumps(full), encoding="utf-8")


@pytest.fixture()
def settings(tmp_path: Path) -> Settings:
    return make_settings(tmp_path / "data")
