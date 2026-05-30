#!/bin/sh
set -eu

apk add --no-cache curl openssl coreutils >/dev/null

touch "$EDGE_CONFIG_PATH"
touch "$METRIC_PATH"

/agent/register.sh || true
/agent/pull_config.sh || true

while true; do
  /agent/heartbeat.sh || true
  /agent/pull_config.sh || true
  /agent/push_metrics.sh || true
  sleep 10
done
