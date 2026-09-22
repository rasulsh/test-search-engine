"""Pipeline configuration, read from the environment.

Mirrors the model/normalization settings in server/config.example.php. Never
hardcode model, dimension, or thresholds elsewhere — read them from here.
"""

from __future__ import annotations

import os
from typing import Any

from normalize import NORMALIZATION_VERSION


def load() -> dict[str, Any]:
    return {
        "model": {
            "name": os.getenv("SEARCH_MODEL", "intfloat/multilingual-e5-small"),
            "revision": os.getenv("SEARCH_MODEL_REVISION", "main"),
            "dim": int(os.getenv("SEARCH_MODEL_DIM", "384")),
            "normalization_version": int(
                os.getenv("SEARCH_NORMALIZATION_VERSION", str(NORMALIZATION_VERSION))
            ),
        },
        # "mock" needs no GPU/model download; "real" loads the model (M3).
        "embedder": os.getenv("EMBEDDER", "mock"),
        "keyword": {
            # Tokens shorter than this are ignored when building the dictionaries.
            "min_token_length": int(os.getenv("SEARCH_MIN_TOKEN_LENGTH", "2")),
        },
    }
