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


# --- M36: the zero-result loop (server/tools/logs-export.php --grouped) -----------

GROUPED_CSV = (
    "normalized_q,searches,last_seen\n"
    "رزیدنت اویل,84,2026-10-23 20:59:28\n"
    "پی اس فایو,40,2026-10-23 20:53:28\n"
    '"ولورینگ, تازه",9,2026-10-23 20:50:00\n'
    "پی  اس فایو,3,2026-10-22 10:00:00\n"
    "x,1,2026-10-22 10:00:00\n"
)


def test_read_queries_file_reads_the_grouped_export(tmp_path: Path) -> None:
    path = tmp_path / "zero.csv"
    path.write_text(GROUPED_CSV, encoding="utf-8")

    # File order kept; the spacing duplicate of an earlier query dropped; Persian-only kept.
    assert ga.read_queries_file(path, 60) == ["رزیدنت اویل", "پی اس فایو", "ولورینگ, تازه", "x"]
    assert ga.read_queries_file(path, 5) == ["x"]


def test_read_queries_file_accepts_a_bom_and_a_raw_export(tmp_path: Path) -> None:
    path = tmp_path / "raw.csv"
    path.write_text("ts,raw_q,normalized_q\n2026-01-01,GTA V,gta 5\n", encoding="utf-8-sig")

    assert ga.read_queries_file(path, 60) == ["gta 5"]
    path.write_text("ts,raw_q\n2026-01-01,GTA V\n", encoding="utf-8")
    assert ga.read_queries_file(path, 60) == ["GTA V"]


def test_read_queries_file_rejects_a_file_without_a_query_column(tmp_path: Path) -> None:
    path = tmp_path / "other.csv"
    path.write_text("a,b\n1,2\n", encoding="utf-8")

    with pytest.raises(ValueError, match="normalized_q"):
        ga.read_queries_file(path, 60)


def test_exporter_header_and_reader_agree() -> None:
    """The PHP exporter's grouped header must stay what gen_aliases reads."""
    source = (Path(__file__).resolve().parents[2] / "server/src/LogsExport.php").read_text(
        encoding="utf-8"
    )
    columns = source.split("GROUPED_COLUMNS = [")[1].split("]")[0]
    assert "'normalized_q'" in columns


def test_validate_alternatives_keeps_either_script_and_drops_noise() -> None:
    assert ga.validate_alternatives(
        "رزیدنت اویل",
        ["Resident Evil", "رزیدنت  اویل", "resident evil", "123", "", 5, "x" * 30,
         "رزیدنت ایول", "Resident Evil 2", "extra"],
        3, 20,
    ) == ["Resident Evil", "رزیدنت ایول", "Resident Evil 2"]
    assert ga.validate_alternatives("q", "not a list", 3, 20) == []


def test_generate_for_queries_uses_the_query_prompt_and_makes_alias_groups() -> None:
    seen: list[str] = []

    def client(system: str, user: str) -> str:
        seen.append(system)
        queries = json.loads(user)
        return json.dumps({q: ["Resident Evil"] for q in queries if q == "رزیدنت اویل"})

    groups = ga.generate(["رزیدنت اویل", "کلمه"], client, SETTINGS, ga.QUERIES_PROMPT_PATH,
                         ga.validate_alternatives)

    assert groups == [["رزیدنت اویل", "Resident Evil"]]
    assert "found NOTHING" in seen[0]


def test_cli_queries_file_to_candidates_then_merge(tmp_path: Path) -> None:
    export = tmp_path / "zero.csv"
    export.write_text(GROUPED_CSV, encoding="utf-8")
    out = tmp_path / "generated.json"
    aliases = tmp_path / "aliases.json"
    aliases.write_text('[["Sony", "سونی"]]', encoding="utf-8")
    client = fake_client({"رزیدنت اویل": ["Resident Evil"], "پی اس فایو": ["PS5", "PlayStation 5"]})

    # No --csv: only the exported queries are asked about.
    assert ga.main(["--queries-file", str(export), "--out", str(out)], client=client) == 0

    assert json.loads(out.read_text(encoding="utf-8")) == [
        ["رزیدنت اویل", "Resident Evil"], ["پی اس فایو", "PS5", "PlayStation 5"]
    ]
    assert client.calls[0][0] == "رزیدنت اویل"  # type: ignore[attr-defined]
    assert json.loads(aliases.read_text(encoding="utf-8")) == [["Sony", "سونی"]]  # untouched

    assert ga.main(["--merge", str(out), "--aliases", str(aliases)]) == 0
    merged = json.loads(aliases.read_text(encoding="utf-8"))
    assert ["Sony", "سونی"] in merged
    assert ["رزیدنت اویل", "Resident Evil"] in merged


def test_cli_queries_file_alongside_the_catalog_and_limit(tmp_path: Path) -> None:
    csv_path = tmp_path / "export.csv"
    csv_path.write_text(
        "id,title_fa,title_en,desc,brand,category,model,sku,price,stock,url,image,popularity,"
        "attributes,feature,tags\n"
        "1,بازی,Modern Warfare,,,,,,,,,,,,,\n",
        encoding="utf-8",
    )
    export = tmp_path / "zero.csv"
    export.write_text(GROUPED_CSV, encoding="utf-8")
    out = tmp_path / "generated.json"
    client = fake_client({"Modern Warfare": ["مدرن وارفار"], "رزیدنت اویل": ["Resident Evil"]})

    ga.main(["--csv", str(csv_path), "--queries-file", str(export), "--limit", "1",
             "--out", str(out)], client=client)

    assert json.loads(out.read_text(encoding="utf-8")) == [
        ["Modern Warfare", "مدرن وارفار"], ["رزیدنت اویل", "Resident Evil"]
    ]


def test_cli_needs_csv_or_queries_file() -> None:
    with pytest.raises(SystemExit):
        ga.main([], client=fake_client({}))


def test_cli_bad_queries_file_is_a_usage_error(tmp_path: Path,
                                               capsys: pytest.CaptureFixture[str]) -> None:
    bad = tmp_path / "bad.csv"
    bad.write_text("a,b\n1,2\n", encoding="utf-8")
    with pytest.raises(SystemExit) as exc:
        ga.main(["--queries-file", str(bad)], client=fake_client({}))
    assert exc.value.code == 2
    assert "normalized_q" in capsys.readouterr().err
