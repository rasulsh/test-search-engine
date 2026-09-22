"""Contract 1 (normalization parity), Python side.

Asserts normalize() matches the shared fixture, which is the same file the PHP
suite checks Normalizer.php against — so both implementations agree.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from normalize import NORMALIZATION_VERSION, normalize

REPO_ROOT = Path(__file__).resolve().parents[2]
FIXTURE = json.loads(
    (REPO_ROOT / "fixtures" / "normalization_cases.json").read_text(encoding="utf-8")
)


def test_version_matches_fixture() -> None:
    assert FIXTURE["normalization_version"] == NORMALIZATION_VERSION


@pytest.mark.parametrize(
    "case",
    FIXTURE["cases"],
    ids=[c["name"] for c in FIXTURE["cases"]],
)
def test_normalization_matches_expected(case: dict[str, str]) -> None:
    assert normalize(case["input"]) == case["expected"]


@pytest.mark.parametrize(
    "case",
    FIXTURE["cases"],
    ids=[c["name"] for c in FIXTURE["cases"]],
)
def test_normalization_is_idempotent(case: dict[str, str]) -> None:
    assert normalize(case["expected"]) == case["expected"]


def test_none_and_empty_normalize_to_empty() -> None:
    assert normalize(None) == ""
    assert normalize("") == ""
