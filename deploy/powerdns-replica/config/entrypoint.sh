#!/bin/sh
set -e

DB=/var/lib/powerdns/pdns.sqlite3
SCHEMA=/usr/share/doc/pdns-backend-gsqlite3/schema.sqlite3.sql

if [ ! -f "$DB" ]; then
  echo "[init] Creating PowerDNS sqlite3 schema (replica)..."
  sqlite3 "$DB" < "$SCHEMA"
  echo "[init] Seeding supermasters..."
  sh /etc/powerdns/seed-supermasters.sh "$DB"
fi

exec pdns_server --daemon=no --guardian=no
