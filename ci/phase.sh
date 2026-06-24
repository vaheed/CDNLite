#!/usr/bin/env bash
set -Eeuo pipefail

source "$(dirname "$0")/lib.sh"

PHASE="${1:-}"
PROFILE="pr"
CLEAN="0"

if [[ -z "$PHASE" ]]; then
  fail "usage: ./ci/phase.sh 01 --profile pr|full|release [--clean]"
fi
shift || true

while [[ $# -gt 0 ]]; do
  case "$1" in
    --profile)
      PROFILE="${2:-}"
      shift 2
      ;;
    --clean)
      CLEAN="1"
      shift
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
done

if [[ "$PHASE" != "01" ]]; then
  fail "phase ${PHASE} is not registered yet"
fi
if [[ "$PROFILE" != "pr" && "$PROFILE" != "full" && "$PROFILE" != "release" ]]; then
  fail "profile must be pr, full, or release"
fi

MANIFEST="ci/phases/phase-${PHASE}.yml" # phase-01.yml is the first registered manifest.
[[ -f "$MANIFEST" ]] || fail "missing phase manifest: ${MANIFEST}"

if [[ "$PROFILE" == "full" && "$CLEAN" != "1" ]]; then
  fail "full profile requires --clean so evidence is tied to a disposable run"
fi
if [[ "$PROFILE" == "release" && "${CDNLITE_DISPOSABLE_ENV:-0}" != "1" ]]; then
  fail "release profile requires CDNLITE_DISPOSABLE_ENV=1"
fi

REPORT_MD="ci/reports/phase-${PHASE}-report.md"
REPORT_JSON="ci/reports/phase-${PHASE}-report.json"
REPORT_JUNIT="ci/reports/phase-${PHASE}-junit.xml"
CI_ENV_NAME="phase-${PHASE}-${PROFILE}"
export REPORT_MD REPORT_JSON REPORT_JUNIT CI_ENV_NAME
init_report

run_step() {
  local name="$1"
  shift
  log "phase ${PHASE}/${PROFILE}: ${name}"
  "$@"
  record_step PASS "$name" "$*"
}

trap 'write_reports' EXIT

run_step "manifest-present" test -s "$MANIFEST"
run_step "compose-config" docker compose config --quiet
run_step "php-syntax" bash -c "find core -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"
run_step "phase-contract-tests" pytest -q core/tests/test_phase1_reporting_foundation_contract.py
run_step "agent-shell-syntax" sh -n edge/agent/register.sh
run_step "phase-runner-syntax" bash -n ci/phase.sh
run_step "stress-platform-syntax" bash -n ci/stress-platform.sh

if [[ "$PROFILE" == "pr" ]]; then
  exit 0
fi

if [[ "$CLEAN" == "1" ]]; then
  run_step "clean-stack-down" docker compose down --volumes --remove-orphans
fi

run_step "stack-up" docker compose up -d --build
run_step "smoke" ./ci/smoke.sh
run_step "e2e" ./ci/e2e.sh
run_step "phase-stress" ./ci/stress-platform.sh --scenario phase1-reporting-foundation
run_step "post-stress-smoke" ./ci/smoke.sh
run_step "post-stress-e2e" ./ci/e2e.sh

if [[ "$PROFILE" == "release" ]]; then
  run_step "dns-stress" ./ci/stress-dns.sh
fi

run_step "docs-build" bash -c "cd docs && npm ci && npm run docs:build"
