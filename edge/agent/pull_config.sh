#!/bin/sh
set -eu

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
tmp="${EDGE_CONFIG_PATH}.tmp"
curl -sS "$CORE_URL/api/v1/edge/config" \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNT-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNT-Timestamp: ${ts}" \
  -H "X-CDNT-Nonce: ${nonce}" > "$tmp"
mv "$tmp" "$EDGE_CONFIG_PATH"
