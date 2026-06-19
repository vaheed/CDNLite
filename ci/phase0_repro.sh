#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:${CORE_HOST_PORT:-8080}}"
EDGE_URL="${EDGE_URL:-http://localhost:${EDGE_HOST_PORT:-8081}}"
CI_ENV_NAME="${CI_ENV_NAME:-phase0-repro}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"
ADMIN_SESSION_TOKEN=""
RUN_KEY="${GITHUB_RUN_ID:-local}-$RANDOM"
TEST_DOMAIN="phase0-${RUN_KEY}.test.local"
DOMAIN_ID=""
DNS_IDS=()

export CORE_URL EDGE_URL CI_ENV_NAME ADMIN_SESSION_TOKEN

init_report

on_error() {
  local rc=$?
  local line="${1:-unknown}"
  local cmd="${2:-unknown}"
  echo "phase0-repro: error rc=$rc at line=$line cmd=$cmd"
  collect_diagnostics
  write_reports
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

cleanup() {
  if [[ -n "$DOMAIN_ID" ]]; then
    for rid in "${DNS_IDS[@]}"; do
      [[ -n "$rid" ]] || continue
      api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${rid}" >/dev/null || true
    done
    api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" >/dev/null || true
  fi
}

on_exit() {
  local rc=$?
  cleanup
  collect_diagnostics
  write_reports
  exit $rc
}
trap on_exit EXIT

login_admin() {
  local login_code
  login_code="$(curl -sS -o /tmp/phase0-admin-login.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"${ADMIN_USERNAME}\",\"password\":\"${ADMIN_PASSWORD}\"}")"
  assert_eq "$login_code" "200" "admin login should return 200"
  ADMIN_SESSION_TOKEN="$(json_get "$(cat /tmp/phase0-admin-login.json)" '.data.token')"
  export ADMIN_SESSION_TOKEN
}

phase0_expect_failure() {
  local name="$1"
  local detail="$2"
  record_step FAIL "$name" "$detail"
  fail "$detail"
}

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "stack-health" "core and edge health endpoints reachable"

login_admin
record_step PASS "admin-login" "admin dashboard API session acquired"

api_post "${CORE_URL}/api/v1/domains" "{\"domain\":\"${TEST_DOMAIN}\"}"
assert_http_status "$HTTP_CODE" "201" "domain create failed"
DOMAIN_ID="$(json_get "$HTTP_BODY" '.data.id')"
record_step PASS "domain-create" "domain_id=${DOMAIN_ID}"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/verify-nameservers" "{}"
assert_http_status "$HTTP_CODE" "200" "manual nameserver verification endpoint should return 200"
if ! jq -e '.data.expected_nameservers and .data.observed_nameservers and .data.missing_nameservers and .data.checked_at and (.data.resolver_errors != null)' <<<"$HTTP_BODY" >/dev/null; then
  phase0_expect_failure "nameserver-refresh-trace" "manual refresh does not return expected/observed/missing/checked_at/resolver_errors trace fields"
fi
record_step PASS "nameserver-refresh-trace" "manual refresh returned detailed trace fields"

force_code="$(curl -sS -o /tmp/phase0-force-noauth.json -w '%{http_code}' \
  -X POST "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/nameservers/force-verify" \
  -H 'Content-Type: application/json' \
  -d '{"reason":"phase0 non-admin negative check"}')"
if [[ "$force_code" != "401" && "$force_code" != "403" ]]; then
  phase0_expect_failure "force-verify-non-admin" "force verify should reject non-admin callers with 401/403, got ${force_code}"
fi
record_step PASS "force-verify-non-admin" "non-admin force verify rejected"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/nameservers/force-verify" '{"reason":"phase0 reproduction requires active verified routing"}'
if [[ "$HTTP_CODE" != "200" ]]; then
  phase0_expect_failure "force-verify-admin" "admin force verify endpoint is missing or unusable, got ${HTTP_CODE} body=${HTTP_BODY}"
fi
record_step PASS "force-verify-admin" "admin force verify activated the domain"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"@","content":"172.20.0.20","ttl":60,"proxied":true}'
assert_http_status "$HTTP_CODE" "201" "first proxied DNS record create failed"
DNS_IDS+=("$(json_get "$HTTP_BODY" '.data.id')")

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"@","content":"172.20.0.21","ttl":60,"proxied":true}'
if [[ "$HTTP_CODE" != "201" ]]; then
  phase0_expect_failure "multiple-proxied-record-create" "second proxied DNS record should create a stored DNS row, got ${HTTP_CODE} body=${HTTP_BODY}"
fi
second_id="$(json_get "$HTTP_BODY" '.data.id')"
DNS_IDS+=("$second_id")
if [[ "$second_id" == "${DNS_IDS[0]}" ]]; then
  phase0_expect_failure "multiple-proxied-record-distinct-id" "second proxied DNS record returned the first record id instead of a new row"
fi

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records"
assert_http_status "$HTTP_CODE" "200" "DNS record list failed"
proxied_count="$(jq '[.data[] | select(.proxied == true and .name == "@")] | length' <<<"$HTTP_BODY")"
if [[ "$proxied_count" -lt 2 ]]; then
  phase0_expect_failure "multiple-proxied-record-list" "DNS tab/API should show both proxied records; observed ${proxied_count}"
fi
record_step PASS "multiple-proxied-records" "two proxied records are stored and listed"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/ssl/request" "{}"
assert_http_status "$HTTP_CODE" "202" "SSL request endpoint should queue request"
if ! jq -e 'if (.data | type) == "array" then (.data[0].status != null) else (.data.status and (.data.progress != null or .data.job_id != null or .data.certificate_id != null)) end' <<<"$HTTP_BODY" >/dev/null; then
  phase0_expect_failure "ssl-request-progress" "SSL request should return user-visible progress/job/certificate status fields"
fi
record_step PASS "ssl-request-progress" "SSL request returned visible progress metadata"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity?limit=10"
if [[ "$HTTP_CODE" != "200" ]]; then
  phase0_expect_failure "activity-detail" "activity endpoint should return 200 once Phase 6 is implemented, got ${HTTP_CODE} body=${HTTP_BODY}"
elif ! jq -e '.data.items and (.data.summary.request_count != null) and (.data.items[]? | has("request_id") or has("origin_id") or has("upstream_status"))' <<<"$HTTP_BODY" >/dev/null; then
  phase0_expect_failure "activity-detail" "activity page/API lacks request/origin/upstream detail needed for diagnosis"
fi
record_step PASS "activity-detail" "activity API includes request/origin/upstream detail"

edge_code="$(curl -sS -o /tmp/phase0-edge-origin.txt -w '%{http_code}' -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/")"
if [[ "$edge_code" != "200" ]]; then
  phase0_expect_failure "edge-origin-proxy-200" "proxied edge request should return 200 from the origin, got ${edge_code}"
fi
record_step PASS "edge-origin-proxy-200" "edge returned 200 from origin"

record_step PASS "phase0-reproduction-complete" "all Phase 0 reported issues are covered by this harness"
pass "phase0 reproduction harness completed"
