#!/bin/sh
set -eu

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"

curl -sS -X POST "$CORE_URL/api/v1/edge/register" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNT-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNT-Timestamp: ${ts}" \
  -H "X-CDNT-Nonce: ${nonce}" \
  -d "{\"edge_id\":\"${EDGE_ID}\",\"hostname\":\"${EDGE_HOSTNAME}\",\"public_ip\":\"${EDGE_PUBLIC_IP}\",\"region\":\"${EDGE_REGION}\",\"version\":\"${EDGE_VERSION}\"}" >/dev/null
