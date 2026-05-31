#!/usr/bin/env bash
set -Eeuo pipefail

REPORT_DIR="${REPORT_DIR:-ci/reports}"
REPORT_MD="${REPORT_MD:-$REPORT_DIR/e2e-report.md}"
REPORT_JSON="${REPORT_JSON:-$REPORT_DIR/e2e-report.json}"
REPORT_JUNIT="${REPORT_JUNIT:-$REPORT_DIR/e2e-junit.xml}"
STEP_LINES=()
STEP_JSON_ITEMS=()
TOTAL_STEPS=0
FAILED_STEPS=0

log() {
  printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

pass() {
  log "PASS: $*"
}

fail() {
  log "FAIL: $*"
  return 1
}

assert_eq() {
  local got="$1"
  local expected="$2"
  local msg="$3"
  [[ "$got" == "$expected" ]] || fail "$msg (expected='$expected' got='$got')"
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local msg="$3"
  [[ "$haystack" == *"$needle"* ]] || fail "$msg (missing '$needle')"
}

retry() {
  local tries="$1"
  local sleep_s="$2"
  shift 2
  local n=1
  until "$@"; do
    if [[ "$n" -ge "$tries" ]]; then
      return 1
    fi
    n=$((n + 1))
    sleep "$sleep_s"
  done
}

http_request() {
  local method="$1"
  local url="$2"
  local data="${3:-}"
  local extra_headers="${4:-}"
  local tmp
  tmp="$(mktemp)"
  local code
  if [[ -n "$data" ]]; then
    code="$(curl -sS -o "$tmp" -w '%{http_code}' -X "$method" "$url" \
      -H 'Content-Type: application/json' $extra_headers \
      --data "$data")"
  else
    code="$(curl -sS -o "$tmp" -w '%{http_code}' -X "$method" "$url" $extra_headers)"
  fi
  HTTP_CODE="$code"
  HTTP_BODY="$(cat "$tmp")"
  rm -f "$tmp"
}

api_get() { http_request GET "$1"; }
api_post() { http_request POST "$1" "$2"; }
api_patch() { http_request PATCH "$1" "$2"; }
api_delete() { http_request DELETE "$1"; }

assert_http_status() {
  local got="$1"
  local expected="$2"
  local msg="$3"
  [[ "$got" == "$expected" ]] || fail "$msg (expected $expected got $got body=${HTTP_BODY:-})"
}

json_get() {
  local input="$1"
  local expr="$2"
  jq -er "$expr" <<<"$input"
}

db_query() {
  local sql="$1"
  docker compose exec -T postgres psql -h 127.0.0.1 -p 5432 -U cdnlite -d cdnlite -t -A -c "$sql"
}

compose_has_service() {
  local name="$1"
  docker compose config --services 2>/dev/null | grep -qx "$name"
}

pdns_get() {
  local path="$1"
  curl -sS -H "X-API-Key: ${POWERDNS_API_KEY:-test-key}" "${POWERDNS_API_URL}${path}"
}

record_step() {
  local status="$1"
  local name="$2"
  local details="${3:-}"
  TOTAL_STEPS=$((TOTAL_STEPS + 1))
  if [[ "$status" != "PASS" ]]; then
    FAILED_STEPS=$((FAILED_STEPS + 1))
  fi
  STEP_LINES+=("| $status | $name | ${details//|/\\|} |")
  STEP_JSON_ITEMS+=("{\"status\":\"$status\",\"name\":\"$name\",\"details\":$(jq -Rn --arg v "$details" '$v')}")
}

init_report() {
  mkdir -p "$REPORT_DIR"
}

write_reports() {
  local ts sha job env_name
  ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  sha="${GITHUB_SHA:-local}"
  job="${GITHUB_JOB:-local}"
  env_name="${CI_ENV_NAME:-default}"

  {
    echo "# CDNLite Test Report"
    echo
    echo "- Time: $ts"
    echo "- Git SHA: $sha"
    echo "- Job: $job"
    echo "- Environment: $env_name"
    echo "- Core URL: ${CORE_URL:-http://localhost:8080}"
    echo "- Edge URL: ${EDGE_URL:-http://localhost:8081}"
    echo "- Total Steps: $TOTAL_STEPS"
    echo "- Failed Steps: $FAILED_STEPS"
    echo
    echo "| Status | Step | Details |"
    echo "|---|---|---|"
    printf '%s\n' "${STEP_LINES[@]}"
  } >"$REPORT_MD"

  {
    echo "{"
    echo "  \"time\": $(jq -Rn --arg v "$ts" '$v'),"
    echo "  \"git_sha\": $(jq -Rn --arg v "$sha" '$v'),"
    echo "  \"job\": $(jq -Rn --arg v "$job" '$v'),"
    echo "  \"environment\": $(jq -Rn --arg v "$env_name" '$v'),"
    echo "  \"total_steps\": $TOTAL_STEPS,"
    echo "  \"failed_steps\": $FAILED_STEPS,"
    echo "  \"steps\": [$(IFS=,; echo "${STEP_JSON_ITEMS[*]}")]"
    echo "}"
  } >"$REPORT_JSON"

  cat >"$REPORT_JUNIT" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<testsuite name="cdnlite-e2e" tests="$TOTAL_STEPS" failures="$FAILED_STEPS">
$(for line in "${STEP_JSON_ITEMS[@]}"; do
  name="$(jq -r '.name' <<<"$line")"
  status="$(jq -r '.status' <<<"$line")"
  details="$(jq -r '.details' <<<"$line")"
  if [[ "$status" == "PASS" ]]; then
    printf '<testcase name="%s"/>\n' "$name"
  else
    printf '<testcase name="%s"><failure message="%s"/></testcase>\n' "$name" "$details"
  fi
done)
</testsuite>
EOF
}

collect_diagnostics() {
  mkdir -p "$REPORT_DIR"
  docker compose ps >"$REPORT_DIR/compose-ps.txt" || true
  docker compose logs --no-color >"$REPORT_DIR/compose-logs.txt" || true
  for svc in core edge edge-agent postgres powerdns; do
    if compose_has_service "$svc"; then
      docker compose logs --no-color --tail=200 "$svc" >"$REPORT_DIR/${svc}-tail.log" || true
    fi
  done
}
