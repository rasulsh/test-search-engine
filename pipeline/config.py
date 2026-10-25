"""Pipeline configuration, read from the environment.

Mirrors the normalization setting of server/config.example.php and holds the VPS
model. Never hardcode model, dimension, or thresholds elsewhere — read them from here.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

from normalize import NORMALIZATION_VERSION


def load() -> dict[str, Any]:
    return {
        # The cPanel bundle carries no vectors and no model since M42; /reload checks the
        # normalization version only.
        "model": {
            "normalization_version": int(
                os.getenv("SEARCH_NORMALIZATION_VERSION", str(NORMALIZATION_VERSION))
            ),
        },
        # Model of the VPS vector service (vps/, build.py --vps-out). Contract 2:
        # vps/search_vectors/config.py embeds queries with the same model,
        # revision, pooling and prefixes; the VPS rejects vectors whose meta.json
        # disagrees. bge-m3 dense retrieval takes no query/passage instruction.
        "vps_model": {
            "name": os.getenv("SEARCH_VPS_MODEL", "BAAI/bge-m3"),
            "revision": os.getenv(
                "SEARCH_VPS_MODEL_REVISION", "5617a9f61b028005a4858fdac845db406aefb181"
            ),
            "dim": int(os.getenv("SEARCH_VPS_MODEL_DIM", "1024")),
            "pooling": os.getenv("SEARCH_VPS_POOLING", "cls"),
            "query_prefix": os.getenv("SEARCH_VPS_QUERY_PREFIX", ""),
            "passage_prefix": os.getenv("SEARCH_VPS_PASSAGE_PREFIX", ""),
        },
        # "mock" needs no GPU/model download; "real" loads the model.
        "embedder": os.getenv("EMBEDDER", "mock"),
        # RealEmbedder knobs (embed.py). Defaults fit bge-m3 on a 4 GB GPU.
        "embed": {
            # auto = cuda when torch sees a GPU, else cpu; or cpu / cuda / cuda:N.
            "device": os.getenv("SEARCH_EMBED_DEVICE", "auto"),
            # auto = half precision on CUDA only; on / off force it.
            "fp16": os.getenv("SEARCH_EMBED_FP16", "auto"),
            "batch_size": int(os.getenv("SEARCH_EMBED_BATCH_SIZE", "16")),
        },
        # Offline alias generator (gen_aliases.py, docs/ALIASES.md): any
        # OpenAI-compatible chat endpoint. Never read at search time.
        "alias_llm": {
            "base_url": os.getenv("ALIAS_LLM_BASE_URL", ""),
            "model": os.getenv("ALIAS_LLM_MODEL", ""),
            "api_key": os.getenv("ALIAS_LLM_API_KEY", ""),
            "batch_size": int(os.getenv("ALIAS_LLM_BATCH_SIZE", "40")),
            "max_variants": int(os.getenv("ALIAS_MAX_VARIANTS", "4")),
            "max_variant_chars": int(os.getenv("ALIAS_MAX_VARIANT_CHARS", "40")),
            "max_name_chars": int(os.getenv("ALIAS_MAX_NAME_CHARS", "60")),
        },
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
            # Cap on the whole embedded passage (titles, specs, description) so it
            # stays within the model's token limit; the
            # description gives way first. 0 = no cap.
            "passage_char_limit": int(os.getenv("SEARCH_PASSAGE_CHAR_LIMIT", "1000")),
            # Only this many leading characters of the cleaned description are
            # keyword-indexed (normalized_desc); the full text is kept for
            # display. Deep spec text otherwise matches queries for things the
            # shop does not sell and bloats FULLTEXT. 0 indexes the whole text.
            # Raised from 400 in M15: attributes / feature titles are indexed in
            # full as specs and the semantic floor gates description-only hits.
            "desc_index_chars": int(os.getenv("SEARCH_DESC_INDEX_CHARS", "800")),
            # Deprecated (M41): aliases live on the server (server/storage/aliases.json, edited in
            # the admin panel) and are not part of the bundle. This file is only the gen_aliases.py
            # --merge target and a seed for new installs. A missing file means no aliases.
            "aliases_file": os.getenv(
                "SEARCH_ALIASES_FILE", str(Path(__file__).with_name("aliases.json"))
            ),
            # The shop owner's editable intent map (docs/SEARCH-BEHAVIOR.md, intent
            # map), shipped in the bundle as intent_map.json. A missing file means none.
            "intent_map_file": os.getenv(
                "SEARCH_INTENT_MAP_FILE", str(Path(__file__).with_name("intent_map.json"))
            ),
            # products.load.sql is split into statements no larger than this so
            # each fits the host's max_allowed_packet (MariaDB default 16 MB).
            "max_statement_bytes": int(os.getenv("SEARCH_LOAD_MAX_STATEMENT_BYTES", "1000000")),
        },
    }
