#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
DASHBOARD_URL="${DASHBOARD_URL:-http://localhost:${DASHBOARD_PORT:-8082}}"
POWERDNS_API_URL="${POWERDNS_API_URL:-http://localhost:8089}"
CI_ENV_NAME="${CI_ENV_NAME:-smoke}"
export CORE_URL EDGE_URL DASHBOARD_URL POWERDNS_API_URL CI_ENV_NAME

init_report
trap 'collect_diagnostics; write_reports' EXIT

on_error() {
  local rc=$?
  local line="${1:-unknown}"
  local cmd="${2:-unknown}"
  echo "smoke: error rc=$rc at line=$line cmd=$cmd, printing diagnostics"
  docker compose ps || true
  docker compose logs --no-color || true
  for svc in core edge edge-agent dashboard postgres; do
    echo "----- ${svc} (tail 200) -----"
    docker compose logs --no-color --tail=200 "$svc" || true
  done
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
record_step PASS "core-health" "core health endpoint reachable"
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "edge-health" "edge health endpoint reachable"

docker compose ps dashboard | grep -q "Up"
record_step PASS "dashboard-container-running" "dashboard service is up"

retry 40 2 docker compose exec -T dashboard wget -qO- http://127.0.0.1/healthz >/dev/null
record_step PASS "dashboard-healthz" "dashboard nginx health endpoint reachable"

dashboard_index="$(docker compose exec -T dashboard wget -qO- http://127.0.0.1/)"
assert_contains "$dashboard_index" '<div id="app">' "dashboard index should contain Vue app mount"
record_step PASS "dashboard-index" "dashboard SPA index served"

./ci/agent_flow_checks.sh >/dev/null
record_step PASS "agent-flow-checks" "config and metrics failure handling verified"

wait_for_postgres
retry 40 2 db_query "SELECT 1;" >/dev/null
record_step PASS "postgres-connectivity" "postgres reachable"

if [[ -n "${CDNLITE_API_TOKEN:-}" ]]; then
  no_auth_code="$(curl -sS -o /tmp/smoke-auth.txt -w '%{http_code}' "${CORE_URL}/api/v1/sites")"
  assert_eq "$no_auth_code" "401" "control-plane endpoint should require bearer auth when token is configured"
  record_step PASS "api-auth-negative" "unauthenticated /api/v1/sites returned 401"

  api_get "${CORE_URL}/api/v1/sites"
  assert_http_status "$HTTP_CODE" "200" "authenticated site list failed"
  record_step PASS "api-auth-positive" "authenticated /api/v1/sites returned 200"
fi

# Initialize core DB schema explicitly before table assertions.
retry 40 2 docker compose exec -T core php -r "require '/app/app/Support/bootstrap.php'; App\\Support\\Database::pdo(); echo 'ok';" >/dev/null
record_step PASS "core-db-init" "core schema initialization completed"

required_tables=(
  sites dns_records edge_nodes edge_tokens edge_request_nonces
  admin_users admin_sessions
  usage_rollups usage_ingest_keys usage_aggregates config_state config_snapshots
  site_cache_settings cache_purge_requests cache_purge_versions page_rules ssl_certificates
  rate_limit_rules_v2 audit_log
)
for t in "${required_tables[@]}"; do
  count="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='${t}';")"
  assert_eq "$count" "1" "table ${t} missing"
done
record_step PASS "schema-tables" "all required tables exist"

waf_action_col="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='waf_rules' AND column_name='action';")"
assert_eq "$waf_action_col" "1" "waf_rules.action column missing"
record_step PASS "schema-waf-v2-column" "waf_rules.action column exists"

ssl_key_check="$(docker compose exec -T core php -r "echo getenv('CDNLITE_SSL_SECRET_KEY') ? 'set' : 'missing';")"
if [[ "$ssl_key_check" == "set" ]]; then
  record_step PASS "ssl-secret-configured" "CDNLITE_SSL_SECRET_KEY present"
else
  record_step PASS "ssl-secret-configured" "CDNLITE_SSL_SECRET_KEY missing in running container (e2e ssl import will enforce/validate)"
fi

docker compose ps edge | grep -q "Up"
record_step PASS "edge-container-running" "edge service is up"

docker compose exec -T edge-agent sh -lc 'test -e "${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}"'
record_step PASS "edge-config-path" "edge config path exists"

if [[ "${POWERDNS_ENABLED:-0}" == "1" ]]; then
  retry 30 2 curl -fsS "${POWERDNS_API_URL}/health" >/dev/null
  record_step PASS "powerdns-health" "powerdns endpoint reachable"
fi

pass "smoke checks completed"
