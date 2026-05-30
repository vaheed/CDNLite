#!/bin/sh
set -eu

if [ ! -f "$METRIC_PATH" ]; then
  exit 0
fi

if [ ! -s "$METRIC_PATH" ]; then
  exit 0
fi

payload_file="${METRIC_PATH}.payload"
{
  printf '{"items":['
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

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

curl -sS -X POST "$CORE_URL/api/v1/collector/usage" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNT-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNT-Timestamp: ${ts}" \
  -H "X-CDNT-Nonce: ${nonce}" \
  --data-binary "@$payload_file" >/dev/null || exit 0

: > "$METRIC_PATH"
rm -f "$payload_file"
