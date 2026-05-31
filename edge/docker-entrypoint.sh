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

exec "$@"
