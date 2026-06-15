#!/usr/bin/env bash
set -euo pipefail

# CDNLite upstream runtime generator
# - No local CDNLite repository clone is created inside core/edge folders.
# - CDNLite core/edge use upstream GHCR images by default.
# - DNSGeo components build from upstream GitHub remote build contexts.
# - All persistent state is bind-mounted under ./runtime. No Docker named volumes.

umask 077

SCRIPT_VERSION="upstream-runtime-v19"
CDNLITE_REPO="${CDNLITE_REPO:-https://github.com/vaheed/CDNLite.git}"
CDNLITE_REF_DEFAULT="${CDNLITE_REF_DEFAULT:-main}"
REGISTRY_OWNER_DEFAULT="${REGISTRY_OWNER_DEFAULT:-vaheed}"
IMAGE_TAG_DEFAULT="${IMAGE_TAG_DEFAULT:-latest}"
PRIMARY_DIR_DEFAULT="${PRIMARY_DIR_DEFAULT:-core}"
EDGE_DIR_DEFAULT="${EDGE_DIR_DEFAULT:-edge}"
AUTO_DEFAULTS="${AUTO_DEFAULTS:-no}"
FORCE="${FORCE:-no}"
VALIDATE_COMPOSE="${VALIDATE_COMPOSE:-yes}"
WITH_NPM_DEFAULT="${WITH_NPM_DEFAULT:-yes}"
WITH_PDNS_SECONDARY_DEFAULT="${WITH_PDNS_SECONDARY_DEFAULT:-yes}"
# image = use upstream ghcr.io images for CDNLite app services. build = build app services from remote GitHub contexts.
CDNLITE_COMPONENT_MODE_DEFAULT="${CDNLITE_COMPONENT_MODE_DEFAULT:-image}"

say() { printf '\n\033[1;32m%s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING:\033[0m %s\n' "$*" >&2; }
fail() { printf '\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"; }
rand_b64() { openssl rand -base64 "${1:-48}" | tr -d '\n' | tr '/+' '_-' | tr -d '='; }
trim_value() { printf '%s' "${1-}" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//'; }

is_ipv4() {
  local ip="${1-}" a b c d octet IFS=.
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  read -r a b c d <<< "$ip"
  for octet in "$a" "$b" "$c" "$d"; do
    [[ "$octet" =~ ^[0-9]+$ ]] || return 1
    [ "$octet" -ge 0 ] && [ "$octet" -le 255 ] || return 1
  done
}

normalize_domain() {
  local x
  x="$(trim_value "${1-}")"
  x="${x#http://}"; x="${x#https://}"; x="${x%%/*}"; x="${x%.}"
  printf '%s' "${x,,}"
}

normalize_url() {
  local x
  x="$(trim_value "${1-}")"
  [ -n "$x" ] || { printf ''; return 0; }
  if [[ ! "$x" =~ ^[a-zA-Z][a-zA-Z0-9+.-]*:// ]]; then x="https://${x}"; fi
  while [[ "$x" == */ ]]; do x="${x%/}"; done
  printf '%s' "$x"
}

normalize_hostmaster() {
  local x
  x="$(trim_value "${1-}")"
  x="${x//@/.}"; x="${x%.}"
  printf '%s' "$x"
}

default_admin_email() {
  local d
  d="$(normalize_domain "${1-}")"
  case "$d" in cdn.*) d="${d#cdn.}" ;; esac
  printf 'admin@%s' "$d"
}

detect_public_ipv4() {
  local ip="" u
  if command -v curl >/dev/null 2>&1; then
    for u in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
      ip="$(curl -4fsS --max-time 3 "$u" 2>/dev/null | tr -d '\r\n ' || true)"
      if is_ipv4 "$ip"; then printf '%s' "$ip"; return 0; fi
    done
  fi
  if command -v wget >/dev/null 2>&1; then
    for u in https://api.ipify.org https://icanhazip.com; do
      ip="$(wget -4qO- -T 3 "$u" 2>/dev/null | tr -d '\r\n ' || true)"
      if is_ipv4 "$ip"; then printf '%s' "$ip"; return 0; fi
    done
  fi
  return 1
}

prompt() {
  local __prompt_out="$1" __prompt_label="$2" __prompt_default="${3-}" __prompt_input="" __prompt_value=""
  if [ "${AUTO_DEFAULTS:-no}" = "yes" ] && [ -n "$__prompt_default" ]; then
    __prompt_value="$__prompt_default"
    printf '%s [%s]: %s\n' "$__prompt_label" "$__prompt_default" "$__prompt_value"
  elif [ -n "$__prompt_default" ]; then
    read -r -p "$__prompt_label [$__prompt_default]: " __prompt_input || true
    __prompt_input="$(trim_value "$__prompt_input")"
    __prompt_value="${__prompt_input:-$__prompt_default}"
  else
    read -r -p "$__prompt_label: " __prompt_input || true
    __prompt_value="$(trim_value "$__prompt_input")"
  fi
  printf -v "$__prompt_out" '%s' "$__prompt_value"
}

prompt_required() {
  local __required_out="$1" __required_label="$2" __required_default="${3-}" __required_value=""
  while true; do
    prompt __required_value "$__required_label" "$__required_default"
    __required_value="$(trim_value "$__required_value")"
    if [ -n "$__required_value" ]; then printf -v "$__required_out" '%s' "$__required_value"; return 0; fi
    echo "Value is required."
  done
}

prompt_yes_no() {
  local __yn_out="$1" __yn_label="$2" __yn_default="${3:-y}" __yn_input="" __yn_value=""
  while true; do
    if [ "${AUTO_DEFAULTS:-no}" = "yes" ]; then
      __yn_value="$__yn_default"
      printf '%s [%s]: %s\n' "$__yn_label" "$__yn_default" "$__yn_value"
    else
      read -r -p "$__yn_label [${__yn_default}]: " __yn_input || true
      __yn_value="$(trim_value "$__yn_input")"; __yn_value="${__yn_value:-$__yn_default}"
    fi
    case "${__yn_value,,}" in
      y|yes|true|1) printf -v "$__yn_out" '%s' "yes"; return 0 ;;
      n|no|false|0) printf -v "$__yn_out" '%s' "no"; return 0 ;;
      *) echo "Please answer yes or no." ;;
    esac
  done
}

safe_name() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//'; }
sql_escape() { printf "%s" "$1" | sed "s/'/''/g"; }

usage() {
  cat <<EOF
CDNLite upstream runtime generator (${SCRIPT_VERSION})

Creates core + edge deployment folders without cloning CDNLite into either folder.

Options:
  --auto               Use env/defaults without prompting.
  --force              Replace existing output folders.
  --no-compose-check   Skip docker compose config validation.
  -h, --help           Show help.

Important env overrides:
  DNS_BASE_DOMAIN, PRIMARY_PUBLIC_IP, EDGE_PUBLIC_IP, CDNLITE_REF, REGISTRY_OWNER, IMAGE_TAG,
  CDNLITE_COMPONENT_MODE=image|build, PRIMARY_DIR, EDGE_DIR.

Default behavior:
  CDNLite app services use upstream images: ghcr.io/<REGISTRY_OWNER>/cdnlite-*:<IMAGE_TAG>
  DNSGeo services build from upstream remote contexts: <CDNLITE_REPO>#<CDNLITE_REF>:infra/dnsgeo/...
  No local full repository clone is written to disk.
EOF
}

runtime_dirs() {
  local base="$1" kind="$2"
  mkdir -p "$base/runtime"
  if [ "$kind" = "core" ]; then
    mkdir -p \
      "$base/runtime/core-postgres-data" \
      "$base/runtime/pdns-postgres-data" \
      "$base/runtime/pdns-postgres-tls" \
      "$base/runtime/pdns-mmdb" \
      "$base/runtime/geo" \
      "$base/runtime/npm-data" \
      "$base/runtime/npm-letsencrypt" \
      "$base/scripts"
  else
    mkdir -p \
      "$base/runtime/cdnlite-edge-data" \
      "$base/runtime/pdns-replica-data" \
      "$base/runtime/pdns-replica-config" \
      "$base/scripts"
  fi
}

write_permissions_script() {
  local base="$1" kind="$2"
  cat > "$base/scripts/fix-runtime-permissions.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p runtime
find runtime -type d -exec chmod 755 {} \;
if [ -d runtime/core-postgres-data ]; then chmod 700 runtime/core-postgres-data || true; fi
if [ -d runtime/pdns-postgres-data ]; then chmod 700 runtime/pdns-postgres-data || true; fi
if [ -d runtime/pdns-postgres-tls ]; then chmod 755 runtime/pdns-postgres-tls || true; fi
if [ -d runtime/cdnlite-edge-data ]; then chmod 755 runtime/cdnlite-edge-data || true; fi
if [ -d runtime/pdns-replica-data ]; then chmod 775 runtime/pdns-replica-data || true; fi
if [ -d runtime/pdns-replica-config ]; then chmod 755 runtime/pdns-replica-config || true; chmod 755 runtime/pdns-replica-config/*.sh 2>/dev/null || true; chmod 644 runtime/pdns-replica-config/*.conf 2>/dev/null || true; fi
printf 'Runtime directory permissions refreshed.\n'
EOF
  chmod +x "$base/scripts/fix-runtime-permissions.sh"
}

write_geo_bootstrap() {
  local dir="$1/runtime/geo"
  mkdir -p "$dir"
  cat > "$dir/lua-bootstrap.yml" <<'EOF'
# Empty GeoIP backend zone file.
# CDNLite owns routable authoritative DNS records through the PowerDNS desired-state path.
domains: []
EOF
  chmod 644 "$dir/lua-bootstrap.yml"
}

write_edge_replica_config() {
  local d="$1/runtime/pdns-replica-config"
  mkdir -p "$d"
  cat > "$d/pdns-replica.conf" <<'EOF'
# PowerDNS secondary/replica - aligned to upstream deploy/powerdns-replica
launch=gsqlite3
gsqlite3-database=/var/lib/powerdns/pdns.sqlite3
primary=no
secondary=yes
autosecondary=yes
superslave=yes
local-address=0.0.0.0
local-port=53
log-dns-queries=no
loglevel=4
EOF
  cat > "$d/entrypoint.sh" <<'EOF'
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
EOF
  cat > "$d/seed-supermasters.sh" <<'EOF'
#!/bin/sh
set -e
DB="${1:-/var/lib/powerdns/pdns.sqlite3}"
sqlite3 "$DB" \
  "INSERT OR IGNORE INTO supermasters (ip, nameserver, account) VALUES ('${PDNS_PRIMARY_IP}', 'ns1.${PDNS_PRIMARY_ACCOUNT}.invalid', '${PDNS_PRIMARY_ACCOUNT}');"
echo "[init] Supermaster ${PDNS_PRIMARY_IP} registered."
EOF
  chmod +x "$d/entrypoint.sh" "$d/seed-supermasters.sh"
  chmod 644 "$d/pdns-replica.conf"
}

write_core_env() {
  local f="$1/.env"
  cat > "$f" <<EOF
# Generated by ${SCRIPT_VERSION} on $(date -u +%Y-%m-%dT%H:%M:%SZ)
# Do not commit. Contains secrets.

# ===== Upstream source/image settings =====
CDNLITE_REPO=${CDNLITE_REPO}
CDNLITE_REF=${CDNLITE_REF}
REGISTRY_OWNER=${REGISTRY_OWNER}
IMAGE_TAG=${IMAGE_TAG}
CDNLITE_COMPONENT_MODE=${CDNLITE_COMPONENT_MODE}
CDNLITE_CORE_IMAGE=ghcr.io/${REGISTRY_OWNER}/cdnlite-core:${IMAGE_TAG}
CDNLITE_DASHBOARD_IMAGE=cdnlite-dashboard:upstream-build
CDNLITE_CORE_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:core
CDNLITE_DASHBOARD_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:dash
DNSGEO_POSTGRES_PRIMARY_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/postgres-primary
DNSGEO_POSTGRES_INIT_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/postgres-init
DNSGEO_MMDB_UPDATER_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/mmdb-updater
DNSGEO_PDNS_RECURSOR_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/pdns-recursor
DNSGEO_PDNS_AUTH_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/pdns-auth
DNSGEO_POWERADMIN_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:infra/dnsgeo/docker/poweradmin

# ===== Core PostgreSQL =====
POSTGRES_DB=cdnlite
POSTGRES_USER=cdnlite
POSTGRES_PASSWORD=${CDNLITE_DB_PASSWORD}
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=cdnlite
DB_USERNAME=cdnlite
DB_PASSWORD=${CDNLITE_DB_PASSWORD}

# ===== CDNLite core =====
APP_ENV=production
APP_LOG_ENABLED=1
APP_LOG_LEVEL=info
APP_DEBUG=0
CDNLITE_API_TOKEN=${CDNLITE_API_TOKEN}
CDNLITE_SSL_SECRET_KEY=${CDNLITE_SSL_SECRET_KEY}
CDNLITE_ORIGIN_SHIELD_SECRET=${CDNLITE_ORIGIN_SHIELD_SECRET}
CDNLITE_ACME_DIRECTORY_URL=https://acme-v02.api.letsencrypt.org/directory
CDNLITE_ACME_CONTACT_EMAIL=${ACME_EMAIL}
CDNLITE_ACME_DNS_PROPAGATION_SECONDS=30
CDNLITE_ACME_POLL_ATTEMPTS=30
CDNLITE_ADMIN_SESSION_TTL_SECONDS=28800
CDNLITE_CORS_ALLOWED_ORIGINS=${DASHBOARD_PUBLIC_URL}
CDNLITE_BOOTSTRAP_ADMIN_USER=1
CDNLITE_BOOTSTRAP_ADMIN_USERNAME=${CDNLITE_ADMIN_USERNAME}
CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=${CDNLITE_ADMIN_PASSWORD}
CDNLITE_BOOTSTRAP_ADMIN_DISPLAY_NAME=CDNLite Admin
CDNLITE_READINESS_SNAPSHOT_MAX_AGE_SECONDS=900
CDNLITE_SCHEDULER_IDLE=0
CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS=300
CDNLITE_SYNC_INTERVAL_SECONDS=30
CDNLITE_POWERDNS_VERIFY_AFTER_WRITE=true
CDNLITE_POWERDNS_RETRIES=3
CDNLITE_POWERDNS_RETRY_SLEEP_MS=500
CDNLITE_POWERDNS_TIMEOUT_SECONDS=10
CDNLITE_POWERADMIN_URL=${PA_APPLICATION_URL}

# ===== First edge bootstrap =====
EDGE_ID=${EDGE_ID}
EDGE_TOKEN=${EDGE_TOKEN}
EDGE_HOSTNAME=${EDGE_HOSTNAME}
EDGE_PUBLIC_IP=${EDGE_PUBLIC_IP}
EDGE_REGION=${EDGE_REGION}
EDGE_VERSION=v1
DEV_MODE=0
CDNLITE_BOOTSTRAP_EDGE_TOKEN=1
CDNLITE_BOOTSTRAP_EDGE_ID=${EDGE_ID}
CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE=${EDGE_TOKEN}

# ===== Ports / public URLs =====
CORE_HOST_PORT=127.0.0.1:${CORE_HOST_PORT}
DASHBOARD_PORT=127.0.0.1:${DASHBOARD_PORT}
TZ=${TZ_VALUE}
VITE_CDNLITE_CORE_URL=${CORE_PUBLIC_URL}
VITE_CDNLITE_EDGE_URL=${EDGE_PUBLIC_URL}
VITE_CDNLITE_APP_NAME=CDNLite Admin
VITE_CDNLITE_API_TOKEN=
VITE_ENABLE_EDGE_DEV_TOOLS=false
VITE_ENABLE_USAGE_SIMULATOR=false
VITE_ENABLE_SSL_TOOLS=true
VITE_ENABLE_SECURITY_EVENT_VIEWER=true
VITE_ENABLE_LOG_VIEWER=true
# Dashboard build includes Event Viewer and central Job Queue operations pages.
VITE_DEFAULT_USAGE_BUCKET=minute
VITE_DASHBOARD_REFRESH_SECONDS=15
VITE_REQUEST_TIMEOUT_MS=15000
CORE_URL=${CORE_PUBLIC_URL}

# ===== CDNLite DNS / PowerDNS platform settings =====
CDNLITE_EDGE_BASE_DOMAIN=${DNS_BASE_DOMAIN}
CDNLITE_CDN_ZONE=${CDN_ZONE}
CDNLITE_CDN_PROXY_HOST=${CDN_PROXY_HOST}
CDNLITE_EDGE_ZONE_PREFIX=${EDGE_ZONE_PREFIX}
CDNLITE_EDGE_DEFAULT_TARGET=geo
CDNLITE_EDGE_TTL=60
CDNLITE_EDGE_HEALTH_MODE=ifportup
CDNLITE_EDGE_HEALTH_PORT=80
CDNLITE_EDGE_HEALTH_URL=/cdn-health
CDNLITE_EDGE_HEALTH_TIMEOUT=1
CDNLITE_EDGE_HEALTH_INTERVAL=10
CDNLITE_EDGE_HEALTH_MIN_FAILURES=2
CDNLITE_EDGE_SELECTOR=pickclosest
CDNLITE_EDGE_BACKUP_SELECTOR=empty
CDNLITE_EDGE_APEX_MODE=ALIAS
CDNLITE_GEO_DEFAULT_POLICY=auto
CDNLITE_GEO_ENABLE_COUNTRY_RULES=true
CDNLITE_GEO_ENABLE_CONTINENT_RULES=true
CDNLITE_GEO_ENABLE_REGION_RULES=true
CDNLITE_NS1_IP=${NS1_IPV4}
CDNLITE_NS2_IP=${NS2_IPV4}
CDNLITE_BOOTSTRAP_EDGE_DNS=1
POWERDNS_ENABLED=1
POWERDNS_STRICT=1
POWERDNS_API_URL=http://pdns-auth:8081
POWERDNS_PUBLIC_API_URL=http://127.0.0.1:${PDNS_API_PORT}
POWERDNS_API_KEY=${PDNS_API_KEY}
POWERDNS_SERVER_ID=localhost
POWERDNS_ZONE_KIND=NATIVE
POWERDNS_ZONE_NAMESERVERS=${DNS_NS1},${DNS_NS2}

# ===== DNSGeo / PowerDNS primary =====
PDNS_POSTGRES_SUPERUSER_PASSWORD=${PDNS_POSTGRES_SUPERUSER_PASSWORD}
PDNS_DB_PASSWORD=${PDNS_DB_PASSWORD}
POWERADMIN_DB_PASSWORD=${POWERADMIN_DB_PASSWORD}
PDNS_REPLICATION_PASSWORD=${PDNS_REPLICATION_PASSWORD}
PDNS_API_KEY=${PDNS_API_KEY}
PDNS_WEBSERVER_PASSWORD=${PDNS_WEBSERVER_PASSWORD}
PDNS_WEBSERVER_ALLOW_FROM=127.0.0.1,172.31.0.0/24,${PRIMARY_PUBLIC_IP},${EDGE_PUBLIC_IP}
PDNS_DNS_BIND_ADDRESS=0.0.0.0
PDNS_DNS_PORT=${DNS_PORT}
PDNS_API_BIND_ADDRESS=127.0.0.1
PDNS_API_PORT=${PDNS_API_PORT}
PDNS_PG_MAX_WAL_SENDERS=10
PDNS_PG_MAX_REPLICATION_SLOTS=10
PDNS_PG_WAL_KEEP_SIZE=512MB
PDNS_PG_TLS_DAYS=3650
PDNS_PG_TLS_SAN=DNS:pdns-postgres,IP:127.0.0.1,IP:${PRIMARY_PUBLIC_IP}
CDNLITE_DNS_BASE_DOMAIN=${DNS_BASE_DOMAIN}
CDNLITE_DNS_NS1=${DNS_NS1}
CDNLITE_DNS_NS2=${DNS_NS2}
CDNLITE_DNS_HOSTMASTER=${DNS_HOSTMASTER}
CDNLITE_MMDB_PROVIDER=dbip-jsdelivr
CDNLITE_MMDB_DOWNLOAD_URL=
CDNLITE_MMDB_DOWNLOAD_INTERVAL_SECONDS=86400
CDNLITE_MMDB_DOWNLOAD_RETRIES=5
CDNLITE_MMDB_EXPECTED_SHA256=
POWERADMIN_BIND_ADDRESS=127.0.0.1
POWERADMIN_PORT=${POWERADMIN_PORT}
POWERADMIN_TAG=stable
POWERADMIN_ADMIN_USERNAME=${POWERADMIN_ADMIN_USERNAME}
POWERADMIN_ADMIN_PASSWORD=${POWERADMIN_ADMIN_PASSWORD}
POWERADMIN_ADMIN_EMAIL=${ACME_EMAIL}
POWERADMIN_SESSION_KEY=${POWERADMIN_SESSION_KEY}

# ===== NPM optional profile =====
WITH_NPM=${WITH_NPM}
NPM_IMAGE=jc21/nginx-proxy-manager:latest
NPM_BIND_IP=0.0.0.0
NPM_HTTP_PORT=${NPM_HTTP_PORT}
NPM_HTTPS_PORT=${NPM_HTTPS_PORT}
NPM_ADMIN_BIND_IP=127.0.0.1
NPM_ADMIN_PORT=${NPM_ADMIN_PORT}
NPM_DISABLE_IPV6=true
EOF
  chmod 600 "$f"
}

write_edge_env() {
  local f="$1/.env"
  cat > "$f" <<EOF
# Generated by ${SCRIPT_VERSION} on $(date -u +%Y-%m-%dT%H:%M:%SZ)
# Unique per edge/POP. Do not commit.

CDNLITE_REPO=${CDNLITE_REPO}
CDNLITE_REF=${CDNLITE_REF}
REGISTRY_OWNER=${REGISTRY_OWNER}
IMAGE_TAG=${IMAGE_TAG}
CDNLITE_COMPONENT_MODE=${CDNLITE_COMPONENT_MODE}
CDNLITE_EDGE_IMAGE=ghcr.io/${REGISTRY_OWNER}/cdnlite-edge:${IMAGE_TAG}
CDNLITE_EDGE_AGENT_IMAGE=ghcr.io/${REGISTRY_OWNER}/cdnlite-edge-agent:${IMAGE_TAG}
CDNLITE_EDGE_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:edge
CDNLITE_EDGE_AGENT_BUILD_CONTEXT=${CDNLITE_REPO}#${CDNLITE_REF}:edge

EDGE_ID=${EDGE_ID}
EDGE_TOKEN=${EDGE_TOKEN}
EDGE_HOSTNAME=${EDGE_HOSTNAME}
EDGE_REGION=${EDGE_REGION}
EDGE_PUBLIC_IP=${EDGE_PUBLIC_IP}
CORE_URL=${CORE_PUBLIC_URL}
DEV_MODE=0
EDGE_VERSION=v1
EDGE_HOST_PORT=${EDGE_HOST_PORT}
EDGE_TLS_HOST_PORT=${EDGE_TLS_HOST_PORT}
CDNLITE_CACHE_DEFAULT_TTL=60s
EDGE_CONFIG_MAX_STALE_SECONDS=900
EDGE_CONFIG_PATH=/var/lib/cdnlite/config.json
EDGE_CONFIG_CACHE_PATH=/var/lib/cdnlite/config.json
EDGE_SYNC_STATUS_PATH=/var/lib/cdnlite/edge-sync-status.json
METRIC_PATH=/var/lib/cdnlite/metrics.ndjson
SECURITY_EVENT_PATH=/var/lib/cdnlite/security-events.ndjson
EDGE_AGENT_IDLE=0

WITH_PDNS_SECONDARY=${WITH_PDNS_SECONDARY}
PDNS_PRIMARY_IP=${PRIMARY_PUBLIC_IP}
PDNS_PRIMARY_ACCOUNT=cdnlite
PDNS_DNS_PORT=${EDGE_DNS_PORT}
EOF
  chmod 600 "$f"
}

core_image_block() {
  if [ "$CDNLITE_COMPONENT_MODE" = "build" ]; then
    cat <<'EOF'
x-cdnlite-core: &cdnlite-core
  image: cdnlite-core:upstream-build
  build:
    context: "${CDNLITE_CORE_BUILD_CONTEXT}"
EOF
  else
    cat <<'EOF'
x-cdnlite-core: &cdnlite-core
  image: "${CDNLITE_CORE_IMAGE}"
EOF
  fi
}

edge_image_block() {
  if [ "$CDNLITE_COMPONENT_MODE" = "build" ]; then
    cat <<'EOF'
    image: cdnlite-edge:upstream-build
    build:
      context: "${CDNLITE_EDGE_BUILD_CONTEXT}"
EOF
  else
    cat <<'EOF'
    image: "${CDNLITE_EDGE_IMAGE}"
EOF
  fi
}

edge_agent_image_block() {
  if [ "$CDNLITE_COMPONENT_MODE" = "build" ]; then
    cat <<'EOF'
    image: cdnlite-edge-agent:upstream-build
    build:
      context: "${CDNLITE_EDGE_AGENT_BUILD_CONTEXT}"
      dockerfile: agent/Dockerfile
EOF
  else
    cat <<'EOF'
    image: "${CDNLITE_EDGE_AGENT_IMAGE}"
EOF
  fi
}

write_core_compose() {
  local f="$1/docker-compose.yml"
  {
    cat <<'EOF'
name: cdnlite-upstream-core-dnsgeo

EOF
    core_image_block
    cat <<'EOF'

services:
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -h 127.0.0.1 -p 5432 -U \"$${POSTGRES_USER}\" -d \"$${POSTGRES_DB}\""]
      interval: 2s
      timeout: 2s
      retries: 30
    volumes:
      - ./runtime/core-postgres-data:/var/lib/postgresql/data
    networks: [cdnlite-internal]

  core:
    <<: *cdnlite-core
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    env_file: .env
    ports:
      - "${CORE_HOST_PORT}:8080"
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1:8080/health >/dev/null 2>&1 || exit 1"]
      interval: 2s
      timeout: 2s
      retries: 60
    networks: [cdnlite-internal, public]

  nameserver-scheduler:
    <<: *cdnlite-core
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    env_file: .env
    command: ["sh", "-lc", "if [ \"$${CDNLITE_SCHEDULER_IDLE:-0}\" = \"1\" ]; then echo 'nameserver scheduler idle'; tail -f /dev/null; fi; while true; do php artisan cdn:domains:verify-all || true; sleep \"$${CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS:-300}\"; done"]
    networks: [cdnlite-internal]

  dns-reconciler:
    <<: *cdnlite-core
    restart: unless-stopped
    depends_on:
      core:
        condition: service_healthy
      platform-settings-bootstrap:
        condition: service_completed_successfully
    env_file: .env
    command: ["sh", "-lc", "while true; do php artisan cdn:dns:reconcile || true; php artisan cdn:powerdns:force-sync || true; sleep \"$${CDNLITE_SYNC_INTERVAL_SECONDS:-30}\"; done"]
    networks: [cdnlite-internal]

  ssl-scheduler:
    <<: *cdnlite-core
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    env_file: .env
    command: ["sh", "-lc", "if [ \"$${CDNLITE_SCHEDULER_IDLE:-0}\" = \"1\" ]; then echo 'ssl scheduler idle'; tail -f /dev/null; fi; while true; do php artisan cdn:ssl:renew-due || true; sleep 3600; done"]
    networks: [cdnlite-internal]

  origin-health-scheduler:
    <<: *cdnlite-core
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    env_file: .env
    command: ["sh", "-lc", "if [ \"$${CDNLITE_SCHEDULER_IDLE:-0}\" = \"1\" ]; then echo 'origin health scheduler idle'; tail -f /dev/null; fi; while true; do php artisan cdn:origins:health-check || true; sleep 30; done"]
    networks: [cdnlite-internal]

  dashboard:
    image: "${CDNLITE_DASHBOARD_IMAGE}"
    build:
      context: "${CDNLITE_DASHBOARD_BUILD_CONTEXT}"
      args:
        VITE_CDNLITE_CORE_URL: ${VITE_CDNLITE_CORE_URL}
        VITE_CDNLITE_EDGE_URL: ${VITE_CDNLITE_EDGE_URL}
        VITE_CDNLITE_APP_NAME: ${VITE_CDNLITE_APP_NAME}
        VITE_CDNLITE_API_TOKEN: ${VITE_CDNLITE_API_TOKEN}
        VITE_ENABLE_EDGE_DEV_TOOLS: ${VITE_ENABLE_EDGE_DEV_TOOLS}
        VITE_ENABLE_USAGE_SIMULATOR: ${VITE_ENABLE_USAGE_SIMULATOR}
        VITE_ENABLE_SSL_TOOLS: ${VITE_ENABLE_SSL_TOOLS}
        VITE_ENABLE_SECURITY_EVENT_VIEWER: ${VITE_ENABLE_SECURITY_EVENT_VIEWER}
        VITE_ENABLE_LOG_VIEWER: ${VITE_ENABLE_LOG_VIEWER}
        VITE_DEFAULT_USAGE_BUCKET: ${VITE_DEFAULT_USAGE_BUCKET}
        VITE_DASHBOARD_REFRESH_SECONDS: ${VITE_DASHBOARD_REFRESH_SECONDS}
        VITE_REQUEST_TIMEOUT_MS: ${VITE_REQUEST_TIMEOUT_MS}
    restart: unless-stopped
    depends_on:
      core:
        condition: service_started
    ports:
      - "${DASHBOARD_PORT}:80"
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1/healthz >/dev/null 2>&1 || exit 1"]
      interval: 10s
      timeout: 3s
      retries: 12
    networks: [public]

  platform-settings-bootstrap:
    image: postgres:16-alpine
    restart: "no"
    depends_on:
      core:
        condition: service_healthy
    env_file: .env
    volumes:
      - ./scripts/platform-settings-bootstrap.sql:/bootstrap/platform-settings-bootstrap.sql:ro
    command: >
      sh -lc 'PGPASSWORD="$$DB_PASSWORD" psql "host=$$DB_HOST port=$$DB_PORT dbname=$$DB_DATABASE user=$$DB_USERNAME sslmode=disable" -v ON_ERROR_STOP=1 -f /bootstrap/platform-settings-bootstrap.sql || true'
    networks: [cdnlite-internal]

  edge-token-bootstrap:
    <<: *cdnlite-core
    restart: "no"
    depends_on:
      core:
        condition: service_healthy
      platform-settings-bootstrap:
        condition: service_completed_successfully
    env_file: .env
    command: >
      sh -lc 'php artisan cdn:edge:register-token --edge_id="$$EDGE_ID" --token="$$EDGE_TOKEN" || true; php artisan cdn:dns:reconcile || true; php artisan cdn:powerdns:doctor || true; php artisan cdn:powerdns:force-sync || true'
    networks: [cdnlite-internal]

  pdns-postgres:
    image: cdnlite-dnsgeo-postgres-primary:upstream-build
    build:
      context: "${DNSGEO_POSTGRES_PRIMARY_BUILD_CONTEXT}"
    restart: unless-stopped
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${PDNS_POSTGRES_SUPERUSER_PASSWORD}
      POSTGRES_DB: postgres
      POSTGRES_INITDB_ARGS: --auth-host=scram-sha-256
      PGDATA: /var/lib/postgresql/data
      PDNS_DB_PASSWORD: ${PDNS_DB_PASSWORD}
      POWERADMIN_DB_PASSWORD: ${POWERADMIN_DB_PASSWORD}
      REPLICATION_PASSWORD: ${PDNS_REPLICATION_PASSWORD}
      PG_MAX_WAL_SENDERS: ${PDNS_PG_MAX_WAL_SENDERS}
      PG_MAX_REPLICATION_SLOTS: ${PDNS_PG_MAX_REPLICATION_SLOTS}
      PG_WAL_KEEP_SIZE: ${PDNS_PG_WAL_KEEP_SIZE}
      PG_TLS_DAYS: ${PDNS_PG_TLS_DAYS}
      PG_TLS_CA_CN: CDNLitePowerDNSCA
      PG_TLS_SERVER_CN: pdns-postgres
      PG_TLS_SAN: ${PDNS_PG_TLS_SAN}
      PG_REPLICATION_CLIENT_CERT_AUTH: "true"
    volumes:
      - ./runtime/pdns-postgres-data:/var/lib/postgresql/data
      - ./runtime/pdns-postgres-tls:/var/lib/postgresql/tls
    healthcheck:
      test: ["CMD-SHELL", "test -s /var/lib/postgresql/tls/ca.crt && PGPASSWORD=\"$${POSTGRES_PASSWORD}\" psql \"host=localhost dbname=postgres user=postgres sslmode=verify-ca sslrootcert=/var/lib/postgresql/tls/ca.crt\" -qAt -c 'SELECT 1' >/dev/null"]
      interval: 5s
      timeout: 5s
      retries: 30
    networks: [cdnlite-internal]

  pdns-db-init:
    image: cdnlite-dnsgeo-postgres-init:upstream-build
    build:
      context: "${DNSGEO_POSTGRES_INIT_BUILD_CONTEXT}"
    restart: "no"
    depends_on:
      pdns-postgres:
        condition: service_healthy
    environment:
      POSTGRES_HOST: pdns-postgres
      POSTGRES_USER: postgres
      POSTGRES_SUPERUSER_PASSWORD: ${PDNS_POSTGRES_SUPERUSER_PASSWORD}
      PDNS_DB_PASSWORD: ${PDNS_DB_PASSWORD}
      POWERADMIN_DB_PASSWORD: ${POWERADMIN_DB_PASSWORD}
      REPLICATION_PASSWORD: ${PDNS_REPLICATION_PASSWORD}
    volumes:
      - ./runtime/pdns-postgres-tls:/certs/postgres:ro
    networks: [cdnlite-internal]

  pdns-mmdb-updater:
    image: cdnlite-dnsgeo-mmdb-updater:upstream-build
    build:
      context: "${DNSGEO_MMDB_UPDATER_BUILD_CONTEXT}"
    restart: unless-stopped
    environment:
      MMDB_PROVIDER: ${CDNLITE_MMDB_PROVIDER}
      MMDB_DOWNLOAD_URL: ${CDNLITE_MMDB_DOWNLOAD_URL}
      MMDB_DOWNLOAD_INTERVAL_SECONDS: ${CDNLITE_MMDB_DOWNLOAD_INTERVAL_SECONDS}
      MMDB_DOWNLOAD_RETRIES: ${CDNLITE_MMDB_DOWNLOAD_RETRIES}
      MMDB_EXPECTED_SHA256: ${CDNLITE_MMDB_EXPECTED_SHA256}
      MMDB_TARGET_FILE: GeoLite2-City.mmdb
    volumes:
      - ./runtime/pdns-mmdb:/mmdb
    healthcheck:
      test: ["CMD-SHELL", "/usr/local/bin/mmdb-healthcheck.sh"]
      interval: 5s
      timeout: 5s
      retries: 120
      start_period: 60s
    networks: [cdnlite-internal]

  pdns-recursor:
    image: cdnlite-dnsgeo-pdns-recursor:upstream-build
    build:
      context: "${DNSGEO_PDNS_RECURSOR_BUILD_CONTEXT}"
    restart: unless-stopped
    environment:
      CDNLITE_DNS_BASE_DOMAIN: ${CDNLITE_DNS_BASE_DOMAIN}
      CDNLITE_CDN_ZONE: ${CDNLITE_CDN_ZONE}
      PDNS_AUTH_ADDRESS: 172.31.0.54
    networks:
      cdnlite-internal:
        ipv4_address: 172.31.0.53
    healthcheck:
      test: ["CMD-SHELL", "/usr/local/bin/recursor-healthcheck.sh"]
      interval: 5s
      timeout: 5s
      retries: 30

  pdns-auth:
    image: cdnlite-dnsgeo-pdns-auth:upstream-build
    build:
      context: "${DNSGEO_PDNS_AUTH_BUILD_CONTEXT}"
    restart: unless-stopped
    depends_on:
      pdns-postgres:
        condition: service_healthy
      pdns-db-init:
        condition: service_completed_successfully
      pdns-mmdb-updater:
        condition: service_healthy
      pdns-recursor:
        condition: service_healthy
    environment:
      PDNS_LOCAL_ADDRESS: "0.0.0.0,::"
      PDNS_LOCAL_PORT: 53
      PDNS_DB_HOST: pdns-postgres
      PDNS_DB_PORT: 5432
      PDNS_DB_NAME: pdns
      PDNS_DB_USER: pdns
      PDNS_DB_PASSWORD: ${PDNS_DB_PASSWORD}
      PDNS_GPGSQL_EXTRA_CONNECTION_PARAMETERS: sslmode=verify-ca sslrootcert=/var/lib/postgresql/tls/ca.crt
      PDNS_PRIMARY: "yes"
      PDNS_SECONDARY: "no"
      PDNS_API_KEY: ${PDNS_API_KEY}
      PDNS_WEBSERVER_PASSWORD: ${PDNS_WEBSERVER_PASSWORD}
      PDNS_WEBSERVER_ALLOW_FROM: ${PDNS_WEBSERVER_ALLOW_FROM}
      PDNS_GEO_BOOTSTRAP_ZONE_FILE: /etc/powerdns/geo/lua-bootstrap.yml
      PDNS_GEO_MMDB_FILE: /var/lib/powerdns/mmdb/GeoLite2-City.mmdb
      PDNS_EDNS_SUBNET_PROCESSING: "yes"
      PDNS_ENABLE_LUA_RECORDS: "yes"
      PDNS_EXPAND_ALIAS: "yes"
      PDNS_RESOLVER: 172.31.0.53:5300
      PDNS_CACHE_TTL: 20
      PDNS_QUERY_CACHE_TTL: 20
      PDNS_LOGLEVEL: 4
    ports:
      - "${PDNS_DNS_BIND_ADDRESS}:${PDNS_DNS_PORT}:53/udp"
      - "${PDNS_DNS_BIND_ADDRESS}:${PDNS_DNS_PORT}:53/tcp"
      - "${PDNS_API_BIND_ADDRESS}:${PDNS_API_PORT}:8081"
    volumes:
      - ./runtime/geo:/etc/powerdns/geo:ro
      - ./runtime/pdns-mmdb:/var/lib/powerdns/mmdb:ro
      - ./runtime/pdns-postgres-tls:/var/lib/postgresql/tls:ro
    healthcheck:
      test: ["CMD-SHELL", "/usr/local/bin/pdns-healthcheck.sh"]
      interval: 5s
      timeout: 5s
      retries: 60
    networks:
      cdnlite-internal:
        ipv4_address: 172.31.0.54
      public:

  poweradmin:
    image: cdnlite-dnsgeo-poweradmin:upstream-build
    build:
      context: "${DNSGEO_POWERADMIN_BUILD_CONTEXT}"
      args:
        POWERADMIN_TAG: ${POWERADMIN_TAG}
    restart: unless-stopped
    depends_on:
      pdns-db-init:
        condition: service_completed_successfully
      pdns-auth:
        condition: service_healthy
    environment:
      PA_CREATE_ADMIN: "true"
      PA_ADMIN_USERNAME: ${POWERADMIN_ADMIN_USERNAME}
      PA_ADMIN_PASSWORD: ${POWERADMIN_ADMIN_PASSWORD}
      PA_ADMIN_EMAIL: ${POWERADMIN_ADMIN_EMAIL}
      PA_ADMIN_FULLNAME: CDNLite DNS Administrator
      PA_SESSION_KEY: ${POWERADMIN_SESSION_KEY}
      PA_STYLE: dark
      PA_TIMEZONE: UTC
      PA_PDNS_API_URL: http://pdns-auth:8081
      PA_PDNS_API_KEY: ${PDNS_API_KEY}
      PA_PDNS_SERVER_NAME: localhost
      DB_TYPE: pgsql
      DB_HOST: pdns-postgres
      DB_USER: poweradmin
      DB_PASS: ${POWERADMIN_DB_PASSWORD}
      DB_NAME: pdns
      DB_SSL: "true"
      DB_SSL_VERIFY: "true"
      DB_SSL_CA: /certs/postgres/ca.crt
      DNS_NS1: ${CDNLITE_DNS_NS1}
      DNS_NS2: ${CDNLITE_DNS_NS2}
      DNS_HOSTMASTER: ${CDNLITE_DNS_HOSTMASTER}
    volumes:
      - ./runtime/pdns-postgres-tls:/certs/postgres:ro
    ports:
      - "${POWERADMIN_BIND_ADDRESS}:${POWERADMIN_PORT}:80"
    healthcheck:
      test: ["CMD-SHELL", "/usr/local/bin/poweradmin-healthcheck.sh"]
      interval: 5s
      timeout: 5s
      retries: 60
    networks: [cdnlite-internal]

  nginx-proxy-manager:
    image: ${NPM_IMAGE}
    restart: unless-stopped
    profiles: ["npm"]
    environment:
      TZ: ${TZ}
      DISABLE_IPV6: ${NPM_DISABLE_IPV6}
    ports:
      - "${NPM_BIND_IP}:${NPM_HTTP_PORT}:80"
      - "${NPM_BIND_IP}:${NPM_HTTPS_PORT}:443"
      - "${NPM_ADMIN_BIND_IP}:${NPM_ADMIN_PORT}:81"
    volumes:
      - ./runtime/npm-data:/data
      - ./runtime/npm-letsencrypt:/etc/letsencrypt
    networks: [public, cdnlite-internal]

networks:
  public:
    driver: bridge
  cdnlite-internal:
    driver: bridge
    ipam:
      config:
        - subnet: 172.31.0.0/24
EOF
  } > "$f"
}

write_edge_compose() {
  local f="$1/docker-compose.yml"
  {
    cat <<'EOF'
name: cdnlite-upstream-edge

services:
  edge:
EOF
    edge_image_block
    cat <<'EOF'
    restart: unless-stopped
    ports:
      - "${EDGE_HOST_PORT}:8081"
      - "${EDGE_TLS_HOST_PORT}:8443"
    volumes:
      - ./runtime/cdnlite-edge-data:/var/lib/cdnlite
    environment:
      EDGE_ID: ${EDGE_ID}
      EDGE_REGION: ${EDGE_REGION}
      DEV_MODE: ${DEV_MODE}
      CDNLITE_CACHE_DEFAULT_TTL: ${CDNLITE_CACHE_DEFAULT_TTL}
      EDGE_CONFIG_MAX_STALE_SECONDS: ${EDGE_CONFIG_MAX_STALE_SECONDS}
      EDGE_SYNC_STATUS_PATH: ${EDGE_SYNC_STATUS_PATH}
      SECURITY_EVENT_PATH: ${SECURITY_EVENT_PATH}
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1:8081/health >/dev/null 2>&1 || exit 1"]
      interval: 2s
      timeout: 2s
      retries: 60
    networks: [edge-net]

  edge-agent:
EOF
    edge_agent_image_block
    cat <<'EOF'
    restart: unless-stopped
    depends_on:
      edge:
        condition: service_healthy
    volumes:
      - ./runtime/cdnlite-edge-data:/var/lib/cdnlite
    environment:
      CORE_URL: ${CORE_URL}
      EDGE_ID: ${EDGE_ID}
      EDGE_TOKEN: ${EDGE_TOKEN}
      EDGE_HOSTNAME: ${EDGE_HOSTNAME}
      EDGE_PUBLIC_IP: ${EDGE_PUBLIC_IP}
      EDGE_REGION: ${EDGE_REGION}
      EDGE_VERSION: ${EDGE_VERSION}
      DEV_MODE: ${DEV_MODE}
      EDGE_CONFIG_PATH: ${EDGE_CONFIG_PATH}
      EDGE_CONFIG_CACHE_PATH: ${EDGE_CONFIG_CACHE_PATH}
      EDGE_SYNC_STATUS_PATH: ${EDGE_SYNC_STATUS_PATH}
      EDGE_CONFIG_MAX_STALE_SECONDS: ${EDGE_CONFIG_MAX_STALE_SECONDS}
      METRIC_PATH: ${METRIC_PATH}
      SECURITY_EVENT_PATH: ${SECURITY_EVENT_PATH}
      EDGE_AGENT_IDLE: ${EDGE_AGENT_IDLE}
    networks: [edge-net]

  pdns-replica:
    image: powerdns/pdns-auth-49
    restart: unless-stopped
    profiles: ["pdns-secondary"]
    entrypoint: ["/etc/powerdns/entrypoint.sh"]
    ports:
      - "${PDNS_DNS_PORT}:53/udp"
      - "${PDNS_DNS_PORT}:53/tcp"
    volumes:
      - ./runtime/pdns-replica-config/pdns-replica.conf:/etc/powerdns/pdns.conf:ro
      - ./runtime/pdns-replica-config/entrypoint.sh:/etc/powerdns/entrypoint.sh:ro
      - ./runtime/pdns-replica-config/seed-supermasters.sh:/etc/powerdns/seed-supermasters.sh:ro
      - ./runtime/pdns-replica-data:/var/lib/powerdns
    environment:
      PDNS_PRIMARY_IP: ${PDNS_PRIMARY_IP}
      PDNS_PRIMARY_ACCOUNT: ${PDNS_PRIMARY_ACCOUNT}
    healthcheck:
      test: ["CMD-SHELL", "pdns_control ping 2>/dev/null || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
    networks: [edge-net]

networks:
  edge-net:
    driver: bridge
EOF
  } > "$f"
}

write_platform_settings_sql() {
  local out="$1/scripts/platform-settings-bootstrap.sql"
  mkdir -p "$1/scripts"
  local now_ms pdns_api_key_esc pdns_api_base_esc pdns_api_url_esc ns1_esc ns2_esc cdn_zone_esc proxy_host_esc edge_base_esc edge_prefix_esc poweradmin_esc
  now_ms="$(date +%s)000"
  pdns_api_key_esc="$(sql_escape "$PDNS_API_KEY")"
  pdns_api_url_esc="$(sql_escape "http://pdns-auth:8081")"
  pdns_api_base_esc="$(sql_escape "http://pdns-auth:8081/api/v1")"
  ns1_esc="$(sql_escape "$DNS_NS1")"; ns2_esc="$(sql_escape "$DNS_NS2")"
  cdn_zone_esc="$(sql_escape "$CDN_ZONE")"; proxy_host_esc="$(sql_escape "$CDN_PROXY_HOST")"
  edge_base_esc="$(sql_escape "$DNS_BASE_DOMAIN")"; edge_prefix_esc="$(sql_escape "$EDGE_ZONE_PREFIX")"
  poweradmin_esc="$(sql_escape "$PA_APPLICATION_URL")"
  cat > "$out" <<EOF
DO \$\$
BEGIN
  IF to_regclass('public.platform_settings') IS NOT NULL THEN
    EXECUTE \$sql\$
      INSERT INTO platform_settings(key, group_name, value_json, is_secret, description, updated_by, updated_at) VALUES
      ('platform.powerdns.enabled','platform.powerdns','true'::jsonb,false,'Generated: enable PowerDNS publishing','generator',${now_ms}),
      ('platform.powerdns.strict','platform.powerdns','true'::jsonb,false,'Generated: fail closed if PowerDNS sync fails','generator',${now_ms}),
      ('platform.powerdns.api_url','platform.powerdns','"${pdns_api_url_esc}"'::jsonb,false,'Generated: internal PowerDNS API URL','generator',${now_ms}),
      ('platform.powerdns.api_base','platform.powerdns','"${pdns_api_base_esc}"'::jsonb,false,'Generated: internal PowerDNS API base','generator',${now_ms}),
      ('platform.powerdns.api_key','platform.powerdns','"${pdns_api_key_esc}"'::jsonb,true,'Generated: PowerDNS API key','generator',${now_ms}),
      ('platform.powerdns.server_id','platform.powerdns','"localhost"'::jsonb,false,'Generated: PowerDNS server id','generator',${now_ms}),
      ('platform.powerdns.zone_kind','platform.powerdns','"Native"'::jsonb,false,'Generated: PowerDNS zone kind','generator',${now_ms}),
      ('platform.poweradmin.url','platform.powerdns','"${poweradmin_esc}"'::jsonb,false,'Generated: Poweradmin operator URL','generator',${now_ms}),
      ('platform.nameservers.hostnames','platform.nameservers','["${ns1_esc}","${ns2_esc}"]'::jsonb,false,'Generated: authoritative nameservers','generator',${now_ms}),
      ('platform.cdn.zone','platform.edge_dns','"${cdn_zone_esc}"'::jsonb,false,'Generated: shared CDN zone','generator',${now_ms}),
      ('platform.cdn.proxy_host','platform.edge_dns','"${proxy_host_esc}"'::jsonb,false,'Generated: shared CDN proxy host','generator',${now_ms}),
      ('platform.edge_dns.base_domain','platform.edge_dns','"${edge_base_esc}"'::jsonb,false,'Generated: edge base domain','generator',${now_ms}),
      ('platform.edge_dns.zone_prefix','platform.edge_dns','"${edge_prefix_esc}"'::jsonb,false,'Generated: edge hostname prefix','generator',${now_ms})
      ON CONFLICT (key) DO UPDATE SET
        group_name = EXCLUDED.group_name,
        value_json = EXCLUDED.value_json,
        is_secret = EXCLUDED.is_secret,
        description = EXCLUDED.description,
        updated_by = EXCLUDED.updated_by,
        updated_at = EXCLUDED.updated_at
    \$sql\$;
  END IF;
END
\$\$;
EOF
  chmod 600 "$out"
}

write_helpers() {
  local p="$1" e="$2"
  cat > "$p/scripts/sync-dns.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec core php artisan cdn:dns:reconcile || true
docker compose exec core php artisan cdn:powerdns:doctor || true
docker compose exec core php artisan cdn:powerdns:dry-run || true
docker compose exec core php artisan cdn:powerdns:force-sync || true
EOF
  chmod +x "$p/scripts/sync-dns.sh"

  cat > "$p/scripts/register-edge-token.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
cd "\$(dirname "\$0")/.."
docker compose exec core php artisan cdn:edge:register-token --edge_id='${EDGE_ID}' --token='${EDGE_TOKEN}'
docker compose exec core php artisan cdn:dns:reconcile || true
docker compose exec core php artisan cdn:powerdns:force-sync || true
EOF
  chmod +x "$p/scripts/register-edge-token.sh"

  cat > "$p/scripts/validate-core.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { echo "Missing .env" >&2; exit 1; }
set -a; . ./.env; set +a

echo '== Compose config =='
docker compose config --quiet

echo '== Services =='
docker compose ps -a

echo '== Core health =='
curl -fsS "http://127.0.0.1:${CORE_HOST_PORT#*:}/health" || true; echo
curl -fsS "http://127.0.0.1:${CORE_HOST_PORT#*:}/ready" || true; echo

echo '== PowerDNS API =='
curl -fsS -H "X-API-Key: $PDNS_API_KEY" "http://127.0.0.1:${PDNS_API_PORT}/api/v1/servers/localhost" || true; echo

echo '== CDNLite DNS commands =='
docker compose exec core php artisan cdn:readiness:check || true
docker compose exec core php artisan cdn:powerdns:doctor || true
docker compose exec core php artisan cdn:powerdns:dry-run || true

if command -v dig >/dev/null 2>&1; then
  echo '== DNS query smoke =='
  dig @127.0.0.1 -p "$PDNS_DNS_PORT" "$CDNLITE_CDN_ZONE" SOA +short || true
  dig @127.0.0.1 -p "$PDNS_DNS_PORT" "$CDNLITE_CDN_PROXY_HOST" A +short || true
fi
EOF
  chmod +x "$p/scripts/validate-core.sh"


  cat > "$p/scripts/rebuild-dashboard.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { echo "Missing .env" >&2; exit 1; }
docker compose build --no-cache dashboard
docker compose up -d --force-recreate dashboard
EOF
  chmod +x "$p/scripts/rebuild-dashboard.sh"

  cat > "$p/scripts/lockdown-bootstrap-flags.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
cp .env ".env.before-lockdown.$(date +%Y%m%d%H%M%S)"
sed -i.bak \
  -e 's/^CDNLITE_BOOTSTRAP_ADMIN_USER=.*/CDNLITE_BOOTSTRAP_ADMIN_USER=0/' \
  -e 's/^CDNLITE_BOOTSTRAP_EDGE_TOKEN=.*/CDNLITE_BOOTSTRAP_EDGE_TOKEN=0/' \
  -e 's/^CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=.*/CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=/' \
  -e 's/^CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE=.*/CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE=/' \
  .env
chmod 600 .env
printf 'Bootstrap flags disabled. Recreate core/dashboard services if needed.\n'
EOF
  chmod +x "$p/scripts/lockdown-bootstrap-flags.sh"

  cat > "$e/scripts/healthcheck.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose config --quiet
docker compose ps -a
docker compose exec edge wget -qO- http://127.0.0.1:8081/health || true
if docker compose ps pdns-replica >/dev/null 2>&1; then docker compose exec pdns-replica pdns_control ping || true; fi
EOF
  chmod +x "$e/scripts/healthcheck.sh"

  cat > "$e/register-this-edge-on-core.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
CORE_DIR=\${CORE_DIR:-../${PRIMARY_DIR}}
cd "\$CORE_DIR"
docker compose exec core php artisan cdn:edge:register-token --edge_id='${EDGE_ID}' --token='${EDGE_TOKEN}'
docker compose exec core php artisan cdn:dns:reconcile || true
docker compose exec core php artisan cdn:powerdns:force-sync || true
EOF
  chmod +x "$e/register-this-edge-on-core.sh"
}

write_readmes() {
  local p="$1" e="$2"
  cat > "$p/README_RUNBOOK.md" <<EOF
# CDNLite upstream core + DNSGeo

Generated by \`${SCRIPT_VERSION}\`.

This folder does **not** contain a CDNLite repository clone. CDNLite app services use upstream GHCR images by default. DNSGeo images build from GitHub remote contexts defined in \`.env\`.

## Start

\`\`\`bash
./scripts/fix-runtime-permissions.sh
docker compose --profile npm up -d --build --wait
./scripts/validate-core.sh
\`\`\`

Omit \`--profile npm\` if you do not want Nginx Proxy Manager.

## Important URLs

- Core API: ${CORE_PUBLIC_URL}
- Dashboard: ${DASHBOARD_PUBLIC_URL}
- PowerDNS API loopback: http://127.0.0.1:${PDNS_API_PORT}/api/v1
- Poweradmin loopback: http://127.0.0.1:${POWERADMIN_PORT}
- CDNLite admin: \`${CDNLITE_ADMIN_USERNAME}\` / \`${CDNLITE_ADMIN_PASSWORD}\`
- Poweradmin admin: \`${POWERADMIN_ADMIN_USERNAME}\` / \`${POWERADMIN_ADMIN_PASSWORD}\`

## Parent DNS delegation

| Type | Name | Value |
| --- | --- | --- |
| NS | \`${DNS_BASE_DOMAIN}\` | \`${DNS_NS1}\` |
| NS | \`${DNS_BASE_DOMAIN}\` | \`${DNS_NS2}\` |
| A | \`${DNS_NS1}\` | \`${NS1_IPV4}\` |
| A | \`${DNS_NS2}\` | \`${NS2_IPV4}\` |

## Update behavior

- For upstream image updates, keep \`IMAGE_TAG=latest\` and run \`docker compose pull && docker compose up -d\`.
- For source builds without a local clone, set \`CDNLITE_COMPONENT_MODE=build\`; Docker will build from remote GitHub contexts.
- To pin production, set \`CDNLITE_REF=<tag-or-commit>\` and \`IMAGE_TAG=<same-tag>\`.
EOF

  cat > "$e/README_RUNBOOK.md" <<EOF
# CDNLite upstream edge

Generated by \`${SCRIPT_VERSION}\`.

This folder does **not** contain a CDNLite repository clone. Edge/agent use upstream GHCR images by default, or remote GitHub build contexts when \`CDNLITE_COMPONENT_MODE=build\`.

## Start

Register the generated edge token on the core host:

\`\`\`bash
CORE_DIR=../${PRIMARY_DIR} ./register-this-edge-on-core.sh
\`\`\`

Start edge only:

\`\`\`bash
./scripts/fix-runtime-permissions.sh
docker compose up -d --wait
./scripts/healthcheck.sh
\`\`\`

Start edge plus PowerDNS secondary:

\`\`\`bash
docker compose --profile pdns-secondary up -d --wait
\`\`\`

PowerDNS secondary uses the upstream \`deploy/powerdns-replica\` sqlite/autosecondary pattern and persists under \`./runtime/pdns-replica-data\`.
EOF
}

validate_generated_compose() {
  local p="$1" e="$2"
  if [ "$VALIDATE_COMPOSE" != "yes" ]; then warn "Skipping compose validation."; return 0; fi
  if ! command -v docker >/dev/null 2>&1; then warn "Docker not found; skipping docker compose config validation."; return 0; fi
  say "Validating generated Docker Compose files"
  (cd "$p" && docker compose config --quiet)
  (cd "$e" && docker compose config --quiet)
}

main() {
  while [ $# -gt 0 ]; do
    case "$1" in
      --auto) AUTO_DEFAULTS=yes ;;
      --force) FORCE=yes ;;
      --no-compose-check) VALIDATE_COMPOSE=no ;;
      -h|--help) usage; exit 0 ;;
      *) fail "Unknown argument: $1" ;;
    esac
    shift
  done

  need openssl
  need sed

  local detected_ip=""
  detected_ip="$(detect_public_ipv4 || true)"

  PRIMARY_DIR="${PRIMARY_DIR:-$PRIMARY_DIR_DEFAULT}"
  EDGE_DIR="${EDGE_DIR:-$EDGE_DIR_DEFAULT}"
  prompt PRIMARY_DIR "Core/DNSGeo output directory" "$PRIMARY_DIR"
  prompt EDGE_DIR "Edge output directory" "$EDGE_DIR"

  CDNLITE_REF="${CDNLITE_REF:-$CDNLITE_REF_DEFAULT}"
  IMAGE_TAG="${IMAGE_TAG:-$IMAGE_TAG_DEFAULT}"
  REGISTRY_OWNER="${REGISTRY_OWNER:-$REGISTRY_OWNER_DEFAULT}"
  CDNLITE_COMPONENT_MODE="${CDNLITE_COMPONENT_MODE:-$CDNLITE_COMPONENT_MODE_DEFAULT}"
  prompt CDNLITE_REF "CDNLite upstream git ref for remote build contexts" "$CDNLITE_REF"
  prompt IMAGE_TAG "CDNLite upstream image tag" "$IMAGE_TAG"
  prompt REGISTRY_OWNER "GHCR registry owner" "$REGISTRY_OWNER"
  prompt CDNLITE_COMPONENT_MODE "CDNLite component mode: image or build" "$CDNLITE_COMPONENT_MODE"
  case "$CDNLITE_COMPONENT_MODE" in image|build) ;; *) fail "CDNLITE_COMPONENT_MODE must be image or build" ;; esac

  DNS_BASE_DOMAIN="$(normalize_domain "${DNS_BASE_DOMAIN:-}")"
  prompt_required DNS_BASE_DOMAIN "Base authoritative DNS domain" "${DNS_BASE_DOMAIN:-example.com}"
  DNS_BASE_DOMAIN="$(normalize_domain "$DNS_BASE_DOMAIN")"

  ACME_EMAIL="${ACME_EMAIL:-$(default_admin_email "$DNS_BASE_DOMAIN")}"; prompt_required ACME_EMAIL "Admin/ACME email" "$ACME_EMAIL"
  TZ_VALUE="${TZ_VALUE:-UTC}"; prompt TZ_VALUE "Timezone" "$TZ_VALUE"

  PRIMARY_PUBLIC_IP="${PRIMARY_PUBLIC_IP:-${detected_ip:-}}"
  EDGE_PUBLIC_IP="${EDGE_PUBLIC_IP:-${detected_ip:-}}"
  prompt_required PRIMARY_PUBLIC_IP "Core/DNSGeo public IPv4" "${PRIMARY_PUBLIC_IP:-127.0.0.1}"
  prompt_required EDGE_PUBLIC_IP "First edge public IPv4" "${EDGE_PUBLIC_IP:-$PRIMARY_PUBLIC_IP}"
  is_ipv4 "$PRIMARY_PUBLIC_IP" || warn "Core public IP does not look like IPv4: $PRIMARY_PUBLIC_IP"
  is_ipv4 "$EDGE_PUBLIC_IP" || warn "Edge public IP does not look like IPv4: $EDGE_PUBLIC_IP"

  CDN_ZONE="$(normalize_domain "${CDN_ZONE:-cdn.${DNS_BASE_DOMAIN}}")"; prompt_required CDN_ZONE "CDNLite CDN zone" "$CDN_ZONE"; CDN_ZONE="$(normalize_domain "$CDN_ZONE")"
  CDN_PROXY_HOST="$(normalize_domain "${CDN_PROXY_HOST:-proxy.${CDN_ZONE}}")"; prompt_required CDN_PROXY_HOST "Shared CDN proxy host" "$CDN_PROXY_HOST"; CDN_PROXY_HOST="$(normalize_domain "$CDN_PROXY_HOST")"
  EDGE_ZONE_PREFIX="${EDGE_ZONE_PREFIX:-edge}"; prompt_required EDGE_ZONE_PREFIX "Edge DNS zone prefix" "$EDGE_ZONE_PREFIX"

  DNS_NS1="$(normalize_domain "${DNS_NS1:-ns1.${DNS_BASE_DOMAIN}}")"; prompt_required DNS_NS1 "Primary nameserver hostname" "$DNS_NS1"; DNS_NS1="$(normalize_domain "$DNS_NS1")"
  DNS_NS2="$(normalize_domain "${DNS_NS2:-ns2.${DNS_BASE_DOMAIN}}")"; prompt_required DNS_NS2 "Secondary nameserver hostname" "$DNS_NS2"; DNS_NS2="$(normalize_domain "$DNS_NS2")"
  NS1_IPV4="${NS1_IPV4:-$PRIMARY_PUBLIC_IP}"; prompt_required NS1_IPV4 "${DNS_NS1} IPv4" "$NS1_IPV4"
  NS2_IPV4="${NS2_IPV4:-$EDGE_PUBLIC_IP}"; prompt_required NS2_IPV4 "${DNS_NS2} IPv4" "$NS2_IPV4"
  DNS_HOSTMASTER="${DNS_HOSTMASTER:-$(normalize_hostmaster "hostmaster@${DNS_BASE_DOMAIN}")}"; prompt_required DNS_HOSTMASTER "SOA hostmaster" "$DNS_HOSTMASTER"; DNS_HOSTMASTER="$(normalize_hostmaster "$DNS_HOSTMASTER")"

  CORE_PUBLIC_URL="${CORE_PUBLIC_URL:-https://api.${DNS_BASE_DOMAIN}}"; prompt_required CORE_PUBLIC_URL "Public Core API URL" "$CORE_PUBLIC_URL"; CORE_PUBLIC_URL="$(normalize_url "$CORE_PUBLIC_URL")"
  DASHBOARD_PUBLIC_URL="${DASHBOARD_PUBLIC_URL:-https://dashboard.${DNS_BASE_DOMAIN}}"; prompt_required DASHBOARD_PUBLIC_URL "Public Dashboard URL" "$DASHBOARD_PUBLIC_URL"; DASHBOARD_PUBLIC_URL="$(normalize_url "$DASHBOARD_PUBLIC_URL")"
  EDGE_HOSTNAME="$(normalize_domain "${EDGE_HOSTNAME:-edge-1.${DNS_BASE_DOMAIN}}")"; prompt_required EDGE_HOSTNAME "First edge hostname" "$EDGE_HOSTNAME"; EDGE_HOSTNAME="$(normalize_domain "$EDGE_HOSTNAME")"
  EDGE_PUBLIC_URL="${EDGE_PUBLIC_URL:-https://${EDGE_HOSTNAME}}"; prompt_required EDGE_PUBLIC_URL "Public first Edge URL" "$EDGE_PUBLIC_URL"; EDGE_PUBLIC_URL="$(normalize_url "$EDGE_PUBLIC_URL")"
  EDGE_REGION="${EDGE_REGION:-global}"; prompt EDGE_REGION "First edge region" "$EDGE_REGION"
  EDGE_ID="${EDGE_ID:-edge-1}"; prompt EDGE_ID "First edge ID" "$EDGE_ID"; EDGE_ID="$(safe_name "$EDGE_ID")"

  CORE_HOST_PORT="${CORE_HOST_PORT:-8080}"; prompt CORE_HOST_PORT "Core host port on loopback" "$CORE_HOST_PORT"
  DASHBOARD_PORT="${DASHBOARD_PORT:-8082}"; prompt DASHBOARD_PORT "Dashboard host port on loopback" "$DASHBOARD_PORT"
  DNS_PORT="${DNS_PORT:-53}"; prompt DNS_PORT "Primary authoritative DNS host port" "$DNS_PORT"
  PDNS_API_PORT="${PDNS_API_PORT:-8089}"; prompt PDNS_API_PORT "PowerDNS API host port on loopback" "$PDNS_API_PORT"
  POWERADMIN_PORT="${POWERADMIN_PORT:-8084}"; prompt POWERADMIN_PORT "Poweradmin host port on loopback" "$POWERADMIN_PORT"
  EDGE_HOST_PORT="${EDGE_HOST_PORT:-80}"; prompt EDGE_HOST_PORT "Edge HTTP host port" "$EDGE_HOST_PORT"
  EDGE_TLS_HOST_PORT="${EDGE_TLS_HOST_PORT:-443}"; prompt EDGE_TLS_HOST_PORT "Edge HTTPS host port" "$EDGE_TLS_HOST_PORT"
  EDGE_DNS_PORT="${EDGE_DNS_PORT:-53}"; prompt EDGE_DNS_PORT "Edge/secondary DNS host port" "$EDGE_DNS_PORT"

  NPM_HTTP_PORT="${NPM_HTTP_PORT:-80}"; NPM_HTTPS_PORT="${NPM_HTTPS_PORT:-443}"; NPM_ADMIN_PORT="${NPM_ADMIN_PORT:-81}"
  prompt_yes_no WITH_NPM "Generate/start Nginx Proxy Manager profile" "$([ "$WITH_NPM_DEFAULT" = yes ] && echo y || echo n)"
  prompt_yes_no WITH_PDNS_SECONDARY "Generate edge PowerDNS secondary profile" "$([ "$WITH_PDNS_SECONDARY_DEFAULT" = yes ] && echo y || echo n)"

  CDNLITE_DB_PASSWORD="${CDNLITE_DB_PASSWORD:-$(rand_b64 36)}"
  CDNLITE_API_TOKEN="${CDNLITE_API_TOKEN:-$(rand_b64 48)}"
  CDNLITE_SSL_SECRET_KEY="${CDNLITE_SSL_SECRET_KEY:-$(rand_b64 48)}"
  CDNLITE_ORIGIN_SHIELD_SECRET="${CDNLITE_ORIGIN_SHIELD_SECRET:-$(rand_b64 48)}"
  CDNLITE_ADMIN_USERNAME="${CDNLITE_ADMIN_USERNAME:-admin}"
  CDNLITE_ADMIN_PASSWORD="${CDNLITE_ADMIN_PASSWORD:-$(rand_b64 28)}"
  EDGE_TOKEN="${EDGE_TOKEN:-$(rand_b64 48)}"
  PDNS_POSTGRES_SUPERUSER_PASSWORD="${PDNS_POSTGRES_SUPERUSER_PASSWORD:-$(rand_b64 36)}"
  PDNS_DB_PASSWORD="${PDNS_DB_PASSWORD:-$(rand_b64 36)}"
  POWERADMIN_DB_PASSWORD="${POWERADMIN_DB_PASSWORD:-$(rand_b64 36)}"
  PDNS_REPLICATION_PASSWORD="${PDNS_REPLICATION_PASSWORD:-$(rand_b64 36)}"
  PDNS_API_KEY="${PDNS_API_KEY:-$(rand_b64 48)}"
  PDNS_WEBSERVER_PASSWORD="${PDNS_WEBSERVER_PASSWORD:-$(rand_b64 28)}"
  POWERADMIN_SESSION_KEY="${POWERADMIN_SESSION_KEY:-$(rand_b64 48)}"
  POWERADMIN_ADMIN_USERNAME="${POWERADMIN_ADMIN_USERNAME:-admin}"
  POWERADMIN_ADMIN_PASSWORD="${POWERADMIN_ADMIN_PASSWORD:-$(rand_b64 28)}"
  PA_APPLICATION_URL="${PA_APPLICATION_URL:-https://poweradmin.${DNS_BASE_DOMAIN}}"

  if [ -e "$PRIMARY_DIR" ] || [ -e "$EDGE_DIR" ]; then
    if [ "$FORCE" = "yes" ]; then
      rm -rf "$PRIMARY_DIR" "$EDGE_DIR"
    else
      fail "Output folder exists. Use --force or set different PRIMARY_DIR/EDGE_DIR."
    fi
  fi

  say "Generating upstream/no-clone runtime deployment"
  runtime_dirs "$PRIMARY_DIR" core
  runtime_dirs "$EDGE_DIR" edge
  write_permissions_script "$PRIMARY_DIR" core
  write_permissions_script "$EDGE_DIR" edge
  write_geo_bootstrap "$PRIMARY_DIR"
  write_edge_replica_config "$EDGE_DIR"
  write_core_env "$PRIMARY_DIR"
  write_edge_env "$EDGE_DIR"
  write_core_compose "$PRIMARY_DIR"
  write_edge_compose "$EDGE_DIR"
  write_platform_settings_sql "$PRIMARY_DIR"
  write_helpers "$PRIMARY_DIR" "$EDGE_DIR"
  write_readmes "$PRIMARY_DIR" "$EDGE_DIR"
  "$PRIMARY_DIR/scripts/fix-runtime-permissions.sh" >/dev/null
  "$EDGE_DIR/scripts/fix-runtime-permissions.sh" >/dev/null
  validate_generated_compose "$PRIMARY_DIR" "$EDGE_DIR"

  say "Done"
  cat <<EOF
Generated:
  Core: $PRIMARY_DIR
  Edge: $EDGE_DIR

No CDNLite repository clone was written to either folder.
Persistent state is under:
  $PRIMARY_DIR/runtime
  $EDGE_DIR/runtime

Start core:
  cd $PRIMARY_DIR
  ./scripts/fix-runtime-permissions.sh
  docker compose --profile npm up -d --build --wait

Start edge:
  cd ../$EDGE_DIR
  CORE_DIR=../$PRIMARY_DIR ./register-this-edge-on-core.sh
  ./scripts/fix-runtime-permissions.sh
  docker compose --profile pdns-secondary up -d --wait
EOF
}

main "$@"
