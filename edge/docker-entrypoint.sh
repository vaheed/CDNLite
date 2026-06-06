#!/bin/sh
set -eu

if [ "${EDGE_ID:-}" = "" ] && [ "${DEV_MODE:-0}" != "1" ]; then
  echo "EDGE_ID is required unless DEV_MODE=1" >&2
  exit 1
fi

ttl="${CDNLITE_CACHE_DEFAULT_TTL:-60s}"
case "$ttl" in
  *[!0-9smhdw]*|'')
    echo "invalid CDNLITE_CACHE_DEFAULT_TTL: $ttl" >&2
    exit 1
    ;;
esac

sed "s/__CDNLITE_CACHE_DEFAULT_TTL__/$ttl/g" \
  /usr/local/openresty/nginx/conf/nginx.conf.template \
  > /usr/local/openresty/nginx/conf/nginx.conf

mkdir -p /var/lib/cdnlite/tls
mkdir -p /var/lib/cdnlite
touch /var/lib/cdnlite/metrics.ndjson /var/lib/cdnlite/security-events.ndjson
chmod 666 /var/lib/cdnlite/metrics.ndjson /var/lib/cdnlite/security-events.ndjson || true
if [ ! -f /var/lib/cdnlite/tls/default.crt ] || [ ! -f /var/lib/cdnlite/tls/default.key ]; then
  openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout /var/lib/cdnlite/tls/default.key \
    -out /var/lib/cdnlite/tls/default.crt \
    -subj "/CN=cdnlite-default.local" \
    -days 3650 >/dev/null 2>&1
fi

exec "$@"
