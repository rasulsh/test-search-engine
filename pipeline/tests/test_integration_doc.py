"""INTEGRATION.md must document every error code and reload reason the server emits.

The storefront and operators code against INTEGRATION.md, so a new error code or
`invalid_bundle` reason added to the PHP without a doc update is a silent contract
break. Parsed textually; no PHP runtime needed.
"""

from __future__ import annotations

import re
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DOC = (REPO_ROOT / "INTEGRATION.md").read_text(encoding="utf-8")


def _error_codes() -> set[str]:
    codes: set[str] = set()
    for php in (REPO_ROOT / "server" / "public").glob("*.php"):
        codes |= set(re.findall(r"'error'\s*=>\s*'([a-z_]+)'", php.read_text(encoding="utf-8")))
    return codes


def _reload_reasons() -> set[str]:
    source = (REPO_ROOT / "server" / "src" / "Reload.php").read_text(encoding="utf-8")
    return set(re.findall(r"new ReloadException\(\s*'([a-z_]+)'", source))


def test_every_endpoint_error_code_is_documented() -> None:
    codes = _error_codes()
    assert {"invalid_json", "unauthorized", "reload_disabled", "internal_error"} <= codes
    missing = sorted(code for code in codes if f"`{code}`" not in DOC)
    assert not missing, f"error codes missing from INTEGRATION.md: {missing}"


def test_every_reload_reason_is_documented() -> None:
    reasons = _reload_reasons()
    assert {"model_mismatch", "dim_mismatch", "count_mismatch"} <= reasons
    missing = sorted(reason for reason in reasons if f"`{reason}`" not in DOC)
    assert not missing, f"invalid_bundle reasons missing from INTEGRATION.md: {missing}"
