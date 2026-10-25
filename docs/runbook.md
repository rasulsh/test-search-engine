# Runbook — rebuild and deploy

Full cycle: export the catalog → build on Colab → update the VPS → update the cPanel
host. Run top to bottom.

Substitute once:
- `<DOMAIN>` — the store domain (e.g. `pspro.ir`); the search API lives at
  `https://<DOMAIN>/search-api`.
- `<VPS_HOST>` — the VPS ssh host; `<VPS_URL>` — the VPS service URL
  (e.g. `https://vsearch.<DOMAIN>`).
- Tokens are read from the environment: `$SEARCH_RELOAD_TOKEN`, `$VPS_TOKEN`.

---

## 1. Export the catalog — on the cPanel host (the DB is local there)

```bash
cd ~/search-service            # the repo checkout on the host
pip install --user "pymysql<1.1"

# DB credentials (once per shell), or put them in a .env next to the command:
export OC_DB_HOST=localhost OC_DB_NAME=<db> OC_DB_USER=<user> OC_DB_PASSWORD='<pass>'

python3 pipeline/db_export.py --out export.csv
# Note the product count it prints. Then download export.csv to your PC.
```

## 2. Build on Google Colab (GPU)

New notebook → Runtime → Change runtime type → **T4 GPU**. Run each cell:

```python
!nvidia-smi
!git clone https://github.com/rasulsh/test-search-engine
!pip install -q phpserialize==1.3 "sentence-transformers>=2.2"
```

Upload `export.csv` (drag it into the Files panel on the left), then:

```python
!wc -l export.csv          # rows minus 1 must equal the count from step 1
!mv export.csv /content/export.csv

!cd test-search-engine && python pipeline/release.py --csv /content/export.csv --out /content/release.zip
# If the GPU runs out of memory, rerun with a smaller batch:
# !cd test-search-engine && SEARCH_EMBED_BATCH_SIZE=8 python pipeline/release.py --csv /content/export.csv --out /content/release.zip

!cd /content && zip -r vps_vectors.zip vps_vectors
```

```python
from google.colab import files
files.download('/content/release.zip')
files.download('/content/vps_vectors.zip')
```

## 3. Update the VPS (vectors) — on your PC

```bash
unzip -o vps_vectors.zip
rsync -a --delete ./vps_vectors/ root@<VPS_HOST>:/var/lib/search-vectors/incoming/
ssh root@<VPS_HOST> chown -R searchvec: /var/lib/search-vectors/incoming
curl -s -X POST https://<VPS_URL>/reload -H "Authorization: Bearer $VPS_TOKEN"
# expect: {"ok":true,"count":<N>,"model":"BAAI/bge-m3",...}
curl -s https://<VPS_URL>/health
```

## 4. Update the cPanel host (code + bundle)

Upload `release.zip` into `~/search-service/server/` (scp, or File Manager → Upload), then:

```bash
cd ~/search-service/server && unzip -o ~/release.zip
php tools/config-check.php          # must print no ERROR; fix config.php if it does
curl -sS -X POST -H "X-Reload-Token: $SEARCH_RELOAD_TOKEN" \
     "https://<DOMAIN>/search-api/reload.php?load=1"
# expect: {"ok":true,"count":<N>,...}
curl -sS "https://<DOMAIN>/search-api/health.php"   # product_count == <N>, config.ok true
```

## 5. Verify

```bash
curl -sS -X POST "https://<DOMAIN>/search-api/search.php" \
     -H 'Content-Type: application/json' -d '{"q":"دوال شاک","limit":5}'
```

Log in at `https://<DOMAIN>/search-api/admin.php` and open the analytics page: the dashboard loads.

---

## Optional — refresh aliases before step 2 (reduces zero-result searches)

On the cPanel host, then on your PC:

```bash
# host:
php server/tools/logs-export.php --zero-only --grouped --window 30d --out zero.csv
# PC (needs the LLM key in the ALIAS_LLM_* env):
python pipeline/gen_aliases.py --queries-file zero.csv   # -> aliases.generated.json
# review aliases.generated.json, then:
python pipeline/gen_aliases.py --merge                   # -> pipeline/aliases.json
```

Then build from step 2 as usual.

## Rollback

- VPS: `ssh root@<VPS_HOST> 'cp -a /var/lib/search-vectors/previous /var/lib/search-vectors/incoming'`
  then the reload curl from step 3.
- cPanel: the previous catalog stays as the `products_old` table and `data_old/`.
