#!/usr/bin/env bash
set -euo pipefail

: "${PGDATA:?PGDATA is required}"

PG_MAX_WAL_SENDERS="${PG_MAX_WAL_SENDERS:-10}"
PG_MAX_REPLICATION_SLOTS="${PG_MAX_REPLICATION_SLOTS:-10}"
PG_WAL_KEEP_SIZE="${PG_WAL_KEEP_SIZE:-512MB}"
PG_TLS_DIR="${PG_TLS_DIR:-/var/lib/postgresql/tls}"
PG_REPLICATION_CLIENT_CERT_AUTH="${PG_REPLICATION_CLIENT_CERT_AUTH:-true}"

mkdir -p "$PGDATA/conf.d"

cat > "$PGDATA/conf.d/00-cdnlite-pdns.conf" <<EOF_CONF
listen_addresses = '*'
wal_level = replica
max_wal_senders = ${PG_MAX_WAL_SENDERS}
max_replication_slots = ${PG_MAX_REPLICATION_SLOTS}
wal_keep_size = '${PG_WAL_KEEP_SIZE}'
hot_standby = on
password_encryption = 'scram-sha-256'
ssl = on
ssl_ca_file = '${PG_TLS_DIR}/ca.crt'
ssl_cert_file = '${PG_TLS_DIR}/server.crt'
ssl_key_file = '${PG_TLS_DIR}/private/server.key'
ssl_min_protocol_version = 'TLSv1.2'
EOF_CONF

if ! grep -qxF "include_dir = 'conf.d'" "$PGDATA/postgresql.conf"; then
  printf "\ninclude_dir = 'conf.d'\n" >> "$PGDATA/postgresql.conf"
fi

replication_cert_option=""
case "${PG_REPLICATION_CLIENT_CERT_AUTH,,}" in
  true|1|yes|on) replication_cert_option=" clientcert=verify-full" ;;
esac

cat > "$PGDATA/pg_hba.conf" <<EOF_HBA
# Local administrative access inside the PostgreSQL container.
local   all             all                                     trust

# Optional CDNLite DNS replicas use TLS, SCRAM, and client certificates.
hostssl replication     replicator      0.0.0.0/0               scram-sha-256${replication_cert_option}
hostssl replication     replicator      ::/0                    scram-sha-256${replication_cert_option}

# CDNLite DNS services require TLS plus SCRAM password authentication.
hostssl all             all             0.0.0.0/0               scram-sha-256
hostssl all             all             ::/0                    scram-sha-256

# Do not allow plaintext TCP connections.
hostnossl all           all             0.0.0.0/0               reject
hostnossl all           all             ::/0                    reject
EOF_HBA

chown -R postgres:postgres "$PGDATA/conf.d" "$PGDATA/pg_hba.conf" "$PGDATA/postgresql.conf"
