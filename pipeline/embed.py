"""Embedding for the offline pipeline.

Two embedders behind one interface:
- MockEmbedder: deterministic (seeded from a hash of the input), correct dim,
  L2-normalized. Needs no GPU or model download, so CI and the server's cosine
  tests can run without the real model.
- RealEmbedder: sentence-transformers on the developer's GPU machine (device,
  fp16 and batch size come from config['embed']; see resolve_device).

What is embedded is the passage composed by build.compose_passage (M21: titles,
tags, brand, category, model, features, attributes, description); this module
only prefixes and embeds it, for the mock and real paths alike.

The same code embeds for two models: config['model'] (e5, the cPanel bundle)
and config['vps_model'] (bge-m3, the VPS vector service, which embeds queries
since M18; contract 2 is between this file and vps/search_vectors).
"""

from __future__ import annotations

import hashlib
from typing import Protocol

import numpy as np

# e5 passage prefix (the cPanel bundle's model).
PASSAGE_PREFIX = "passage: "


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


def cuda_available() -> bool:
    try:
        import torch
    except ImportError:
        return False
    return bool(torch.cuda.is_available())


def resolve_device(setting: str, cuda: bool) -> str:
    """'auto' picks cuda when torch sees a GPU, else cpu; an explicit value
    ('cpu', 'cuda', 'cuda:1') is kept, but asking for cuda without one is an
    error rather than a silent hour-long CPU run."""
    setting = (setting or "auto").strip().lower()
    if setting == "auto":
        return "cuda" if cuda else "cpu"
    if setting.startswith("cuda") and not cuda:
        raise ValueError(
            f"SEARCH_EMBED_DEVICE={setting!r} but torch.cuda.is_available() is False "
            "(install the CUDA build of torch, see docs/PIPELINE.md 'Where to build (GPU)')"
        )
    return setting


def resolve_fp16(setting: str, device: str) -> bool:
    """'auto' = half precision on CUDA only; 'on'/'off' force it. fp16 on a CPU
    is slower and unsupported by some ops, so it is refused."""
    setting = (setting or "auto").strip().lower()
    on_gpu = device.startswith("cuda")
    if setting == "auto":
        return on_gpu
    if setting in ("1", "true", "on", "yes"):
        if not on_gpu:
            raise ValueError("SEARCH_EMBED_FP16 is on but the device is not CUDA")
        return True
    if setting in ("0", "false", "off", "no"):
        return False
    raise ValueError(f"SEARCH_EMBED_FP16={setting!r} (expected auto, on or off)")


class RealEmbedder:
    """Loads the real model. Imported lazily so mock runs need no ML deps.

    fp16 only changes the precision of the forward pass: outputs are cast back
    to float32 and L2-normalized here, so contract 2 (model, revision, pooling,
    prefixes, normalization) is untouched.
    """

    def __init__(
        self,
        model_name: str,
        revision: str,
        dim: int,
        pooling: str | None = None,
        device: str = "cpu",
        fp16: bool = False,
        batch_size: int = 16,
        show_progress: bool = False,
    ) -> None:
        from sentence_transformers import SentenceTransformer

        if batch_size < 1:
            raise ValueError("batch size must be at least 1")
        self.dim = dim
        self.device = device
        self.fp16 = fp16
        self.batch_size = batch_size
        self.show_progress = show_progress
        self._model = SentenceTransformer(model_name, revision=revision, device=device)
        if fp16:
            self._model.half()
        print(f"Embedder: {model_name} on {device}, fp16={'on' if fp16 else 'off'}, "
              f"batch_size={batch_size}", flush=True)
        if pooling is not None:
            # Contract 2: the query side pools as configured, so the model's own
            # pooling (from its sentence-transformers config) must agree.
            actual = [mode for mode in map(_pooling_mode, self._model) if mode]
            if actual != [pooling]:
                raise ValueError(f"{model_name} pools {actual}, config says {pooling!r}")

    def embed(self, texts: list[str]) -> np.ndarray:
        vectors = self._model.encode(
            texts,
            batch_size=self.batch_size,
            show_progress_bar=self.show_progress,
            convert_to_numpy=True,
            normalize_embeddings=False,
        )
        vectors = np.asarray(vectors, dtype=np.float32)
        if vectors.ndim != 2 or vectors.shape[1] != self.dim:
            raise ValueError(f"Model returned dim {vectors.shape}, expected {self.dim}")
        return l2_normalize(vectors)


def _pooling_mode(module: object) -> str | None:
    # sentence-transformers < 6 exposes get_pooling_mode_str(); 6+ a pooling_mode str.
    if hasattr(module, "get_pooling_mode_str"):
        return module.get_pooling_mode_str()
    mode = getattr(module, "pooling_mode", None)
    return mode if isinstance(mode, str) else None


def create_embedder(config: dict, model: dict | None = None) -> Embedder:
    """Build the embedder named by config['embedder'] ('mock' or 'real') for
    `model` (default config['model']; config['vps_model'] for the VPS vectors)."""
    mode = config["embedder"]
    model = config["model"] if model is None else model
    dim = int(model["dim"])
    if mode == "mock":
        return MockEmbedder(dim)
    if mode == "real":
        settings = config.get("embed", {})
        device = resolve_device(settings.get("device", "auto"), cuda_available())
        return RealEmbedder(
            model["name"], model["revision"], dim, model.get("pooling"),
            device=device,
            fp16=resolve_fp16(settings.get("fp16", "auto"), device),
            batch_size=int(settings.get("batch_size", 16)),
            show_progress=True,
        )
    raise ValueError(f"Unknown embedder: {mode!r} (expected 'mock' or 'real')")


def embed_passages(
    embedder: Embedder, texts: list[str], prefix: str = PASSAGE_PREFIX
) -> np.ndarray:
    """Embed product passages with the model's passage prefix (e5 by default)."""
    return embedder.embed([prefix + text for text in texts])
