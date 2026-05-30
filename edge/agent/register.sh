#!/bin/sh
set -eu

curl -sS -X POST "$CORE_URL/api/v1/edge/register" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -d "{\"edge_id\":\"${EDGE_ID}\",\"hostname\":\"${EDGE_HOSTNAME}\",\"public_ip\":\"${EDGE_PUBLIC_IP}\",\"region\":\"${EDGE_REGION}\",\"version\":\"${EDGE_VERSION}\"}" >/dev/null
