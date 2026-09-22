"""Tests for the embedder (mock determinism, dim, L2, prefixes)."""

from __future__ import annotations

import numpy as np
import pytest

from embed import (
    PASSAGE_PREFIX,
    QUERY_PREFIX,
    MockEmbedder,
    create_embedder,
    embed_passages,
    l2_normalize,
)


def test_prefixes_are_asymmetric() -> None:
    assert PASSAGE_PREFIX == "passage: "
    assert QUERY_PREFIX == "query: "


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
