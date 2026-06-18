#!/bin/sh
set -eu

EDGE_CONFIG_CACHE_PATH="${EDGE_CONFIG_CACHE_PATH:-$EDGE_CONFIG_PATH}"
EDGE_SYNC_STATUS_PATH="${EDGE_SYNC_STATUS_PATH:-/var/lib/cdnlite/edge-sync-status.json}"

validate_config() {
  file="$1"
  [ -s "$file" ] || return 1
  python3 - "$file" <<'PY' >/dev/null 2>&1
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8", errors="replace") as fh:
    data = json.load(fh)

if not isinstance(data, dict):
    raise SystemExit(1)
if "version" not in data:
    raise SystemExit(1)
if "hosts" not in data:
    raise SystemExit(1)
PY
}

copy_atomic() {
  src="$1"
  dst="$2"
  dst_dir="$(dirname "$dst")"
  mkdir -p "$dst_dir"
  suffix="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  tmp="${dst_dir}/.$(basename "$dst").part.${suffix}"
  cp "$src" "$tmp"
  mv "$tmp" "$dst"
  chmod 0644 "$dst"
}

write_status_cache() {
  ts="$(date +%s)"
  version="$(python3 - "$EDGE_CONFIG_PATH" <<'PY' 2>/dev/null || true
import json,sys
with open(sys.argv[1], 'r', encoding='utf-8', errors='replace') as f:
 d=json.load(f)
v=d.get('version') if isinstance(d,dict) else None
print(v if isinstance(v,int) else 'null')
PY
)"
  status_dir="$(dirname "$EDGE_SYNC_STATUS_PATH")"
  mkdir -p "$status_dir"
  suffix="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  tmp="${status_dir}/.$(basename "$EDGE_SYNC_STATUS_PATH").part.${suffix}"
  printf '{"current_config_version":%s,"last_successful_sync_time":%s,"config_source":"cache","core_reachable":false}\n' "$version" "$ts" > "$tmp"
  mv "$tmp" "$EDGE_SYNC_STATUS_PATH"
  chmod 0644 "$EDGE_SYNC_STATUS_PATH"
}

if /agent/pull_config.sh; then
  exit 0
fi

if validate_config "$EDGE_CONFIG_CACHE_PATH"; then
  copy_atomic "$EDGE_CONFIG_CACHE_PATH" "$EDGE_CONFIG_PATH"
  write_status_cache
  exit 0
fi

echo "config init failed: core unreachable and no valid cached config" >&2
exit 1
