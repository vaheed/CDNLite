#!/bin/sh
set -eu

if [ "${CDNLITE_AUTO_MIGRATE:-true}" = "true" ]; then
  php artisan cdn:db:migrate
else
  echo "CDNLITE_AUTO_MIGRATE=false; skipping automatic database migrations"
fi

exec "$@"
