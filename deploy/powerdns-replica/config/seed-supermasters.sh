#!/bin/sh
set -e

DB="${1:-/var/lib/powerdns/pdns.sqlite3}"
sqlite3 "$DB" \
  "INSERT OR IGNORE INTO supermasters (ip, nameserver, account) VALUES ('${PDNS_PRIMARY_IP}', 'ns1.${PDNS_PRIMARY_ACCOUNT}.invalid', '${PDNS_PRIMARY_ACCOUNT}');"
echo "[init] Supermaster ${PDNS_PRIMARY_IP} registered."
