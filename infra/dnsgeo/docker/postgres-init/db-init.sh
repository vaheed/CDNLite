#!/usr/bin/env bash
set -euo pipefail

: "${POSTGRES_SUPERUSER_PASSWORD:?POSTGRES_SUPERUSER_PASSWORD is required}"
: "${PDNS_DB_PASSWORD:?PDNS_DB_PASSWORD is required}"
: "${POWERADMIN_DB_PASSWORD:?POWERADMIN_DB_PASSWORD is required}"
: "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is required}"

POSTGRES_HOST="${POSTGRES_HOST:-postgres}"
POSTGRES_PORT="${POSTGRES_PORT:-5432}"
POSTGRES_USER="${POSTGRES_USER:-postgres}"
POSTGRES_DB="${POSTGRES_DB:-postgres}"
PG_TLS_CA_FILE="${PG_TLS_CA_FILE:-/certs/postgres/ca.crt}"
PG_SSLMODE="${PG_SSLMODE:-verify-ca}"
DB_INIT_TIMEOUT_SECONDS="${DB_INIT_TIMEOUT_SECONDS:-300}"

log() {
  printf '[%s] [cdnlite-pdns-db-init] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

base_conn="host=${POSTGRES_HOST} port=${POSTGRES_PORT} user=${POSTGRES_USER} dbname=${POSTGRES_DB} sslmode=${PG_SSLMODE} sslrootcert=${PG_TLS_CA_FILE}"
pdns_conn="host=${POSTGRES_HOST} port=${POSTGRES_PORT} user=${POSTGRES_USER} dbname=pdns sslmode=${PG_SSLMODE} sslrootcert=${PG_TLS_CA_FILE}"

export PGPASSWORD="$POSTGRES_SUPERUSER_PASSWORD"

log "waiting for PostgreSQL TLS CA at ${PG_TLS_CA_FILE}"
for _ in $(seq 1 "$DB_INIT_TIMEOUT_SECONDS"); do
  [ -s "$PG_TLS_CA_FILE" ] && break
  sleep 1
done

if [ ! -s "$PG_TLS_CA_FILE" ]; then
  log "PostgreSQL CA file is missing or unreadable: ${PG_TLS_CA_FILE}"
  exit 1
fi

log "waiting for PostgreSQL at ${POSTGRES_HOST}:${POSTGRES_PORT}"
for _ in $(seq 1 "$DB_INIT_TIMEOUT_SECONDS"); do
  if psql "$base_conn" -v ON_ERROR_STOP=1 -qAt -c 'SELECT 1' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

psql "$base_conn" -v ON_ERROR_STOP=1 -qAt -c 'SELECT 1' >/dev/null

log "ensuring roles and pdns database"
psql "$base_conn" -v ON_ERROR_STOP=1 \
  -v pdns_password="$PDNS_DB_PASSWORD" \
  -v poweradmin_password="$POWERADMIN_DB_PASSWORD" \
  -v replication_password="$REPLICATION_PASSWORD" \
  -f /sql/10-roles-and-database.sql

log "ensuring PowerDNS schema"
psql "$pdns_conn" -v ON_ERROR_STOP=1 -f /sql/20-pdns-schema.sql

log "ensuring Poweradmin schema"
psql "$pdns_conn" -v ON_ERROR_STOP=1 -f /sql/30-poweradmin-schema.sql

log "database initialization completed; Core owns all DNS zones and records"
