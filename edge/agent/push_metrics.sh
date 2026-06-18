#!/bin/sh
set -eu

payload_file="${METRIC_PATH}.payload"
count_file="${payload_file}.count"
lock_dir="${METRIC_PATH}.push.lock"

if ! mkdir "$lock_dir" 2>/dev/null; then
  exit 0
fi
trap 'rmdir "$lock_dir" 2>/dev/null || true' 0 HUP INT TERM

ensure_metric_writable() {
  [ -f "$METRIC_PATH" ] || : > "$METRIC_PATH"
  chmod 666 "$METRIC_PATH" 2>/dev/null || true
}

payload_count() {
  file="$1"
  python3 - "$file" <<'PY' 2>/dev/null || true
import json
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)

items = data.get("items") if isinstance(data, dict) else None
if isinstance(items, list):
    print(len(items))
PY
}

build_payload() {
  [ -f "$METRIC_PATH" ] || return 1
  [ -s "$METRIC_PATH" ] || return 1

  count="$(awk 'NF { n++ } END { print n + 0 }' "$METRIC_PATH")"
  [ "$count" -gt 0 ] || return 1

  metrics_hash="$(sha256sum "$METRIC_PATH" | awk '{print $1}')"
  {
    printf '{"idempotency_key":"agent-%s","items":[' "$metrics_hash"
    first=1
    while IFS= read -r line; do
      [ -z "$line" ] && continue
      if [ "$first" -eq 1 ]; then
        printf '%s' "$line"
        first=0
      else
        printf ',%s' "$line"
      fi
    done < "$METRIC_PATH"
    printf ']}'
  } > "$payload_file"
  printf '%s\n' "$count" > "$count_file"
}

drop_sent_metrics() {
  sent_count="$1"
  tmp="${METRIC_PATH}.tmp.$$"
  if [ ! -f "$METRIC_PATH" ]; then
    ensure_metric_writable
    return 0
  fi

  awk -v n="$sent_count" '
    BEGIN { skipped = 0 }
    $0 != "" && skipped < n { skipped++; next }
    { print }
  ' "$METRIC_PATH" > "$tmp"
  mv "$tmp" "$METRIC_PATH"
  ensure_metric_writable
}

if [ ! -s "$payload_file" ]; then
  if ! build_payload; then
    exit 0
  fi
fi

sent_count=""
if [ -s "$count_file" ]; then
  sent_count="$(awk 'NR == 1 { print int($1) }' "$count_file")"
fi
if [ "$sent_count" = "" ] || [ "$sent_count" -le 0 ]; then
  sent_count="$(payload_count "$payload_file")"
fi
if [ "$sent_count" = "" ] || [ "$sent_count" -le 0 ]; then
  echo "metrics payload is invalid; preserving payload and metrics" >&2
  exit 1
fi

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/collector/usage"
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
  --data-binary "@$payload_file" >/dev/null
then
  echo "metrics push failed; preserving metrics and payload" >&2
  exit 1
fi

drop_sent_metrics "$sent_count"
rm -f "$payload_file" "$count_file"
