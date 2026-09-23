"""Download everything the browser needs to embed queries with NO third-party requests.

Run once on the developer machine (the only place that talks to npm/HuggingFace),
then upload the output folder next to the test page (see README, "Self-hosted
browser assets"). Every file is pinned by version/commit AND checksum, so an
upstream change can never silently swap the model or runtime.

Output layout (default: <repo>/client/, gitignored except embedder.js):

    vendor/transformers.min.js, vendor/ort-wasm*.wasm   transformers.js runtime
    model/<MODEL_ID>/config.json, tokenizer*.json, ...   tokenizer + config
    model/<MODEL_ID>/onnx/model_quantized.onnx           int8 ONNX weights
    fonts/Vazirmatn-Variable.woff2, fonts/OFL.txt        UI font + its licence

Model parity (contract 2): MODEL_ID is read from pipeline config — the SAME value
embed.py uses. The browser weights are the transformers.js (ONNX) conversion of
those exact weights; the script checks the downloaded config.json names the
configured model and dim. Run pipeline/tools/model_parity.py afterwards to verify
numerically.

Usage:
    python pipeline/tools/fetch_web_model.py [--out DIR] [--force]
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import sys
import tarfile
import urllib.request
from collections.abc import Callable
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT / "pipeline"))

import config as pipeline_config  # noqa: E402

DEFAULT_OUT = REPO_ROOT / "client"

# transformers.js 2.x: the version client/embedder.js and the parity check use.
TRANSFORMERS_TARBALL = {
    "url": "https://registry.npmjs.org/@xenova/transformers/-/transformers-2.17.2.tgz",
    "integrity": (
        "sha512-lZmHqzrVIkSvZdKZEx7IYY51TK0WDrC8eR0c5IMnBsO"
        "8di8are1zzw8BlLhyO2TklZKLN5UffNGs1IJwT6oOqQ=="
    ),
    "files": {
        "package/dist/transformers.min.js": "vendor/transformers.min.js",
        "package/dist/ort-wasm.wasm": "vendor/ort-wasm.wasm",
        "package/dist/ort-wasm-simd.wasm": "vendor/ort-wasm-simd.wasm",
        "package/dist/ort-wasm-threaded.wasm": "vendor/ort-wasm-threaded.wasm",
        "package/dist/ort-wasm-simd-threaded.wasm": "vendor/ort-wasm-simd-threaded.wasm",
    },
}

FONT_TARBALL = {
    "url": "https://registry.npmjs.org/vazirmatn/-/vazirmatn-33.0.3.tgz",
    "integrity": (
        "sha512-fbjNc0CMjazZpIegWzz9OHGzI1APFboT+x7ZecXlUCt"
        "De/nkyx7gtCTZnWS/+eeXG7fbfXymCPKJI8EsnM3iqw=="
    ),
    "files": {
        "package/fonts/webfonts/Vazirmatn[wght].woff2": "fonts/Vazirmatn-Variable.woff2",
        "package/OFL.txt": "fonts/OFL.txt",
    },
}

# Browser (ONNX) conversions keyed by the configured model id. The intfloat repo
# does not publish onnx/model_quantized.onnx, which transformers.js loads by
# default, so the Xenova conversion of the same weights is used, pinned to a
# commit. Changing the model means adding its pinned entry here.
WEB_MODELS: dict[str, dict] = {
    "intfloat/multilingual-e5-small": {
        "repo": "Xenova/multilingual-e5-small",
        "revision": "761b726dd34fb83930e26aab4e9ac3899aa1fa78",
        "files": {
            "config.json":
                "cb99455288675345e1a4f411438d5d0adbba5fbd3a67ea4fb03c015433b996c1",
            "tokenizer.json":
                "0b44a9d7b51c3c62626640cda0e2c2f70fdacdc25bbbd68038369d14ebdf4c39",
            "tokenizer_config.json":
                "a1d6bc8734a6f635dc158508bef000f8e2e5a759c7d92f984b2c86e5ff53425b",
            "special_tokens_map.json":
                "d05497f1da52c5e09554c0cd874037a083e1dc1b9cfd48034d1c717f1afc07a7",
            "onnx/model_quantized.onnx":
                "f80102d3f2a1229f387d3c81909990d8945513e347b0eab049f7de3c6f98c193",
        },
    },
}

Fetch = Callable[[str], bytes]


class FetchError(RuntimeError):
    pass


def http_get(url: str) -> bytes:
    with urllib.request.urlopen(url, timeout=120) as response:  # noqa: S310 (pinned https URLs)
        return response.read()


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def check_integrity(data: bytes, integrity: str) -> None:
    """Verify an npm-style Subresource Integrity string (sha512-<base64>)."""
    algorithm, _, expected = integrity.partition("-")
    actual = base64.b64encode(hashlib.new(algorithm, data).digest()).decode("ascii")
    if actual != expected:
        raise FetchError(f"integrity mismatch: expected {integrity}, got {algorithm}-{actual}")


def write_file(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".part")
    tmp.write_bytes(data)
    tmp.replace(path)  # never leave a truncated asset behind


def fetch_tarball(spec: dict, out: Path, fetch: Fetch, force: bool) -> list[Path]:
    targets = [out / rel for rel in spec["files"].values()]
    if not force and all(t.is_file() for t in targets):
        return []
    data = fetch(spec["url"])
    check_integrity(data, spec["integrity"])
    written = []
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as archive:
        for member, rel in spec["files"].items():
            extracted = archive.extractfile(member)
            if extracted is None:
                raise FetchError(f"{member} not found in {spec['url']}")
            write_file(out / rel, extracted.read())
            written.append(out / rel)
    return written


def fetch_model(model_id: str, dim: int, out: Path, fetch: Fetch, force: bool) -> list[Path]:
    if model_id not in WEB_MODELS:
        raise FetchError(
            f"no pinned browser conversion for model {model_id!r}; add it to WEB_MODELS"
        )
    spec = WEB_MODELS[model_id]
    model_dir = out / "model" / model_id
    written = []
    for rel, expected in spec["files"].items():
        target = model_dir / rel
        if not force and target.is_file() and sha256_hex(target.read_bytes()) == expected:
            continue
        url = f"https://huggingface.co/{spec['repo']}/resolve/{spec['revision']}/{rel}"
        data = fetch(url)
        actual = sha256_hex(data)
        if actual != expected:
            raise FetchError(f"sha256 mismatch for {rel}: expected {expected}, got {actual}")
        write_file(target, data)
        written.append(target)
    check_model_config(model_dir / "config.json", model_id, dim)
    return written


def check_model_config(path: Path, model_id: str, dim: int) -> None:
    """The browser weights must be the configured model at the configured dim."""
    model_config = json.loads(path.read_text("utf-8"))
    if model_config.get("_name_or_path") != model_id:
        raise FetchError(f"{path} is a conversion of {model_config.get('_name_or_path')!r}")
    if model_config.get("hidden_size") != dim:
        raise FetchError(f"{path} has hidden_size {model_config.get('hidden_size')}, want {dim}")


def fetch_all(out: Path, fetch: Fetch = http_get, force: bool = False) -> list[Path]:
    model = pipeline_config.load()["model"]
    written = fetch_tarball(TRANSFORMERS_TARBALL, out, fetch, force)
    written += fetch_tarball(FONT_TARBALL, out, fetch, force)
    written += fetch_model(model["name"], int(model["dim"]), out, fetch, force)
    return written


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Fetch self-hosted browser assets.")
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT, help="Output folder.")
    parser.add_argument("--force", action="store_true", help="Re-download existing files.")
    args = parser.parse_args(argv)
    try:
        written = fetch_all(args.out, force=args.force)
    except (FetchError, OSError) as exc:
        print(f"fetch failed: {exc}", file=sys.stderr)
        return 1
    for path in written:
        print(f"wrote {path}")
    print(f"assets ready in {args.out} ({len(written)} file(s) downloaded)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
