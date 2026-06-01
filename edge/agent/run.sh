#!/bin/sh
set -eu

touch "$EDGE_CONFIG_PATH"
touch "$METRIC_PATH"

if [ "${EDGE_AGENT_IDLE:-0}" = "1" ]; then
  tail -f /dev/null
fi

/agent/register.sh || true
/agent/init_config.sh || true

while true; do
  /agent/heartbeat.sh || true
  /agent/pull_config.sh || true
  /agent/push_metrics.sh || true
  sleep 10
done
