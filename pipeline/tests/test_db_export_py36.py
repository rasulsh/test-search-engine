"""Guards that db_export.py stays Python 3.6 compatible: it runs on the cPanel
server (Python 3.6.8) beside the OpenCart DB; the rest of the pipeline is modern."""

from __future__ import annotations

import ast
import shutil
import subprocess
from pathlib import Path

import pytest

SOURCE = Path(__file__).resolve().parents[1] / "db_export.py"
BUILTIN_GENERICS = {"list", "dict", "tuple", "set", "frozenset", "type"}
NEWER_STDLIB = {"dataclasses", "zoneinfo", "contextvars", "graphlib", "tomllib"}


def _tree() -> ast.Module:
    return ast.parse(SOURCE.read_text(encoding="utf-8"), feature_version=(3, 6))


def _annotations(tree: ast.Module) -> list[ast.expr]:
    found: list[ast.expr] = []
    for node in ast.walk(tree):
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            found += [a.annotation for a in node.args.args + node.args.kwonlyargs if a.annotation]
            if node.returns:
                found.append(node.returns)
        elif isinstance(node, ast.AnnAssign):
            found.append(node.annotation)
    return found


def test_no_future_annotations_import() -> None:
    # `from __future__ import annotations` is a SyntaxError on 3.6.
    futures = [n for n in ast.walk(_tree()) if isinstance(n, ast.ImportFrom)
               and n.module == "__future__"]
    assert futures == []


def test_no_pep585_or_pep604_annotations() -> None:
    for annotation in _annotations(_tree()):
        for node in ast.walk(annotation):
            assert not (isinstance(node, ast.BinOp) and isinstance(node.op, ast.BitOr)), \
                "X | Y annotation needs 3.10; use typing.Optional / Union"
            assert not (isinstance(node, ast.Subscript) and isinstance(node.value, ast.Name)
                        and node.value.id in BUILTIN_GENERICS), \
                "list[...] style annotation needs 3.9; use typing.List / Dict / Tuple"


def test_no_newer_stdlib_imports() -> None:
    imported = set()
    for node in ast.walk(_tree()):
        if isinstance(node, ast.Import):
            imported |= {a.name.split(".")[0] for a in node.names}
        elif isinstance(node, ast.ImportFrom) and node.module:
            imported.add(node.module.split(".")[0])
    assert imported & NEWER_STDLIB == set()


def test_vermin_minimum_version_is_3_6() -> None:
    vermin = shutil.which("vermin")
    if vermin is None:
        pytest.skip("vermin is not installed")
    result = subprocess.run([vermin, "--no-tips", "--target=3.6-", "--violations", str(SOURCE)],
                            capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr


def test_compiles_under_a_real_python_3_6_when_available() -> None:
    python36 = shutil.which("python3.6")
    if python36 is None:
        pytest.skip("python3.6 is not installed")
    result = subprocess.run([python36, "-m", "py_compile", str(SOURCE)],
                            capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
