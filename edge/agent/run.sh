#!/bin/sh
set -eu

touch "$EDGE_CONFIG_PATH"
touch "$METRIC_PATH"
touch "${SECURITY_EVENT_PATH:-/var/lib/cdnlite/security-events.ndjson}"

if [ "${EDGE_AGENT_IDLE:-0}" = "1" ]; then
  tail -f /dev/null
fi

/agent/register.sh || true
/agent/init_config.sh || true

backoff=2
max_backoff="${EDGE_AGENT_MAX_BACKOFF_SECONDS:-60}"

while true; do
  ok=1
  /agent/heartbeat.sh || ok=0
  /agent/pull_config.sh || ok=0
  /agent/push_metrics.sh || ok=0
  /agent/push_security_events.sh || ok=0

  if [ "$ok" -eq 1 ]; then
    backoff=2
    sleep 10
    continue
  fi

  jitter="$(awk 'BEGIN{srand(); print int(rand()*3)}')"
  sleep_seconds=$((backoff + jitter))
  sleep "$sleep_seconds"
  if [ "$backoff" -lt "$max_backoff" ]; then
    backoff=$((backoff * 2))
    if [ "$backoff" -gt "$max_backoff" ]; then
      backoff="$max_backoff"
    fi
  fi
done
