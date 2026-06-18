#!/bin/sh
set -eu

payload_file="${METRIC_PATH}.payload"
count_file="${payload_file}.count"
lock_dir="${METRIC_PATH}.push.lock"
response_file="${payload_file}.response"
bad_file="${METRIC_PATH}.bad"

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
  [ -f "$METRIC_PATH" ] || return 1
  [ -s "$METRIC_PATH" ] || return 1

  tmp_metrics="${METRIC_PATH}.valid.$$"
  tmp_payload="${payload_file}.$$"
  tmp_bad="${bad_file}.$$"
  if ! python3 - "$METRIC_PATH" "$tmp_metrics" "$tmp_payload" "$tmp_bad" <<'PY'
import json
import sys
import time

source, metrics_out, payload_out, bad_out = sys.argv[1:5]
items = []
bad_count = 0

with open(source, "r", encoding="utf-8", errors="replace") as fh, \
     open(metrics_out, "w", encoding="utf-8") as metrics_fh, \
     open(bad_out, "w", encoding="utf-8") as bad_fh:
    for line_no, raw in enumerate(fh, 1):
        line = raw.strip()
        if line == "":
            continue
        try:
            item = json.loads(line)
        except Exception as exc:
            bad_count += 1
            bad_fh.write(json.dumps({
                "ts": int(time.time()),
                "line": line_no,
                "error": str(exc),
                "raw": line,
            }, separators=(",", ":")) + "\n")
            continue
        if not isinstance(item, dict):
            bad_count += 1
            bad_fh.write(json.dumps({
                "ts": int(time.time()),
                "line": line_no,
                "error": "metric_line_must_be_json_object",
                "raw": line,
            }, separators=(",", ":")) + "\n")
            continue
        items.append(item)
        metrics_fh.write(json.dumps(item, separators=(",", ":")) + "\n")

if bad_count:
    print(f"quarantined {bad_count} invalid metric line(s)", file=sys.stderr)

with open(payload_out, "w", encoding="utf-8") as payload_fh:
    json.dump({
        "idempotency_key": "agent-pending",
        "items": items,
    }, payload_fh, separators=(",", ":"))

print(len(items))
PY
  then
    rm -f "$tmp_metrics" "$tmp_payload" "$tmp_bad"
    return 1
  fi

  if [ -s "$tmp_bad" ]; then
    cat "$tmp_bad" >> "$bad_file"
  fi
  mv "$tmp_metrics" "$METRIC_PATH"
  ensure_metric_writable

  count="$(payload_count "$tmp_payload")"
  if [ "$count" -le 0 ]; then
    rm -f "$tmp_payload" "$tmp_bad"
    return 1
  fi

  metrics_hash="$(sha256sum "$METRIC_PATH" | awk '{print $1}')"
  python3 - "$tmp_payload" "$payload_file" "$metrics_hash" <<'PY'
import json
import sys

pending, target, metrics_hash = sys.argv[1:4]
with open(pending, "r", encoding="utf-8", errors="replace") as fh:
    payload = json.load(fh)
payload["idempotency_key"] = "agent-" + metrics_hash
with open(target, "w", encoding="utf-8") as fh:
    json.dump(payload, fh, separators=(",", ":"))
PY
  rm -f "$tmp_payload" "$tmp_bad"
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

if [ -s "$payload_file" ]; then
  existing_count="$(payload_count "$payload_file")"
  if [ "$existing_count" = "" ] || [ "$existing_count" -le 0 ]; then
    echo "metrics payload is invalid; rebuilding from metrics queue" >&2
    rm -f "$payload_file" "$count_file" "$response_file"
  fi
fi

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

rm -f "$response_file"
http_status="$(curl -sS -o "$response_file" -w '%{http_code}' -X POST "$CORE_URL${path}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}" \
  --data-binary "@$payload_file")" || http_status=""
case "$http_status" in
  2*) ;;
  *)
    if [ -s "$response_file" ]; then
      echo "metrics collector response saved to $response_file" >&2
    fi
    echo "metrics push failed; preserving metrics and payload" >&2
    exit 1
    ;;
esac

drop_sent_metrics "$sent_count"
rm -f "$payload_file" "$count_file" "$response_file"
