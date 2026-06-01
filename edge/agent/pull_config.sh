#!/bin/sh
set -eu

EDGE_CONFIG_CACHE_PATH="${EDGE_CONFIG_CACHE_PATH:-$EDGE_CONFIG_PATH}"
EDGE_SYNC_STATUS_PATH="${EDGE_SYNC_STATUS_PATH:-/var/lib/cdnlite/edge-sync-status.json}"

config_version() {
  file="$1"
  [ -s "$file" ] || return 0
  python3 - "$file" <<'PY' 2>/dev/null || true
import json
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)

version = data.get("version") if isinstance(data, dict) else None
if isinstance(version, int) and version >= 0:
    print(version)
PY
}

write_status() {
  core_reachable="$1"
  source="$2"
  sync_time="$3"
  version="$(config_version "$EDGE_CONFIG_PATH")"
  status_dir="$(dirname "$EDGE_SYNC_STATUS_PATH")"
  mkdir -p "$status_dir"
  suffix="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  tmp="${status_dir}/.$(basename "$EDGE_SYNC_STATUS_PATH").part.${suffix}"
  printf '{"current_config_version":%s,"last_successful_sync_time":%s,"config_source":"%s","core_reachable":%s}\n' \
    "${version:-null}" "${sync_time:-null}" "$source" "$core_reachable" > "$tmp"
  mv "$tmp" "$EDGE_SYNC_STATUS_PATH"
  chmod 0644 "$EDGE_SYNC_STATUS_PATH"
}

is_not_modified_response() {
  file="$1"
  python3 - "$file" <<'PY' 2>/dev/null
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as fh:
    data = json.load(fh)

if isinstance(data, dict) and data.get("not_modified") is True:
    sys.exit(0)
sys.exit(1)
PY
}

validate_config() {
  file="$1"
  python3 - "$file" <<'PY'
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as fh:
    data = json.load(fh)

if not isinstance(data, dict):
    raise SystemExit("config root must be an object")
if "version" not in data:
    raise SystemExit("config missing version")
if "hosts" not in data:
    raise SystemExit("config missing hosts")
PY
}

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/edge/config"
body_hash="$(printf '' | sha256sum | awk '{print $1}')"
canonical="$(printf 'GET\n%s\n%s\n%s\n%s' "${path}" "${ts}" "${nonce}" "${body_hash}")"
sig="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')" -binary | od -An -tx1 | tr -d ' \n')"

url="${CORE_URL}${path}"
current_version="$(config_version "$EDGE_CONFIG_PATH")"
if [ "$current_version" = "" ] && [ "$EDGE_CONFIG_CACHE_PATH" != "$EDGE_CONFIG_PATH" ]; then
  current_version="$(config_version "$EDGE_CONFIG_CACHE_PATH")"
fi
if [ "$current_version" != "" ]; then
  url="${url}?if_version=${current_version}"
fi

config_dir="$(dirname "$EDGE_CONFIG_PATH")"
suffix="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
tmp="${config_dir}/.$(basename "$EDGE_CONFIG_PATH").part.${suffix}"
trap 'rm -f "$tmp"' EXIT HUP INT TERM

if ! curl -fsS -o "$tmp" "$url" \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}"
then
  write_status false "active" "null"
  echo "config pull failed; keeping last-known-good config" >&2
  exit 1
fi

if is_not_modified_response "$tmp"; then
  rm -f "$tmp"
  write_status true "remote" "$ts"
  trap - EXIT HUP INT TERM
  exit 0
fi

if ! validate_config "$tmp"; then
  write_status true "active" "null"
  echo "config validation failed; keeping last-known-good config" >&2
  exit 1
fi

mv "$tmp" "$EDGE_CONFIG_PATH"
chmod 0644 "$EDGE_CONFIG_PATH"

cache_dir="$(dirname "$EDGE_CONFIG_CACHE_PATH")"
mkdir -p "$cache_dir"
cache_suffix="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
cache_tmp="${cache_dir}/.$(basename "$EDGE_CONFIG_CACHE_PATH").part.${cache_suffix}"
if [ "$EDGE_CONFIG_CACHE_PATH" != "$EDGE_CONFIG_PATH" ]; then
  cp "$EDGE_CONFIG_PATH" "$cache_tmp"
  mv "$cache_tmp" "$EDGE_CONFIG_CACHE_PATH"
  chmod 0600 "$EDGE_CONFIG_CACHE_PATH"
else
  rm -f "$cache_tmp"
fi

write_status true "remote" "$ts"
trap - EXIT HUP INT TERM
