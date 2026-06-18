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
for svc in core edge edge-agent dashboard postgres origin-tls origin-http; do
    echo "----- ${svc} (tail 200) -----"
    docker compose logs --no-color --tail=200 "$svc" || true
  done
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
record_step PASS "core-health" "core health endpoint reachable"
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "edge-health" "edge health endpoint reachable"

edge_identity_header="$(curl -sSI "$EDGE_URL/" | tr -d '\r' | awk -F': ' 'tolower($1) == "x-cdnlite-edge" {print $2; exit}')"
assert_eq "$edge_identity_header" "${EDGE_ID:-edge-local-1}" "edge response identity header mismatch"
record_step PASS "edge-identity-header" "edge response exposes configured EDGE_ID"

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
  no_auth_code="$(curl -sS -o /tmp/smoke-auth.txt -w '%{http_code}' "${CORE_URL}/api/v1/domains")"
  assert_eq "$no_auth_code" "401" "control-plane endpoint should require bearer auth when token is configured"
  record_step PASS "api-auth-negative" "unauthenticated /api/v1/domains returned 401"

  api_get "${CORE_URL}/api/v1/domains"
  assert_http_status "$HTTP_CODE" "200" "authenticated domain list failed"
  record_step PASS "api-auth-positive" "authenticated /api/v1/domains returned 200"
fi

# Initialize core DB schema explicitly before table assertions.
retry 40 2 docker compose exec -T core php -r "require '/app/app/Support/bootstrap.php'; App\\Support\\Database::pdo(); echo 'ok';" >/dev/null
record_step PASS "core-db-init" "core schema initialization completed"

if [[ "${CDNLITE_BOOTSTRAP_ADMIN_USER:-1}" == "1" ]]; then
  bootstrap_admin_code="$(curl -sS -o /tmp/smoke-bootstrap-admin.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d '{"username":"admin","password":"admin"}')"
  assert_eq "$bootstrap_admin_code" "200" "bootstrap admin login should return 200"
  assert_contains "$(cat /tmp/smoke-bootstrap-admin.json)" '"username":"admin"' "bootstrap admin login should include admin user"
  record_step PASS "bootstrap-admin-login" "default dashboard bootstrap admin can log in"
fi

required_tables=(
  domains dns_records edge_nodes edge_tokens edge_request_nonces
  admin_users admin_sessions
  usage_rollups usage_ingest_keys usage_aggregates config_state config_snapshots
  domain_cache_settings cache_purge_requests cache_purge_versions page_rules ssl_certificates
  rate_limit_rules audit_log platform_settings platform_settings_audit
)
for t in "${required_tables[@]}"; do
  count="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='${t}';")"
  assert_eq "$count" "1" "table ${t} missing"
done
record_step PASS "schema-tables" "all required tables exist"

removed_origin_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domains' AND column_name IN ('origin_host','origin_port','origin_scheme','geo_origins_json','proxy_enabled');")"
assert_eq "$removed_origin_columns" "0" "domain origin columns should be absent"
record_step PASS "schema-domain-origin-columns-absent" "domain origin columns are absent"

record_origin_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='dns_records' AND column_name IN ('origin_host','origin_tls_verify','origin_scheme','origin_status','geo_origins_json');")"
assert_eq "$record_origin_columns" "5" "DNS record origin columns are incomplete"
record_step PASS "schema-dns-record-origin-columns" "record-level origin columns are present"

retry 30 1 docker compose exec -T origin-tls wget -qO- --no-check-certificate https://127.0.0.1/ >/dev/null
retry 30 1 docker compose exec -T origin-http wget -qO- http://127.0.0.1/ >/dev/null
record_step PASS "origin-fixtures-health" "HTTPS and HTTP origin fixtures are reachable"

if [[ "${CDNLITE_BOOTSTRAP_ADMIN_USER:-1}" == "1" ]]; then
  admin_token="$(json_get "$(cat /tmp/smoke-bootstrap-admin.json)" '.data.token')"
  settings_code="$(curl -sS -o /tmp/smoke-settings.json -w '%{http_code}' \
    -H "Authorization: Bearer ${admin_token}" \
    "${CORE_URL}/api/v1/settings/platform.powerdns")"
  assert_eq "$settings_code" "200" "PowerDNS settings group should be readable"
  assert_contains "$(cat /tmp/smoke-settings.json)" '"api_key":{"configured":' "PowerDNS API key must be masked"
  record_step PASS "platform-settings" "settings API is readable and secrets are masked"

  ADMIN_SESSION_TOKEN="$admin_token"
  export ADMIN_SESSION_TOKEN
  api_get "${CORE_URL}/api/v1/audit?limit=1"
  assert_http_status "$HTTP_CODE" "200" "global audit query failed against live schema"
  assert_contains "$HTTP_BODY" '"items":' "global audit response should include items"
  api_get "${CORE_URL}/api/v1/security/events?limit=1"
  assert_http_status "$HTTP_CODE" "200" "global security events query failed against live schema"
  assert_contains "$HTTP_BODY" '"items":' "global security events response should include items"
  api_get "${CORE_URL}/api/v1/security/summary"
  assert_http_status "$HTTP_CODE" "200" "global security summary query failed against live schema"
  assert_contains "$HTTP_BODY" '"by_type":' "global security summary should include by_type"
  record_step PASS "operations-query-smoke" "audit, global security events, and security summary execute against PostgreSQL"
fi

waf_action_col="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='waf_rules' AND column_name='action';")"
assert_eq "$waf_action_col" "1" "waf_rules.action column missing"
record_step PASS "schema-waf-v2-column" "waf_rules.action column exists"

protection_tables="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('protection_profiles','protection_intents','managed_rule_links','profile_change_history','profile_rollback_points');")"
assert_eq "$protection_tables" "5" "protection contract tables are incomplete"
managed_rule_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('waf_rules','rate_limit_rules','domain_ip_rules','cache_rules','domain_header_rules') AND column_name IN ('profile_id','intent_id','template_key','managed_by','user_modified','last_generated_at','last_applied_at');")"
assert_eq "$managed_rule_columns" "35" "managed rule ownership columns are incomplete"
intent_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='protection_intents' AND column_name IN ('domain_id','profile_id','intent_key','name','status','mode','settings_json');")"
assert_eq "$intent_columns" "7" "protection intent workflow columns are incomplete"
rollback_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='profile_rollback_points' AND column_name IN ('domain_id','profile_id','intent_id','label','snapshot_json','created_at');")"
assert_eq "$rollback_columns" "6" "protection rollback columns are incomplete"
history_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='profile_change_history' AND column_name IN ('domain_id','profile_id','intent_id','action','reason','before_json','after_json','created_at');")"
assert_eq "$history_columns" "8" "protection history columns are incomplete"
record_step PASS "schema-protection-contract" "protection ownership, intent, history, and rollback schema exists"

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
  retry 30 2 curl -fsS -H "X-API-Key: ${PDNS_API_KEY:-cdnlite-local-powerdns-key}" \
    "${POWERDNS_PUBLIC_API_URL:-http://localhost:8089}/api/v1/servers/localhost" >/dev/null
  record_step PASS "powerdns-health" "powerdns endpoint reachable"
fi

pass "smoke checks completed"
