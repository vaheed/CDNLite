#!/bin/sh
set -eu

if [ "${EDGE_ID:-}" = "" ] && [ "${DEV_MODE:-0}" != "1" ]; then
  echo "EDGE_ID is required unless DEV_MODE=1" >&2
  exit 1
fi

ttl="${CDNLITE_CACHE_DEFAULT_TTL:-60s}"
case "$ttl" in
  *[!0-9smhdw]*|'')
    echo "invalid CDNLITE_CACHE_DEFAULT_TTL: $ttl" >&2
    exit 1
    ;;
esac

log_level="${CDNLITE_EDGE_LOG_LEVEL:-info}"
case "$log_level" in
  debug|info|notice|warn|error|crit|alert|emerg) ;;
  *)
    echo "invalid CDNLITE_EDGE_LOG_LEVEL: $log_level" >&2
    exit 1
    ;;
esac

log_format="${CDNLITE_EDGE_LOG_FORMAT:-json}"
case "$log_format" in
  json) access_log_format="cdnlite_json" ;;
  combined) access_log_format="combined" ;;
  *)
    echo "invalid CDNLITE_EDGE_LOG_FORMAT: $log_format" >&2
    exit 1
    ;;
esac

positive_number() {
  name="$1"
  value="$2"
  case "$value" in
    ''|*[!0-9]*)
      echo "invalid $name: $value" >&2
      exit 1
      ;;
  esac
  if [ "$value" -lt 1 ]; then
    echo "invalid $name: $value" >&2
    exit 1
  fi
}

size_value() {
  name="$1"
  value="$2"
  case "$value" in
    *[!0-9kKmMgG]*|'')
      echo "invalid $name: $value" >&2
      exit 1
      ;;
  esac
}

time_value() {
  name="$1"
  value="$2"
  case "$value" in
    *[!0-9smh]*|'')
      echo "invalid $name: $value" >&2
      exit 1
      ;;
  esac
}

worker_processes="${CDNLITE_EDGE_WORKER_PROCESSES:-auto}"
case "$worker_processes" in
  auto) ;;
  *) positive_number CDNLITE_EDGE_WORKER_PROCESSES "$worker_processes" ;;
esac
worker_connections="${CDNLITE_EDGE_WORKER_CONNECTIONS:-4096}"
positive_number CDNLITE_EDGE_WORKER_CONNECTIONS "$worker_connections"

limits_dict="${CDNLITE_EDGE_LIMITS_DICT_SIZE:-20m}"
request_context_dict="${CDNLITE_EDGE_REQUEST_CONTEXT_DICT_SIZE:-10m}"
metric_queue_dict="${CDNLITE_EDGE_METRIC_QUEUE_DICT_SIZE:-10m}"
security_event_queue_dict="${CDNLITE_EDGE_SECURITY_EVENT_QUEUE_DICT_SIZE:-10m}"
for item in "$limits_dict" "$request_context_dict" "$metric_queue_dict" "$security_event_queue_dict"; do
  size_value CDNLITE_EDGE_SHARED_DICT_SIZE "$item"
done

resolver="${CDNLITE_EDGE_RESOLVER:-127.0.0.11}"
client_header_buffer_size="${CDNLITE_EDGE_CLIENT_HEADER_BUFFER_SIZE:-8k}"
large_client_header_buffers="${CDNLITE_EDGE_LARGE_CLIENT_HEADER_BUFFERS:-4 16k}"
client_body_buffer_size="${CDNLITE_EDGE_CLIENT_BODY_BUFFER_SIZE:-128k}"
client_max_body_size="${CDNLITE_EDGE_CLIENT_MAX_BODY_SIZE:-20m}"
proxy_connect_timeout="${CDNLITE_EDGE_PROXY_CONNECT_TIMEOUT:-5s}"
proxy_read_timeout="${CDNLITE_EDGE_PROXY_READ_TIMEOUT:-60s}"
proxy_send_timeout="${CDNLITE_EDGE_PROXY_SEND_TIMEOUT:-60s}"
config_max_bytes="${CDNLITE_EDGE_CONFIG_MAX_BYTES:-1048576}"
telemetry_batch_size="${CDNLITE_EDGE_TELEMETRY_BATCH_SIZE:-100}"
telemetry_queue_max_items="${CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS:-10000}"
telemetry_queue_max_bytes="${CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES:-1048576}"
size_value CDNLITE_EDGE_CLIENT_HEADER_BUFFER_SIZE "$client_header_buffer_size"
size_value CDNLITE_EDGE_CLIENT_BODY_BUFFER_SIZE "$client_body_buffer_size"
size_value CDNLITE_EDGE_CLIENT_MAX_BODY_SIZE "$client_max_body_size"
time_value CDNLITE_EDGE_PROXY_CONNECT_TIMEOUT "$proxy_connect_timeout"
time_value CDNLITE_EDGE_PROXY_READ_TIMEOUT "$proxy_read_timeout"
time_value CDNLITE_EDGE_PROXY_SEND_TIMEOUT "$proxy_send_timeout"
positive_number CDNLITE_EDGE_CONFIG_MAX_BYTES "$config_max_bytes"
positive_number CDNLITE_EDGE_TELEMETRY_BATCH_SIZE "$telemetry_batch_size"
positive_number CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS "$telemetry_queue_max_items"
positive_number CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES "$telemetry_queue_max_bytes"

sed \
  -e "s/__CDNLITE_EDGE_WORKER_PROCESSES__/$worker_processes/g" \
  -e "s/__CDNLITE_EDGE_WORKER_CONNECTIONS__/$worker_connections/g" \
  -e "s/__CDNLITE_EDGE_LIMITS_DICT_SIZE__/$limits_dict/g" \
  -e "s/__CDNLITE_EDGE_REQUEST_CONTEXT_DICT_SIZE__/$request_context_dict/g" \
  -e "s/__CDNLITE_EDGE_METRIC_QUEUE_DICT_SIZE__/$metric_queue_dict/g" \
  -e "s/__CDNLITE_EDGE_SECURITY_EVENT_QUEUE_DICT_SIZE__/$security_event_queue_dict/g" \
  -e "s#__CDNLITE_EDGE_RESOLVER__#$resolver#g" \
  -e "s/__CDNLITE_EDGE_CLIENT_HEADER_BUFFER_SIZE__/$client_header_buffer_size/g" \
  -e "s/__CDNLITE_EDGE_LARGE_CLIENT_HEADER_BUFFERS__/$large_client_header_buffers/g" \
  -e "s/__CDNLITE_EDGE_CLIENT_BODY_BUFFER_SIZE__/$client_body_buffer_size/g" \
  -e "s/__CDNLITE_EDGE_CLIENT_MAX_BODY_SIZE__/$client_max_body_size/g" \
  -e "s/__CDNLITE_EDGE_PROXY_CONNECT_TIMEOUT__/$proxy_connect_timeout/g" \
  -e "s/__CDNLITE_EDGE_PROXY_READ_TIMEOUT__/$proxy_read_timeout/g" \
  -e "s/__CDNLITE_EDGE_PROXY_SEND_TIMEOUT__/$proxy_send_timeout/g" \
  -e "s/__CDNLITE_CACHE_DEFAULT_TTL__/$ttl/g" \
  -e "s/__CDNLITE_EDGE_ERROR_LOG_LEVEL__/$log_level/g" \
  -e "s/__CDNLITE_EDGE_ACCESS_LOG_FORMAT__/$access_log_format/g" \
  /usr/local/openresty/nginx/conf/nginx.conf.template \
  > /usr/local/openresty/nginx/conf/nginx.conf

mkdir -p /var/lib/cdnlite/tls
mkdir -p /var/lib/cdnlite
touch /var/lib/cdnlite/metrics.ndjson /var/lib/cdnlite/security-events.ndjson
chmod 666 /var/lib/cdnlite/metrics.ndjson /var/lib/cdnlite/security-events.ndjson || true
if [ ! -f /var/lib/cdnlite/tls/default.crt ] || [ ! -f /var/lib/cdnlite/tls/default.key ]; then
  openssl req -x509 -nodes -newkey rsa:2048 \
    -keyout /var/lib/cdnlite/tls/default.key \
    -out /var/lib/cdnlite/tls/default.crt \
    -subj "/CN=cdnlite-default.local" \
    -days 3650 >/dev/null 2>&1
fi

exec "$@"
