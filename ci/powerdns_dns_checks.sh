#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
DASHBOARD_URL="${DASHBOARD_URL:-http://localhost:${DASHBOARD_PORT:-8082}}"
POWERDNS_PUBLIC_API_URL="${POWERDNS_PUBLIC_API_URL:-http://localhost:${PDNS_API_HOST_PORT:-8089}}"
PDNS_DNS_HOST_PORT="${PDNS_DNS_HOST_PORT:-5353}"
CI_ENV_NAME="${CI_ENV_NAME:-powerdns-dns-checks}"
PDNS_API_KEY="${PDNS_API_KEY:-test-key}"
TEST_ZONE="${PDNS_DNS_TEST_ZONE:-dns-check.test.}"
TEST_ADDRESS="${PDNS_DNS_TEST_ADDRESS:-192.0.2.53}"
export CORE_URL EDGE_URL DASHBOARD_URL POWERDNS_PUBLIC_API_URL CI_ENV_NAME

init_report

cleanup_zone() {
  curl -fsS -X DELETE \
    "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_ZONE}" \
    -H "X-API-Key: ${PDNS_API_KEY}" >/dev/null 2>&1 || true
}

finish() {
  cleanup_zone
  collect_diagnostics
  write_reports
}

trap finish EXIT

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
retry 40 2 curl -fsS -H "X-API-Key: ${PDNS_API_KEY:-test-key}" \
  "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost" >/dev/null
record_step PASS "powerdns-health" "real PowerDNS API reachable"

for host in core edge dashboard postgres origin-http origin-tls pdns-postgres pdns-recursor pdns-auth poweradmin; do
  check_service_dns "$host"
done

cleanup_zone
create_status="$(curl -sS -o /tmp/powerdns-dns-create.json -w '%{http_code}' \
  -X POST "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones" \
  -H "X-API-Key: ${PDNS_API_KEY}" \
  -H "Content-Type: application/json" \
  --data "{\"name\":\"${TEST_ZONE}\",\"kind\":\"Native\",\"nameservers\":[\"ns1.${TEST_ZONE}\"]}")"
assert_eq "$create_status" "201" "PowerDNS should create an isolated CI zone"
record_step PASS "powerdns-zone-create" "real PowerDNS API created ${TEST_ZONE}"

patch_status="$(curl -sS -o /tmp/powerdns-dns-patch.json -w '%{http_code}' \
  -X PATCH "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_ZONE}" \
  -H "X-API-Key: ${PDNS_API_KEY}" \
  -H "Content-Type: application/json" \
  --data "{\"rrsets\":[{\"name\":\"www.${TEST_ZONE}\",\"type\":\"A\",\"ttl\":60,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"${TEST_ADDRESS}\",\"disabled\":false}]}]}")"
assert_eq "$patch_status" "204" "PowerDNS should write an rrset to the isolated CI zone"
record_step PASS "powerdns-rrset-write" "real PowerDNS API wrote an A rrset"

settings_status="$(curl -sS -o /tmp/powerdns-dns-settings.json -w '%{http_code}' \
  -X GET "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_ZONE}" \
  -H "X-API-Key: ${PDNS_API_KEY}")"
assert_eq "$settings_status" "200" "PowerDNS zone endpoint should be reachable with the configured API key"
record_step PASS "powerdns-api-auth-positive" "PowerDNS zone endpoint accepted configured API key"

bad_status="$(curl -sS -o /tmp/powerdns-dns-bad-key.json -w '%{http_code}' \
  -X GET "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_ZONE}" \
  -H "X-API-Key: bad-key")"
assert_eq "$bad_status" "403" "PowerDNS zone endpoint should reject a bad API key"
record_step PASS "powerdns-api-auth-negative" "PowerDNS zone endpoint rejected bad API key"

soa_answer="$(dig @127.0.0.1 -p "$PDNS_DNS_HOST_PORT" "$TEST_ZONE" SOA +short)"
[[ -n "$soa_answer" ]] || fail "PowerDNS authoritative listener did not answer for ${TEST_ZONE}"
a_answer="$(dig @127.0.0.1 -p "$PDNS_DNS_HOST_PORT" "www.${TEST_ZONE}" A +short)"
assert_eq "$a_answer" "$TEST_ADDRESS" "PowerDNS authoritative listener should return the API-written rrset"
record_step PASS "powerdns-authoritative-dig" "authoritative DNS returned the real PostgreSQL-backed rrset"

pass "powerdns dns checks completed"
