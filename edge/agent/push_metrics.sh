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

curl -sS -X POST "$CORE_URL/api/v1/collector/usage" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  --data-binary "@$payload_file" >/dev/null || exit 0

: > "$METRIC_PATH"
rm -f "$payload_file"
