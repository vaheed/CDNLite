#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
POWERDNS_API_URL="${POWERDNS_API_URL:-http://localhost:8089}"
CI_ENV_NAME="${CI_ENV_NAME:-smoke}"
export CORE_URL EDGE_URL POWERDNS_API_URL CI_ENV_NAME

init_report
trap 'collect_diagnostics; write_reports' EXIT

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
record_step PASS "core-health" "core health endpoint reachable"
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
record_step PASS "edge-health" "edge health endpoint reachable"

retry 40 2 db_query "SELECT 1;" >/dev/null
record_step PASS "postgres-connectivity" "postgres reachable"

required_tables=(
  sites dns_records edge_nodes edge_tokens edge_request_nonces
  usage_rollups usage_ingest_keys usage_aggregates config_state config_snapshots
)
for t in "${required_tables[@]}"; do
  count="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='${t}';")"
  assert_eq "$count" "1" "table ${t} missing"
done
record_step PASS "schema-tables" "all required tables exist"

docker compose ps edge | grep -q "Up"
record_step PASS "edge-container-running" "edge service is up"

docker compose exec -T edge-agent sh -lc 'test -e "${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}"'
record_step PASS "edge-config-path" "edge config path exists"

if [[ "${POWERDNS_ENABLED:-0}" == "1" ]]; then
  retry 30 2 curl -fsS "${POWERDNS_API_URL}/health" >/dev/null
  record_step PASS "powerdns-health" "powerdns endpoint reachable"
fi

pass "smoke checks completed"
