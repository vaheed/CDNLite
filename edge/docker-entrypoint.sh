#!/bin/sh
set -eu

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
if [ ! -f /var/lib/cdnlite/tls/default.crt ] || [ ! -f /var/lib/cdnlite/tls/default.key ]; then
  openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout /var/lib/cdnlite/tls/default.key \
    -out /var/lib/cdnlite/tls/default.crt \
    -subj "/CN=cdnlite-default.local" \
    -days 3650 >/dev/null 2>&1
fi

exec "$@"
