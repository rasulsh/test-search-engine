"""Download the query model's ONNX build, pinned by commit AND sha256.

    python -m search_vectors.fetch_model --out /opt/search-vectors/model [--onnx-file F]

Queries are embedded on CPU with an ONNX export of BAAI/bge-m3 (Xenova/bge-m3);
the pipeline embeds products with the original weights in sentence-transformers.
Same weights, tokenizer, CLS pooling and L2 (contract 2). The int8 build fits a
2 GB VPS at a small, measured drift; the fp32 build is exact but needs ~2.5 GB
RAM (vps/README.md, "Model parity").
"""

from __future__ import annotations

import argparse
import hashlib
import os
import sys
import urllib.request
from pathlib import Path

# Keyed by VPS_MODEL. Changing the model means adding its pinned entry here.
MODELS: dict[str, dict] = {
    "BAAI/bge-m3": {
        "repo": "Xenova/bge-m3",
        "revision": "4de13258303883538bd53b696b452bf8099f0858",
        "files": {
            "config.json": "734a79bf12d388c1467a4e3ab625f45de7f6906cffcfb93a1eca1787504bed95",
            "tokenizer.json": "6710678b12670bc442b99edc952c4d996ae309a7020c1fa0096dd245c2faf790",
        },
        # VPS_ONNX_FILE -> the files it needs (fp32 keeps weights in .onnx_data).
        "onnx": {
            "onnx/model_quantized.onnx": {
                "onnx/model_quantized.onnx":
                    "0826f8c1ab9edf1801db86c61919d4d108e8bfc0b809ec823ad366882ff0b77d",
            },
            "onnx/model.onnx": {
                "onnx/model.onnx":
                    "5d89a0010dd39aa2cfa8b22bb49f06904c5bbf5877135f877da419480f40cde3",
                "onnx/model.onnx_data":
                    "1eebfb28493f67bba03ce0ef64bfdc7fc5a3bd9d7493f818bb1d78cd798416b4",
            },
        },
    },
}


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def fetch(model: str, onnx_file: str, out: Path) -> list[str]:
    """Download missing or mismatched files; return the ones fetched."""
    entry = MODELS.get(model)
    if entry is None or onnx_file not in entry["onnx"]:
        raise SystemExit(f"no pinned ONNX build {onnx_file!r} for {model!r}; add it to MODELS")
    fetched = []
    for relative, sha256 in {**entry["files"], **entry["onnx"][onnx_file]}.items():
        target = out / relative
        if target.is_file() and sha256_file(target) == sha256:
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        url = f"https://huggingface.co/{entry['repo']}/resolve/{entry['revision']}/{relative}"
        tmp = target.with_name(target.name + ".part")
        print(f"downloading {url}", file=sys.stderr)
        with urllib.request.urlopen(url, timeout=60) as response, tmp.open("wb") as handle:
            while block := response.read(1 << 20):
                handle.write(block)
        actual = sha256_file(tmp)
        if actual != sha256:
            tmp.unlink()
            raise SystemExit(f"{relative}: sha256 {actual}, pinned {sha256}")
        os.replace(tmp, target)
        fetched.append(relative)
    return fetched


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--out", required=True, type=Path)
    parser.add_argument("--model", default=os.environ.get("VPS_MODEL", "BAAI/bge-m3"))
    parser.add_argument("--onnx-file",
                        default=os.environ.get("VPS_ONNX_FILE", "onnx/model_quantized.onnx"))
    args = parser.parse_args()
    fetched = fetch(args.model, args.onnx_file, args.out)
    print(f"{args.model}: {len(fetched)} file(s) downloaded into {args.out}")


if __name__ == "__main__":
    main()
