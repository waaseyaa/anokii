#!/usr/bin/env bash
set -euo pipefail

# Real FrankenPHP worker acceptance for Anokii production HTTP.
# Requires FrankenPHP 1.12.x serving PHP 8.5. Uses a dedicated database so an
# inherited WAASEYAA_DB cannot contaminate the fingerprint.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FRANKENPHP="${FRANKENPHP_BINARY:-}"
if [[ -z "$FRANKENPHP" ]]; then
  if [[ -x /tmp/frankenphp-linux-x86_64 ]]; then
    FRANKENPHP=/tmp/frankenphp-linux-x86_64
  elif command -v frankenphp >/dev/null 2>&1; then
    FRANKENPHP="$(command -v frankenphp)"
  else
    echo "Set FRANKENPHP_BINARY to FrankenPHP 1.12.4" >&2
    exit 1
  fi
fi

PORT="${ANOKII_ACCEPTANCE_PORT:-3055}"
if ss -ltn 2>/dev/null | grep -qE ":${PORT}[[:space:]]"; then
  echo "Port ${PORT} is already in use; refusing a contaminated listener." >&2
  exit 1
fi

RUNTIME_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/anokii-frankenphp-acceptance.XXXXXX")"

export APP_ENV=production
export ANOKII_COMMUNITY_ID="${ANOKII_COMMUNITY_ID:-acceptance-community}"
export ANOKII_PRIVACY_SECRET="${ANOKII_PRIVACY_SECRET:-$(php -r 'echo str_repeat("p", 32);')}"
export WAASEYAA_JWT_SECRET="${WAASEYAA_JWT_SECRET:-$(php -r 'echo str_repeat("j", 32);')}"
export WAASEYAA_APP_SECRET="${WAASEYAA_APP_SECRET:-base64:$(php -r 'echo base64_encode(str_repeat("a", 32));')}"
export AUTH_TOKEN_SECRET="${AUTH_TOKEN_SECRET:-$(php -r 'echo bin2hex(random_bytes(32));')}"
export WAASEYAA_DB="$ROOT/storage/acceptance-waaseyaa.sqlite"
export WAASEYAA_SKIP_DOTENV=true

mkdir -p "$ROOT/storage" "$ROOT/.waaseyaa"
rm -f "$WAASEYAA_DB" "$WAASEYAA_DB-shm" "$WAASEYAA_DB-wal" \
  "$ROOT/.waaseyaa/field-access-preflight.json"

APP_ENV=local "$FRANKENPHP" php-cli "$ROOT/vendor/bin/waaseyaa" install:init
APP_ENV=production "$FRANKENPHP" php-cli "$ROOT/vendor/bin/waaseyaa" field-access:preflight --format=json --write-artifact >/tmp/anokii-acceptance-preflight.json
python3 - <<'PY'
import json
p=json.load(open("/tmp/anokii-acceptance-preflight.json"))
assert p.get("ready") is True, p
assert p.get("conflicts") == []
assert p.get("unclassified_entries") == []
print("preflight ready", p["schema_fingerprint"])
PY
APP_ENV=production "$FRANKENPHP" php-cli "$ROOT/vendor/bin/waaseyaa" list >/dev/null

CADDYFILE="$RUNTIME_ROOT/Caddyfile"
cat >"$CADDYFILE" <<EOF
{
	admin off
	frankenphp {
		worker $ROOT/public/index.php
	}
}

http://127.0.0.1:${PORT} {
	root $ROOT/public
	php_server
}
EOF

WORKER_PID=""
cleanup() {
  exit_code=$?
  trap - EXIT
  if [[ -n "${WORKER_PID}" ]]; then
    if ! kill "${WORKER_PID}" 2>/dev/null; then
      echo "FrankenPHP worker ${WORKER_PID} was not running during cleanup." >&2
      [[ "$exit_code" -ne 0 ]] || exit_code=1
    fi
    if ! wait "${WORKER_PID}"; then
      echo "FrankenPHP worker ${WORKER_PID} did not shut down cleanly." >&2
      [[ "$exit_code" -ne 0 ]] || exit_code=1
    fi
  fi
  if ss -ltn 2>/dev/null | grep -qE ":${PORT}[[:space:]]"; then
    echo "Port ${PORT} is still listening after FrankenPHP shutdown." >&2
    [[ "$exit_code" -ne 0 ]] || exit_code=1
  fi
  rm -rf -- "$RUNTIME_ROOT"
  exit "$exit_code"
}
trap cleanup EXIT

XDG_CONFIG_HOME="$RUNTIME_ROOT/config" XDG_DATA_HOME="$RUNTIME_ROOT/data" \
  "$FRANKENPHP" run --config "$CADDYFILE" --adapter caddyfile &
WORKER_PID=$!
ready=0
for _ in $(seq 1 50); do
  if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/admin/anokii/login"; then
    ready=1
    break
  fi
  sleep 0.1
done
if [[ "$ready" -ne 1 ]]; then
  echo "Worker did not become ready on port ${PORT}" >&2
  exit 1
fi

status_file="$RUNTIME_ROOT/serial-status.txt"
for _ in $(seq 1 20); do
  curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:${PORT}/admin/anokii/login" >>"$status_file"
done
concurrent_file="$RUNTIME_ROOT/concurrent-status.txt"
seq 20 | xargs -P 20 -I{} curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:${PORT}/admin/anokii/login" >"$concurrent_file"
login_post_file="$RUNTIME_ROOT/login-post.json"
login_post="$(curl -sS -o "$login_post_file" -w '%{http_code}' -H 'Content-Type: application/json' -H 'Accept: application/json' -X POST --data '{}' "http://127.0.0.1:${PORT}/admin/anokii/login")"
redirect_code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/admin/anokii")"
redirect_url="$(curl -sS -o /dev/null -w '%{redirect_url}' "http://127.0.0.1:${PORT}/admin/anokii")"

python3 - <<PY
from collections import Counter
serial=open("$status_file").read().split()
concurrent=open("$concurrent_file").read().split()
assert serial == ["200"]*20, serial
assert Counter(concurrent) == Counter({"200": 20}), concurrent
print("repeated login", Counter(serial))
print("concurrent login", Counter(concurrent))
assert "${login_post}" == "401", "${login_post}"
print("json login POST", open("$login_post_file").read().strip())
assert "${redirect_code}" == "302", "${redirect_code}"
assert "${redirect_url}".endswith("/admin/anokii/login"), "${redirect_url}"
print("workspace redirect", "${redirect_code}", "${redirect_url}")
PY
