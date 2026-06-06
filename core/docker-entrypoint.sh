#!/bin/sh
set -eu

php artisan cdn:migrate
exec "$@"
