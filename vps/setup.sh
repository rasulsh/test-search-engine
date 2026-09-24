#!/usr/bin/env bash
# Provision the vector service on a fresh Debian/Ubuntu VPS. Run as root from
# the repository's vps/ directory; safe to re-run (updates code and deps, keeps
# the env file, the model and the vectors).
#
#   sudo ./setup.sh
set -euo pipefail

APP_ROOT=/opt/search-vectors
DATA_DIR=/var/lib/search-vectors
ENV_FILE=/etc/search-vectors.env
SERVICE_USER=searchvec
HERE="$(cd "$(dirname "$0")" && pwd)"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (sudo ./setup.sh)." >&2
    exit 1
fi

echo "==> System packages"
apt-get update -qq
apt-get install -y -qq python3 python3-venv ca-certificates >/dev/null
python3 -c 'import sys; sys.exit(sys.version_info < (3, 11))' \
    || { echo "Python 3.11+ is required." >&2; exit 1; }

echo "==> Service user and directories"
id -u "$SERVICE_USER" >/dev/null 2>&1 \
    || useradd --system --home-dir "$DATA_DIR" --shell /usr/sbin/nologin "$SERVICE_USER"
install -d -o root -g root -m 0755 "$APP_ROOT" "$APP_ROOT/app"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0750 "$DATA_DIR"

echo "==> Code and Python dependencies"
rm -rf "$APP_ROOT/app/search_vectors"
cp -r "$HERE/search_vectors" "$APP_ROOT/app/"
cp "$HERE/requirements.txt" "$APP_ROOT/app/"
[ -d "$APP_ROOT/venv" ] || python3 -m venv "$APP_ROOT/venv"
"$APP_ROOT/venv/bin/pip" install -q --upgrade pip
"$APP_ROOT/venv/bin/pip" install -q -r "$APP_ROOT/app/requirements.txt"

echo "==> Environment file"
if [ ! -f "$ENV_FILE" ]; then
    token="$("$APP_ROOT/venv/bin/python" -c 'import secrets; print(secrets.token_hex(32))')"
    sed "s|^VPS_TOKEN=.*|VPS_TOKEN=$token|" "$HERE/search-vectors.env.example" > "$ENV_FILE"
    echo "    wrote $ENV_FILE with a new random VPS_TOKEN (copy it to the cPanel config)"
else
    echo "    keeping existing $ENV_FILE"
fi
chown root:"$SERVICE_USER" "$ENV_FILE"
chmod 0640 "$ENV_FILE"

echo "==> Query model (pinned commit + sha256)"
model_dir="$(sed -n 's/^VPS_MODEL_DIR=//p' "$ENV_FILE")"
onnx_file="$(sed -n 's/^VPS_ONNX_FILE=//p' "$ENV_FILE")"
model="$(sed -n 's/^VPS_MODEL=//p' "$ENV_FILE")"
(cd "$APP_ROOT/app" && "$APP_ROOT/venv/bin/python" -m search_vectors.fetch_model \
    --model "$model" --onnx-file "$onnx_file" --out "$model_dir")
chmod -R a+rX "$model_dir"

echo "==> systemd"
install -m 0644 "$HERE/search-vectors.service" /etc/systemd/system/search-vectors.service
systemctl daemon-reload
systemctl enable search-vectors >/dev/null
systemctl restart search-vectors

echo "Done. Status: systemctl status search-vectors; logs: journalctl -u search-vectors"
echo "Next: upload vectors to $DATA_DIR/incoming/ and POST /reload (vps/README.md)."
