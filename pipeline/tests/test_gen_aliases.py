"""Tests for gen_aliases.py with a mocked LLM client (no network, no key)."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

import gen_aliases as ga
from normalize import normalize

SETTINGS = {
    "batch_size": 2, "max_variants": 3, "max_variant_chars": 20, "max_name_chars": 60,
}


def fake_client(answers: dict[str, list]):
    calls: list[list[str]] = []

    def client(system: str, user: str) -> str:
        names = json.loads(user)
        calls.append(names)
        return "```json\n" + json.dumps({n: answers[n] for n in names if n in answers}) + "\n```"

    client.calls = calls  # type: ignore[attr-defined]
    return client


def test_collect_names_dedups_and_drops_noise() -> None:
    rows = [
        {"brand": "Sony", "title_en": "Modern Warfare"},
        {"brand": "SONY", "title_en": "12345"},
        {"brand": "X", "title_en": "گوشی"},
        {"brand": "", "title_en": "Modern  Warfare"},
    ]
    names = ga.collect_names(rows, ["far cry 5", "sony", "9"], 60)
    assert names == ["Sony", "Modern Warfare", "far cry 5"]


def test_collect_names_length_cap() -> None:
    assert ga.collect_names([{"brand": "a" * 61}], [], 60) == []


def test_batches() -> None:
    assert ga.batches(["a", "b", "c"], 2) == [["a", "b"], ["c"]]


def test_parse_reply_tolerates_fences_and_rejects_garbage() -> None:
    assert ga.parse_reply('```json\n{"a": ["ب"]}\n```') == {"a": ["ب"]}
    for bad in ("nothing", "{bad json}", "[1]"):
        with pytest.raises(ValueError):
            ga.parse_reply(bad)


def test_validate_variants() -> None:
    out = ga.validate_variants(
        "Sony",
        ["سونی", "سونی", " ", "sony", 5, "x" * 30, "سُونی ", "ب", "پ", "ت"],
        3, 20,
    )
    # The duplicate, empty, Latin, non-string and over-long entries are dropped; the
    # diacritic spelling is a duplicate after normalization; the cap is 3.
    assert out == ["سونی", "ب", "پ"]
    assert ga.validate_variants("Sony", "سونی", 3, 20) == []


def test_generate_batches_and_candidate_format() -> None:
    answers = {
        "Modern Warfare": ["مدرن وارفار", "مدرن وارفیر"],
        "Sony": ["سونی"],
        "Bad": "not a list",
    }
    client = fake_client(answers)
    groups = ga.generate(["Modern Warfare", "Sony", "Bad", "Unknown"], client, SETTINGS)
    assert client.calls == [["Modern Warfare", "Sony"], ["Bad", "Unknown"]]
    assert groups == [["Modern Warfare", "مدرن وارفار", "مدرن وارفیر"], ["Sony", "سونی"]]


def test_generate_skips_a_bad_batch(capsys: pytest.CaptureFixture[str]) -> None:
    replies = iter(["oops", '{"Sony": ["سونی"]}'])

    def client(system: str, user: str) -> str:
        return next(replies)

    groups = ga.generate(["A1", "B1", "Sony"], client, SETTINGS)
    assert groups == [["Sony", "سونی"]]
    assert "batch 1: skipped" in capsys.readouterr().err


def test_merge_groups_dedups_and_extends() -> None:
    existing = [["Sony", "سونی"], ["gta", "جی تی ای"]]
    candidates = [
        ["SONY", "سوني", "سونی استور"],      # Arabic yeh is a known term; the new one joins
        ["Valve", "ولو"],                    # new group
        ["Valve", "والو"],                   # extends the group added just before
        ["gta", "جی تی ای"],                 # nothing new
    ]
    merged = ga.merge_groups(existing, candidates)
    assert merged[0] == ["Sony", "سونی", "سونی استور"]
    assert merged[1] == ["gta", "جی تی ای"]
    assert merged[2] == ["Valve", "ولو", "والو"]
    assert len(merged) == 3
    # Idempotent: merging the same candidates again changes nothing.
    assert ga.merge_groups(merged, candidates) == merged


def test_generated_variants_match_english_through_the_alias_loader(tmp_path: Path) -> None:
    candidates = [["Modern Warfare", "مدرن وارفار", "مدرن وارفیر"]]
    aliases = tmp_path / "aliases.json"
    ga._write_groups(aliases, ga.merge_groups([], candidates))
    (group,) = ga._keyword.load_aliases(aliases)
    assert normalize("Modern Warfare") in group
    assert normalize("مدرن  وارفار") in group  # spacing variants normalize the same
    assert normalize("مدرن وارفیر") in group


def test_cli_generate_then_merge(tmp_path: Path) -> None:
    csv_path = tmp_path / "export.csv"
    csv_path.write_text(
        "id,title_fa,title_en,desc,brand,category,model,sku,price,stock,url,image,popularity,"
        "attributes,feature,tags\n"
        "1,بازی,Modern Warfare,,Sony,,,,,,,,,,,\n",
        encoding="utf-8",
    )
    out = tmp_path / "generated.json"
    aliases = tmp_path / "aliases.json"
    aliases.write_text('[["Sony", "سونی"]]', encoding="utf-8")
    client = fake_client({"Modern Warfare": ["مدرن وارفار"], "Sony": ["سونی", "سونی‌"]})

    assert ga.main(["--csv", str(csv_path), "--out", str(out)], client=client) == 0
    assert json.loads(out.read_text(encoding="utf-8")) == [
        ["Sony", "سونی"], ["Modern Warfare", "مدرن وارفار"]
    ]
    assert json.loads(aliases.read_text(encoding="utf-8")) == [["Sony", "سونی"]]  # untouched

    assert ga.main(["--merge", str(out), "--aliases", str(aliases)]) == 0
    assert json.loads(aliases.read_text(encoding="utf-8")) == [
        ["Sony", "سونی"], ["Modern Warfare", "مدرن وارفار"]
    ]


def test_cli_requires_llm_settings(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    for key in ("ALIAS_LLM_BASE_URL", "ALIAS_LLM_MODEL", "ALIAS_LLM_API_KEY"):
        monkeypatch.delenv(key, raising=False)
    with pytest.raises(SystemExit):
        ga.main(["--csv", str(tmp_path / "x.csv")])
