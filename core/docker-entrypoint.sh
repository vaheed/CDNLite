#!/bin/sh
set -eu

attempt=1
while :; do
  if php artisan cdn:migrate; then
    break
  fi
  if [ "$attempt" -ge 5 ]; then
    echo "cdn:migrate failed after ${attempt} attempts" >&2
    exit 1
  fi
  sleep_seconds=$((attempt * 2))
  echo "cdn:migrate failed on attempt ${attempt}; retrying in ${sleep_seconds}s" >&2
  sleep "$sleep_seconds"
  attempt=$((attempt + 1))
done
exec "$@"
