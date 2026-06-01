#!/bin/sh
set -eu

. /agent/lib.sh

env_ok="ok"
core_ok="ok"
config_ok="ok"
metrics_ok="ok"
signing_ok="ok"

required_vars="CORE_URL EDGE_ID EDGE_TOKEN EDGE_CONFIG_PATH METRIC_PATH EDGE_HOSTNAME EDGE_REGION EDGE_VERSION"
for name in $required_vars; do
  eval "value=\${$name:-}"
  if [ "$value" = "" ]; then
    env_ok="fail"
    break
  fi
done

if ! curl -fsS --max-time 3 "${CORE_URL:-http://127.0.0.1}/health" >/dev/null 2>&1; then
  core_ok="fail"
fi

if ! python3 - "${EDGE_CONFIG_PATH:-/tmp/missing}" <<'PY' >/dev/null 2>&1
import json
import os
import sys
p = sys.argv[1]
if not os.path.exists(p) or os.path.getsize(p) == 0:
    raise SystemExit(0)
with open(p, "r", encoding="utf-8") as fh:
    json.load(fh)
PY
then
  config_ok="fail"
fi

if ! touch "${METRIC_PATH:-/tmp/cdnlite-metrics-test}" 2>/dev/null; then
  metrics_ok="fail"
fi

if ! command -v openssl >/dev/null 2>&1; then
  signing_ok="fail"
fi

overall=true
for v in "$env_ok" "$core_ok" "$config_ok" "$metrics_ok" "$signing_ok"; do
  if [ "$v" != "ok" ]; then
    overall=false
    break
  fi
done

printf '{"ok":%s,"checks":{"env":"%s","core":"%s","config":"%s","metrics":"%s","signing":"%s"}}\n' \
  "$overall" "$env_ok" "$core_ok" "$config_ok" "$metrics_ok" "$signing_ok"
