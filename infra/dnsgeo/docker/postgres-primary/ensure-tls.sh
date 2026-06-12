#!/usr/bin/env bash
set -euo pipefail

PG_TLS_DIR="${PG_TLS_DIR:-/var/lib/postgresql/tls}"
PG_TLS_DAYS="${PG_TLS_DAYS:-3650}"
PG_TLS_CA_CN="${PG_TLS_CA_CN:-CDNLite PowerDNS PostgreSQL CA}"
PG_TLS_SERVER_CN="${PG_TLS_SERVER_CN:-pdns-postgres}"
PG_TLS_SAN="${PG_TLS_SAN:-DNS:pdns-postgres,IP:127.0.0.1}"
REPLICATION_USER="${REPLICATION_USER:-replicator}"

mkdir -p "$PG_TLS_DIR/private" "$PG_TLS_DIR/replicator"

# The top-level TLS directory must be traversable by client containers that only
# need ca.crt. Private keys remain locked down below private/.
chmod 755 "$PG_TLS_DIR"
chmod 700 "$PG_TLS_DIR/private" "$PG_TLS_DIR/replicator"

if [ ! -s "$PG_TLS_DIR/ca.crt" ] || [ ! -s "$PG_TLS_DIR/private/ca.key" ]; then
  echo "[postgres-tls] generating PostgreSQL CA valid for ${PG_TLS_DAYS} days"
  rm -f "$PG_TLS_DIR/ca.crt" "$PG_TLS_DIR/private/ca.key" "$PG_TLS_DIR/ca.srl"
  openssl genrsa -out "$PG_TLS_DIR/private/ca.key" 4096
  openssl req -new -x509 -sha256 -days "$PG_TLS_DAYS" \
    -key "$PG_TLS_DIR/private/ca.key" \
    -out "$PG_TLS_DIR/ca.crt" \
    -subj "/CN=${PG_TLS_CA_CN}"
fi

if [ ! -s "$PG_TLS_DIR/server.crt" ] || [ ! -s "$PG_TLS_DIR/private/server.key" ]; then
  echo "[postgres-tls] generating PostgreSQL server certificate valid for ${PG_TLS_DAYS} days"
  tmpdir="$(mktemp -d)"
  trap 'rm -rf "$tmpdir"' RETURN

  rm -f "$PG_TLS_DIR/server.crt" "$PG_TLS_DIR/private/server.key"
  openssl genrsa -out "$PG_TLS_DIR/private/server.key" 4096
  openssl req -new \
    -key "$PG_TLS_DIR/private/server.key" \
    -out "$tmpdir/server.csr" \
    -subj "/CN=${PG_TLS_SERVER_CN}"

  cat > "$tmpdir/server.ext" <<EOF_EXT
subjectAltName=${PG_TLS_SAN}
extendedKeyUsage=serverAuth
keyUsage=digitalSignature,keyEncipherment
EOF_EXT

  openssl x509 -req -sha256 -days "$PG_TLS_DAYS" \
    -in "$tmpdir/server.csr" \
    -CA "$PG_TLS_DIR/ca.crt" \
    -CAkey "$PG_TLS_DIR/private/ca.key" \
    -CAcreateserial \
    -out "$PG_TLS_DIR/server.crt" \
    -extfile "$tmpdir/server.ext"
fi

if [ ! -s "$PG_TLS_DIR/replicator/replicator.crt" ] || [ ! -s "$PG_TLS_DIR/replicator/replicator.key" ]; then
  echo "[cdnlite-pdns-postgres] generating replication client certificate"
  tmpdir="$(mktemp -d)"
  trap 'rm -rf "$tmpdir"' RETURN
  openssl genrsa -out "$PG_TLS_DIR/replicator/replicator.key" 4096
  openssl req -new -key "$PG_TLS_DIR/replicator/replicator.key" \
    -out "$tmpdir/replicator.csr" -subj "/CN=${REPLICATION_USER}"
  cat > "$tmpdir/client.ext" <<EOF_EXT
extendedKeyUsage=clientAuth
keyUsage=digitalSignature,keyEncipherment
EOF_EXT
  openssl x509 -req -sha256 -days "$PG_TLS_DAYS" \
    -in "$tmpdir/replicator.csr" -CA "$PG_TLS_DIR/ca.crt" \
    -CAkey "$PG_TLS_DIR/private/ca.key" -CAcreateserial \
    -out "$PG_TLS_DIR/replicator/replicator.crt" -extfile "$tmpdir/client.ext"
fi

cp -f "$PG_TLS_DIR/ca.crt" "$PG_TLS_DIR/replicator/ca.crt"
chmod 600 "$PG_TLS_DIR/private/ca.key" "$PG_TLS_DIR/private/server.key" "$PG_TLS_DIR/replicator/replicator.key"
chmod 644 "$PG_TLS_DIR/ca.crt" "$PG_TLS_DIR/server.crt" "$PG_TLS_DIR/replicator/ca.crt" "$PG_TLS_DIR/replicator/replicator.crt"
chmod 755 "$PG_TLS_DIR"
chmod 700 "$PG_TLS_DIR/private" "$PG_TLS_DIR/replicator"
chown -R postgres:postgres "$PG_TLS_DIR"
