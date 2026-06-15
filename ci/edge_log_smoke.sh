#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
CI_ENV_NAME="${CI_ENV_NAME:-edge-log-smoke}"
REPORT_MD="${REPORT_MD:-$REPORT_DIR/edge-log-smoke-report.md}"
REPORT_JSON="${REPORT_JSON:-$REPORT_DIR/edge-log-smoke-report.json}"
REPORT_JUNIT="${REPORT_JUNIT:-$REPORT_DIR/edge-log-smoke-junit.xml}"
EDGE_LOG_SMOKE_VALID_HOST="${EDGE_LOG_SMOKE_VALID_HOST:-}"
EDGE_LOG_SMOKE_VALID_PATH="${EDGE_LOG_SMOKE_VALID_PATH:-/health?via=edge-log-smoke-valid&token=secret-smoke-token}"
EDGE_LOG_SMOKE_UNKNOWN_HOST="${EDGE_LOG_SMOKE_UNKNOWN_HOST:-unknown.edge-log-smoke.test}"
EDGE_LOG_SMOKE_DOWN_HOST="${EDGE_LOG_SMOKE_DOWN_HOST:-}"
EDGE_LOG_SMOKE_DOWN_PATH="${EDGE_LOG_SMOKE_DOWN_PATH:-/health?via=edge-log-smoke-down&signature=secret-smoke-signature}"
EDGE_LOG_SMOKE_TAIL="${EDGE_LOG_SMOKE_TAIL:-600}"
export CORE_URL EDGE_URL CI_ENV_NAME REPORT_MD REPORT_JSON REPORT_JUNIT

init_report
trap 'collect_diagnostics; write_reports' EXIT

require_env() {
  local name="$1"
  local value="$2"
  if [[ -z "$value" ]]; then
    fail "$name is required"
  fi
}

edge_logs() {
  docker compose logs --no-color --tail="$EDGE_LOG_SMOKE_TAIL" edge
}

assert_edge_log_contains() {
  local needle="$1"
  local msg="$2"
  local logs
  logs="$(edge_logs)"
  assert_contains "$logs" "$needle" "$msg"
}

assert_edge_log_not_contains() {
  local needle="$1"
  local msg="$2"
  local logs
  logs="$(edge_logs)"
  if [[ "$logs" == *"$needle"* ]]; then
    fail "$msg (unexpected '$needle')"
  fi
}

request_edge() {
  local host="$1"
  local path="$2"
  curl -sS -o /tmp/cdnlite-edge-log-smoke-body.txt -D /tmp/cdnlite-edge-log-smoke-headers.txt \
    -w '%{http_code}' "$EDGE_URL$path" -H "Host: $host"
}

require_env EDGE_LOG_SMOKE_VALID_HOST "$EDGE_LOG_SMOKE_VALID_HOST"
require_env EDGE_LOG_SMOKE_DOWN_HOST "$EDGE_LOG_SMOKE_DOWN_HOST"

retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "edge-health" "edge health endpoint reachable"

valid_code="$(request_edge "$EDGE_LOG_SMOKE_VALID_HOST" "$EDGE_LOG_SMOKE_VALID_PATH")"
if [[ "$valid_code" =~ ^[23] ]]; then
  record_step PASS "valid-proxied-request" "host=${EDGE_LOG_SMOKE_VALID_HOST} status=${valid_code}"
else
  fail "valid proxied request failed (host=${EDGE_LOG_SMOKE_VALID_HOST} status=${valid_code})"
fi

unknown_code="$(request_edge "$EDGE_LOG_SMOKE_UNKNOWN_HOST" "/unknown?via=edge-log-smoke-unknown&password=secret-smoke-password")"
assert_eq "$unknown_code" "502" "unknown host should produce edge 502"
record_step PASS "unknown-host-request" "host=${EDGE_LOG_SMOKE_UNKNOWN_HOST} status=${unknown_code}"

down_code="$(request_edge "$EDGE_LOG_SMOKE_DOWN_HOST" "$EDGE_LOG_SMOKE_DOWN_PATH")"
assert_eq "$down_code" "502" "origin-down host should produce edge 502"
record_step PASS "origin-down-request" "host=${EDGE_LOG_SMOKE_DOWN_HOST} status=${down_code}"

assert_edge_log_contains '"request_id":' "edge logs should include request_id"
assert_edge_log_contains "\"host\":\"${EDGE_LOG_SMOKE_VALID_HOST}\"" "edge logs should include valid host"
assert_edge_log_contains "\"host\":\"${EDGE_LOG_SMOKE_UNKNOWN_HOST}\"" "edge logs should include unknown host"
assert_edge_log_contains 'router_error' "edge logs should include router_error diagnostics"
assert_edge_log_contains 'origin_id' "edge logs should include selected origin metadata"
assert_edge_log_contains 'upstream_status' "edge logs should include upstream status"
assert_edge_log_contains 'upstream_response_time' "edge logs should include upstream timing"
assert_edge_log_not_contains 'secret-smoke-token' "edge logs must redact token query value"
assert_edge_log_not_contains 'secret-smoke-password' "edge logs must redact password query value"
assert_edge_log_not_contains 'secret-smoke-signature' "edge logs must redact signature query value"
record_step PASS "docker-visible-edge-logs" "request, router, origin, upstream, and redaction fields observed"

pass "edge log smoke checks completed"
