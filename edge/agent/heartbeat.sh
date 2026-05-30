#!/bin/sh
set -eu

curl -sS -X POST "$CORE_URL/api/v1/edge/heartbeat" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -d "{\"edge_id\":\"${EDGE_ID}\"}" >/dev/null
