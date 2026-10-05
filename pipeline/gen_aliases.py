"""Offline alias generator (M25): English catalog names -> Persian spellings.

    python pipeline/gen_aliases.py --csv export.csv [--queries zero_results.txt]
        [--out aliases.generated.json] [--limit N]
    python pipeline/gen_aliases.py --merge [aliases.generated.json]

Step 1 asks an OpenAI-compatible LLM for the Persian spellings shoppers type
for each distinct English brand / title_en (and optional zero-result queries)
and writes CANDIDATES to aliases.generated.json for human review. Step 2
(--merge) folds the reviewed file into aliases.json. It runs at prep time only;
search never calls it. aliases.json is never written without --merge.
"""

from __future__ import annotations

# Same stdlib `keyword` shadowing guard as build.py (this directory is
# sys.path[0] when run as a script).
import os as _os
import sys as _sys

_HERE = _os.path.dirname(_os.path.abspath(__file__))
if _sys.path and _os.path.abspath(_sys.path[0]) == _HERE:
    _sys.path.pop(0)
    import importlib

    importlib.import_module("keyword")
    _sys.path.insert(0, _HERE)

import argparse
import importlib.util
import json
import re
import urllib.request
from collections.abc import Callable, Iterable
from pathlib import Path
from typing import Any

import config as pipeline_config
from normalize import normalize

_kw_spec = importlib.util.spec_from_file_location(
    "catalog_keyword", Path(__file__).with_name("keyword.py")
)
assert _kw_spec is not None and _kw_spec.loader is not None
_keyword = importlib.util.module_from_spec(_kw_spec)
_kw_spec.loader.exec_module(_keyword)

PROMPT_PATH = Path(__file__).with_name("gen_aliases_prompt.txt")
_PERSIAN = re.compile(r"[؀-ۿ]")
_LATIN = re.compile(r"[a-z]")

# A chat client: (system prompt, user prompt) -> the model's text reply.
LLMClient = Callable[[str, str], str]


# --- Input -------------------------------------------------------------------


def clean_name(raw: str | None, max_chars: int) -> str:
    """Whitespace-collapsed name, or "" for noise: too short or long, no Latin
    letters (pure numbers, Persian-only text) or a single character."""
    name = " ".join((raw or "").split())
    if not 2 <= len(name) <= max_chars or not _LATIN.search(name.lower()):
        return ""
    return name


def collect_names(
    rows: Iterable[dict[str, Any]], queries: Iterable[str], max_chars: int
) -> list[str]:
    """Distinct brand / title_en names, then queries, de-duplicated by their
    normalized form (first spelling wins), noise dropped, order kept."""
    seen: set[str] = set()
    names: list[str] = []

    def add(raw: str | None) -> None:
        name = clean_name(raw, max_chars)
        key = normalize(name)
        if name and key and key not in seen:
            seen.add(key)
            names.append(name)

    for row in rows:
        add(row.get("brand"))
        add(row.get("title_en"))
    for query in queries:
        add(query)
    return names


def batches(names: list[str], size: int) -> list[list[str]]:
    return [names[i:i + size] for i in range(0, len(names), size)]


# --- LLM ---------------------------------------------------------------------


def openai_client(base_url: str, model: str, api_key: str, timeout: int = 120) -> LLMClient:
    """Minimal OpenAI-compatible /chat/completions client (no extra dependency)."""
    url = base_url.rstrip("/") + "/chat/completions"

    def call(system: str, user: str) -> str:
        body = json.dumps({
            "model": model,
            "temperature": 0,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }).encode("utf-8")
        request = urllib.request.Request(url, data=body, headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}",
        })
        with urllib.request.urlopen(request, timeout=timeout) as response:  # noqa: S310
            data = json.loads(response.read().decode("utf-8"))
        return str(data["choices"][0]["message"]["content"])

    return call


def parse_reply(text: str) -> dict[str, list[Any]]:
    """The JSON object {english: [persian, ...]} out of a reply (code fences
    tolerated). Anything else raises ValueError."""
    match = re.search(r"\{.*\}", text, re.DOTALL)
    if not match:
        raise ValueError("no JSON object in the reply")
    try:
        data = json.loads(match.group(0))
    except json.JSONDecodeError as exc:
        raise ValueError(f"invalid JSON in the reply: {exc}") from exc
    if not isinstance(data, dict):
        raise ValueError("reply is not a JSON object")
    return data


def validate_variants(
    english: str, variants: Any, max_variants: int, max_chars: int
) -> list[str]:
    """Persian spellings worth keeping: strings with Persian letters, within the
    length cap, distinct after normalization from each other and the English
    name; at most max_variants."""
    if not isinstance(variants, list):
        return []
    seen = {normalize(english)}
    kept: list[str] = []
    for variant in variants:
        if not isinstance(variant, str):
            continue
        text = " ".join(variant.split())
        key = normalize(text)
        if not text or len(text) > max_chars or not _PERSIAN.search(text) or key in seen:
            continue
        seen.add(key)
        kept.append(text)
        if len(kept) >= max_variants:
            break
    return kept


def generate(names: list[str], client: LLMClient, settings: dict[str, Any]) -> list[list[str]]:
    """Candidate groups [english, *persian] for every name the model answered
    validly. A bad batch reply is skipped (reported on stderr), not fatal."""
    system = PROMPT_PATH.read_text(encoding="utf-8")
    groups: list[list[str]] = []
    for number, batch in enumerate(batches(names, settings["batch_size"]), start=1):
        try:
            reply = parse_reply(client(system, json.dumps(batch, ensure_ascii=False)))
        except (ValueError, OSError, KeyError) as exc:
            print(f"batch {number}: skipped ({exc})", file=_sys.stderr)
            continue
        for english in batch:
            variants = validate_variants(
                english, reply.get(english),
                settings["max_variants"], settings["max_variant_chars"],
            )
            if variants:
                groups.append([english, *variants])
    return groups


# --- Merge -------------------------------------------------------------------


def merge_groups(existing: list[list[str]], candidates: list[list[str]]) -> list[list[str]]:
    """Fold candidate groups into the existing ones. A candidate whose
    normalized terms are all known to one group adds nothing; one that shares a
    term with an existing group extends that group with its new terms; the rest
    are appended. Existing groups and their spelling are never reordered."""
    merged = [list(group) for group in existing]
    index: dict[str, int] = {}
    for position, group in enumerate(merged):
        for term in group:
            index.setdefault(_term_key(term), position)

    for candidate in candidates:
        terms = [t for t in candidate if _term_key(t)]
        target = next((index[_term_key(t)] for t in terms if _term_key(t) in index), None)
        if target is None:
            merged.append([])
            target = len(merged) - 1
        for term in terms:
            key = _term_key(term)
            if key not in index:
                index[key] = target
                merged[target].append(term)
    return [group for group in merged if len(group) > 1]


def _term_key(term: str) -> str:
    return " ".join(_keyword.tokenize(normalize(term)))


# --- CLI ---------------------------------------------------------------------


def _read_json_groups(path: Path) -> list[list[str]]:
    data = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else []
    if not isinstance(data, list) or not all(
        isinstance(g, list) and all(isinstance(t, str) for t in g) for g in data
    ):
        raise ValueError(f"{path}: expected a list of groups of strings")
    return data


def _write_groups(path: Path, groups: list[list[str]]) -> None:
    lines = [json.dumps(group, ensure_ascii=False) for group in groups]
    path.write_text("[\n  " + ",\n  ".join(lines) + "\n]\n" if lines else "[]\n", encoding="utf-8")


def _read_queries(path: str | None) -> list[str]:
    if not path:
        return []
    return [ln.strip() for ln in Path(path).read_text(encoding="utf-8").splitlines() if ln.strip()]


def main(argv: list[str] | None = None, client: LLMClient | None = None) -> int:
    settings = pipeline_config.load()["alias_llm"]
    default_out = Path(__file__).with_name("aliases.generated.json")
    parser = argparse.ArgumentParser(description="Generate Persian alias candidates.")
    parser.add_argument("--csv", help="export.csv (columns brand, title_en).")
    parser.add_argument("--queries", help="Text file, one zero-result query per line.")
    parser.add_argument("--out", default=str(default_out), help="Candidate file.")
    parser.add_argument("--limit", type=int, default=0, help="Only the first N names (0 = all).")
    parser.add_argument("--merge", nargs="?", const=str(default_out), metavar="CANDIDATES",
                        help="Fold a reviewed candidate file into the alias file.")
    parser.add_argument("--aliases", default=pipeline_config.load()["build"]["aliases_file"],
                        help="Alias file --merge updates (default SEARCH_ALIASES_FILE).")
    args = parser.parse_args(argv)

    if args.merge:
        aliases = Path(args.aliases)
        existing = _read_json_groups(aliases)
        merged = merge_groups(existing, _read_json_groups(Path(args.merge)))
        _write_groups(aliases, merged)
        _keyword.load_aliases(aliases)  # fail now, not at the next build
        print(f"merged into {aliases}: {len(existing)} -> {len(merged)} groups")
        return 0

    if not args.csv:
        parser.error("--csv is required unless --merge is given")
    if client is None:
        missing = [k for k in ("base_url", "model", "api_key") if not settings[k]]
        if missing:
            parser.error("set ALIAS_LLM_BASE_URL, ALIAS_LLM_MODEL and ALIAS_LLM_API_KEY")
        client = openai_client(settings["base_url"], settings["model"], settings["api_key"])

    import build  # heavy import, only needed to read the export

    names = collect_names(build.read_products(args.csv), _read_queries(args.queries),
                          settings["max_name_chars"])
    if args.limit:
        names = names[:args.limit]
    groups = generate(names, client, settings)
    _write_groups(Path(args.out), groups)
    print(f"{len(names)} names -> {len(groups)} candidate groups in {args.out}; "
          "review, then run with --merge")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
