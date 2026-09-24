"""Service settings, read from the environment (/etc/search-vectors.env).

Model, revision, dim, pooling and query prefix MUST equal pipeline/config.py's
vps_model (contract 2); /reload refuses vectors whose meta.json disagrees.
"""

from __future__ import annotations

import os
from collections.abc import Mapping
from dataclasses import dataclass
from pathlib import Path

POOLINGS = ("cls", "mean")
EMBEDDERS = ("onnx", "mock")
MIN_TOKEN_LENGTH = 16


@dataclass(frozen=True)
class Settings:
    token: str
    model: str
    revision: str
    dim: int
    pooling: str
    query_prefix: str
    embedder: str
    model_dir: Path
    onnx_file: str
    max_tokens: int
    threads: int
    vectors_dir: Path
    incoming_dir: Path
    previous_dir: Path
    min_score: float
    default_limit: int
    max_limit: int
    max_query_chars: int


def load(env: Mapping[str, str] = os.environ) -> Settings:
    data = Path(env.get("VPS_DATA_DIR", "/var/lib/search-vectors"))
    settings = Settings(
        token=env.get("VPS_TOKEN", ""),
        model=env.get("VPS_MODEL", "BAAI/bge-m3"),
        revision=env.get("VPS_MODEL_REVISION", "5617a9f61b028005a4858fdac845db406aefb181"),
        dim=int(env.get("VPS_MODEL_DIM", "1024")),
        pooling=env.get("VPS_POOLING", "cls"),
        query_prefix=env.get("VPS_QUERY_PREFIX", ""),
        embedder=env.get("VPS_EMBEDDER", "onnx"),
        model_dir=Path(env.get("VPS_MODEL_DIR", "/opt/search-vectors/model")),
        onnx_file=env.get("VPS_ONNX_FILE", "onnx/model_quantized.onnx"),
        max_tokens=int(env.get("VPS_MAX_TOKENS", "512")),
        # 0 lets ONNX Runtime use every core.
        threads=int(env.get("VPS_THREADS", "0")),
        vectors_dir=data / "active",
        incoming_dir=data / "incoming",
        previous_dir=data / "previous",
        # bge-m3 cosines spread wider than e5's; tune on the eval set.
        min_score=float(env.get("VPS_SEMANTIC_MIN_SCORE", "0.5")),
        default_limit=int(env.get("VPS_DEFAULT_LIMIT", "100")),
        max_limit=int(env.get("VPS_MAX_LIMIT", "500")),
        max_query_chars=int(env.get("VPS_MAX_QUERY_CHARS", "200")),
    )
    if len(settings.token) < MIN_TOKEN_LENGTH:
        raise ValueError(f"VPS_TOKEN must be set (at least {MIN_TOKEN_LENGTH} characters)")
    if settings.pooling not in POOLINGS:
        raise ValueError(f"VPS_POOLING must be one of {POOLINGS}")
    if settings.embedder not in EMBEDDERS:
        raise ValueError(f"VPS_EMBEDDER must be one of {EMBEDDERS}")
    if settings.dim < 1 or not 1 <= settings.default_limit <= settings.max_limit:
        raise ValueError("VPS_MODEL_DIM and 1 <= VPS_DEFAULT_LIMIT <= VPS_MAX_LIMIT required")
    return settings
