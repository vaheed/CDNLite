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

if [[ "$SCENARIO" != "phase1-reporting-foundation" ]]; then
  fail "unknown stress scenario: ${SCENARIO}"
fi

REPORT_MD="${REPORT_MD:-ci/reports/stress-platform-report.md}"
REPORT_JSON="${REPORT_JSON:-ci/reports/stress-platform-report.json}"
REPORT_JUNIT="${REPORT_JUNIT:-ci/reports/stress-platform-junit.xml}"
CI_ENV_NAME="${CI_ENV_NAME:-stress-platform}"
init_report
trap 'write_reports' EXIT

wait_for_postgres
retry 30 1 db_query "SELECT 1;" >/dev/null
record_step PASS "postgres-ready" "PostgreSQL accepted stress-platform connection"

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
