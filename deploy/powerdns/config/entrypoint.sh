#!/bin/sh
set -e

DB=/var/lib/powerdns/pdns.sqlite3
SCHEMA=/usr/share/doc/pdns-backend-gsqlite3/schema.sqlite3.sql

if [ ! -f "$DB" ]; then
  echo "[init] Creating PowerDNS sqlite3 schema..."
  sqlite3 "$DB" < "$SCHEMA"
fi

cp /etc/powerdns/pdns.conf.template /etc/powerdns/pdns.conf
sed -i "s|\$PDNS_API_KEY|${PDNS_API_KEY}|g" /etc/powerdns/pdns.conf
sed -i "s|\$PDNS_REPLICA_IP|${PDNS_REPLICA_IP}|g" /etc/powerdns/pdns.conf

exec pdns_server --daemon=no --guardian=no
