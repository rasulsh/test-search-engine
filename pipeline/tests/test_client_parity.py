"""Static model-parity guard for the browser client (CLAUDE.md contract #2).

The real cross-implementation numerical check needs the model + a browser and
runs offline (pipeline/tools/model_parity.py). What CI CAN guarantee cheaply is
that the constants declared in client/embedder.js still agree with the pipeline
config, embed.QUERY_PREFIX, and the server config — so the three sides can never
silently drift apart. This test parses those constants textually (no model
download, no node).
"""

from __future__ import annotations

import re
from pathlib import Path

import config as pipeline_config
from embed import QUERY_PREFIX

REPO_ROOT = Path(__file__).resolve().parents[2]
EMBEDDER_JS = REPO_ROOT / "client" / "embedder.js"
SERVER_CONFIG = REPO_ROOT / "server" / "config.example.php"


def _js_const(name: str) -> str:
    text = EMBEDDER_JS.read_text(encoding="utf-8")
    match = re.search(rf"export const {name}\s*=\s*(.+?);", text)
    assert match, f"constant {name} not found in embedder.js"
    return match.group(1).strip()


def _js_string(name: str) -> str:
    raw = _js_const(name)
    assert raw[0] == raw[-1] and raw[0] in "'\"", f"{name} is not a string literal: {raw}"
    return raw[1:-1]


def _php_fallback(env_var: str) -> str:
    """Extract the committed default a config value falls back to when unset."""
    text = SERVER_CONFIG.read_text(encoding="utf-8")
    match = re.search(rf"getenv\('{env_var}'\)[^?]*\?:\s*'?([^',)]+)'?", text)
    assert match, f"fallback for {env_var} not found in config.example.php"
    return match.group(1).strip()


def test_js_constants_match_pipeline_config() -> None:
    model = pipeline_config.load()["model"]
    assert _js_string("MODEL_ID") == model["name"]
    assert _js_string("MODEL_REVISION") == model["revision"]
    assert int(_js_const("EMBEDDING_DIM")) == model["dim"]


def test_js_query_prefix_matches_embed_module() -> None:
    # The asymmetric e5 prefix is the single most breakage-prone constant.
    assert _js_string("QUERY_PREFIX") == QUERY_PREFIX


def test_js_constants_match_server_config() -> None:
    assert _js_string("MODEL_ID") == _php_fallback("SEARCH_MODEL")
    assert _js_string("MODEL_REVISION") == _php_fallback("SEARCH_MODEL_REVISION")
    assert int(_js_const("EMBEDDING_DIM")) == int(_php_fallback("SEARCH_MODEL_DIM"))


def test_pooling_and_normalization_declared() -> None:
    # Parity also requires mean pooling + L2 normalization (contract #2).
    assert _js_string("POOLING") == "mean"
    assert _js_const("NORMALIZE") == "true"
