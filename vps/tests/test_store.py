"""Vector loading/validation, cosine top-K, and the atomic reload swap."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pytest
from conftest import DIM, make_settings, unit, write_vectors

from search_vectors.store import BundleError, Index, ReloadBusy, Store, load_vectors, top_k

RNG = np.random.default_rng(7)


def random_index(count: int = 300) -> Index:
    ids = np.arange(1000, 1000 + count, dtype=np.int64)
    return Index(ids, unit(RNG.standard_normal((count, DIM))), {})


def test_top_k_matches_brute_force() -> None:
    index = random_index()
    query = unit(RNG.standard_normal((1, DIM)))[0]
    hits = top_k(index, query, 10, -1.0)

    scores = index.vectors @ query
    expected = [int(index.ids[i]) for i in np.argsort(-scores)[:10]]
    assert [pid for pid, _ in hits] == expected
    assert [s for _, s in hits] == sorted((s for _, s in hits), reverse=True)
    np.testing.assert_allclose([s for _, s in hits], np.sort(scores)[::-1][:10], rtol=1e-6)


def test_top_k_finds_exact_vector_first_with_score_one() -> None:
    index = random_index()
    hits = top_k(index, index.vectors[42], 3, -1.0)
    assert hits[0][0] == int(index.ids[42])
    assert hits[0][1] == pytest.approx(1.0, abs=1e-5)


def test_floor_drops_low_cosine() -> None:
    ids = np.array([1, 2, 3], dtype=np.int64)
    # Cosines with the query [1,0,...]: 1.0, 0.6, -0.6.
    rows = np.zeros((3, DIM), dtype=np.float32)
    rows[0, 0] = 1
    rows[1, :2] = [0.6, 0.8]
    rows[2, :2] = [-0.6, 0.8]
    index = Index(ids, rows, {})
    query = rows[0]

    assert [pid for pid, _ in top_k(index, query, 10, -1.0)] == [1, 2, 3]
    assert [pid for pid, _ in top_k(index, query, 10, 0.5)] == [1, 2]
    assert [pid for pid, _ in top_k(index, query, 10, 0.61)] == [1]
    assert top_k(index, -query, 10, 0.7) == []


def test_limit_larger_than_count_and_ties_by_id() -> None:
    rows = unit(np.ones((3, DIM)))
    index = Index(np.array([30, 10, 20], dtype=np.int64), rows, {})
    assert [pid for pid, _ in top_k(index, rows[0], 99, 0.0)] == [10, 20, 30]


def test_load_accepts_valid_vectors(settings, tmp_path: Path) -> None:
    vectors = unit(RNG.standard_normal((5, DIM)))
    write_vectors(tmp_path / "v", settings, [5, 4, 3, 2, 1], vectors)
    index = load_vectors(tmp_path / "v", settings)
    assert index.ids.tolist() == [5, 4, 3, 2, 1]
    np.testing.assert_array_equal(index.vectors, vectors)


@pytest.mark.parametrize(
    ("meta", "message"),
    [
        ({"model": "intfloat/multilingual-e5-small"}, "model"),
        ({"revision": "main"}, "revision"),
        ({"dim": 384}, "dim"),
        ({"pooling": "mean"}, "pooling"),
        ({"query_prefix": "query: "}, "query_prefix"),
        ({"embedder": "real"}, "embedder"),
        ({"count": 4}, "bytes"),
        ({"count": 0}, "count"),
        ({"checksum": "0" * 64}, "checksum"),
    ],
)
def test_load_rejects_mismatched_meta(settings, tmp_path: Path, meta, message) -> None:
    write_vectors(tmp_path / "v", settings, [1, 2, 3, 4, 5],
                  unit(RNG.standard_normal((5, DIM))), **meta)
    with pytest.raises(BundleError, match=message):
        load_vectors(tmp_path / "v", settings)


def test_real_service_rejects_mock_vectors(tmp_path: Path) -> None:
    real = make_settings(tmp_path / "data", VPS_EMBEDDER="onnx")
    write_vectors(tmp_path / "v", real, [1], unit(np.ones((1, DIM))), embedder="mock")
    with pytest.raises(BundleError, match="embedder"):
        load_vectors(tmp_path / "v", real)


@pytest.mark.parametrize(
    ("ids", "rows", "message"),
    [
        ([1, 2, 3], unit(np.ones((2, DIM))), "lines"),  # idx longer than vectors
        ([1, 1], unit(np.ones((2, DIM))), "duplicate"),
        ([1, "x"], unit(np.ones((2, DIM))), "vectors.idx"),
        ([1, 2], np.ones((2, DIM), dtype=np.float32), "L2"),
        ([1, 2], np.full((2, DIM), np.nan, dtype=np.float32), "non-finite"),
    ],
)
def test_load_rejects_bad_files(settings, tmp_path: Path, ids, rows, message) -> None:
    write_vectors(tmp_path / "v", settings, ids, rows, count=len(rows))
    with pytest.raises(BundleError, match=message):
        load_vectors(tmp_path / "v", settings)


def test_load_rejects_missing_or_corrupt_files(settings, tmp_path: Path) -> None:
    write_vectors(tmp_path / "v", settings, [1], unit(np.ones((1, DIM))))
    (tmp_path / "v" / "meta.json").write_text("{not json", encoding="utf-8")
    with pytest.raises(BundleError, match="unreadable"):
        load_vectors(tmp_path / "v", settings)
    (tmp_path / "v" / "meta.json").unlink()
    with pytest.raises(BundleError, match="unreadable"):
        load_vectors(tmp_path / "v", settings)


def _ids(settings, which: Path) -> list[int]:
    return load_vectors(which, settings).ids.tolist()


def test_reload_swaps_on_disk_and_in_memory(settings) -> None:
    write_vectors(settings.vectors_dir, settings, [1, 2], unit(RNG.standard_normal((2, DIM))))
    store = Store(settings)
    store.load_active()
    assert store.index.ids.tolist() == [1, 2]

    write_vectors(settings.incoming_dir, settings, [7, 8, 9], unit(RNG.standard_normal((3, DIM))))
    index = store.reload()

    assert index is store.index and store.index.ids.tolist() == [7, 8, 9]
    assert _ids(settings, settings.vectors_dir) == [7, 8, 9]
    assert _ids(settings, settings.previous_dir) == [1, 2]  # one-step rollback kept
    assert not settings.incoming_dir.exists()


def test_rejected_reload_changes_nothing(settings) -> None:
    write_vectors(settings.vectors_dir, settings, [1, 2], unit(RNG.standard_normal((2, DIM))))
    write_vectors(settings.previous_dir, settings, [0], unit(RNG.standard_normal((1, DIM))))
    store = Store(settings)
    store.load_active()
    served = store.index

    write_vectors(settings.incoming_dir, settings, [7], unit(np.ones((1, DIM))), dim=1024)
    with pytest.raises(BundleError, match="dim"):
        store.reload()

    assert store.index is served
    assert _ids(settings, settings.vectors_dir) == [1, 2]
    assert _ids(settings, settings.previous_dir) == [0]
    assert json.loads((settings.incoming_dir / "meta.json").read_text())["dim"] == 1024


def test_reload_without_upload_is_rejected(settings) -> None:
    with pytest.raises(BundleError, match="no vectors uploaded"):
        Store(settings).reload()


def test_first_reload_with_no_active(settings) -> None:
    store = Store(settings)
    store.load_active()
    assert store.index is None
    write_vectors(settings.incoming_dir, settings, [3], unit(np.ones((1, DIM))))
    assert store.reload().ids.tolist() == [3]
    assert not settings.previous_dir.exists()


def test_failed_rename_restores_active(settings, monkeypatch: pytest.MonkeyPatch) -> None:
    write_vectors(settings.vectors_dir, settings, [1], unit(np.ones((1, DIM))))
    write_vectors(settings.incoming_dir, settings, [2], unit(np.ones((1, DIM))))
    store = Store(settings)
    store.load_active()
    served = store.index
    original = Path.rename

    def rename(self: Path, target):
        if self == settings.incoming_dir:
            raise OSError("disk full")
        return original(self, target)

    monkeypatch.setattr(Path, "rename", rename)
    with pytest.raises(OSError):
        store.reload()
    assert store.index is served
    assert _ids(settings, settings.vectors_dir) == [1]


def test_concurrent_reload_is_refused(settings) -> None:
    store = Store(settings)
    store._reload_lock.acquire()
    try:
        with pytest.raises(ReloadBusy):
            store.reload()
    finally:
        store._reload_lock.release()


def test_startup_falls_back_to_previous_after_interrupted_swap(settings) -> None:
    # Crash between "active -> previous" and "incoming -> active".
    write_vectors(settings.previous_dir, settings, [4, 5], unit(RNG.standard_normal((2, DIM))))
    store = Store(settings)
    store.load_active()
    assert store.index.ids.tolist() == [4, 5]


def test_startup_skips_invalid_active(settings) -> None:
    write_vectors(settings.vectors_dir, settings, [1], unit(np.ones((1, DIM))), checksum="bad")
    store = Store(settings)
    store.load_active()
    assert store.index is None


def test_latency_guard_20k_products() -> None:
    """Regression guard (not an SLA): global top-K over a realistic catalog,
    20k x 1024. Measured ~10 ms on the development machine."""
    import time

    count, dim = 20_000, 1024
    rng = np.random.default_rng(1)
    index = Index(np.arange(count, dtype=np.int64), unit(rng.standard_normal((count, dim))), {})
    query = index.vectors[123]
    top_k(index, query, 100, 0.0)
    started = time.perf_counter()
    for _ in range(5):
        hits = top_k(index, query, 100, 0.0)
    per_query_ms = (time.perf_counter() - started) * 1000 / 5
    assert hits[0][0] == 123
    assert per_query_ms < 150, f"top-K took {per_query_ms:.1f} ms"
