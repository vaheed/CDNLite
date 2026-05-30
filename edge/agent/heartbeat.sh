#!/bin/sh
set -eu

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

curl -sS -X POST "$CORE_URL/api/v1/edge/heartbeat" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNT-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNT-Timestamp: ${ts}" \
  -H "X-CDNT-Nonce: ${nonce}" \
  -d "{\"edge_id\":\"${EDGE_ID}\"}" >/dev/null
