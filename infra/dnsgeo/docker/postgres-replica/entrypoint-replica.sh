#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "postgres" ] || [ "${1:-}" = "" ] || [ "${1:0:1}" = "-" ]; then
  : "${PGDATA:?PGDATA is required}"
  : "${PRIMARY_HOST:?PRIMARY_HOST is required}"
  : "${REPLICATION_PASSWORD:?REPLICATION_PASSWORD is required}"

  PRIMARY_PORT="${PRIMARY_PORT:-5432}"
  REPLICATION_USER="${REPLICATION_USER:-replicator}"
  REPLICATION_SLOT_NAME="${REPLICATION_SLOT_NAME:-replica_$(hostname | tr '-' '_')}"
  REPLICA_CREATE_SLOT="${REPLICA_CREATE_SLOT:-true}"
  TLS_DIR="${TLS_DIR:-/tls}"
  EFFECTIVE_TLS_DIR="/tmp/cdnlite-replica-tls"
  conninfo="host=${PRIMARY_HOST} port=${PRIMARY_PORT} user=${REPLICATION_USER} sslmode=verify-ca sslrootcert=${EFFECTIVE_TLS_DIR}/ca.crt sslcert=${EFFECTIVE_TLS_DIR}/replicator.crt sslkey=${EFFECTIVE_TLS_DIR}/replicator.key"

  test -s "$TLS_DIR/ca.crt"
  test -s "$TLS_DIR/replicator.crt"
  test -s "$TLS_DIR/replicator.key"
  rm -rf "$EFFECTIVE_TLS_DIR"
  install -d -m 0700 -o postgres -g postgres "$EFFECTIVE_TLS_DIR"
  install -m 0644 -o postgres -g postgres "$TLS_DIR/ca.crt" "$TLS_DIR/replicator.crt" "$EFFECTIVE_TLS_DIR/"
  install -m 0600 -o postgres -g postgres "$TLS_DIR/replicator.key" "$EFFECTIVE_TLS_DIR/"

  if [ ! -s "$PGDATA/PG_VERSION" ]; then
    rm -rf "$PGDATA"/*
    install -d -m 0700 -o postgres -g postgres "$PGDATA"
    export PGPASSWORD="$REPLICATION_PASSWORD"
    until gosu postgres pg_isready -d "$conninfo" >/dev/null 2>&1; do sleep 2; done
    args=(-D "$PGDATA" -Fp -Xs -P -R -d "$conninfo")
    case "${REPLICA_CREATE_SLOT,,}" in true|1|yes|on) args+=(-C -S "$REPLICATION_SLOT_NAME");; esac
    gosu postgres pg_basebackup "${args[@]}"
  fi

  mkdir -p "$PGDATA/conf.d"
  cat >"$PGDATA/conf.d/99-cdnlite-replica.conf" <<EOF
listen_addresses = '*'
hot_standby = on
ssl = off
EOF
  grep -qxF "include_dir = 'conf.d'" "$PGDATA/postgresql.conf" || printf "\ninclude_dir = 'conf.d'\n" >>"$PGDATA/postgresql.conf"
  chown -R postgres:postgres "$PGDATA"
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
