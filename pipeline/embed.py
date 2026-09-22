"""Embedding for the offline pipeline.

Two embedders behind one interface:
- MockEmbedder: deterministic (seeded from a hash of the input), correct dim,
  L2-normalized. Needs no GPU or model download, so CI and the server's cosine
  tests can run without the real model.
- RealEmbedder: sentence-transformers on the developer's GPU machine.

Model parity (contract 2): multilingual-e5 REQUIRES asymmetric prefixes. Product
passages are embedded with PASSAGE_PREFIX here; the M4 browser client MUST embed
queries with QUERY_PREFIX. Vectors from mismatched prefixes are not comparable.
"""

from __future__ import annotations

import hashlib
from typing import Protocol

import numpy as np

# e5 asymmetric prefixes. Do not change one without the other.
PASSAGE_PREFIX = "passage: "
QUERY_PREFIX = "query: "  # M4 client contract: prefix every query with this.


def l2_normalize(vectors: np.ndarray) -> np.ndarray:
    """Row-wise L2 normalization; zero rows are left as zeros. Returns float32."""
    vectors = np.asarray(vectors, dtype=np.float32)
    norms = np.linalg.norm(vectors, axis=1, keepdims=True)
    norms[norms == 0.0] = 1.0
    return (vectors / norms).astype(np.float32)


class Embedder(Protocol):
    dim: int

    def embed(self, texts: list[str]) -> np.ndarray:
        """Return an (len(texts), dim) float32, L2-normalized array."""
        ...


class MockEmbedder:
    """Deterministic stand-in: same text -> same unit vector, across processes."""

    def __init__(self, dim: int) -> None:
        self.dim = dim

    def embed(self, texts: list[str]) -> np.ndarray:
        out = np.empty((len(texts), self.dim), dtype=np.float32)
        for i, text in enumerate(texts):
            digest = hashlib.sha256(text.encode("utf-8")).digest()
            seed = int.from_bytes(digest[:8], "big")
            out[i] = np.random.default_rng(seed).standard_normal(self.dim)
        return l2_normalize(out)


class RealEmbedder:
    """Loads the real model. Imported lazily so mock runs need no ML deps."""

    def __init__(self, model_name: str, revision: str, dim: int) -> None:
        from sentence_transformers import SentenceTransformer

        self.dim = dim
        self._model = SentenceTransformer(model_name, revision=revision)

    def embed(self, texts: list[str]) -> np.ndarray:
        vectors = self._model.encode(texts, convert_to_numpy=True, normalize_embeddings=False)
        vectors = np.asarray(vectors, dtype=np.float32)
        if vectors.ndim != 2 or vectors.shape[1] != self.dim:
            raise ValueError(f"Model returned dim {vectors.shape}, expected {self.dim}")
        return l2_normalize(vectors)


def create_embedder(config: dict) -> Embedder:
    """Build the embedder named by config['embedder'] ('mock' or 'real')."""
    mode = config["embedder"]
    dim = int(config["model"]["dim"])
    if mode == "mock":
        return MockEmbedder(dim)
    if mode == "real":
        model = config["model"]
        return RealEmbedder(model["name"], model["revision"], dim)
    raise ValueError(f"Unknown embedder: {mode!r} (expected 'mock' or 'real')")


def embed_passages(embedder: Embedder, texts: list[str]) -> np.ndarray:
    """Embed product passages with the required e5 passage prefix."""
    return embedder.embed([PASSAGE_PREFIX + text for text in texts])


def embed_queries(embedder: Embedder, texts: list[str]) -> np.ndarray:
    """Embed search queries with the required e5 query prefix.

    This is the offline reference for the browser client (client/embedder.js),
    which MUST prepend the same QUERY_PREFIX. Used by the model-parity check to
    compare the two implementations' query vectors.
    """
    return embedder.embed([QUERY_PREFIX + text for text in texts])
