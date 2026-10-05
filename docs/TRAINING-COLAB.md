# Rebuild / retrain on Google Colab

The complete, self-contained runbook for rebuilding the search index and the semantic vectors, now or months from now, with a free Google Colab GPU. Follow it top to bottom; nothing else is needed.

**Why Colab.** The embedding model is `BAAI/bge-m3` (560M parameters), a large model. On a CPU the ~24.7k-product catalog takes about an hour, and an entry GPU without tensor cores (a GTX 1650, for example) takes hours. A free Colab **T4** does the full catalog in about **3 minutes** of embedding. Colab is the recommended build path; a local GPU works but is slow ([PIPELINE.md](PIPELINE.md#where-to-build-gpu)).

**What you produce** (one build gives both, from the same export, so both servers hold the same catalog):

| artifact | goes to | what it is |
| --- | --- | --- |
| `release.zip` | cPanel host | server code + the bundle (`products.load.sql`, `meta.json`, spellcheck, synonyms, aliases, a mock `vectors.bin`) |
| `vps_vectors/` (downloaded as `vps_vectors.zip`) | the vector-service VM (the VPS) | the real bge-m3 product vectors |

**When to rerun it:** after a catalog update, after editing `pipeline/aliases.json`, or after any schema/ranking change that adds a column to the load file (for example the M23 `normalized_collapsed` column). Keys mentioned below are defined in [CONFIGURATION.md](CONFIGURATION.md).

## Step 0 — export first, where the database is local

On the server where the OpenCart database is local (the export tool is Python 3.6-compatible for exactly this reason):

```bash
cd <repo checkout on that server>
pip install "pymysql<1.1"      # once
cp .env.example .env           # fill OC_DB_HOST / OC_DB_USER / OC_DB_PASSWORD / OC_DB_NAME (.env is gitignored)
python3 pipeline/db_export.py  # writes export.csv
# ...
# Wrote 24701 rows to export.csv
```

**Write down the row count it prints** (24701 in this example); step 7 checks against it. Then move `export.csv` to the machine you will upload from (your PC). Details of the export and its query: [PIPELINE.md](PIPELINE.md#exporting-from-opencart-db_exportpy); the surrounding update flow: [DEPLOY.md](DEPLOY.md#every-catalog-update).

## Step 1 — open Colab

Go to <https://colab.research.google.com>, sign in with a Google account, and choose **New notebook**.

## Step 2 — choose the GPU

**Runtime → Change runtime type → Hardware accelerator: GPU (T4) → Save.**

## Step 3 — confirm the GPU

Run this cell:

```
!nvidia-smi
```

It must show **Tesla T4**. If it says no GPU, see the gotchas at the end.

## Step 4 — clone the repository

```
!git clone https://github.com/rasulsh/test-search-engine
```

The checkout lands in `/content/test-search-engine`. (If the repository is private, clone with a personal access token instead: `!git clone https://<user>:<token>@github.com/rasulsh/test-search-engine`, or upload a zip of the repo through the Files pane and unzip it. Do not leave the token in a notebook you share.)

## Step 5 — install the dependencies

```
!pip install -q phpserialize==1.3 "sentence-transformers>=2.2"
```

Colab already ships torch with CUDA: **do not reinstall torch**. (`numpy` is also preinstalled. The pipeline needs Python 3.11 or newer; check with `!python --version`. Colab's default is new enough at the time of writing.)

## Step 6 — upload `export.csv` reliably

A direct upload of a large file through the browser can silently truncate it. Upload a compressed archive instead:

1. On your machine, in the folder holding `export.csv`:
   ```bash
   tar -czf export.tgz export.csv
   ```
   (Windows 10/11 includes `tar`.)
2. In Colab open the **Files** pane (folder icon, left side) and upload `export.tgz`.
3. Unpack it in Colab:
   ```
   !tar -xzf /content/export.tgz -C /content
   ```

A truncated archive fails to unpack with an error, instead of quietly yielding a short CSV.

## Step 7 — verify the row count before building

```python
import csv, sys
csv.field_size_limit(sys.maxsize)
print(sum(1 for _ in csv.DictReader(open('/content/export.csv', encoding='utf-8', newline=''))))
```

It **must equal the number `db_export.py` printed in step 0** (24701 in the example). If it is lower, the upload was truncated or corrupted: redo step 6. Do not build from a short file; the index would silently miss products.

## Step 8 — build

```
!cd /content/test-search-engine && python pipeline/release.py --csv /content/export.csv --out /content/release.zip
```

Expect, in this order:

```
Embedder: BAAI/bge-m3 on cuda, fp16=on, batch_size=16      <- then a progress bar
Built VPS vectors in /content/vps_vectors: <N> products, BAAI/bge-m3, dim 1024, embedder real
Built release.zip: <N> products, dim 384, embedder mock, <n> files
```

`<N>` must equal the count you verified in step 7. The embedding takes about 3 minutes on a T4, after a one-time model download on a fresh session. The "dim 384, embedder mock" line is correct: the cPanel bundle's `vectors.bin` is a deterministic mock that `/search` never reads (only its size and checksum are validated on reload); the real vectors are the VPS ones (see [DEPLOY.md](DEPLOY.md#every-catalog-update)).

**CUDA out of memory:** lower the embedding batch size with `SEARCH_EMBED_BATCH_SIZE` and rerun, for example:

```
!cd /content/test-search-engine && SEARCH_EMBED_BATCH_SIZE=8 python pipeline/release.py --csv /content/export.csv --out /content/release.zip
```

(the default is 16; try 8, then 4). Other build options, if you need them, are `--aliases FILE` and `--desc-index-chars N` (see [CONFIGURATION.md](CONFIGURATION.md#pipeline-build-machine)).

## Step 9 — download both outputs

```
!cd /content && zip -r vps_vectors.zip vps_vectors
from google.colab import files
files.download('/content/release.zip'); files.download('/content/vps_vectors.zip')
```

(The `!zip` line and the Python lines go in separate cells, or run the Python lines in a cell of their own.) If the browser blocks the second download, allow multiple downloads for the site, or right-click each file in the Files pane and choose Download.

## Step 10 — deploy

Follow [DEPLOY.md](DEPLOY.md), with both artifacts from the **same** build:

- `release.zip` → the cPanel host: unzip into `~/search-service/server/` and `curl` the token-protected reload ([DEPLOY.md, step 3](DEPLOY.md#every-catalog-update)).
- `vps_vectors.zip` → unzip, then rsync the `vps_vectors/` folder to the VPS's `incoming/` and call its `/reload` ([`vps/README.md`](../vps/README.md), "Updating the product vectors").

Then confirm the count on both hosts: `GET /health` on cPanel reports `product_count`, and the VPS `/reload` / `/health` reports `count`; both must equal the number from steps 0 and 7.

## Gotchas

- **Session limits.** Free Colab sessions last about 12 hours at most, and a GPU is **not guaranteed**: if step 3 shows no GPU, or Colab says none is available, retry later, or reconnect and try again.
- **A disconnected session loses everything**: the uploaded file, the installed packages and the outputs. Start again from step 2 and download the outputs (step 9) as soon as the build finishes.
- **Harmless warning.** A message about `HF_TOKEN` / "unauthenticated requests to the Hugging Face Hub" is harmless; the model downloads without a token.
- **Always check the count twice:** in step 7 before building, and through `/health` after the reload (step 10).
- **Model, revision and dimension must not drift.** The pipeline's `SEARCH_VPS_MODEL*` and `SEARCH_VPS_POOLING` must match the VPS (contract 2); a build with different values is refused by the VPS `/reload`. Leave the defaults unless you are deliberately changing the model ([INTEGRATION.md](../INTEGRATION.md#changing-the-model)).
