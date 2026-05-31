#!/bin/sh
set -eu

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/edge/heartbeat"
body="{\"edge_id\":\"${EDGE_ID}\"}"
body_hash="$(printf '%s' "$body" | sha256sum | awk '{print $1}')"
canonical="$(printf 'POST\n%s\n%s\n%s\n%s' "${path}" "${ts}" "${nonce}" "${body_hash}")"
sig="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')" -binary | od -An -tx1 | tr -d ' \n')"

curl -sS -X POST "$CORE_URL${path}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}" \
  -d "$body" >/dev/null
