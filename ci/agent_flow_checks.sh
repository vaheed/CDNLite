#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$TMP_DIR/bin" "$TMP_DIR/agent"

cat >"$TMP_DIR/bin/curl" <<'SH'
#!/bin/sh
out=""
url=""
data=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o)
      out="$2"
      shift 2
      ;;
    --data-binary)
      data="$2"
      shift 2
      ;;
    -X|-H)
      shift 2
      ;;
    -*)
      shift
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done

if [ "${MOCK_CURL_URL_FILE:-}" != "" ]; then
  printf '%s\n' "$url" > "$MOCK_CURL_URL_FILE"
fi
if [ "${MOCK_CURL_DATA_FILE:-}" != "" ]; then
  printf '%s\n' "$data" > "$MOCK_CURL_DATA_FILE"
fi

case "${MOCK_CURL_MODE:-}" in
  config_bad)
    printf 'not-json\n' > "$out"
    exit 0
    ;;
  config_not_modified)
    printf '{"not_modified":true,"version":1}\n' > "$out"
    exit 0
    ;;
  config_good)
    printf '{"version":2,"hosts":{}}\n' > "$out"
    exit 0
    ;;
  metrics_fail)
    exit 22
    ;;
  metrics_ok)
    exit 0
    ;;
esac

exit 1
SH
chmod +x "$TMP_DIR/bin/curl"

export PATH="$TMP_DIR/bin:$PATH"
export CORE_URL="http://core.test"
export EDGE_ID="edge-test"
export EDGE_TOKEN="edge-token"
export EDGE_CONFIG_PATH="$TMP_DIR/agent/config.json"
export METRIC_PATH="$TMP_DIR/agent/metrics.ndjson"
export MOCK_CURL_URL_FILE="$TMP_DIR/curl-url.txt"
export MOCK_CURL_DATA_FILE="$TMP_DIR/curl-data.txt"

assert_eq() {
  got="$1"
  expected="$2"
  message="$3"
  if [ "$got" != "$expected" ]; then
    printf 'FAIL: %s (expected=%s got=%s)\n' "$message" "$expected" "$got" >&2
    exit 1
  fi
}

assert_file_eq() {
  file="$1"
  expected="$2"
  message="$3"
  got="$(cat "$file")"
  assert_eq "$got" "$expected" "$message"
}

printf '{"version":1,"hosts":{"demo.local":{}}}\n' > "$EDGE_CONFIG_PATH"
before="$(cat "$EDGE_CONFIG_PATH")"
export MOCK_CURL_MODE="config_bad"
if sh "$ROOT/edge/agent/pull_config.sh" >/dev/null 2>"$TMP_DIR/pull-bad.err"; then
  printf 'FAIL: bad config pull unexpectedly succeeded\n' >&2
  exit 1
fi
assert_file_eq "$EDGE_CONFIG_PATH" "$before" "bad config response overwrote last-known-good config"

export MOCK_CURL_MODE="config_not_modified"
sh "$ROOT/edge/agent/pull_config.sh" >/dev/null 2>"$TMP_DIR/pull-not-modified.err"
assert_file_eq "$EDGE_CONFIG_PATH" "$before" "no-change config response modified local config"
case "$(cat "$MOCK_CURL_URL_FILE")" in
  *'/api/v1/edge/config?if_version=1') ;;
  *)
    printf 'FAIL: pull_config did not send if_version from current config\n' >&2
    exit 1
    ;;
esac

metric_line='{"ts":1,"site_id":"site-1","edge_node_id":"edge-1","requests_count":1,"bytes_in":2,"bytes_out":3,"status":200}'
printf '%s\n' "$metric_line" > "$METRIC_PATH"
export MOCK_CURL_MODE="metrics_fail"
if sh "$ROOT/edge/agent/push_metrics.sh" >/dev/null 2>"$TMP_DIR/metrics-fail.err"; then
  printf 'FAIL: failed metrics push unexpectedly succeeded\n' >&2
  exit 1
fi
assert_file_eq "$METRIC_PATH" "$metric_line" "failed metrics push deleted metrics"
if [ ! -s "${METRIC_PATH}.payload" ]; then
  printf 'FAIL: failed metrics push did not preserve payload\n' >&2
  exit 1
fi

export MOCK_CURL_MODE="metrics_ok"
sh "$ROOT/edge/agent/push_metrics.sh" >/dev/null 2>"$TMP_DIR/metrics-ok.err"
assert_file_eq "$METRIC_PATH" "" "successful metrics push did not clear metrics"
if [ -e "${METRIC_PATH}.payload" ]; then
  printf 'FAIL: successful metrics push left payload spool behind\n' >&2
  exit 1
fi

printf 'agent flow checks passed\n'
