#!/bin/sh
set -eu

if [ "${CDNLITE_AUTO_MIGRATE:-true}" = "true" ]; then
  php artisan migrate --seed --force
else
  echo "CDNLITE_AUTO_MIGRATE=false; skipping automatic database migrations"
fi

exec "$@"
