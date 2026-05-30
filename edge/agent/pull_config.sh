#!/bin/sh
set -eu

ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/edge/config"
body_hash="$(printf '' | sha256sum | awk '{print $1}')"
canonical="GET\n${path}\n${ts}\n${nonce}\n${body_hash}"
sig="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')" -binary | od -An -tx1 | tr -d ' \n')"
tmp="${EDGE_CONFIG_PATH}.tmp"
curl -sS "$CORE_URL${path}" \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}" > "$tmp"
mv "$tmp" "$EDGE_CONFIG_PATH"
