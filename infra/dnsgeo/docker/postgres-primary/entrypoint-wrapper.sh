#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "postgres" ] || [ "${1:-}" = "" ] || [ "${1:0:1}" = "-" ]; then
  /usr/local/bin/cdnlite-pdns-ensure-tls.sh

  if [ -n "${PGDATA:-}" ] && [ -s "$PGDATA/PG_VERSION" ]; then
    /usr/local/bin/cdnlite-pdns-configure-primary.sh
  fi
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
