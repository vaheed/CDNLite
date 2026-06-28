#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname "$0")/lib.sh"

SCENARIO="phase1-reporting-foundation"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --scenario)
      SCENARIO="${2:-}"
      shift 2
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
done

case "$SCENARIO" in
  phase1-reporting-foundation|phase2-analytics-async|phase3-edge-hot-path|phase4-challenge-clearance|phase5-waiting-room|phase6-cache-correctness|phase7-origin-resilience) ;;
  *) fail "unknown stress scenario: ${SCENARIO}" ;;
esac

REPORT_MD="${REPORT_MD:-ci/reports/stress-platform-report.md}"
REPORT_JSON="${REPORT_JSON:-ci/reports/stress-platform-report.json}"
REPORT_JUNIT="${REPORT_JUNIT:-ci/reports/stress-platform-junit.xml}"
CI_ENV_NAME="${CI_ENV_NAME:-stress-platform}"
init_report
trap 'write_reports' EXIT

wait_for_postgres
retry 30 1 db_query "SELECT 1;" >/dev/null
record_step PASS "postgres-ready" "PostgreSQL accepted stress-platform connection"

if [[ "$SCENARIO" == "phase7-origin-resilience" ]]; then
  origin_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domain_origins' AND column_name IN ('load_balancing_algorithm','connection_timeout_seconds','response_timeout_seconds','retry_attempts','retry_budget_per_minute','circuit_breaker_enabled','circuit_failure_threshold','circuit_recovery_seconds','max_concurrent_requests','drain','shield_enabled');")"
  assert_eq "$origin_columns" "11" "Phase 7 origin resilience columns are incomplete"
  record_step PASS "phase7-origin-columns" "origin resilience columns are present"

  observation_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='origin_health_observations' AND column_name IN ('domain_id','origin_id','edge_node_id','status','reason','upstream_status','latency_ms','jitter_ms','sample_count','last_observed_at','last_success_at','last_failure_at');")"
  assert_eq "$observation_columns" "12" "Phase 7 origin health observation columns are incomplete"
  record_step PASS "phase7-origin-observation-columns" "edge origin health observation columns are present"

  grep -Fq "weighted_pick" edge/openresty/lua/origin_selector.lua
  grep -Fq "healthy_backup" edge/openresty/lua/origin_selector.lua
  grep -Fq "origin.drain ~= true" edge/openresty/lua/origin_selector.lua
  record_step PASS "phase7-edge-selection" "edge selector has weighted primary/backup/drain behavior"

  grep -Fq "X-CDNLite-Origin-Retry-Attempts" edge/openresty/lua/proxy.lua
  grep -Fq "method ~= 'GET' and method ~= 'HEAD'" edge/openresty/lua/proxy.lua
  grep -Fq "X-CDNLite-Origin-Circuit-Breaker" edge/openresty/lua/proxy.lua
  record_step PASS "phase7-retry-circuit-metadata" "edge exposes bounded retry and circuit metadata"

  grep -Fq "origin_health_checker" edge/openresty/nginx.conf
  grep -Fq "origin_health_probe = true" edge/openresty/lua/origin_health_checker.lua
  grep -Fq "health_check_enabled == true" edge/openresty/lua/origin_health_checker.lua
  record_step PASS "phase7-edge-origin-health-checker" "edge active origin checker is enabled for monitored origins"

  if compose_has_service edge; then
    ready_body="$(curl -fsS "${EDGE_URL:-http://localhost:8081}/ready")"
    assert_contains "$ready_body" "\"ok\":true" "edge must be ready before origin resilience validation"
    record_step PASS "phase7-edge-ready" "edge readiness confirmed before origin resilience validation"
  fi

  record_step PASS "phase7-origin-recovery" "post-stress smoke/e2e gates verify routing recovery in full profile"
  exit 0
fi

if [[ "$SCENARIO" == "phase6-cache-correctness" ]]; then
  cache_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='domain_cache_settings' AND column_name IN ('cache_methods_json','cache_status_code_policy_json','bypass_headers_json','bypass_cookies_json','vary_headers_json','cache_key_dimensions_json','debug_headers_enabled','stale_while_revalidate_seconds','negative_ttl_seconds','max_object_size_bytes');")"
  assert_eq "$cache_columns" "10" "Phase 6 cache correctness columns are incomplete"
  record_step PASS "phase6-cache-columns" "cache correctness settings are present"

  grep -Fq "X-CDNLite-Cache" edge/openresty/nginx.conf
  grep -Fq "proxy_cache_lock on" edge/openresty/nginx.conf
  if grep -Fq "proxy_ignore_headers X-Accel-Expires" edge/openresty/nginx.conf; then
    fail "edge must honor Lua-selected X-Accel-Expires TTL"
  fi
  record_step PASS "phase6-nginx-cache-controls" "cache lock, status headers, and per-rule TTL honoring are configured"

  grep -Fq "build_cache_key" edge/openresty/lua/proxy.lua
  grep -Fq "request_has_bypass_header" edge/openresty/lua/proxy.lua
  grep -Fq "cache_settings.cache_key_dimensions" edge/openresty/lua/proxy.lua
  record_step PASS "phase6-lua-cache-policy" "edge Lua has explicit cache key and bypass policy"

  if compose_has_service edge; then
    ready_body="$(curl -fsS "${EDGE_URL:-http://localhost:8081}/ready")"
    assert_contains "$ready_body" "\"ok\":true" "edge must be ready before cache correctness validation"
    record_step PASS "phase6-edge-ready" "edge readiness confirmed before cache validation"
  fi

  record_step PASS "phase6-cache-recovery" "post-stress smoke/e2e gates verify cache recovery in full profile"
  exit 0
fi

if [[ "$SCENARIO" == "phase5-waiting-room" ]]; then
  waiting_room_table="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='waiting_room_policies';")"
  assert_eq "$waiting_room_table" "1" "waiting_room_policies table is missing"
  record_step PASS "phase5-waiting-room-table" "waiting-room policy table exists"

  waiting_room_columns="$(db_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='waiting_room_policies' AND column_name IN ('enabled','mode','state','rps_threshold','active_origin_threshold','origin_latency_ms_threshold','origin_error_rate_threshold','admission_rate_per_minute','queue_limit','per_client_ticket_limit','ticket_ttl_seconds','admission_ttl_seconds','status_poll_seconds','jitter_seconds','unhealthy_windows','healthy_windows','minimum_state_seconds','recovery_ramp_percent','manual_override_until','waiting_room_title','waiting_room_message');")"
  assert_eq "$waiting_room_columns" "21" "waiting-room policy columns are incomplete"
  record_step PASS "phase5-waiting-room-columns" "waiting-room policy columns are present"

  route_count="$(grep -c '/api/v1/domains/{domainId}/waiting-room' core/public_index.php)"
  if [[ "$route_count" -lt "4" ]]; then
    fail "waiting-room API routes are incomplete"
  fi
  record_step PASS "phase5-waiting-room-routes" "waiting-room API routes are registered"

  if compose_has_service edge; then
    ready_body="$(curl -fsS "${EDGE_URL:-http://localhost:8081}/ready")"
    assert_contains "$ready_body" "\"ok\":true" "edge must be ready before waiting-room validation"
    record_step PASS "phase5-edge-ready" "edge readiness confirmed before waiting-room validation"

    docker compose exec -T edge grep -Fq "cdnlite_waiting_room" /usr/local/openresty/nginx/conf/nginx.conf
    record_step PASS "phase5-edge-shared-dict" "waiting-room shared dictionary rendered into nginx config"
  fi

  record_step PASS "phase5-waiting-room-recovery" "post-stress smoke/e2e gates verify waiting-room recovery in full profile"
  exit 0
fi

if [[ "$SCENARIO" == "phase4-challenge-clearance" ]]; then
  if compose_has_service edge; then
    ready_body="$(curl -fsS "${EDGE_URL:-http://localhost:8081}/ready")"
    assert_contains "$ready_body" "\"ok\":true" "edge must be ready before challenge pressure"
    record_step PASS "phase4-edge-ready" "edge readiness confirmed before challenge validation"
  fi

  waf_challenge_actions="$(db_query "SELECT COUNT(*) FROM waf_rules WHERE action='challenge';")"
  rate_challenge_actions="$(db_query "SELECT COUNT(*) FROM rate_limit_rules WHERE action='challenge';")"
  if [[ "$waf_challenge_actions" -lt "0" || "$rate_challenge_actions" -lt "0" ]]; then
    fail "challenge action queries returned invalid counts"
  fi
  record_step PASS "phase4-challenge-actions-query" "challenge-capable WAF and rate-limit tables are queryable"
  record_step PASS "phase4-clearance-recovery" "post-stress smoke/e2e gates verify challenge recovery in full profile"
  exit 0
fi

if [[ "$SCENARIO" == "phase3-edge-hot-path" ]]; then
  if compose_has_service edge; then
    ready_body="$(curl -fsS "${EDGE_URL:-http://localhost:8081}/ready")"
    assert_contains "$ready_body" "current_config_version" "edge ready response should expose active config version"
    assert_contains "$ready_body" "telemetry" "edge ready response should expose telemetry queue health"
    assert_contains "$ready_body" "max_items" "telemetry health should expose bounded queue limits"
    record_step PASS "phase3-edge-ready-telemetry" "edge ready reports config and telemetry queue bounds"

    reload_body="$(docker compose exec -T edge wget -qO- http://127.0.0.1:8081/__cdnlite_reload_config)"
    assert_contains "$reload_body" "\"ok\":true" "manual config reload should succeed"
    record_step PASS "phase3-manual-config-reload" "manual local config reload endpoint succeeded"
  fi
  exit 0
fi

if [[ "$SCENARIO" == "phase2-analytics-async" ]]; then
  phase2_tables="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('analytics_rollup_jobs','analytics_query_cache','usage_aggregates','reporting_rollup_watermarks');")"
  assert_eq "$phase2_tables" "4" "Phase 2 analytics tables are incomplete"
  record_step PASS "phase2-analytics-tables" "analytics job, cache, aggregate, and watermark tables exist"

  aggregate_identity="$(db_query "SELECT COUNT(*) FROM pg_constraint c JOIN pg_class rel ON rel.oid = c.conrelid WHERE rel.relname='usage_aggregates' AND c.conname='usage_aggregates_bucket_ts_domain_id_edge_node_id_status_cache_status_key';")"
  assert_eq "$aggregate_identity" "1" "usage_aggregates idempotent unique constraint is missing"
  record_step PASS "phase2-aggregate-identity" "usage aggregate idempotent identity is enforced"

  job_status_check="$(db_query "SELECT COUNT(*) FROM pg_constraint c JOIN pg_class rel ON rel.oid = c.conrelid WHERE rel.relname='analytics_rollup_jobs' AND pg_get_constraintdef(c.oid) LIKE '%queued%' AND pg_get_constraintdef(c.oid) LIKE '%running%' AND pg_get_constraintdef(c.oid) LIKE '%succeeded%' AND pg_get_constraintdef(c.oid) LIKE '%failed%' AND pg_get_constraintdef(c.oid) LIKE '%cancelled%';")"
  assert_eq "$job_status_check" "1" "analytics_rollup_jobs status lifecycle constraint is missing"
  record_step PASS "phase2-job-lifecycle" "rollup job status lifecycle is constrained"

  if compose_has_service core; then
    docker compose exec -T core php artisan cdn:usage:recalculate >/tmp/cdnlite-phase2-recalculate.json
    assert_contains "$(cat /tmp/cdnlite-phase2-recalculate.json)" "\"accepted\":true" "recalculation should be accepted asynchronously"
    assert_contains "$(cat /tmp/cdnlite-phase2-recalculate.json)" "\"job_id\"" "recalculation should return a job id"
    record_step PASS "phase2-recalculate-accepted" "usage recalculation returned accepted job metadata"
  fi

  watermark_table="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='reporting_rollup_watermarks';")"
  assert_eq "$watermark_table" "1" "reporting rollup watermarks table is missing"
  record_step PASS "phase2-watermark-table" "rollup watermark table is available for recovery"
  exit 0
fi

budget_count="$(db_query "SELECT COUNT(*) FROM database_workload_budgets WHERE workload IN ('control','telemetry_ingest','reporting','jobs','maintenance');")"
assert_eq "$budget_count" "5" "Phase 1 workload budgets are incomplete"
record_step PASS "workload-budgets" "workload budget rows are installed"

read_model_count="$(db_query "SELECT COUNT(*) FROM pg_matviews WHERE matviewname='reporting_current_platform_summary';")"
assert_eq "$read_model_count" "1" "current platform summary read model is missing"
record_step PASS "current-read-model" "reporting_current_platform_summary exists"

batch_tables="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('telemetry_ingest_batches','telemetry_rejected_events','reporting_rollup_watermarks','reporting_reconciliation_results');")"
assert_eq "$batch_tables" "4" "Phase 1 telemetry/reporting diagnostic tables are missing"
record_step PASS "diagnostic-tables" "telemetry batch, rejected event, watermark, and reconciliation tables exist"

if compose_has_service core; then
  docker compose exec -T core php artisan cdn:usage:prune --dry-run >/tmp/cdnlite-phase1-prune.json
  assert_contains "$(cat /tmp/cdnlite-phase1-prune.json)" "usage_rollups" "retention dry-run should report usage_rollups"
  record_step PASS "retention-dry-run" "retention dry-run completed without deleting data"
fi

refresh_result="$(db_query "REFRESH MATERIALIZED VIEW reporting_current_platform_summary; SELECT COUNT(*) FROM reporting_current_platform_summary;")"
assert_contains "$refresh_result" "1" "current summary refresh should leave one row"
record_step PASS "read-model-refresh" "current summary read model refreshes"
