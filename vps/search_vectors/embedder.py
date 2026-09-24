"""Query embedders: the bge-m3 ONNX build on CPU, or the deterministic mock.

Contract 2: the pipeline embeds product passages with sentence-transformers
(pipeline/embed.py); here the same model's ONNX export embeds queries with the
same pooling and L2 normalization, so the two are cosine-comparable. The mock
is byte-identical to pipeline/embed.py's MockEmbedder (tests enforce it).
"""

from __future__ import annotations

import hashlib
from typing import Any, Protocol

import numpy as np

from .config import Settings


def l2_normalize(vectors: np.ndarray) -> np.ndarray:
    vectors = np.asarray(vectors, dtype=np.float32)
    norms = np.linalg.norm(vectors, axis=1, keepdims=True)
    norms[norms == 0.0] = 1.0
    return (vectors / norms).astype(np.float32)


class Embedder(Protocol):
    dim: int

    def embed(self, texts: list[str]) -> np.ndarray:
        """(len(texts), dim) float32, L2-normalized."""
        ...


class MockEmbedder:
    def __init__(self, dim: int) -> None:
        self.dim = dim

    def embed(self, texts: list[str]) -> np.ndarray:
        out = np.empty((len(texts), self.dim), dtype=np.float32)
        for i, text in enumerate(texts):
            seed = int.from_bytes(hashlib.sha256(text.encode("utf-8")).digest()[:8], "big")
            out[i] = np.random.default_rng(seed).standard_normal(self.dim)
        return l2_normalize(out)


class OnnxEmbedder:
    """Transformer encoder export (last_hidden_state output) + pooling + L2."""

    def __init__(self, session: Any, tokenizer: Any, pooling: str, dim: int) -> None:
        self.dim = dim
        self._session = session
        self._tokenizer = tokenizer
        self._pooling = pooling
        self._inputs = {i.name for i in session.get_inputs()}
        # XLM-RoBERTa derives position ids from the pad id, so pad with the real one.
        self._pad_id = tokenizer.token_to_id("<pad>") or 0

    @classmethod
    def load(cls, settings: Settings) -> OnnxEmbedder:
        import onnxruntime as ort
        from tokenizers import Tokenizer

        tokenizer = Tokenizer.from_file(str(settings.model_dir / "tokenizer.json"))
        tokenizer.enable_truncation(settings.max_tokens)
        tokenizer.no_padding()
        options = ort.SessionOptions()
        options.intra_op_num_threads = settings.threads
        session = ort.InferenceSession(
            str(settings.model_dir / settings.onnx_file),
            options,
            providers=["CPUExecutionProvider"],
        )
        return cls(session, tokenizer, settings.pooling, settings.dim)

    def embed(self, texts: list[str]) -> np.ndarray:
        encodings = self._tokenizer.encode_batch(texts)
        width = max(len(e.ids) for e in encodings)
        ids = np.full((len(texts), width), self._pad_id, dtype=np.int64)
        mask = np.zeros((len(texts), width), dtype=np.int64)
        for row, encoding in enumerate(encodings):
            ids[row, : len(encoding.ids)] = encoding.ids
            mask[row, : len(encoding.ids)] = 1
        feed = {"input_ids": ids, "attention_mask": mask}
        if "token_type_ids" in self._inputs:
            feed["token_type_ids"] = np.zeros_like(ids)
        hidden = np.asarray(self._session.run(None, feed)[0], dtype=np.float32)
        if hidden.ndim != 3 or hidden.shape[2] != self.dim:
            raise ValueError(f"model output {hidden.shape}, expected (batch, tokens, {self.dim})")
        if self._pooling == "cls":
            pooled = hidden[:, 0]
        else:
            weights = mask[:, :, None].astype(np.float32)
            pooled = (hidden * weights).sum(axis=1) / np.maximum(weights.sum(axis=1), 1.0)
        return l2_normalize(pooled)


def create_embedder(settings: Settings) -> Embedder:
    if settings.embedder == "mock":
        return MockEmbedder(settings.dim)
    return OnnxEmbedder.load(settings)
