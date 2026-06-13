#!/usr/bin/env bash
set -euo pipefail

# Generate deployable CDNLite split-host bundles from the checked-in templates.
# The templates remain authoritative; this script only copies them and fills .env.

umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="${OUTPUT_DIR:-cdnlite-deployment}"
AUTO="${AUTO:-no}"
FORCE="${FORCE:-no}"
VALIDATE_COMPOSE="${VALIDATE_COMPOSE:-yes}"
WITH_REPLICA="${WITH_REPLICA:-no}"

say() { printf '\n%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"; }

usage() {
  cat <<'EOF'
Generate a CDNLite split deployment from the maintained deploy templates.

Usage:
  deploy/generate-split-deployment.sh [options]

Options:
  --auto                Use environment values and defaults without prompting.
  --output DIR          Output directory (default: cdnlite-deployment).
  --with-replica        Include a PowerDNS secondary bundle.
  --force               Replace an existing output directory.
  --no-compose-check    Skip generated Docker Compose validation.
  -h, --help            Show this help.

Common environment values:
  REGISTRY_OWNER, IMAGE_TAG, DNS_BASE_DOMAIN, CORE_PUBLIC_URL,
  DASHBOARD_PUBLIC_URL, CORE_PUBLIC_IP, DNSGEO_PUBLIC_IP, EDGE_PUBLIC_IP, EDGE_ID,
  EDGE_HOSTNAME, EDGE_REGION, ACME_EMAIL, NS1_IPV4, NS2_IPV4.

All generated secrets can also be supplied through their matching .env names.
Otherwise, cryptographically random values are generated with openssl.
EOF
}

trim() {
  local value="${1-}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "$value"
}

normalize_domain() {
  local value
  value="$(trim "${1-}")"
  value="${value#http://}"
  value="${value#https://}"
  value="${value%%/*}"
  value="${value%.}"
  printf '%s' "${value,,}"
}

normalize_url() {
  local value
  value="$(trim "${1-}")"
  case "$value" in
    http://*|https://*) ;;
    *) value="https://${value}" ;;
  esac
  printf '%s' "${value%/}"
}

is_ipv4() {
  local ip="${1-}" octet IFS=.
  local -a parts
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  read -r -a parts <<<"$ip"
  for octet in "${parts[@]}"; do
    ((10#$octet >= 0 && 10#$octet <= 255)) || return 1
  done
}

prompt() {
  local out_var="$1" label="$2" default="${3-}" input=""
  if [ "$AUTO" = "yes" ]; then
    input="$default"
  elif [ -n "$default" ]; then
    read -r -p "$label [$default]: " input || true
    input="$(trim "$input")"
    input="${input:-$default}"
  else
    read -r -p "$label: " input || true
    input="$(trim "$input")"
  fi
  printf -v "$out_var" '%s' "$input"
}

prompt_required() {
  local out_var="$1" label="$2" default="${3-}" value=""
  while true; do
    prompt value "$label" "$default"
    [ -n "$value" ] && { printf -v "$out_var" '%s' "$value"; return; }
    [ "$AUTO" = "yes" ] && fail "$label is required"
    warn "A value is required."
  done
}

random_secret() {
  openssl rand -base64 "${1:-48}" | tr -d '\n=/' | tr '+' '-'
}

env_set() {
  local file="$1" key="$2" value="$3" escaped
  escaped="${value//\\/\\\\}"
  escaped="${escaped//&/\\&}"
  escaped="${escaped//|/\\|}"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >>"$file"
  fi
}

copy_bundle() {
  local name="$1"
  mkdir -p "$OUTPUT_DIR/$name"
  cp -a "$SCRIPT_DIR/$name/." "$OUTPUT_DIR/$name/"
  cp "$SCRIPT_DIR/$name/.env.example" "$OUTPUT_DIR/$name/.env"
  sed -i \
    -e 's/Fill every CHANGE_ME value before running docker compose up -d/Generated values: review before running docker compose up -d/' \
    -e 's/Replace every CHANGE_ME value before deployment/Generated values: review before deployment/' \
    "$OUTPUT_DIR/$name/.env"
  chmod 600 "$OUTPUT_DIR/$name/.env"
}

verify_env() {
  local file="$1"
  if grep -Eq '^[A-Za-z_][A-Za-z0-9_]*=.*CHANGE_ME' "$file"; then
    fail "Generated environment still contains an unresolved placeholder: $file"
  fi
}

validate_bundle() {
  local name="$1"
  (
    cd "$OUTPUT_DIR/$name"
    docker compose --env-file .env config --quiet
  )
}

write_runbook() {
  cat >"$OUTPUT_DIR/README.md" <<EOF
# Generated CDNLite Split Deployment

Generated from the repository's maintained deployment templates.

## Hosts

- Core: \`${CORE_PUBLIC_URL}\`
- Dashboard: \`${DASHBOARD_PUBLIC_URL}\`
- DNSGeo host: \`${DNSGEO_PUBLIC_IP}\`
- DNS zone: \`${DNS_BASE_DOMAIN}\`
- Edge: \`${EDGE_HOSTNAME}\` (\`${EDGE_PUBLIC_IP}\`)

## Start

On the DNSGeo host:

\`\`\`bash
cd dnsgeo
docker compose up -d --build --wait
\`\`\`

On the Core host:

\`\`\`bash
cd core
docker compose pull
docker compose up -d --wait
docker compose exec core php artisan cdn:admin:create --username=admin --password='<STRONG_PASSWORD>'
\`\`\`

Configure PowerDNS in the dashboard with API base
\`http://${DNSGEO_PUBLIC_IP}:8089/api/v1\`, server ID \`localhost\`, and the
\`PDNS_API_KEY\` from \`dnsgeo/.env\`. Restrict port 8089 to the Core host.

Register the generated edge token from the generated root:

\`\`\`bash
./register-edge.sh
\`\`\`

Copy \`edge/\` to the POP host, then:

\`\`\`bash
cd edge
docker compose pull
docker compose up -d --wait
\`\`\`

## DNS Delegation

- \`${DNS_BASE_DOMAIN} NS ${DNS_NS1}\`
- \`${DNS_BASE_DOMAIN} NS ${DNS_NS2}\`
- \`${DNS_NS1} A ${NS1_IPV4}\`
- \`${DNS_NS2} A ${NS2_IPV4}\`

Keep all \`.env\` files private. The generator sets mode \`0600\`.
EOF

  cat >"$OUTPUT_DIR/register-edge.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
set -a
# shellcheck disable=SC1091
. edge/.env
set +a
docker compose --project-directory core --env-file core/.env \
  exec core php artisan cdn:edge:register-token \
  --edge_id="$EDGE_ID" --token="$EDGE_TOKEN"
EOF
  chmod 700 "$OUTPUT_DIR/register-edge.sh"
}

main() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --auto) AUTO=yes ;;
      --output)
        shift
        [ "$#" -gt 0 ] || fail "--output requires a directory"
        OUTPUT_DIR="$1"
        ;;
      --with-replica) WITH_REPLICA=yes ;;
      --force) FORCE=yes ;;
      --no-compose-check) VALIDATE_COMPOSE=no ;;
      -h|--help) usage; exit 0 ;;
      *) fail "Unknown option: $1" ;;
    esac
    shift
  done

  need openssl
  need sed
  need grep

  prompt_required REGISTRY_OWNER "GHCR registry owner" "${REGISTRY_OWNER:-}"
  prompt_required IMAGE_TAG "Immutable image tag" "${IMAGE_TAG:-}"
  prompt_required DNS_BASE_DOMAIN "Authoritative base domain" "${DNS_BASE_DOMAIN:-example.com}"
  DNS_BASE_DOMAIN="$(normalize_domain "$DNS_BASE_DOMAIN")"

  prompt_required CORE_PUBLIC_IP "Core host IPv4" "${CORE_PUBLIC_IP:-127.0.0.1}"
  prompt_required DNSGEO_PUBLIC_IP "DNSGeo host IPv4" "${DNSGEO_PUBLIC_IP:-$CORE_PUBLIC_IP}"
  prompt_required EDGE_PUBLIC_IP "Edge host IPv4" "${EDGE_PUBLIC_IP:-$CORE_PUBLIC_IP}"
  is_ipv4 "$CORE_PUBLIC_IP" || fail "Invalid CORE_PUBLIC_IP: $CORE_PUBLIC_IP"
  is_ipv4 "$DNSGEO_PUBLIC_IP" || fail "Invalid DNSGEO_PUBLIC_IP: $DNSGEO_PUBLIC_IP"
  is_ipv4 "$EDGE_PUBLIC_IP" || fail "Invalid EDGE_PUBLIC_IP: $EDGE_PUBLIC_IP"

  CORE_PUBLIC_URL="$(normalize_url "${CORE_PUBLIC_URL:-api.${DNS_BASE_DOMAIN}}")"
  DASHBOARD_PUBLIC_URL="$(normalize_url "${DASHBOARD_PUBLIC_URL:-dashboard.${DNS_BASE_DOMAIN}}")"
  EDGE_ID="${EDGE_ID:-edge-1}"
  EDGE_HOSTNAME="$(normalize_domain "${EDGE_HOSTNAME:-${EDGE_ID}.${DNS_BASE_DOMAIN}}")"
  EDGE_REGION="${EDGE_REGION:-global}"
  ACME_EMAIL="${ACME_EMAIL:-admin@${DNS_BASE_DOMAIN}}"
  DNS_NS1="$(normalize_domain "${DNS_NS1:-ns1.${DNS_BASE_DOMAIN}}")"
  DNS_NS2="$(normalize_domain "${DNS_NS2:-ns2.${DNS_BASE_DOMAIN}}")"
  NS1_IPV4="${NS1_IPV4:-$DNSGEO_PUBLIC_IP}"
  NS2_IPV4="${NS2_IPV4:-$EDGE_PUBLIC_IP}"
  is_ipv4 "$NS1_IPV4" || fail "Invalid NS1_IPV4: $NS1_IPV4"
  is_ipv4 "$NS2_IPV4" || fail "Invalid NS2_IPV4: $NS2_IPV4"

  if [ -e "$OUTPUT_DIR" ]; then
    [ "$FORCE" = "yes" ] || fail "Output exists: $OUTPUT_DIR (use --force to replace it)"
    rm -rf -- "$OUTPUT_DIR"
  fi

  say "Generating deployment in $OUTPUT_DIR"
  copy_bundle core
  copy_bundle dnsgeo
  copy_bundle edge
  [ "$WITH_REPLICA" = "yes" ] && copy_bundle powerdns-replica

  local db_password api_token ssl_key shield_secret edge_token
  local pdns_super_password pdns_password poweradmin_db_password
  local replication_password pdns_api_key pdns_web_password
  local poweradmin_password poweradmin_session_key
  db_password="${POSTGRES_PASSWORD:-$(random_secret 36)}"
  api_token="${CDNLITE_API_TOKEN:-$(random_secret 48)}"
  ssl_key="${CDNLITE_SSL_SECRET_KEY:-$(random_secret 48)}"
  shield_secret="${CDNLITE_ORIGIN_SHIELD_SECRET:-$(random_secret 48)}"
  edge_token="${EDGE_TOKEN:-$(random_secret 48)}"
  pdns_super_password="${PDNS_POSTGRES_SUPERUSER_PASSWORD:-$(random_secret 36)}"
  pdns_password="${PDNS_DB_PASSWORD:-$(random_secret 36)}"
  poweradmin_db_password="${POWERADMIN_DB_PASSWORD:-$(random_secret 36)}"
  replication_password="${PDNS_REPLICATION_PASSWORD:-$(random_secret 36)}"
  pdns_api_key="${PDNS_API_KEY:-$(random_secret 48)}"
  pdns_web_password="${PDNS_WEBSERVER_PASSWORD:-$(random_secret 36)}"
  poweradmin_password="${POWERADMIN_ADMIN_PASSWORD:-$(random_secret 36)}"
  poweradmin_session_key="${POWERADMIN_SESSION_KEY:-$(random_secret 48)}"

  local core_env="$OUTPUT_DIR/core/.env"
  env_set "$core_env" REGISTRY_OWNER "$REGISTRY_OWNER"
  env_set "$core_env" GITHUB_REPOSITORY_OWNER "$REGISTRY_OWNER"
  env_set "$core_env" IMAGE_TAG "$IMAGE_TAG"
  env_set "$core_env" POSTGRES_PASSWORD "$db_password"
  env_set "$core_env" DB_PASSWORD "$db_password"
  env_set "$core_env" CDNLITE_API_TOKEN "$api_token"
  env_set "$core_env" CDNLITE_SSL_SECRET_KEY "$ssl_key"
  env_set "$core_env" CDNLITE_ORIGIN_SHIELD_SECRET "$shield_secret"
  env_set "$core_env" CDNLITE_ACME_CONTACT_EMAIL "$ACME_EMAIL"
  env_set "$core_env" CDNLITE_CORS_ALLOWED_ORIGINS "$DASHBOARD_PUBLIC_URL"
  env_set "$core_env" EDGE_ID "$EDGE_ID"
  env_set "$core_env" EDGE_TOKEN "$edge_token"
  env_set "$core_env" EDGE_HOSTNAME "$EDGE_HOSTNAME"
  env_set "$core_env" EDGE_PUBLIC_IP "$EDGE_PUBLIC_IP"
  env_set "$core_env" EDGE_REGION "$EDGE_REGION"
  env_set "$core_env" VITE_CDNLITE_CORE_URL "$CORE_PUBLIC_URL"
  env_set "$core_env" VITE_CDNLITE_EDGE_URL "https://${EDGE_HOSTNAME}"
  env_set "$core_env" CORE_URL "$CORE_PUBLIC_URL"
  env_set "$core_env" CDNLITE_EDGE_BASE_DOMAIN "$DNS_BASE_DOMAIN"
  env_set "$core_env" CDNLITE_CDN_ZONE "cdn.${DNS_BASE_DOMAIN}"
  env_set "$core_env" CDNLITE_CDN_PROXY_HOST "proxy.cdn.${DNS_BASE_DOMAIN}"
  env_set "$core_env" CDNLITE_NS1_IP "$NS1_IPV4"
  env_set "$core_env" CDNLITE_NS2_IP "$NS2_IPV4"

  local dns_env="$OUTPUT_DIR/dnsgeo/.env"
  env_set "$dns_env" PDNS_POSTGRES_SUPERUSER_PASSWORD "$pdns_super_password"
  env_set "$dns_env" PDNS_DB_PASSWORD "$pdns_password"
  env_set "$dns_env" POWERADMIN_DB_PASSWORD "$poweradmin_db_password"
  env_set "$dns_env" PDNS_REPLICATION_PASSWORD "$replication_password"
  env_set "$dns_env" PDNS_API_KEY "$pdns_api_key"
  env_set "$dns_env" PDNS_WEBSERVER_PASSWORD "$pdns_web_password"
  env_set "$dns_env" POWERADMIN_ADMIN_PASSWORD "$poweradmin_password"
  env_set "$dns_env" POWERADMIN_SESSION_KEY "$poweradmin_session_key"
  env_set "$dns_env" CDNLITE_DNS_BASE_DOMAIN "$DNS_BASE_DOMAIN"
  env_set "$dns_env" CDNLITE_CDN_ZONE "cdn.${DNS_BASE_DOMAIN}"
  env_set "$dns_env" CDNLITE_DNS_NS1 "$DNS_NS1"
  env_set "$dns_env" CDNLITE_DNS_NS2 "$DNS_NS2"
  env_set "$dns_env" CDNLITE_DNS_HOSTMASTER "hostmaster.${DNS_BASE_DOMAIN}"
  env_set "$dns_env" PDNS_WEBSERVER_ALLOW_FROM "127.0.0.1,172.31.0.0/24,${CORE_PUBLIC_IP}"
  env_set "$dns_env" POWERADMIN_ADMIN_EMAIL "$ACME_EMAIL"

  local edge_env="$OUTPUT_DIR/edge/.env"
  env_set "$edge_env" REGISTRY_OWNER "$REGISTRY_OWNER"
  env_set "$edge_env" IMAGE_TAG "$IMAGE_TAG"
  env_set "$edge_env" EDGE_ID "$EDGE_ID"
  env_set "$edge_env" EDGE_TOKEN "$edge_token"
  env_set "$edge_env" EDGE_HOSTNAME "$EDGE_HOSTNAME"
  env_set "$edge_env" EDGE_REGION "$EDGE_REGION"
  env_set "$edge_env" EDGE_PUBLIC_IP "$EDGE_PUBLIC_IP"
  env_set "$edge_env" CORE_URL "$CORE_PUBLIC_URL"

  if [ "$WITH_REPLICA" = "yes" ]; then
    env_set "$OUTPUT_DIR/powerdns-replica/.env" PDNS_PRIMARY_IP "$DNSGEO_PUBLIC_IP"
  fi

  verify_env "$core_env"
  verify_env "$dns_env"
  verify_env "$edge_env"
  [ "$WITH_REPLICA" = "yes" ] && verify_env "$OUTPUT_DIR/powerdns-replica/.env"

  write_runbook

  if [ "$VALIDATE_COMPOSE" = "yes" ]; then
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
      say "Validating generated Compose projects"
      validate_bundle core
      validate_bundle dnsgeo
      validate_bundle edge
      [ "$WITH_REPLICA" = "yes" ] && validate_bundle powerdns-replica
    else
      warn "Docker Compose is unavailable; generated Compose validation was skipped."
    fi
  fi

  say "Done. Read $OUTPUT_DIR/README.md before starting the hosts."
}

main "$@"
