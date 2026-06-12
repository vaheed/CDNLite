#!/usr/bin/env sh
set -eu

: "${CDNLITE_DNS_BASE_DOMAIN:?CDNLITE_DNS_BASE_DOMAIN is required}"
: "${CDNLITE_CDN_ZONE:?CDNLITE_CDN_ZONE is required}"
PDNS_AUTH_ADDRESS="${PDNS_AUTH_ADDRESS:-172.30.0.54}"

cat >/etc/powerdns/recursor.conf <<EOF
daemon=no
local-address=0.0.0.0
local-port=5300
allow-from=0.0.0.0/0
dont-query=
system-resolver-ttl=60
forward-zones=${CDNLITE_DNS_BASE_DOMAIN}=${PDNS_AUTH_ADDRESS}:53,${CDNLITE_CDN_ZONE}=${PDNS_AUTH_ADDRESS}:53
quiet=yes
EOF

exec pdns_recursor --config-dir=/etc/powerdns
