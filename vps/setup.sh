#!/usr/bin/env bash
# Provision the vector service on a fresh Debian/Ubuntu VPS, and (with a
# domain) put a Caddy TLS reverse proxy and a firewall in front of it. Run as
# root from the repository's vps/ directory; safe to re-run (updates code,
# deps and the proxy config; keeps the env file/token, the model and vectors).
#
#   sudo ./setup.sh --domain vsearch.example.com [--allow-ip <CPANEL_OUTBOUND_IP>]
#   sudo ./setup.sh --redis [--redis-bind <VPS_ADDRESS> --redis-allow-ip <CPANEL_OUTBOUND_IP>]
#
# --redis (optional, M26) installs and secures Redis as the /search result cache:
# password required, protected mode, no persistence, memory-capped, localhost only
# unless --redis-bind opens one more address, which then needs --redis-allow-ip
# (ufw lets only that IP reach port 6379). See docs/DEPLOY.md, "Result cache".
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

REDIS_CONF_DIR="$P/etc/redis"
REDIS_PORT=6379

DOMAIN="${SEARCH_VPS_DOMAIN:-}"
ALLOW_IP="${SEARCH_VPS_ALLOW_IP:-}"
REDIS="${SEARCH_VPS_REDIS:-0}"
REDIS_BIND="${SEARCH_VPS_REDIS_BIND:-}"
REDIS_ALLOW_IP="${SEARCH_VPS_REDIS_ALLOW_IP:-}"

usage() {
    echo "Usage: sudo ./setup.sh [--domain <fqdn>] [--allow-ip <cpanel-outbound-ip>]"
    echo "                       [--redis [--redis-bind <address> --redis-allow-ip <cpanel-outbound-ip>]]"
    echo "  env: SEARCH_VPS_DOMAIN, SEARCH_VPS_ALLOW_IP, SEARCH_VPS_REDIS=1,"
    echo "       SEARCH_VPS_REDIS_BIND, SEARCH_VPS_REDIS_ALLOW_IP"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
        --allow-ip) ALLOW_IP="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
        --redis) REDIS=1; shift ;;
        --redis-bind) REDIS_BIND="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
        --redis-allow-ip) REDIS_ALLOW_IP="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
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

ADDRESS_RE='^[0-9A-Fa-f:.]+$'
CIDR_RE='^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$'
if [ "$REDIS" != 1 ] && { [ -n "$REDIS_BIND" ] || [ -n "$REDIS_ALLOW_IP" ]; }; then
    echo "--redis-bind and --redis-allow-ip only apply together with --redis." >&2
    exit 2
fi
if [ -n "$REDIS_BIND" ] && ! printf '%s' "$REDIS_BIND" | grep -Eq "$ADDRESS_RE"; then
    echo "Invalid --redis-bind '$REDIS_BIND' (an IPv4/IPv6 address)." >&2
    exit 2
fi
if [ -n "$REDIS_ALLOW_IP" ] && ! printf '%s' "$REDIS_ALLOW_IP" | grep -Eq "$CIDR_RE"; then
    echo "Invalid --redis-allow-ip '$REDIS_ALLOW_IP' (an IPv4/IPv6 address or CIDR)." >&2
    exit 2
fi
case "$REDIS_BIND" in
    ""|127.*|::1) ;;
    *)
        if [ -z "$REDIS_ALLOW_IP" ]; then
            echo "--redis-bind $REDIS_BIND exposes Redis beyond localhost: also pass" >&2
            echo "--redis-allow-ip <cpanel-outbound-ip> so the firewall admits only that address." >&2
            exit 2
        fi ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (sudo ./setup.sh)." >&2
    exit 1
fi

echo "==> System packages"
# Only touch apt when something is missing, so a VPS whose distro mirror is
# unreachable can still be provisioned.
missing=()
wanted=(python3 python3-venv ca-certificates curl tar ufw)
[ "$REDIS" = 1 ] && wanted+=(redis-server)
# A removed-but-not-purged package (deinstall ok config-files) must be reinstalled.
pkg_present() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }
for pkg in "${wanted[@]}"; do
    pkg_present "$pkg" || missing+=("$pkg")
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

# Allow the host's ssh port(s) so enabling ufw never locks the operator out.
allow_ssh() {
    local ssh_ports ssh_port
    ssh_ports="$( (sshd -T 2>/dev/null || true) | sed -n 's/^port //p' | sort -u)"
    for ssh_port in ${ssh_ports:-22}; do
        ufw allow "$ssh_port/tcp" comment 'search-vectors-ssh' >/dev/null
    done
}

# The service binds 127.0.0.1 behind Caddy, so a missing ufw degrades the
# firewall step to a loud warning instead of aborting the whole provisioning.
have_ufw=1
command -v ufw >/dev/null 2>&1 || have_ufw=0
warn_no_ufw() {
    echo "WARNING: ufw is not installed (apt mirror unreachable?); firewall rules were NOT applied:" >&2
    echo "         $1" >&2
    echo "         Re-run setup.sh once ufw installs." >&2
}

if [ "$REDIS" = 1 ]; then
    echo "==> Redis result cache (password, protected mode, no persistence)"
    redis_conf="$REDIS_CONF_DIR/search-cache.conf"
    install -d "$REDIS_CONF_DIR"
    redis_pass=""
    [ -f "$redis_conf" ] && redis_pass="$(sed -n 's/^requirepass //p' "$redis_conf")"
    new_pass=0
    if [ -z "$redis_pass" ]; then
        redis_pass="$("$APP_ROOT/venv/bin/python" -c 'import secrets; print(secrets.token_hex(32))')"
        new_pass=1
    fi
    bind_line="127.0.0.1 -::1"
    [ -n "$REDIS_BIND" ] && bind_line="$bind_line $REDIS_BIND"
    umask 077
    cat > "$redis_conf.new" <<REDISCONF
# Written by vps/setup.sh --redis (the /search result cache). Re-running keeps the password.
bind $bind_line
port $REDIS_PORT
protected-mode yes
requirepass $redis_pass
# A cache: bounded memory, evict least-recently-used, nothing written to disk.
maxmemory 128mb
maxmemory-policy allkeys-lru
save ""
appendonly no
REDISCONF
    umask 022
    mv -f "$redis_conf.new" "$redis_conf"
    chown root:redis "$redis_conf"
    chmod 0640 "$redis_conf"
    # Later directives win, so the include goes last in the distribution's file.
    touch "$REDIS_CONF_DIR/redis.conf"
    grep -qxF "include $redis_conf" "$REDIS_CONF_DIR/redis.conf" \
        || printf '\ninclude %s\n' "$redis_conf" >> "$REDIS_CONF_DIR/redis.conf"
    systemctl enable redis-server >/dev/null
    systemctl restart redis-server

    if [ "$have_ufw" -eq 0 ]; then
        [ -n "$REDIS_ALLOW_IP" ] && warn_no_ufw "$REDIS_PORT/tcp was not restricted to $REDIS_ALLOW_IP"
    else
        # Our previous Redis rules go first, so a changed or dropped --redis-allow-ip takes effect.
        while n="$(ufw status numbered | sed -n 's/^\[ *\([0-9][0-9]*\)\].*search-vectors-redis.*/\1/p' | head -n1)" && [ -n "$n" ]; do
            ufw --force delete "$n" >/dev/null
        done
        if [ -n "$REDIS_ALLOW_IP" ]; then
            allow_ssh
            ufw allow from "$REDIS_ALLOW_IP" to any port "$REDIS_PORT" proto tcp comment 'search-vectors-redis' >/dev/null
            ufw deny "$REDIS_PORT/tcp" comment 'search-vectors-redis-deny' >/dev/null
            ufw --force enable >/dev/null
            echo "    $REDIS_PORT/tcp open to $REDIS_ALLOW_IP only"
        fi
    fi
    if [ "$new_pass" -eq 1 ]; then
        echo "    wrote $redis_conf with a new random password (cPanel SEARCH_REDIS_AUTH=$redis_pass)"
    else
        echo "    keeping the password in $redis_conf"
    fi
    echo "    cPanel: SEARCH_REDIS_ENABLED=1 SEARCH_REDIS_HOST=${REDIS_BIND:-<unreachable: bound to localhost>} SEARCH_REDIS_PORT=$REDIS_PORT"
    if [ -n "$REDIS_BIND" ]; then
        echo "    NOTE: Redis speaks plain TCP: prefer a private network or tunnel between the hosts (docs/DEPLOY.md)."
    fi
fi

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
if [ "$have_ufw" -eq 0 ]; then
    warn_no_ufw "port $port (backend) deny and the 443 restriction were not applied"
else
    allow_ssh
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
fi

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
