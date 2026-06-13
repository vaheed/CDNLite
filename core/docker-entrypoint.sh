#!/bin/sh
set -eu

php -r "require '/app/app/Support/bootstrap.php'; App\\Support\\Database::installFreshSchema();"

exec "$@"
