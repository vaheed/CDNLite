#!/bin/sh
set -eu

. /agent/lib.sh

public_ip="$(cdnlite_public_ip)"
config_version="$(cdnlite_config_version "$EDGE_CONFIG_PATH")"
if [ "$config_version" = "" ]; then
  config_version="0"
fi
config_apply_error="$(cdnlite_config_apply_error "$EDGE_SYNC_STATUS_PATH")"
ts="$(date +%s)"
nonce="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
path="/api/v1/edge/heartbeat"
body="{\"edge_id\":\"${EDGE_ID}\",\"hostname\":\"${EDGE_HOSTNAME}\",\"public_ip\":\"${public_ip}\",\"region\":\"${EDGE_REGION}\",\"country\":\"${EDGE_COUNTRY:-}\",\"continent\":\"${EDGE_CONTINENT:-}\",\"version\":\"${EDGE_VERSION}\",\"config_version\":${config_version},\"config_apply_error\":\"${config_apply_error:-}\",\"health_status\":\"healthy\"}"
body_hash="$(printf '%s' "$body" | sha256sum | awk '{print $1}')"
canonical="$(printf 'POST\n%s\n%s\n%s\n%s' "${path}" "${ts}" "${nonce}" "${body_hash}")"
sig="$(printf '%s' "$canonical" | openssl dgst -sha256 -hmac "$(printf '%s' "$EDGE_TOKEN" | sha256sum | awk '{print $1}')" -binary | od -An -tx1 | tr -d ' \n')"

curl -fsS -X POST "$CORE_URL${path}" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${EDGE_TOKEN}" \
  -H "X-CDNLITE-Edge-Id: ${EDGE_ID}" \
  -H "X-CDNLITE-Timestamp: ${ts}" \
  -H "X-CDNLITE-Nonce: ${nonce}" \
  -H "X-CDNLITE-Signature: ${sig}" \
  -d "$body" >/dev/null
