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
        # "mock" needs no GPU/model download; "real" loads the model.
        "embedder": os.getenv("EMBEDDER", "mock"),
        "keyword": {
            # Tokens shorter than this are ignored when building the dictionaries.
            "min_token_length": int(os.getenv("SEARCH_MIN_TOKEN_LENGTH", "2")),
        },
        "build": {
            # Live products table; the bundle loads into "<table>_new" (staging).
            "products_table": os.getenv("SEARCH_PRODUCTS_TABLE", "products"),
            # Description is truncated to this many characters in the embedding
            # input to bound the composed passage length.
            "desc_char_limit": int(os.getenv("SEARCH_DESC_CHAR_LIMIT", "300")),
            # Only this many leading characters of the cleaned description are
            # keyword-indexed (normalized_desc); the full text is kept for
            # display. Deep spec text otherwise matches queries for things the
            # shop does not sell and bloats FULLTEXT. 0 indexes the whole text.
            "desc_index_chars": int(os.getenv("SEARCH_DESC_INDEX_CHARS", "400")),
            # products.load.sql is split into statements no larger than this so
            # each fits the host's max_allowed_packet (MariaDB default 16 MB).
            "max_statement_bytes": int(os.getenv("SEARCH_LOAD_MAX_STATEMENT_BYTES", "1000000")),
        },
    }
