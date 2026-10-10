"""Mocked runs of setup.sh: every system command is a stub in PATH and all
paths live under SETUP_PREFIX, so nothing touches the host. Real Let's Encrypt
issuance, ufw and systemd behaviour are only verifiable on a live VPS."""

from __future__ import annotations

import hashlib
import io
import subprocess
import tarfile
from pathlib import Path

import pytest

SETUP = Path(__file__).resolve().parents[1] / "setup.sh"
DOMAIN = "vsearch.example.com"

STUBS = {
    "id": '[ "$#" -eq 1 ] && { echo 0; exit 0; }; [ -e "$SB/user.$2" ]',
    "useradd": 'touch "$SB/user.${!#}"',
    "chown": "exit 0",
    "dpkg-query": "printf 'install ok installed'",
    "apt-get": 'echo "apt-get $*" >> "$SB/calls"',
    "sleep": "exit 0",
    "getent": 'echo "203.0.113.7 $2"',
    "uname": "echo x86_64",
    "sshd": "exit 1",
    "install": (
        'args=(); while [ $# -gt 0 ]; do case "$1" in -o|-g) shift 2;; *) args+=("$1"); shift;; esac; done;'
        ' exec /usr/bin/install "${args[@]}"'
    ),
    "systemctl": (
        'echo "systemctl $*" >> "$SB/calls";'
        ' if [ "$1" = is-active ]; then [ -e "$SB/caddy.active" ]; fi;'
        ' if [ "$1" = restart ] && [ "$2" = caddy ]; then touch "$SB/caddy.active"; fi; exit 0'
    ),
    "curl": (
        'echo "curl $*" >> "$SB/calls"; out=""; url="";'
        ' while [ $# -gt 0 ]; do case "$1" in -o) out="$2"; shift 2;; -w) shift 2;;'
        ' http*) url="$1"; shift;; *) shift;; esac; done;'
        ' case "$url" in *.tar.gz) cp "$SB/caddy.tar.gz" "$out";;'
        ' *) [ "${SB_HTTPS_DOWN:-0}" = 1 ] && echo 000 || echo 503;; esac'
    ),
    "python3": (
        'if [ "$1" = -m ] && [ "$2" = venv ]; then mkdir -p "$3/bin";'
        ' printf \'%s\\n\' "#!/usr/bin/env bash" \'case "$1" in -c) echo $(printf "ab%.0s" $(seq 32));;'
        ' -m) mkdir -p "${!#}";; *) exit 0;; esac\' > "$3/bin/python"; printf "#!/usr/bin/env bash\\nexit 0\\n" > "$3/bin/pip";'
        ' chmod +x "$3/bin/python" "$3/bin/pip"; fi; exit 0'
    ),
    "ufw": r"""
rules="$SB/ufw.rules"; touch "$rules"
case "$1" in
  --force) if [ "$2" = delete ]; then sed -i "${3}d" "$rules"; else echo "ENABLED" >> "$SB/ufw.state"; fi ;;
  allow|deny)
    verb="$1"; shift; rule=""; comment=""
    while [ $# -gt 0 ]; do
      if [ "$1" = comment ]; then comment="$2"; shift 2; else rule="$rule $1"; shift; fi
    done
    line="$verb$rule # $comment"
    grep -qxF "$line" "$rules" || echo "$line" >> "$rules" ;;
  status) n=0; while IFS= read -r l; do n=$((n+1)); printf '[%2d] %s\n' "$n" "$l"; done < "$rules" ;;
esac
""",
}

FAKE_CADDY = "#!/usr/bin/env bash\n" + (
    'case "$1" in version) echo "v2.11.4 h1:fake";; validate) grep -q "reverse_proxy" "$3";; *) exit 0;; esac\n'
)


def make_tarball(sandbox: Path, payload: bytes) -> str:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        info = tarfile.TarInfo("caddy")
        info.size = len(payload)
        info.mode = 0o755
        tar.addfile(info, io.BytesIO(payload))
    (sandbox / "caddy.tar.gz").write_bytes(buf.getvalue())
    return hashlib.sha512(buf.getvalue()).hexdigest()


@pytest.fixture
def sandbox(tmp_path: Path) -> Path:
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    for name, body in STUBS.items():
        stub = bin_dir / name
        stub.write_text(f"#!/usr/bin/env bash\n{body}\n")
        stub.chmod(0o755)
    (tmp_path / "root").mkdir()
    return tmp_path


def run_setup(sandbox: Path, *args: str, sha: str | None = None, **env: str):
    real_sha = make_tarball(sandbox, FAKE_CADDY.encode())
    sha = sha or real_sha
    full_env = {
        "PATH": f"{sandbox / 'bin'}:/usr/bin:/bin",
        "HOME": str(sandbox),
        "SB": str(sandbox),
        "SETUP_PREFIX": str(sandbox / "root"),
        "CADDY_SHA512": sha,
        **env,
    }
    return subprocess.run(
        ["bash", str(SETUP), *args], env=full_env, capture_output=True, text=True, timeout=60
    )


def calls(sandbox: Path) -> list[str]:
    path = sandbox / "calls"
    return path.read_text().splitlines() if path.exists() else []


def rules(sandbox: Path) -> list[str]:
    path = sandbox / "ufw.rules"
    return path.read_text().splitlines() if path.exists() else []


def test_domain_sets_up_caddy_caddyfile_and_ufw(sandbox: Path) -> None:
    result = run_setup(sandbox, "--domain", DOMAIN)
    assert result.returncode == 0, result.stderr
    root = sandbox / "root"

    assert any(
        c.startswith("curl")
        and "caddy_2.11.4_linux_amd64.tar.gz" in c
        and "https://github.com/caddyserver/caddy/" in c
        for c in calls(sandbox)
    )
    assert (root / "usr/local/bin/caddy").stat().st_mode & 0o111
    caddyfile = (root / "etc/caddy/Caddyfile").read_text()
    assert f"{DOMAIN} {{" in caddyfile
    assert "reverse_proxy 127.0.0.1:8600" in caddyfile
    assert (root / "etc/systemd/system/caddy.service").is_file()

    assert "allow 80/tcp # search-vectors-acme" in rules(sandbox)
    assert "allow 443/tcp # search-vectors-https" in rules(sandbox)
    assert "deny 8600/tcp # search-vectors-backend" in rules(sandbox)
    assert not any(r.startswith("allow") and "8600" in r for r in rules(sandbox))
    assert "ENABLED" in (sandbox / "ufw.state").read_text()

    assert f"https://{DOMAIN}/" in result.stdout
    assert "DNS A record" in result.stdout


def test_bad_checksum_refuses_to_install_caddy(sandbox: Path) -> None:
    result = run_setup(sandbox, "--domain", DOMAIN, sha="0" * 128)
    assert result.returncode != 0
    assert "does not match the pinned" in result.stderr
    assert not (sandbox / "root/usr/local/bin/caddy").exists()
    assert not (sandbox / "root/etc/caddy/Caddyfile").exists()
    assert rules(sandbox) == []


def test_no_domain_skips_proxy_and_prints_dns_instruction(sandbox: Path) -> None:
    result = run_setup(sandbox)
    assert result.returncode == 0, result.stderr
    assert "DNS A record" in result.stdout
    assert "127.0.0.1" in result.stdout
    assert not (sandbox / "root/etc/caddy").exists()
    assert rules(sandbox) == []
    assert not any(c.startswith("curl") for c in calls(sandbox))
    assert "systemctl restart search-vectors" in calls(sandbox)


def test_domain_from_env(sandbox: Path) -> None:
    result = run_setup(sandbox, SEARCH_VPS_DOMAIN=DOMAIN.upper())
    assert result.returncode == 0, result.stderr
    assert DOMAIN in (sandbox / "root/etc/caddy/Caddyfile").read_text()


@pytest.mark.parametrize(
    "bad", ["localhost", "203.0.113.7", "a b.example.com", "-x.example.com", "x.example.com/y"]
)
def test_invalid_domain_rejected_before_any_change(sandbox: Path, bad: str) -> None:
    result = run_setup(sandbox, "--domain", bad)
    assert result.returncode == 2
    assert not (sandbox / "root/opt").exists()


def test_allow_ip_restricts_443_and_can_be_dropped(sandbox: Path) -> None:
    run_setup(sandbox, "--domain", DOMAIN, "--allow-ip", "198.51.100.9")
    restricted = "allow from 198.51.100.9 to any port 443 proto tcp # search-vectors-https"
    assert restricted in rules(sandbox)
    assert "allow 443/tcp # search-vectors-https" not in rules(sandbox)

    run_setup(sandbox, "--domain", DOMAIN, "--allow-ip", "198.51.100.10")
    assert restricted not in rules(sandbox)
    assert sum("search-vectors-https" in r for r in rules(sandbox)) == 1

    run_setup(sandbox, "--domain", DOMAIN)
    https = [r for r in rules(sandbox) if "search-vectors-https" in r]
    assert https == ["allow 443/tcp # search-vectors-https"]


def test_allow_ip_requires_domain_and_valid_address(sandbox: Path) -> None:
    assert run_setup(sandbox, "--allow-ip", "198.51.100.9").returncode == 2
    assert run_setup(sandbox, "--domain", DOMAIN, "--allow-ip", "1.2.3.4; rm -rf /").returncode == 2


def test_rerun_is_idempotent_and_preserves_token_and_vectors(sandbox: Path) -> None:
    root = sandbox / "root"
    assert run_setup(sandbox, "--domain", DOMAIN).returncode == 0
    env_file = root / "etc/search-vectors.env"
    token_line = next(
        line for line in env_file.read_text().splitlines() if line.startswith("VPS_TOKEN=")
    )
    active = root / "var/lib/search-vectors/active"
    active.mkdir(parents=True)
    (active / "vectors.bin").write_bytes(b"loaded")
    first_rules = rules(sandbox)
    downloads_before = sum("tar.gz" in c for c in calls(sandbox))
    (sandbox / "calls").write_text("")

    env_file.write_text(
        env_file.read_text().replace(token_line, "VPS_TOKEN=keepme-keepme-keepme-1234")
    )
    result = run_setup(sandbox, "--domain", DOMAIN)
    assert result.returncode == 0, result.stderr

    assert "VPS_TOKEN=keepme-keepme-keepme-1234" in env_file.read_text()
    assert (active / "vectors.bin").read_bytes() == b"loaded"
    assert downloads_before == 1
    assert not any("tar.gz" in c for c in calls(sandbox))
    assert sorted(rules(sandbox)) == sorted(first_rules)
    assert "unchanged: " in result.stdout
    assert "systemctl reload caddy" in calls(sandbox)
    assert "systemctl restart caddy" not in calls(sandbox)
    assert len(list((root / "etc/systemd/system").glob("caddy.service*"))) == 1


def test_domain_change_rewrites_caddyfile_in_place_and_reloads(sandbox: Path) -> None:
    run_setup(sandbox, "--domain", DOMAIN)
    (sandbox / "calls").write_text("")
    result = run_setup(sandbox, "--domain", "other.example.org")
    assert result.returncode == 0, result.stderr
    caddyfile = (sandbox / "root/etc/caddy/Caddyfile").read_text()
    assert "other.example.org {" in caddyfile and DOMAIN not in caddyfile
    assert "systemctl reload caddy" in calls(sandbox)


def test_no_domain_rerun_leaves_existing_proxy_alone(sandbox: Path) -> None:
    run_setup(sandbox, "--domain", DOMAIN)
    before = (sandbox / "root/etc/caddy/Caddyfile").read_text()
    result = run_setup(sandbox)
    assert result.returncode == 0
    assert (sandbox / "root/etc/caddy/Caddyfile").read_text() == before
    assert "left unchanged" in result.stdout


def test_https_not_answering_still_succeeds_with_dns_reminder(sandbox: Path) -> None:
    result = run_setup(sandbox, "--domain", DOMAIN, SB_HTTPS_DOWN="1")
    assert result.returncode == 0
    assert "not answering yet" in result.stdout
    assert "DNS A record" in result.stdout


def test_apt_is_skipped_when_nothing_is_missing(sandbox: Path) -> None:
    run_setup(sandbox, "--domain", DOMAIN)
    assert not any(c.startswith("apt-get") for c in calls(sandbox))


def test_apt_installs_only_missing_packages(sandbox: Path) -> None:
    dpkg = sandbox / "bin/dpkg-query"
    dpkg.write_text('#!/usr/bin/env bash\n[ "${!#}" = ufw ] || printf "install ok installed"\n')
    run_setup(sandbox, "--domain", DOMAIN)
    assert any(c.startswith("apt-get install") and c.endswith(" ufw") for c in calls(sandbox))


def test_residual_config_package_counts_as_missing(sandbox: Path) -> None:
    stub = sandbox / "bin/dpkg-query"
    stub.write_text(
        '#!/usr/bin/env bash\n'
        'if [ "${!#}" = ufw ]; then printf "deinstall ok config-files"; else printf "install ok installed"; fi\n'
    )
    run_setup(sandbox, "--domain", DOMAIN)
    assert any(c.startswith("apt-get install") and c.endswith(" ufw") for c in calls(sandbox))


def test_missing_ufw_warns_and_skips_firewall(sandbox: Path) -> None:
    (sandbox / "bin/ufw").unlink()
    result = run_setup(sandbox, "--domain", DOMAIN)
    assert result.returncode == 0, result.stderr
    assert "ufw is not installed" in result.stderr
    assert "Re-run setup.sh" in result.stderr
    assert rules(sandbox) == []


# --- Redis result cache (M26, --redis) ---------------------------------------


def redis_conf(sandbox: Path) -> str:
    return (sandbox / "root/etc/redis/search-cache.conf").read_text()


def test_without_redis_flag_nothing_redis_is_touched(sandbox: Path) -> None:
    result = run_setup(sandbox)
    assert result.returncode == 0, result.stderr
    assert not (sandbox / "root/etc/redis").exists()
    assert not any("redis" in c for c in calls(sandbox))


def test_redis_is_secured_and_local_by_default(sandbox: Path) -> None:
    result = run_setup(sandbox, "--redis")
    assert result.returncode == 0, result.stderr
    conf = redis_conf(sandbox)
    assert "bind 127.0.0.1 -::1\n" in conf
    assert "protected-mode yes" in conf
    assert f"requirepass {'ab' * 32}\n" in conf
    assert "maxmemory 128mb" in conf and "maxmemory-policy allkeys-lru" in conf
    assert 'save ""' in conf and "appendonly no" in conf
    assert (sandbox / "root/etc/redis/search-cache.conf").stat().st_mode & 0o777 == 0o640
    assert (sandbox / "root/etc/redis/redis.conf").read_text().count("search-cache.conf") == 1
    assert "systemctl restart redis-server" in calls(sandbox)
    assert rules(sandbox) == []  # loopback only: no firewall rule needed
    assert f"SEARCH_REDIS_AUTH={'ab' * 32}" in result.stdout


def test_redis_bind_opens_only_to_the_allowed_ip(sandbox: Path) -> None:
    result = run_setup(
        sandbox, "--redis", "--redis-bind", "203.0.113.5", "--redis-allow-ip", "198.51.100.9"
    )
    assert result.returncode == 0, result.stderr
    assert "bind 127.0.0.1 -::1 203.0.113.5\n" in redis_conf(sandbox)
    assert rules(sandbox) == [
        "allow 22/tcp # search-vectors-ssh",
        "allow from 198.51.100.9 to any port 6379 proto tcp # search-vectors-redis",
        "deny 6379/tcp # search-vectors-redis-deny",
    ]
    assert "ENABLED" in (sandbox / "ufw.state").read_text()
    assert "SEARCH_REDIS_HOST=203.0.113.5" in result.stdout


def test_redis_rerun_keeps_the_password_and_replaces_the_firewall_rule(sandbox: Path) -> None:
    args = ("--redis", "--redis-bind", "203.0.113.5")
    run_setup(sandbox, *args, "--redis-allow-ip", "198.51.100.9")
    conf_path = sandbox / "root/etc/redis/search-cache.conf"
    conf_path.write_text(conf_path.read_text().replace("ab" * 32, "keep-this-password"))

    result = run_setup(sandbox, *args, "--redis-allow-ip", "198.51.100.10")
    assert result.returncode == 0, result.stderr

    assert "requirepass keep-this-password\n" in redis_conf(sandbox)
    assert "keeping the password" in result.stdout
    assert (sandbox / "root/etc/redis/redis.conf").read_text().count("search-cache.conf") == 1
    redis_rules = [r for r in rules(sandbox) if "search-vectors-redis" in r]
    assert redis_rules == [
        "allow from 198.51.100.10 to any port 6379 proto tcp # search-vectors-redis",
        "deny 6379/tcp # search-vectors-redis-deny",
    ]


@pytest.mark.parametrize(
    "args",
    [
        ("--redis-bind", "203.0.113.5"),  # without --redis
        ("--redis-allow-ip", "198.51.100.9"),  # without --redis
        ("--redis", "--redis-bind", "203.0.113.5"),  # public bind without an allowed IP
        ("--redis", "--redis-bind", "0.0.0.0"),
        ("--redis", "--redis-bind", "1.2.3.4; rm -rf /", "--redis-allow-ip", "198.51.100.9"),
        ("--redis", "--redis-bind", "203.0.113.5", "--redis-allow-ip", "x; y"),
    ],
)
def test_unsafe_redis_options_are_rejected_before_any_change(
    sandbox: Path, args: tuple[str, ...]
) -> None:
    result = run_setup(sandbox, *args)
    assert result.returncode == 2
    assert not (sandbox / "root/opt").exists()
    assert not (sandbox / "root/etc/redis").exists()


def test_redis_package_is_installed_only_when_missing(sandbox: Path) -> None:
    stub = sandbox / "bin" / "dpkg-query"
    stub.write_text('#!/usr/bin/env bash\n[ "${!#}" = redis-server ] || printf "install ok installed"\n')
    result = run_setup(sandbox, "--redis")
    assert result.returncode == 0, result.stderr
    assert "apt-get install -y -qq redis-server" in calls(sandbox)
