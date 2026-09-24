"""Query embedders: mock parity with the pipeline, ONNX pooling/feeding logic."""

from __future__ import annotations

from types import SimpleNamespace

import numpy as np
import pytest

import embed as pipeline_embed
from search_vectors.embedder import MockEmbedder, OnnxEmbedder

TEXTS = ["گوشی موبایل سامسونگ", "sony wireless headphones", ""]


def test_mock_is_identical_to_pipeline_mock() -> None:
    np.testing.assert_array_equal(
        MockEmbedder(1024).embed(TEXTS), pipeline_embed.MockEmbedder(1024).embed(TEXTS)
    )


class FakeTokenizer:
    """ids = one token per word, plus a leading CLS (0)."""

    def token_to_id(self, token: str) -> int | None:
        return 1 if token == "<pad>" else None

    def encode_batch(self, texts: list[str]):
        return [SimpleNamespace(ids=[0] + [len(w) + 2 for w in t.split()]) for t in texts]


class FakeSession:
    """last_hidden_state[b, t] = (input id, attention mask, 1, 0...) so the
    pooling result shows exactly which tokens were used."""

    def __init__(self, inputs: tuple[str, ...], dim: int = 4) -> None:
        self._inputs = inputs
        self.dim = dim
        self.feeds: list[dict] = []

    def get_inputs(self):
        return [SimpleNamespace(name=n) for n in self._inputs]

    def run(self, _outputs, feed: dict):
        self.feeds.append(feed)
        ids, mask = feed["input_ids"], feed["attention_mask"]
        hidden = np.zeros(ids.shape + (self.dim,), dtype=np.float32)
        hidden[..., 0] = ids
        hidden[..., 1] = mask
        hidden[..., 2] = 1.0
        return [hidden]


def test_cls_pooling_takes_first_token_and_normalizes() -> None:
    session = FakeSession(("input_ids", "attention_mask"))
    out = OnnxEmbedder(session, FakeTokenizer(), "cls", 4).embed(["a bb", "ccc"])
    expected = np.array([[0, 1, 1, 0], [0, 1, 1, 0]], dtype=np.float32) / np.sqrt(2)
    np.testing.assert_allclose(out, expected, rtol=1e-6)
    feed = session.feeds[0]
    assert feed["input_ids"].tolist() == [[0, 3, 4], [0, 5, 1]]  # padded with <pad> id
    assert feed["attention_mask"].tolist() == [[1, 1, 1], [1, 1, 0]]
    assert "token_type_ids" not in feed


def test_mean_pooling_ignores_padding() -> None:
    session = FakeSession(("input_ids", "attention_mask", "token_type_ids"))
    out = OnnxEmbedder(session, FakeTokenizer(), "mean", 4).embed(["a bb", "ccc"])
    # Row 2 mean over its two real tokens (ids 0, 5), not the pad.
    raw = np.array([[7 / 3, 1, 1, 0], [2.5, 1, 1, 0]], dtype=np.float32)
    np.testing.assert_allclose(out, raw / np.linalg.norm(raw, axis=1, keepdims=True), rtol=1e-6)
    assert session.feeds[0]["token_type_ids"].tolist() == [[0, 0, 0], [0, 0, 0]]


def test_dim_mismatch_is_an_error() -> None:
    with pytest.raises(ValueError, match="expected"):
        OnnxEmbedder(FakeSession(("input_ids", "attention_mask")), FakeTokenizer(), "cls",
                     1024).embed(["x"])
