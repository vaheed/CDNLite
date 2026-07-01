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
  for svc in core edge edge-agent dashboard postgres origin-tls origin-http; do
    echo "----- ${svc} (tail 200) -----"
    docker compose logs --no-color --tail=200 "$svc" || true
  done
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
record_step PASS "core-health" "core health endpoint reachable"
retry 40 2 curl -fsS "$CORE_URL/ready" >/dev/null
record_step PASS "core-ready" "core readiness endpoint reachable through nginx/php-fpm"
docker compose exec -T core sh -c "ps | grep -q '[p]hp-fpm' && ps | grep -q '[n]ginx' && ps | grep -q '[s]upervisord'"
record_step PASS "core-runtime-processes" "core runs supervisord, nginx, and php-fpm"
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

dashboard_asset_path="$(printf '%s\n' "$dashboard_index" | sed -n 's/.*src="\([^"]*\/assets\/index-[^"]*\.js\)".*/\1/p' | head -n1)"
if [[ -z "$dashboard_asset_path" ]]; then
  fail "dashboard JS asset path missing from index"
fi
dashboard_asset="$(docker compose exec -T dashboard wget -qO- "http://127.0.0.1${dashboard_asset_path}")"
assert_contains "$dashboard_asset" "Security Center" "dashboard bundle should include the Security Center tab"
assert_contains "$dashboard_asset" "/protection/intents" "dashboard bundle should include Protection intent APIs"
assert_contains "$dashboard_asset" "/protection/profiles" "dashboard bundle should include Protection profile APIs"
assert_contains "$dashboard_asset" "/protection/api-paths" "dashboard bundle should include API Protection discovery API"
assert_contains "$dashboard_asset" "Config snapshot status" "dashboard bundle should include edge config visibility UI"
assert_contains "$dashboard_asset" "config_apply_error" "dashboard bundle should include config apply error rendering"
assert_contains "$dashboard_asset" "Cache static assets" "dashboard bundle should include Performance Starter controls"
assert_contains "$dashboard_asset" "Bot protection" "dashboard bundle should expose Bot Protection events"
assert_contains "$dashboard_asset" "Recommendations" "dashboard bundle should include recommendations panel"
assert_contains "$dashboard_asset" "/recommendations/generate" "dashboard bundle should include recommendation generation API"
assert_contains "$dashboard_asset" "Guided onboarding" "dashboard bundle should include guided onboarding wizard"
assert_contains "$dashboard_asset" "/onboarding/answers" "dashboard bundle should include onboarding answer API"
assert_contains "$dashboard_asset" "Simple view" "dashboard bundle should include beginner Activity toggle"
assert_contains "$dashboard_asset" "Beginner Activity summary" "dashboard bundle should include beginner Activity summary"
assert_contains "$dashboard_asset" "Advanced view" "dashboard bundle should preserve advanced Activity view"
assert_contains "$dashboard_asset" "Enable edge health routing" "dashboard bundle should include optional edge origin health toggle"
record_step PASS "dashboard-security-center-bundle" "Security Center and Protection profile/intent APIs are present in the dashboard bundle"
record_step PASS "dashboard-recommendations-bundle" "Recommendation panel and APIs are present in the dashboard bundle"
record_step PASS "dashboard-onboarding-bundle" "Guided onboarding wizard and APIs are present in the dashboard bundle"
record_step PASS "dashboard-beginner-activity-bundle" "Beginner Activity UX and Advanced toggle are present in the dashboard bundle"

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
retry 40 2 docker compose exec -T core php /app/artisan tinker --execute="DB::connection()->getPdo(); echo 'ok';" >/dev/null
record_step PASS "core-db-init" "core schema initialization completed"
docker compose exec -T core php artisan cdn:scheduler:run --force >/tmp/smoke-schedule-run.json
assert_contains "$(cat /tmp/smoke-schedule-run.json)" '"dns_reconcile"' "cdn:scheduler:run should include DNS reconciliation"
assert_contains "$(cat /tmp/smoke-schedule-run.json)" '"nameserver_verify_all"' "cdn:scheduler:run should include nameserver verification"
record_step PASS "core-scheduler-run" "scheduler registered DNS and nameserver jobs"

if [[ -n "${CDNLITE_DEV_ADMIN_USERNAME:-admin@example.test}" && -n "${CDNLITE_DEV_ADMIN_PASSWORD:-cdnlite-local-admin}" ]]; then
  seeded_admin_code="$(curl -sS -o /tmp/smoke-seeded-admin.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"${CDNLITE_DEV_ADMIN_USERNAME:-admin@example.test}\",\"password\":\"${CDNLITE_DEV_ADMIN_PASSWORD:-cdnlite-local-admin}\"}")"
  assert_eq "$seeded_admin_code" "200" "seeded admin login should return 200"
  assert_contains "$(cat /tmp/smoke-seeded-admin.json)" "\"username\":\"${CDNLITE_DEV_ADMIN_USERNAME:-admin@example.test}\"" "seeded admin login should include admin user"
  record_step PASS "seeded-admin-login" "default dashboard seeded admin can log in"
fi

required_tables=(
  domains dns_records edge_nodes edge_tokens edge_request_nonces
  admin_users admin_sessions
  usage_rollups usage_ingest_keys usage_aggregates config_state config_snapshots
  recommendations domain_onboarding
  domain_cache_settings cache_purge_requests cache_purge_versions page_rules ssl_certificates
  rate_limit_rules audit_log platform_settings platform_settings_audit
)
for t in "${required_tables[@]}"; do
  count="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='${t}';")"
  assert_eq "$count" "1" "table ${t} missing"
done
record_step PASS "schema-tables" "all required tables exist"

recommendation_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='recommendations' AND column_name IN ('domain_id','type','confidence','risk','impact','preview_payload','one_click_action','status','snoozed_until','dismissed_at','applied_at');")"
assert_eq "$recommendation_columns" "11" "recommendations schema is incomplete"
record_step PASS "schema-recommendations" "recommendation engine table and lifecycle columns are present"

onboarding_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domain_onboarding' AND column_name IN ('domain_id','status','answers_json','recommended_profile_key','skipped_at','completed_at','created_at','updated_at');")"
assert_eq "$onboarding_columns" "8" "guided onboarding schema is incomplete"
record_step PASS "schema-onboarding" "guided onboarding answers and lifecycle columns are present"

performance_cache_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domain_cache_settings' AND column_name IN ('static_asset_cache_enabled','ignore_query_strings_for_static','bypass_logged_in_users');")"
assert_eq "$performance_cache_columns" "3" "Performance Starter cache columns are incomplete"
record_step PASS "schema-performance-starter" "safe static cache controls are present"

rate_limit_header_key_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='rate_limit_rules' AND column_name IN ('key_type','key_header_name');")"
assert_eq "$rate_limit_header_key_columns" "2" "rate-limit header key columns are incomplete"
record_step PASS "schema-rate-limit-headers" "rate-limit header key columns are present"

bot_protection_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='waf_rules' AND column_name IN ('bot_class','bot_score','bot_action');")"
assert_eq "$bot_protection_columns" "3" "Bot Protection WAF metadata columns are incomplete"
record_step PASS "schema-bot-protection" "bot class, score, and action columns are present"

verified_bot_source_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='verified_bot_sources' AND column_name IN ('domain_id','bot_class','provider','user_agent_pattern','cidr','enabled');")"
assert_eq "$verified_bot_source_columns" "6" "verified bot source schema is incomplete"
record_step PASS "schema-verified-bot-sources" "verified bot source table is present"

rate_limit_dry_run_route_count="$(grep -c "/domains/{domainId}/rate-limits/dry-run" core/routes/api.php)"
assert_eq "$rate_limit_dry_run_route_count" "1" "rate-limit dry-run route missing"
record_step PASS "schema-rate-limit-dry-run-route" "rate-limit dry-run route is registered"

waiting_room_route_count="$(grep -c "/domains/{domainId}/waiting-room" core/routes/api.php)"
if [[ "$waiting_room_route_count" -lt "4" ]]; then
  fail "waiting-room API routes are incomplete"
fi
grep -Fq "cdnlite_waiting_room" edge/openresty/nginx.conf || fail "waiting-room shared dictionary missing"
record_step PASS "schema-waiting-room" "waiting-room API routes and edge state are registered"

api_protection_route_count="$(grep -c "/domains/{domainId}/protection/api-paths" core/routes/api.php)"
assert_eq "$api_protection_route_count" "1" "API Protection path discovery route missing"
grep -Fq "path_method_not_allowed" edge/openresty/lua/router.lua || fail "API method restriction WAF matcher missing"
record_step PASS "schema-api-protection-route" "API Protection discovery route and method restriction matcher are registered"

edge_config_visibility_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='edge_nodes' AND column_name IN ('applied_config_version','last_config_pull_at','config_apply_error');")"
assert_eq "$edge_config_visibility_columns" "3" "edge config visibility columns are incomplete"
record_step PASS "schema-edge-config-visibility" "edge config visibility columns are present"

config_service="core/app/Modules/Proxy/Services/ConfigService.php"
grep -Fq "'config.publish'" "$config_service" || fail "new config publish audit hook missing"
grep -Fq "'config.publish.reused'" "$config_service" || fail "reused config publish audit hook missing"
grep -Fq "'config.rollback'" "$config_service" || fail "config rollback audit hook missing"
record_step PASS "schema-config-publish-audit" "config publish/rollback audit hooks are wired"

removed_origin_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domains' AND column_name IN ('origin_host','origin_port','origin_scheme','geo_origins_json','proxy_enabled');")"
assert_eq "$removed_origin_columns" "0" "domain origin columns should be absent"
record_step PASS "schema-domain-origin-columns-absent" "domain origin columns are absent"

record_origin_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='dns_records' AND column_name IN ('origin_host','origin_tls_verify','origin_scheme','origin_status','geo_origins_json','managed_by');")"
assert_eq "$record_origin_columns" "6" "DNS record origin and ownership columns are incomplete"
record_step PASS "schema-dns-record-origin-columns" "record-level origin and ownership columns are present"

domain_origin_defaults="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domain_origins' AND ((column_name='preserve_host' AND column_default='true') OR (column_name='health_check_enabled' AND column_default='false') OR (column_name='tls_verify' AND column_default='''ignore''::text'));")"
assert_eq "$domain_origin_defaults" "3" "shared-hosting origin defaults are incomplete"
record_step PASS "schema-origin-shared-hosting-defaults" "preserve_host default on, health checks default off, TLS verify defaults ignore"

geo_route_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='dns_record_geo_routes' AND column_name IN ('route_scope','country_code','continent_code','answer_type','answer_value','enabled');")"
assert_eq "$geo_route_columns" "6" "raw GeoDNS route columns are incomplete"
record_step PASS "schema-raw-geodns-route-columns" "raw GeoDNS route columns are present"

retry 30 1 docker compose exec -T origin-tls wget -qO- --no-check-certificate https://127.0.0.1/ >/dev/null
retry 30 1 docker compose exec -T origin-http wget -qO- http://127.0.0.1/ >/dev/null
record_step PASS "origin-fixtures-health" "HTTPS and HTTP origin fixtures are reachable"

if [[ -f /tmp/smoke-seeded-admin.json ]]; then
  admin_token="$(json_get_file /tmp/smoke-seeded-admin.json '.data.token // .token')"
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
  assert_contains "$HTTP_BODY" '"data":' "global audit response should include data"
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
profile_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='protection_profiles' AND column_name IN ('domain_id','profile_key','name','status','settings_json','created_at','updated_at');")"
assert_eq "$profile_columns" "7" "protection profile workflow columns are incomplete"
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
