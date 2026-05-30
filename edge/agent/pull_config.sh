#!/bin/sh
set -eu

tmp="${EDGE_CONFIG_PATH}.tmp"
curl -sS "$CORE_URL/api/v1/edge/config" \
  -H "Authorization: Bearer ${EDGE_TOKEN}" > "$tmp"
mv "$tmp" "$EDGE_CONFIG_PATH"
