#!/bin/sh
set -eu

touch "$EDGE_CONFIG_PATH"
touch "$METRIC_PATH"

# Keep edge readiness healthy from boot: /ready requires valid JSON config.
if [ ! -s "$EDGE_CONFIG_PATH" ]; then
  printf '%s\n' '{"version":0,"hosts":{}}' >"$EDGE_CONFIG_PATH"
fi

if [ "${EDGE_AGENT_IDLE:-0}" = "1" ]; then
  tail -f /dev/null
fi

/agent/register.sh || true
/agent/pull_config.sh || true

while true; do
  /agent/heartbeat.sh || true
  /agent/pull_config.sh || true
  /agent/push_metrics.sh || true
  sleep 10
done
