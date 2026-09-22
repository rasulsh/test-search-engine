"""Offline model-parity check (CLAUDE.md contract #2).

Embeds the fixed strings in fixtures/parity_strings.json with BOTH query
embedders using the REAL model and asserts each pair's cosine similarity is ~1:

  * Python  — pipeline/embed.py RealEmbedder via embed_queries (this process),
  * JavaScript — client/embedder.js via `node client/tools/embed_queries.mjs`.

Both prepend the same e5 QUERY_PREFIX; identical weights + pooling + L2 must yield
near-identical vectors. Mismatched vectors silently wreck Tier 2 ranking, so run
this on the developer machine before trusting cross-language recall.

This needs the real model download AND node + @xenova/transformers, so it does
NOT run in CI (which uses the deterministic mock embedder). Usage:

    npm --prefix client install @xenova/transformers      # once
    EMBEDDER=real python pipeline/tools/model_parity.py [--threshold 0.99]

Exit code 0 when every pair meets the threshold, 1 otherwise.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

import numpy as np

_REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(_REPO_ROOT / "pipeline"))

import config as pipeline_config  # noqa: E402
from embed import create_embedder, embed_queries  # noqa: E402


def _js_vectors(node_script: Path) -> dict:
    result = subprocess.run(
        ["node", str(node_script)],
        capture_output=True,
        text=True,
        check=True,
        cwd=_REPO_ROOT,
    )
    return json.loads(result.stdout)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Offline client/pipeline model parity check.")
    parser.add_argument("--threshold", type=float, default=0.99, help="Minimum per-pair cosine.")
    args = parser.parse_args(argv)

    strings = json.loads((_REPO_ROOT / "fixtures" / "parity_strings.json").read_text("utf-8"))
    queries = strings["queries"]

    config = pipeline_config.load()
    if config["embedder"] != "real":
        print("Refusing to run parity with the mock embedder; set EMBEDDER=real.", file=sys.stderr)
        return 2

    python_vectors = embed_queries(create_embedder(config), queries)

    node_script = _REPO_ROOT / "client" / "tools" / "embed_queries.mjs"
    js = _js_vectors(node_script)
    js_vectors = np.asarray(js["vectors"], dtype=np.float32)

    if js_vectors.shape != python_vectors.shape:
        print(
            f"Shape mismatch: python {python_vectors.shape} vs js {js_vectors.shape}",
            file=sys.stderr,
        )
        return 1

    ok = True
    print(f"{'cosine':>8}  query")
    for query, p_vec, j_vec in zip(queries, python_vectors, js_vectors, strict=True):
        cosine = float(np.dot(p_vec, j_vec))  # both L2-normalized
        flag = "" if cosine >= args.threshold else "  <-- BELOW THRESHOLD"
        print(f"{cosine:8.4f}  {query}{flag}")
        ok = ok and cosine >= args.threshold

    print(f"\nthreshold={args.threshold}  ->  {'PASS' if ok else 'FAIL'}")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
