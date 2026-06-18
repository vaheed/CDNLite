#!/bin/sh
set -eu

SECURITY_EVENT_PATH="${SECURITY_EVENT_PATH:-/var/lib/cdnlite/security-events.ndjson}"
payload_file="${SECURITY_EVENT_PATH}.payload"
count_file="${payload_file}.count"
lock_dir="${SECURITY_EVENT_PATH}.push.lock"

if ! mkdir "$lock_dir" 2>/dev/null; then
  exit 0
fi
trap 'rmdir "$lock_dir" 2>/dev/null || true' 0 HUP INT TERM

ensure_queue_writable() {
  [ -f "$SECURITY_EVENT_PATH" ] || : > "$SECURITY_EVENT_PATH"
  chmod 666 "$SECURITY_EVENT_PATH" 2>/dev/null || true
}

payload_count() {
  file="$1"
  python3 - "$file" <<'PY' 2>/dev/null || true
import json
import sys
try:
    with open(sys.argv[1], "r", encoding="utf-8", errors="replace") as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)
items = data.get("items") if isinstance(data, dict) else None
if isinstance(items, list):
    print(len(items))
PY
}

build_payload() {
  [ -f "$SECURITY_EVENT_PATH" ] || return 1
  [ -s "$SECURITY_EVENT_PATH" ] || return 1
  count="$(awk 'NF { n++ } END { print n + 0 }' "$SECURITY_EVENT_PATH")"
  [ "$count" -gt 0 ] || return 1
  h="$(sha256sum "$SECURITY_EVENT_PATH" | awk '{print $1}')"
  {
    printf '{"idempotency_key":"sec-%s","items":[' "$h"
    first=1
    while IFS= read -r line; do
      [ -z "$line" ] && continue
      if [ "$first" -eq 1 ]; then printf '%s' "$line"; first=0; else printf ',%s' "$line"; fi
    done < "$SECURITY_EVENT_PATH"
    printf ']}'
  } > "$payload_file"
  printf '%s\n' "$count" > "$count_file"
}

drop_sent() {
  sent="$1"
  tmp="${SECURITY_EVENT_PATH}.tmp.$$"
  awk -v n="$sent" 'BEGIN{sk=0} $0!="" && sk<n {sk++; next} {print}' "$SECURITY_EVENT_PATH" > "$tmp"
  mv "$tmp" "$SECURITY_EVENT_PATH"
  ensure_queue_writable
}

[ -s "$payload_file" ] || build_payload || exit 0
sent_count=""
if [ -s "$count_file" ]; then
  sent_count="$(awk 'NR==1{print int($1)}' "$count_file")"
fi
if [ "$sent_count" = "" ] || [ "$sent_count" -le 0 ]; then
  sent_count="$(payload_count "$payload_file")"
fi
if [ "$sent_count" = "" ] || [ "$sent_count" -le 0 ]; then
  echo "security-events payload is invalid; preserving payload and queue" >&2
  exit 1
fi

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/collector/security-events"
body_hash="$(sha256sum "$payload_file" | awk '{print $1}')"
canonical="$(printf 'POST\n%s\n%s\n%s\n%s' "${path}" "${ts}" "${nonce}" "${body_hash}")"
sig="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')" -binary | od -An -tx1 | tr -d ' \n')"

if ! curl -fsS -X POST "$CORE_URL${path}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}" \
  --data-binary "@$payload_file" >/dev/null; then
  echo "security-events push failed; preserving queue and payload" >&2
  exit 1
fi

drop_sent "$sent_count"
rm -f "$payload_file" "$count_file"
