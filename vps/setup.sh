#!/usr/bin/env bash
# Provision the vector service on a fresh Debian/Ubuntu VPS, and (with a
# domain) put a Caddy TLS reverse proxy and a firewall in front of it. Run as
# root from the repository's vps/ directory; safe to re-run (updates code,
# deps and the proxy config; keeps the env file/token, the model and vectors).
#
#   sudo ./setup.sh --domain vsearch.example.com [--allow-ip <CPANEL_OUTBOUND_IP>]
#
# Prerequisite for the proxy: a DNS A record for the domain pointing at this
# VPS (this script cannot create DNS). Without a domain the service still comes
# up on 127.0.0.1 and the proxy/firewall step is skipped.
set -euo pipefail

# SETUP_PREFIX and CADDY_SHA512 exist for the mocked test run (tests/test_setup_sh.py).
P="${SETUP_PREFIX:-}"
APP_ROOT="$P/opt/search-vectors"
DATA_DIR="$P/var/lib/search-vectors"
ENV_FILE="$P/etc/search-vectors.env"
UNIT_DIR="$P/etc/systemd/system"
SERVICE_USER=searchvec
HERE="$(cd "$(dirname "$0")" && pwd)"

CADDY_VERSION=2.11.4
CADDY_SHA512_AMD64=8220d1f013b6f27510247b2360c9e0ca9f018feebd82515f07635318b34ff9777ccc8fd0b6e6f2486ce3a33fe389fbb7db12d05baa474f4587509fb4f5ebf1c9
CADDY_SHA512_ARM64=d5a7c423853c24a799765e0e8210d5c7c22a8f56ed37a3cae2fb9f58be138853c02b4efd6b59d576e6d8c7c0d30b9c1592deeaa6a536ff69bcca23b8c1ea709c
CADDY_BIN="$P/usr/local/bin/caddy"
CADDY_DIR="$P/etc/caddy"
CADDY_HOME="$P/var/lib/caddy"

DOMAIN="${SEARCH_VPS_DOMAIN:-}"
ALLOW_IP="${SEARCH_VPS_ALLOW_IP:-}"

usage() {
    echo "Usage: sudo ./setup.sh [--domain <fqdn>] [--allow-ip <cpanel-outbound-ip>]"
    echo "  env: SEARCH_VPS_DOMAIN, SEARCH_VPS_ALLOW_IP"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
        --allow-ip) ALLOW_IP="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
if [ -n "$DOMAIN" ]; then
    # A dotted hostname; Let's Encrypt cannot issue for bare names or IPs.
    if ! printf '%s' "$DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]([a-z0-9-]*[a-z0-9])?$'; then
        echo "Invalid --domain '$DOMAIN' (need a hostname such as vsearch.example.com)." >&2
        exit 2
    fi
fi
if [ -n "$ALLOW_IP" ] && ! printf '%s' "$ALLOW_IP" | grep -Eq '^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$'; then
    echo "Invalid --allow-ip '$ALLOW_IP' (an IPv4/IPv6 address or CIDR)." >&2
    exit 2
fi
if [ -n "$ALLOW_IP" ] && [ -z "$DOMAIN" ]; then
    echo "--allow-ip only applies together with --domain." >&2
    exit 2
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (sudo ./setup.sh)." >&2
    exit 1
fi

echo "==> System packages"
# Only touch apt when something is missing, so a VPS whose distro mirror is
# unreachable can still be provisioned.
missing=()
for pkg in python3 python3-venv ca-certificates curl tar ufw; do
    dpkg -s "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
done
if [ "${#missing[@]}" -gt 0 ]; then
    echo "    installing: ${missing[*]}"
    apt-get update -qq
    apt-get install -y -qq "${missing[@]}" >/dev/null
fi
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
install -d "$(dirname "$ENV_FILE")"
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
model_dir="$P$(sed -n 's/^VPS_MODEL_DIR=//p' "$ENV_FILE")"
onnx_file="$(sed -n 's/^VPS_ONNX_FILE=//p' "$ENV_FILE")"
model="$(sed -n 's/^VPS_MODEL=//p' "$ENV_FILE")"
(cd "$APP_ROOT/app" && "$APP_ROOT/venv/bin/python" -m search_vectors.fetch_model \
    --model "$model" --onnx-file "$onnx_file" --out "$model_dir")
chmod -R a+rX "$model_dir"

echo "==> systemd"
install -d "$UNIT_DIR"
install -m 0644 "$HERE/search-vectors.service" "$UNIT_DIR/search-vectors.service"
systemctl daemon-reload
systemctl enable search-vectors >/dev/null
systemctl restart search-vectors


port="$(sed -n 's/^VPS_PORT=//p' "$ENV_FILE")"
port="${port:-8600}"

if [ -z "$DOMAIN" ]; then
    echo "==> TLS proxy and firewall: skipped (no domain)"
    echo "    The service listens on 127.0.0.1:$port only. For https://<domain>/ on 443:"
    echo "    1. create a DNS A record for a name (e.g. vsearch.example.com) -> this VPS's public IP"
    echo "    2. re-run: sudo ./setup.sh --domain vsearch.example.com"
    [ -f "$CADDY_DIR/Caddyfile" ] && echo "    (an existing $CADDY_DIR/Caddyfile was left unchanged)"
    echo "Done. Status: systemctl status search-vectors; logs: journalctl -u search-vectors"
    echo "Next: upload vectors to $DATA_DIR/incoming/ and POST /reload (vps/README.md)."
    exit 0
fi

echo "==> DNS check"
if getent hosts "$DOMAIN" >/dev/null 2>&1; then
    echo "    $DOMAIN resolves to: $(getent hosts "$DOMAIN" | awk '{print $1}' | paste -sd' ')"
    echo "    (it must be THIS VPS's public IP, or the certificate cannot be issued)"
else
    echo "    WARNING: $DOMAIN does not resolve yet. Create the DNS A record -> this VPS;" >&2
    echo "    Caddy keeps retrying certificate issuance until it does." >&2
fi

echo "==> Caddy $CADDY_VERSION (official static binary, pinned sha512)"
case "$(uname -m)" in
    x86_64) arch=amd64; want_sha="$CADDY_SHA512_AMD64" ;;
    aarch64|arm64) arch=arm64; want_sha="$CADDY_SHA512_ARM64" ;;
    *) echo "Unsupported architecture $(uname -m) for the pinned Caddy build." >&2; exit 1 ;;
esac
want_sha="${CADDY_SHA512:-$want_sha}"
binary_changed=0
if [ -x "$CADDY_BIN" ] && "$CADDY_BIN" version 2>/dev/null | grep -q "^v$CADDY_VERSION"; then
    echo "    already installed"
else
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    url="https://github.com/caddyserver/caddy/releases/download/v$CADDY_VERSION/caddy_${CADDY_VERSION}_linux_${arch}.tar.gz"
    echo "    downloading $url"
    curl -fsSL --retry 3 --connect-timeout 20 -o "$tmp/caddy.tar.gz" "$url"
    actual="$(sha512sum "$tmp/caddy.tar.gz" | awk '{print $1}')"
    if [ "$actual" != "$want_sha" ]; then
        echo "Caddy archive sha512 $actual does not match the pinned $want_sha; not installing." >&2
        exit 1
    fi
    tar -xzf "$tmp/caddy.tar.gz" -C "$tmp" caddy
    install -d "$(dirname "$CADDY_BIN")"
    install -m 0755 "$tmp/caddy" "$CADDY_BIN.new"
    mv -f "$CADDY_BIN.new" "$CADDY_BIN"
    binary_changed=1
fi

id -u caddy >/dev/null 2>&1 \
    || useradd --system --home-dir /var/lib/caddy --create-home --shell /usr/sbin/nologin caddy
install -d -o root -g root -m 0755 "$CADDY_DIR"
install -d -o caddy -g caddy -m 0700 "$CADDY_HOME"

echo "==> Caddyfile for $DOMAIN -> 127.0.0.1:$port"
new_conf="$(mktemp)"
cat > "$new_conf" <<CADDYFILE
$DOMAIN {
    reverse_proxy 127.0.0.1:$port
}
CADDYFILE
"$CADDY_BIN" validate --config "$new_conf" --adapter caddyfile >/dev/null
conf_changed=0
if ! cmp -s "$new_conf" "$CADDY_DIR/Caddyfile" 2>/dev/null; then
    install -m 0644 "$new_conf" "$CADDY_DIR/Caddyfile"
    conf_changed=1
fi
rm -f "$new_conf"
echo "    $([ "$conf_changed" -eq 1 ] && echo written || echo unchanged): $CADDY_DIR/Caddyfile"

unit_changed=0
if ! cmp -s "$HERE/caddy.service" "$UNIT_DIR/caddy.service" 2>/dev/null; then
    install -m 0644 "$HERE/caddy.service" "$UNIT_DIR/caddy.service"
    unit_changed=1
fi
systemctl daemon-reload
systemctl enable caddy >/dev/null
if [ "$binary_changed" -eq 1 ] || [ "$unit_changed" -eq 1 ] || ! systemctl is-active --quiet caddy; then
    systemctl restart caddy
else
    systemctl reload caddy
fi

echo "==> Firewall (ufw)"
ssh_ports="$( (sshd -T 2>/dev/null || true) | sed -n 's/^port //p' | sort -u)"
for ssh_port in ${ssh_ports:-22}; do
    ufw allow "$ssh_port/tcp" comment 'search-vectors-ssh' >/dev/null
done
ufw allow 80/tcp comment 'search-vectors-acme' >/dev/null
# Replace our previous 443 rules so a changed or dropped --allow-ip takes effect.
while n="$(ufw status numbered | sed -n 's/^\[ *\([0-9][0-9]*\)\].*search-vectors-https.*/\1/p' | head -n1)" && [ -n "$n" ]; do
    ufw --force delete "$n" >/dev/null
done
if [ -n "$ALLOW_IP" ]; then
    ufw allow from "$ALLOW_IP" to any port 443 proto tcp comment 'search-vectors-https' >/dev/null
    echo "    443 restricted to $ALLOW_IP; 80 open for ACME"
else
    ufw allow 443/tcp comment 'search-vectors-https' >/dev/null
    echo "    443 open (bearer token + TLS); 80 open for ACME"
fi
ufw deny "$port/tcp" comment 'search-vectors-backend' >/dev/null
ufw --force enable >/dev/null

echo "==> Waiting for HTTPS (best effort)"
live=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do
    code="$(curl -sS -m 10 -o /dev/null -w '%{http_code}' "https://$DOMAIN/health" 2>/dev/null || true)"
    if [ -n "$code" ] && [ "$code" != 000 ]; then live=1; break; fi
    sleep 5
done

echo
echo "Done. Service URL: https://$DOMAIN/   (cPanel SEARCH_VPS_URL=https://$DOMAIN)"
echo "Reminder: the DNS A record for $DOMAIN must already resolve to this VPS's public IP."
if [ "$live" -eq 1 ]; then
    echo "HTTPS answered on 443 (GET /health returned $code; 503 is normal until vectors are loaded)."
else
    echo "HTTPS is not answering yet; check: journalctl -u caddy -f (Caddy retries issuance automatically)."
fi
echo "Status: systemctl status search-vectors caddy"
echo "Next: upload vectors to $DATA_DIR/incoming/ and POST /reload (vps/README.md)."
