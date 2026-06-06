#!/bin/sh
set -eu

openssl req -x509 -nodes -newkey rsa:2048 \
  -keyout /tmp/origin.key \
  -out /tmp/origin.crt \
  -subj "/CN=invalid-origin.local" \
  -days 1 >/dev/null 2>&1

exec nginx -g 'daemon off;'
