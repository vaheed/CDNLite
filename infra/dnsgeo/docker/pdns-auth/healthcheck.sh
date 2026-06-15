#!/usr/bin/env bash
set -euo pipefail

: "${PDNS_API_KEY:?PDNS_API_KEY is required for pdns-auth healthcheck}"

curl -fsS --max-time 2 \
  -H "X-API-Key: ${PDNS_API_KEY}" \
  "http://127.0.0.1:8081/api/v1/servers/localhost" \
  >/dev/null
