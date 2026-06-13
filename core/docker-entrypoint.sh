#!/bin/sh
set -eu

php -r "require '/var/www/html/app/Support/bootstrap.php'; App\\Support\\Database::installFreshSchema();"

exec "$@"
