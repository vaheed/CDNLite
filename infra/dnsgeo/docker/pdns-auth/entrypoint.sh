#!/usr/bin/env bash
set -euo pipefail

: "${PDNS_DB_PASSWORD:?PDNS_DB_PASSWORD is required}"
: "${PDNS_API_KEY:?PDNS_API_KEY is required}"

PDNS_LOCAL_ADDRESS="${PDNS_LOCAL_ADDRESS:-0.0.0.0,::}"
PDNS_LOCAL_PORT="${PDNS_LOCAL_PORT:-53}"
PDNS_DB_HOST="${PDNS_DB_HOST:-postgres}"
PDNS_DB_PORT="${PDNS_DB_PORT:-5432}"
PDNS_DB_NAME="${PDNS_DB_NAME:-pdns}"
PDNS_DB_USER="${PDNS_DB_USER:-pdns}"
PDNS_GPGSQL_EXTRA_CONNECTION_PARAMETERS="${PDNS_GPGSQL_EXTRA_CONNECTION_PARAMETERS:-sslmode=verify-ca sslrootcert=/var/lib/postgresql/tls/ca.crt}"
PDNS_PRIMARY="${PDNS_PRIMARY:-yes}"
PDNS_SECONDARY="${PDNS_SECONDARY:-no}"
PDNS_WEBSERVER_PASSWORD="${PDNS_WEBSERVER_PASSWORD:-}"
PDNS_WEBSERVER_ALLOW_FROM="${PDNS_WEBSERVER_ALLOW_FROM:-0.0.0.0/0,::/0}"
PDNS_GEO_BOOTSTRAP_ZONE_FILE="${PDNS_GEO_BOOTSTRAP_ZONE_FILE:-/etc/powerdns/geo/lua-bootstrap.yml}"
PDNS_GEO_MMDB_FILE="${PDNS_GEO_MMDB_FILE:-/var/lib/powerdns/mmdb/GeoLite2-City.mmdb}"
PDNS_EDNS_SUBNET_PROCESSING="${PDNS_EDNS_SUBNET_PROCESSING:-yes}"
PDNS_ENABLE_LUA_RECORDS="${PDNS_ENABLE_LUA_RECORDS:-yes}"
PDNS_LUA_RECORDS_EXEC_LIMIT="${PDNS_LUA_RECORDS_EXEC_LIMIT:-1000}"
PDNS_LUA_HEALTH_CHECKS_INTERVAL="${PDNS_LUA_HEALTH_CHECKS_INTERVAL:-5}"
PDNS_LUA_HEALTH_CHECKS_EXPIRE_DELAY="${PDNS_LUA_HEALTH_CHECKS_EXPIRE_DELAY:-3600}"
PDNS_CACHE_TTL="${PDNS_CACHE_TTL:-30}"
PDNS_QUERY_CACHE_TTL="${PDNS_QUERY_CACHE_TTL:-30}"
PDNS_LOGLEVEL="${PDNS_LOGLEVEL:-5}"
PDNS_RESTART_ON_MMDB_CHANGE="${PDNS_RESTART_ON_MMDB_CHANGE:-yes}"
PDNS_MMDB_WATCH_INTERVAL_SECONDS="${PDNS_MMDB_WATCH_INTERVAL_SECONDS:-300}"
PDNS_DB_WAIT_TIMEOUT_SECONDS="${PDNS_DB_WAIT_TIMEOUT_SECONDS:-300}"
PDNS_EXPAND_ALIAS="${PDNS_EXPAND_ALIAS:-yes}"
PDNS_RESOLVER="${PDNS_RESOLVER:-pdns-recursor:5300}"

log() {
  printf '[%s] [pdns-auth] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

extract_conn_param() {
  local key="$1"
  printf '%s\n' "$PDNS_GPGSQL_EXTRA_CONNECTION_PARAMETERS" | tr ' ' '\n' | awk -F= -v k="$key" '$1 == k {print substr($0, length(k) + 2)}' | tail -n 1
}

wait_for_mmdb() {
  if [ -s "$PDNS_GEO_MMDB_FILE" ]; then
    return 0
  fi

  log "waiting for MMDB file: ${PDNS_GEO_MMDB_FILE}"
  for _ in $(seq 1 120); do
    [ -s "$PDNS_GEO_MMDB_FILE" ] && return 0
    sleep 2
  done

  log "MMDB file is missing; check the pdns-mmdb-updater service."
  exit 1
}

wait_for_database_schema() {
  local sslmode sslrootcert conn elapsed
  sslmode="$(extract_conn_param sslmode)"
  sslrootcert="$(extract_conn_param sslrootcert)"
  sslmode="${sslmode:-disable}"

  if [ -n "$sslrootcert" ] && { [ "$sslmode" = "verify-ca" ] || [ "$sslmode" = "verify-full" ]; }; then
    log "waiting for PostgreSQL CA file ${sslrootcert}"
    elapsed=0
    until [ -s "$sslrootcert" ]; do
      if [ "$elapsed" -ge "$PDNS_DB_WAIT_TIMEOUT_SECONDS" ]; then
        log "CA file is still missing or unreadable: ${sslrootcert}"
        exit 1
      fi
      sleep 1
      elapsed=$((elapsed + 1))
    done
  fi

  conn="host=${PDNS_DB_HOST} port=${PDNS_DB_PORT} user=${PDNS_DB_USER} dbname=${PDNS_DB_NAME} sslmode=${sslmode}"
  if [ -n "$sslrootcert" ]; then
    conn="${conn} sslrootcert=${sslrootcert}"
  fi

  export PGPASSWORD="$PDNS_DB_PASSWORD"
  log "waiting for PowerDNS PostgreSQL schema"
  elapsed=0
  until psql "$conn" -v ON_ERROR_STOP=1 -qAt -c "SELECT 1 WHERE to_regclass('public.domains') IS NOT NULL AND to_regclass('public.records') IS NOT NULL AND to_regclass('public.domainmetadata') IS NOT NULL" | grep -qx 1; do
    if [ "$elapsed" -ge "$PDNS_DB_WAIT_TIMEOUT_SECONDS" ]; then
      log "PowerDNS PostgreSQL schema was not ready after ${PDNS_DB_WAIT_TIMEOUT_SECONDS}s"
      psql "$conn" -v ON_ERROR_STOP=0 -c "SELECT to_regclass('public.domains') AS domains_table, to_regclass('public.records') AS records_table, to_regclass('public.domainmetadata') AS metadata_table;" || true
      exit 1
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done
  log "PowerDNS PostgreSQL schema is ready"
}

render_config() {
  cat > /etc/powerdns/pdns.d/00-local.conf <<EOF_CONF
# Rendered from environment by /usr/local/bin/pdns-entrypoint.sh

no-config=no
daemon=no
guardian=no
local-address=${PDNS_LOCAL_ADDRESS}
local-port=${PDNS_LOCAL_PORT}
reuseport=yes

# Query order: normal and Lua-backed zones are stored in PostgreSQL.
# The geoip backend is loaded only to provide GeoIP functions to Lua records.
launch=gpgsql,geoip

# Generic PostgreSQL backend
gpgsql-host=${PDNS_DB_HOST}
gpgsql-port=${PDNS_DB_PORT}
gpgsql-dbname=${PDNS_DB_NAME}
gpgsql-user=${PDNS_DB_USER}
gpgsql-password=${PDNS_DB_PASSWORD}
gpgsql-dnssec=yes
gpgsql-extra-connection-parameters=${PDNS_GPGSQL_EXTRA_CONNECTION_PARAMETERS}

# Authoritative server mode
primary=${PDNS_PRIMARY}
secondary=${PDNS_SECONDARY}
expand-alias=${PDNS_EXPAND_ALIAS}
resolver=${PDNS_RESOLVER}

# Lua records
# Keep the API private. Lua records execute server-side code.
enable-lua-records=${PDNS_ENABLE_LUA_RECORDS}
lua-records-exec-limit=${PDNS_LUA_RECORDS_EXEC_LIMIT}
lua-health-checks-interval=${PDNS_LUA_HEALTH_CHECKS_INTERVAL}
lua-health-checks-expire-delay=${PDNS_LUA_HEALTH_CHECKS_EXPIRE_DELAY}

# HTTP API
api=yes
api-key=${PDNS_API_KEY}
webserver=yes
webserver-address=0.0.0.0
webserver-port=8081
webserver-password=${PDNS_WEBSERVER_PASSWORD}
webserver-allow-from=${PDNS_WEBSERVER_ALLOW_FROM}

# GeoIP provider for PostgreSQL-backed Lua records.
geoip-database-files=mmdb:${PDNS_GEO_MMDB_FILE}
geoip-zones-file=${PDNS_GEO_BOOTSTRAP_ZONE_FILE}
edns-subnet-processing=${PDNS_EDNS_SUBNET_PROCESSING}

# Conservative defaults for dynamic DNS testing and controlled propagation.
cache-ttl=${PDNS_CACHE_TTL}
query-cache-ttl=${PDNS_QUERY_CACHE_TTL}
loglevel=${PDNS_LOGLEVEL}
EOF_CONF

  if [ -n "${PDNS_EXTRA_CONFIG:-}" ]; then
    printf "\n# Extra operator-provided config\n%s\n" "$PDNS_EXTRA_CONFIG" >> /etc/powerdns/pdns.d/00-local.conf
  fi
}

watch_mmdb_with_inotify() {
  local pid="$1"
  local watch_dir watch_file changed_path
  watch_dir="$(dirname "$PDNS_GEO_MMDB_FILE")"
  watch_file="$(basename "$PDNS_GEO_MMDB_FILE")"

  command -v inotifywait >/dev/null 2>&1 || return 1

  while kill -0 "$pid" 2>/dev/null; do
    changed_path="$(inotifywait -q -e close_write,create,moved_to --format '%f' "$watch_dir" 2>/dev/null || true)"
    if [ "$changed_path" = "$watch_file" ] && kill -0 "$pid" 2>/dev/null; then
      log "MMDB file changed; stopping PowerDNS so Docker restarts it with the new mmap."
      kill -TERM "$pid"
      return 0
    fi
  done
}

watch_mmdb_with_polling() {
  local pid="$1"
  local last_mtime=""
  local current_mtime=""

  if [ -e "$PDNS_GEO_MMDB_FILE" ]; then
    last_mtime="$(stat -c %Y "$PDNS_GEO_MMDB_FILE" 2>/dev/null || true)"
  fi

  while kill -0 "$pid" 2>/dev/null; do
    sleep "$PDNS_MMDB_WATCH_INTERVAL_SECONDS"
    current_mtime="$(stat -c %Y "$PDNS_GEO_MMDB_FILE" 2>/dev/null || true)"
    if [ -n "$last_mtime" ] && [ -n "$current_mtime" ] && [ "$current_mtime" != "$last_mtime" ]; then
      log "MMDB file changed; stopping PowerDNS so Docker restarts it with the new mmap."
      kill -TERM "$pid"
      return 0
    fi
    last_mtime="$current_mtime"
  done
}

watch_mmdb_and_restart() {
  local pid="$1"
  watch_mmdb_with_inotify "$pid" || watch_mmdb_with_polling "$pid"
}

wait_for_mmdb
wait_for_database_schema
render_config

pdns_server --daemon=no --guardian=no &
pdns_pid="$!"

case "${PDNS_RESTART_ON_MMDB_CHANGE,,}" in
  true|1|yes|on)
    watch_mmdb_and_restart "$pdns_pid" &
    ;;
esac

wait "$pdns_pid"
