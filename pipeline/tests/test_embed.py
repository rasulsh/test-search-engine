"""Tests for the embedder (mock determinism, dim, L2, prefixes)."""

from __future__ import annotations

import sys
import types

import numpy as np
import pytest

import embed
from embed import (
    PASSAGE_PREFIX,
    MockEmbedder,
    create_embedder,
    embed_passages,
    l2_normalize,
)


def test_passage_prefix() -> None:
    assert PASSAGE_PREFIX == "passage: "


def test_mock_is_deterministic_correct_dim_and_l2() -> None:
    embedder = MockEmbedder(384)
    a = embedder.embed(["گوشی موبایل", "laptop"])
    b = embedder.embed(["گوشی موبایل", "laptop"])

    assert a.shape == (2, 384)
    assert a.dtype == np.float32
    np.testing.assert_array_equal(a, b)  # same input -> same vectors
    np.testing.assert_allclose(np.linalg.norm(a, axis=1), [1.0, 1.0], atol=1e-6)


def test_mock_distinguishes_texts() -> None:
    embedder = MockEmbedder(384)
    vectors = embedder.embed(["apple", "samsung"])
    assert not np.array_equal(vectors[0], vectors[1])


def test_l2_normalize_handles_zero_row() -> None:
    out = l2_normalize(np.zeros((1, 4), dtype=np.float32))
    assert np.all(out == 0.0)  # zero row stays zero, no divide-by-zero


def test_create_embedder_mock_and_unknown() -> None:
    embedder = create_embedder({"embedder": "mock", "model": {"dim": 8}})
    assert isinstance(embedder, MockEmbedder)
    assert embedder.dim == 8

    with pytest.raises(ValueError):
        create_embedder({"embedder": "nope", "model": {"dim": 8}})


def test_embed_passages_applies_prefix() -> None:
    embedder = MockEmbedder(16)
    prefixed = embed_passages(embedder, ["laptop"])
    manual = embedder.embed([PASSAGE_PREFIX + "laptop"])
    np.testing.assert_array_equal(prefixed, manual)


# --- M22: device / fp16 / batch selection (no GPU, no model download) --------

def test_resolve_device() -> None:
    assert embed.resolve_device("auto", cuda=True) == "cuda"
    assert embed.resolve_device("auto", cuda=False) == "cpu"
    assert embed.resolve_device("", cuda=True) == "cuda"
    assert embed.resolve_device("cpu", cuda=True) == "cpu"  # explicit override wins
    assert embed.resolve_device("CUDA:1", cuda=True) == "cuda:1"
    with pytest.raises(ValueError, match="cuda"):
        embed.resolve_device("cuda", cuda=False)  # never a silent CPU fallback


def test_resolve_fp16() -> None:
    assert embed.resolve_fp16("auto", "cuda") is True
    assert embed.resolve_fp16("auto", "cpu") is False
    assert embed.resolve_fp16("off", "cuda") is False
    assert embed.resolve_fp16("on", "cuda:0") is True
    with pytest.raises(ValueError):
        embed.resolve_fp16("on", "cpu")
    with pytest.raises(ValueError):
        embed.resolve_fp16("maybe", "cuda")


def test_fp16_product_vectors_stay_cosine_compatible() -> None:
    # Numeric bound only: half-precision rounding of a unit vector, renormalized
    # in float32 as RealEmbedder does, keeps cosine to the exact vector ~1.
    rng = np.random.default_rng(0)
    exact = l2_normalize(rng.standard_normal((200, 1024)))
    half = l2_normalize(exact.astype(np.float16).astype(np.float32))
    cosine = np.sum(exact * half, axis=1)
    assert cosine.min() > 0.9999


class _FakeModel(list):
    calls: dict = {}

    def __init__(self, name: str, revision: str, device: str = "cpu") -> None:
        super().__init__()
        _FakeModel.calls = {"device": device, "half": False}

    def half(self) -> None:
        _FakeModel.calls["half"] = True

    def encode(self, texts: list[str], **kwargs: object) -> np.ndarray:
        _FakeModel.calls["encode"] = kwargs
        return np.ones((len(texts), 4), dtype=np.float16) * 3


def _install_fake(monkeypatch: pytest.MonkeyPatch, cuda: bool) -> None:
    module = types.ModuleType("sentence_transformers")
    module.SentenceTransformer = _FakeModel
    monkeypatch.setitem(sys.modules, "sentence_transformers", module)
    monkeypatch.setattr(embed, "cuda_available", lambda: cuda)


def _real_config(**embed_settings: str | int) -> dict:
    return {"embedder": "real", "model": {"name": "m", "revision": "r", "dim": 4},
            "embed": {"device": "auto", "fp16": "auto", "batch_size": 16, **embed_settings}}


def test_create_embedder_on_gpu_uses_fp16_and_batch(monkeypatch: pytest.MonkeyPatch) -> None:
    _install_fake(monkeypatch, cuda=True)
    embedder = create_embedder(_real_config(batch_size=8))
    assert (_FakeModel.calls["device"], _FakeModel.calls["half"]) == ("cuda", True)

    vectors = embedder.embed(["a", "b"])
    assert _FakeModel.calls["encode"]["batch_size"] == 8
    assert _FakeModel.calls["encode"]["show_progress_bar"] is True
    assert vectors.dtype == np.float32  # fp16 output is cast back and re-normalized
    np.testing.assert_allclose(np.linalg.norm(vectors, axis=1), [1.0, 1.0], atol=1e-6)


def test_create_embedder_cpu_and_overrides(monkeypatch: pytest.MonkeyPatch) -> None:
    _install_fake(monkeypatch, cuda=False)
    create_embedder(_real_config())
    assert (_FakeModel.calls["device"], _FakeModel.calls["half"]) == ("cpu", False)

    _install_fake(monkeypatch, cuda=True)
    create_embedder(_real_config(device="cpu"))
    assert (_FakeModel.calls["device"], _FakeModel.calls["half"]) == ("cpu", False)
    create_embedder(_real_config(fp16="off"))
    assert (_FakeModel.calls["device"], _FakeModel.calls["half"]) == ("cuda", False)

    with pytest.raises(ValueError, match="batch"):
        create_embedder(_real_config(batch_size=0))


def test_embed_config_comes_from_env(monkeypatch: pytest.MonkeyPatch) -> None:
    import config as pipeline_config

    monkeypatch.setenv("SEARCH_EMBED_DEVICE", "cpu")
    monkeypatch.setenv("SEARCH_EMBED_FP16", "off")
    monkeypatch.setenv("SEARCH_EMBED_BATCH_SIZE", "4")
    monkeypatch.delenv("SEARCH_BUNDLE_EMBEDDER", raising=False)
    loaded = pipeline_config.load()
    assert loaded["embed"] == {"device": "cpu", "fp16": "off", "batch_size": 4}
    assert loaded["bundle_embedder"] == "mock"
