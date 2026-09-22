"""M0 scaffold checks: the committed structure and config templates exist.

Replaced/extended by real pipeline unit tests (SQL parsing, normalization,
bundle format) in later milestones.
"""

from __future__ import annotations

from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def test_scaffold_files_exist() -> None:
    expected = [
        ".gitignore",
        ".env.example",
        "README.md",
        "server/config.example.php",
        ".github/workflows/ci.yml",
        "pipeline/requirements.txt",
    ]
    missing = [path for path in expected if not (REPO_ROOT / path).is_file()]
    assert not missing, f"Missing scaffold files: {missing}"


def test_repository_structure_dirs_exist() -> None:
    expected_dirs = ["db", "pipeline", "server", "client", "fixtures"]
    missing = [d for d in expected_dirs if not (REPO_ROOT / d).is_dir()]
    assert not missing, f"Missing structure directories: {missing}"


def test_env_example_has_no_populated_secrets() -> None:
    env = (REPO_ROOT / ".env.example").read_text(encoding="utf-8")
    for key in ("SEARCH_DB_PASSWORD", "SEARCH_RELOAD_TOKEN"):
        line = next(ln for ln in env.splitlines() if ln.startswith(f"{key}="))
        assert line.strip() == f"{key}=", f"{key} must be empty in .env.example"
