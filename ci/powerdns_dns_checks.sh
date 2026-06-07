#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
DASHBOARD_URL="${DASHBOARD_URL:-http://localhost:${DASHBOARD_PORT:-8082}}"
POWERDNS_PUBLIC_API_URL="${POWERDNS_PUBLIC_API_URL:-http://localhost:${POWERDNS_HOST_PORT:-8089}}"
CI_ENV_NAME="${CI_ENV_NAME:-powerdns-dns-checks}"
export CORE_URL EDGE_URL DASHBOARD_URL POWERDNS_PUBLIC_API_URL CI_ENV_NAME

init_report
trap 'collect_diagnostics; write_reports' EXIT

resolve_from_core() {
  local host="$1"
  docker compose exec -T core php -r "\$ip = gethostbyname('${host}'); if (\$ip === '${host}') { fwrite(STDERR, 'unresolved'); exit(1); } echo \$ip;"
}

check_service_dns() {
  local host="$1"
  local ip
  ip="$(resolve_from_core "$host")"
  record_step PASS "dns-resolve-${host}" "${host} resolved from core as ${ip}"
}

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
record_step PASS "core-health" "core health endpoint reachable"
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "edge-health" "edge health endpoint reachable"
retry 40 2 curl -fsS "${DASHBOARD_URL}/healthz" >/dev/null
record_step PASS "dashboard-health" "dashboard health endpoint reachable"
retry 40 2 curl -fsS "${POWERDNS_PUBLIC_API_URL}/health" >/dev/null
record_step PASS "powerdns-health" "powerdns mock endpoint reachable"

for host in core edge dashboard postgres origin-http origin-tls powerdns; do
  check_service_dns "$host"
done

settings_status="$(curl -sS -o /tmp/powerdns-dns-settings.json -w '%{http_code}' \
  -X GET "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/dns-check.local." \
  -H "X-API-Key: ${POWERDNS_API_KEY:-test-key}")"
assert_eq "$settings_status" "200" "PowerDNS zone endpoint should be reachable with the configured API key"
record_step PASS "powerdns-api-auth-positive" "PowerDNS zone endpoint accepted configured API key"

bad_status="$(curl -sS -o /tmp/powerdns-dns-bad-key.json -w '%{http_code}' \
  -X GET "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/dns-check.local." \
  -H "X-API-Key: bad-key")"
assert_eq "$bad_status" "403" "PowerDNS zone endpoint should reject a bad API key"
record_step PASS "powerdns-api-auth-negative" "PowerDNS zone endpoint rejected bad API key"

pass "powerdns dns checks completed"
