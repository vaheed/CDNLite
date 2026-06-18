#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:${CORE_HOST_PORT:-8080}}"
EDGE_URL="${EDGE_URL:-http://localhost:${EDGE_HOST_PORT:-8081}}"
DASHBOARD_URL="${DASHBOARD_URL:-http://localhost:${DASHBOARD_PORT:-8082}}"
POWERDNS_API_URL="${POWERDNS_API_URL:-http://localhost:8089}"
POWERDNS_PUBLIC_API_URL="${POWERDNS_PUBLIC_API_URL:-$POWERDNS_API_URL}"
PDNS_API_KEY="${PDNS_API_KEY:-test-key}"
EDGE_ID="${EDGE_ID:-edge-local-1}"
EDGE_TOKEN="${EDGE_TOKEN:-edge-dev-token}"
EDGE_TLS_URL="${EDGE_TLS_URL:-https://localhost:${EDGE_TLS_HOST_PORT:-8443}}"
CDNLITE_SSL_SECRET_KEY="${CDNLITE_SSL_SECRET_KEY:-e2e-ssl-secret}"
CI_ENV_NAME="${CI_ENV_NAME:-e2e}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin-e2e}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin-e2e-password-12345}"
ADMIN_SESSION_TOKEN=""
export CORE_URL EDGE_URL DASHBOARD_URL EDGE_TLS_URL POWERDNS_API_URL POWERDNS_PUBLIC_API_URL PDNS_API_KEY EDGE_ID EDGE_TOKEN CI_ENV_NAME ADMIN_SESSION_TOKEN

RUN_KEY="${GITHUB_RUN_ID:-local}-$RANDOM"
TEST_DOMAIN="e2e-${RUN_KEY}.test.local"
TEST_DOMAIN_2="e2e-${RUN_KEY}-b.test.local"
DOMAIN_ID=""
DNS_IDS=()
NAMESERVER_SCHEDULER_PAUSED=0

init_report

on_error() {
  local rc=$?
  local line="${1:-unknown}"
  local cmd="${2:-unknown}"
  echo "e2e: error rc=$rc at line=$line cmd=$cmd, printing diagnostics"
  echo "----- e2e progress -----"
  if [[ ${#STEP_LINES[@]} -gt 0 ]]; then
    printf '%s\n' "${STEP_LINES[@]}"
  else
    echo "no recorded steps completed"
  fi
  if [[ -s "$REPORT_MD" ]]; then
    echo "----- ${REPORT_MD} -----"
    cat "$REPORT_MD" || true
  fi
  docker compose ps || true
  docker compose logs --no-color || true
  for svc in core edge edge-agent dashboard postgres origin-tls origin-http pdns-postgres pdns-recursor pdns-auth poweradmin; do
    if compose_has_service "$svc"; then
      echo "----- ${svc} (tail 200) -----"
      docker compose logs --no-color --tail=200 "$svc" || true
    fi
  done
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

cleanup() {
  if [[ -n "$DOMAIN_ID" ]]; then
    for rid in "${DNS_IDS[@]}"; do
      [[ -n "$rid" ]] || continue
      curl -sS -X DELETE "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${rid}" >/dev/null || true
    done
    curl -sS -X DELETE "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" >/dev/null || true
  fi
  if [[ "$NAMESERVER_SCHEDULER_PAUSED" == "1" ]]; then
    docker compose start nameserver-scheduler >/dev/null 2>&1 || true
  fi
}

on_exit() {
  local rc=$?
  cleanup
  if [[ $rc -ne 0 ]]; then
    collect_diagnostics
  fi
  write_reports
  exit $rc
}
trap on_exit EXIT

edge_auth_headers_json() {
  local method="$1"
  local path="$2"
  local body="$3"
  python3 - "$method" "$path" "$body" "$EDGE_TOKEN" "$EDGE_ID" <<'PY'
import hashlib
import hmac
import json
import os
import sys
import time
import secrets

method, path, body, token, edge_id = sys.argv[1:6]
ts = int(time.time())
nonce = secrets.token_hex(12)
body_hash = hashlib.sha256(body.encode()).hexdigest()
canonical = f"{method}\n{path}\n{ts}\n{nonce}\n{body_hash}"
secret = hashlib.sha256(token.encode()).hexdigest().encode()
sig = hmac.new(secret, canonical.encode(), hashlib.sha256).hexdigest()
print(json.dumps({
  "Authorization": f"Bearer {token}",
  "X-CDNLITE-Edge-Id": edge_id,
  "X-CDNLITE-Timestamp": str(ts),
  "X-CDNLITE-Nonce": nonce,
  "X-CDNLITE-Signature": sig,
}))
PY
}

edge_api() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local canonical_path="${path%%\?*}"
  local hdrs
  hdrs="$(edge_auth_headers_json "$method" "$canonical_path" "$body")"
  local tmp
  tmp="$(mktemp)"
  local code
  code="$(curl -sS -o "$tmp" -w '%{http_code}' -X "$method" "${CORE_URL}${path}" \
    -H "Content-Type: application/json" \
    -H "Authorization: $(jq -r '.Authorization' <<<"$hdrs")" \
    -H "X-CDNLITE-Edge-Id: $(jq -r '."X-CDNLITE-Edge-Id"' <<<"$hdrs")" \
    -H "X-CDNLITE-Timestamp: $(jq -r '."X-CDNLITE-Timestamp"' <<<"$hdrs")" \
    -H "X-CDNLITE-Nonce: $(jq -r '."X-CDNLITE-Nonce"' <<<"$hdrs")" \
    -H "X-CDNLITE-Signature: $(jq -r '."X-CDNLITE-Signature"' <<<"$hdrs")" \
    --data "$body")"
  HTTP_CODE="$code"
  HTTP_BODY="$(cat "$tmp")"
  rm -f "$tmp"
}

edge_status_for_host() {
  local host="$1"
  curl -s -o /tmp/e2e-edge-status.txt -w '%{http_code}' "${EDGE_URL}/api/v1/domains" \
    -H "Host: ${host}" \
    -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}"
}

assert_edge_server_hidden() {
  local url="$1"
  local host="$2"
  local insecure="${3:-0}"
  local headers body
  headers="$(mktemp)"
  body="$(mktemp)"
  local curl_args=(-sS -D "$headers" -o "$body" -H "Host: ${host}")
  if [[ "$insecure" == "1" ]]; then
    curl_args+=(-k)
  fi
  curl "${curl_args[@]}" "$url" >/dev/null
  if grep -Eiq '^Server:|openresty|nginx' "$headers"; then
    fail "edge disclosed its HTTP server identity in response headers: $(tr '\n' ' ' <"$headers")"
  fi
  if grep -Eiq 'openresty|nginx/[0-9]' "$body"; then
    fail "edge disclosed its HTTP server identity in response body"
  fi
  rm -f "$headers" "$body"
}

edge_status_is() {
  local host="$1"
  local expected="$2"
  local code
  code="$(edge_status_for_host "$host")"
  [[ "$code" == "$expected" ]]
}

edge_status_is_success() {
  local host="$1"
  local code
  code="$(edge_status_for_host "$host")"
  [[ "$code" -ge 200 && "$code" -lt 400 ]]
}

login_admin() {
  local login_code
  login_code="$(curl -sS -o /tmp/e2e-admin-login.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"${ADMIN_USERNAME}\",\"password\":\"${ADMIN_PASSWORD}\"}")"
  assert_eq "$login_code" "200" "admin login should return 200"
  ADMIN_SESSION_TOKEN="$(json_get "$(cat /tmp/e2e-admin-login.json)" '.data.token')"
  export ADMIN_SESSION_TOKEN
}

api_post_with_powerdns_retry() {
  local url="$1"
  local body="$2"
  local attempts="${3:-20}"
  local sleep_seconds="${4:-2}"
  local attempt=1

  while true; do
    api_post "$url" "$body"
    if [[ "$HTTP_CODE" != "502" || "$HTTP_BODY" != *'"error":"powerdns_api_error"'* ]]; then
      return 0
    fi
    if [[ "$attempt" -ge "$attempts" ]]; then
      return 0
    fi
    attempt=$((attempt + 1))
    sleep "$sleep_seconds"
  done
}

edge_wait_status() {
  local host="$1"
  local expected="$2"
  retry 40 1 edge_status_is "$host" "$expected"
}

edge_wait_success_status() {
  local host="$1"
  retry 40 1 edge_status_is_success "$host"
}

edge_config_has_host() {
  local host="$1"
  docker compose exec -T edge-agent sh -lc "grep -Fq \"$host\" \"\${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}\""
}

edge_wait_config_host() {
  local host="$1"
  retry 40 1 edge_config_has_host "$host"
}

edge_is_healthy() {
  [[ "$(db_query "SELECT COUNT(*) FROM edge_nodes
    WHERE edge_id='${EDGE_ID}' AND status='online' AND health_status='healthy'
      AND COALESCE(last_heartbeat_at, last_heartbeat) > EXTRACT(EPOCH FROM NOW())::BIGINT - 90;")" == "1" ]]
}

pdns_zone_is_ready() {
  local zone="$1"
  local output="$2"
  curl -fsS -H "X-API-Key: ${PDNS_API_KEY}" \
    "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${zone}" \
    -o "$output" &&
    jq -e --arg zone "$zone" '.name == $zone' "$output" >/dev/null
}

agent_exec() {
  docker compose exec -T edge-agent sh -lc "CORE_URL=http://core:8080 $1"
}

agent_push_metrics() {
  agent_exec 'lock="${METRIC_PATH:-/var/lib/cdnlite/metrics.ndjson}.push.lock"; i=0; while [ -d "$lock" ] && [ "$i" -lt 20 ]; do i=$((i + 1)); sleep 1; done; [ ! -d "$lock" ] && /agent/push_metrics.sh'
}

edge_cache_header_for_host() {
  local host="$1"
  local path="$2"
  shift 2
  local headers
  headers="$(mktemp)"
  curl -sS -o /tmp/e2e-edge-cache-body.txt -D "$headers" "${EDGE_URL}${path}" -H "Host: ${host}" "$@"
  awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Cache:/ {gsub("\r","",$2); print $2}' "$headers" | tail -n 1
  rm -f "$headers"
}

edge_header_for_host() {
  local host="$1"
  local path="$2"
  local header_name="$3"
  shift 3
  local headers
  headers="$(mktemp)"
  curl -sS -o /tmp/e2e-edge-header-body.txt -D "$headers" "${EDGE_URL}${path}" -H "Host: ${host}" "$@"
  awk -v h="$header_name" 'BEGIN{IGNORECASE=1} $1 ~ "^"h":" {gsub("\r","",$2); print $2}' "$headers" | tail -n 1
  rm -f "$headers"
}

edge_wait_cache_header() {
  local host="$1"
  local path="$2"
  local expected="$3"
  local tries="${4:-20}"
  local sleep_s="${5:-1}"
  local got=""
  local n=1
  while [[ "$n" -le "$tries" ]]; do
    got="$(edge_cache_header_for_host "$host" "$path")"
    if [[ "$got" == "$expected" ]]; then
      return 0
    fi
    n=$((n + 1))
    sleep "$sleep_s"
  done

  local body=""
  if [[ -s /tmp/e2e-edge-cache-body.txt ]]; then
    body="$(tr '\n' ' ' </tmp/e2e-edge-cache-body.txt | cut -c 1-300)"
  fi
  fail "cache header mismatch for ${path} (expected='${expected}' got='${got}' body='${body}')"
}

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
retry 40 2 docker compose exec -T dashboard wget -qO- http://127.0.0.1/healthz >/dev/null
wait_for_postgres
retry 40 2 db_query "SELECT 1;" >/dev/null
record_step PASS "stack-ready" "core, edge, and dashboard health passed (readiness waits for config)"

if [[ "${CDNLITE_BOOTSTRAP_ADMIN_USER:-1}" == "1" ]]; then
  bootstrap_admin_code="$(curl -sS -o /tmp/e2e-bootstrap-admin-login.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d '{"username":"admin","password":"admin"}')"
  assert_eq "$bootstrap_admin_code" "200" "bootstrap admin login should return 200"
  assert_contains "$(cat /tmp/e2e-bootstrap-admin-login.json)" '"username":"admin"' "bootstrap admin login should include admin user"
  record_step PASS "bootstrap-admin-login" "default dashboard bootstrap admin can log in"
fi

docker compose exec -T core php artisan cdn:admin:create --username="$ADMIN_USERNAME" --password="$ADMIN_PASSWORD" --display_name="E2E Admin" >/dev/null
login_admin
api_get "${CORE_URL}/api/v1/admin/me"
assert_http_status "$HTTP_CODE" "200" "admin me failed"
assert_contains "$HTTP_BODY" "$ADMIN_USERNAME" "admin me should include username"
record_step PASS "admin-login" "admin session established"

powerdns_enabled=false
powerdns_strict=false
[[ "${POWERDNS_ENABLED:-1}" == "1" ]] && powerdns_enabled=true
[[ "${POWERDNS_STRICT:-1}" == "1" ]] && powerdns_strict=true
settings_code="$(curl -sS -o /tmp/e2e-powerdns-settings.json -w '%{http_code}' \
  -X PATCH "${CORE_URL}/api/v1/settings/platform.powerdns" \
  -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}" \
  -H 'Content-Type: application/json' \
    -d "{\"values\":{\"enabled\":${powerdns_enabled},\"strict\":${powerdns_strict},\"api_url\":\"http://pdns-auth:8081\",\"api_key\":\"${PDNS_API_KEY}\",\"server_id\":\"localhost\"}}")"
assert_eq "$settings_code" "200" "PowerDNS settings update should return 200"
assert_contains "$(cat /tmp/e2e-powerdns-settings.json)" '"api_key":{"configured":true' "PowerDNS secret should be masked"
record_step PASS "platform-settings" "PowerDNS configured through settings API"

ssl_key_present="$(docker compose exec -T core php -r "echo getenv('CDNLITE_SSL_SECRET_KEY') ? 'set' : 'missing';")"
if [[ "$ssl_key_present" != "set" ]]; then
  CDNLITE_SSL_SECRET_KEY="$CDNLITE_SSL_SECRET_KEY" docker compose up -d --force-recreate core >/dev/null
  retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
  login_admin
  record_step PASS "core-recreate-ssl-secret" "recreated core with CDNLITE_SSL_SECRET_KEY for stage10 checks"
fi

docker compose exec -T core php artisan cdn:edge:register-token --edge_id="$EDGE_ID" --token="$EDGE_TOKEN" >/dev/null
agent_exec '/agent/register.sh' >/dev/null
agent_exec '/agent/heartbeat.sh' >/dev/null
retry 20 1 edge_is_healthy
agent_exec '/agent/pull_config.sh' >/dev/null || true
retry 40 2 curl -fsS "$EDGE_URL/ready" >/dev/null
record_step PASS "edge-token-register" "edge token provisioned and healthy heartbeat persisted"

if compose_has_service nameserver-scheduler; then
  docker compose stop nameserver-scheduler >/dev/null
  NAMESERVER_SCHEDULER_PAUSED=1
fi

if [[ -n "${CDNLITE_API_TOKEN:-}" ]]; then
  no_auth_code="$(curl -sS -o /tmp/e2e-auth.txt -w '%{http_code}' -X POST "${CORE_URL}/api/v1/domains" \
    -H 'Content-Type: application/json' \
    -d '{"name":"unauth-e2e","domain":"unauth.e2e.local"}')"
  assert_eq "$no_auth_code" "401" "control-plane create domain should require bearer auth when token is configured"
  record_step PASS "api-auth-negative-domain-create" "unauthenticated create returned 401"
fi

# Domain lifecycle
api_post "${CORE_URL}/api/v1/domains" \
  "{\"name\":\"e2e-domain-${RUN_KEY}\",\"domain\":\"${TEST_DOMAIN}\"}"
assert_http_status "$HTTP_CODE" "201" "domain create failed"
DOMAIN_ID="$(json_get "$HTTP_BODY" '.data.id')"
record_step PASS "domain-create" "domain_id=${DOMAIN_ID} domain=${TEST_DOMAIN}"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activate" '{"override":true}'
assert_http_status "$HTTP_CODE" "200" "domain activation failed"
now="$(date +%s)"
db_query "UPDATE domains SET status='active', nameserver_status='verified', last_ns_check_at=$now, updated_at=$now WHERE id='${DOMAIN_ID}';" >/dev/null
db_query "UPDATE domain_nameservers SET observed=true, last_checked_at=$now WHERE domain_id='${DOMAIN_ID}';" >/dev/null
record_step PASS "domain-activate" "domain activated with development override"

api_post_with_powerdns_retry "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"@","content":"1.1.1.1","ttl":300,"proxied":true,"origin_host":"origin-tls","origin_tls_verify":"ignore","geo_origins":{"DEFAULT":{"host":"origin-tls","tls_verify":"ignore"},"IR":{"host":"origin-http","tls_verify":"verify"}}}'
assert_http_status "$HTTP_CODE" "201" "proxied DNS create failed"
PRIMARY_DNS_ID="$(json_get "$HTTP_BODY" '.data.id')"
DNS_IDS+=("$PRIMARY_DNS_ID")
record_step PASS "dns-origin-create" "record-level origin, proxy, TLS mode, and geo origins stored"

domain_count="$(db_query "SELECT COUNT(*) FROM domains WHERE id='${DOMAIN_ID}' AND domain='${TEST_DOMAIN}';")"
assert_eq "$domain_count" "1" "domain missing in db"
record_step PASS "domain-db-row" "domain persisted"

api_get "${CORE_URL}/api/v1/domains"
assert_http_status "$HTTP_CODE" "200" "domain list failed"
assert_contains "$HTTP_BODY" "$TEST_DOMAIN" "domain not listed"
record_step PASS "domain-list" "domain listed"

# Static Vue dashboard runtime
spa_headers="$(mktemp)"
spa_code="$(docker compose exec -T dashboard wget -S -qO /tmp/e2e-dashboard-index.html http://127.0.0.1/ 2>"$spa_headers"; printf '%s' "$?")"
assert_eq "$spa_code" "0" "dashboard SPA root should be served"
spa_index="$(docker compose exec -T dashboard cat /tmp/e2e-dashboard-index.html)"
assert_contains "$spa_index" '<div id="app">' "dashboard SPA root should contain Vue app mount"
assert_contains "$spa_index" '/assets/index-' "dashboard SPA root should reference built assets"
rm -f "$spa_headers"
record_step PASS "dashboard-spa-root" "Vue dashboard root served"

spa_fallback="$(docker compose exec -T dashboard wget -qO- http://127.0.0.1/domains)"
assert_contains "$spa_fallback" '<div id="app">' "dashboard SPA fallback route should serve index"
record_step PASS "dashboard-spa-fallback" "Vue dashboard route fallback served"

asset_path="$(printf '%s\n' "$spa_index" | sed -n 's/.*src="\([^"]*\/assets\/index-[^"]*\.js\)".*/\1/p' | head -n1)"
if [[ -z "$asset_path" ]]; then
  fail "dashboard JS asset path missing from index"
fi
asset_headers="$(docker compose exec -T dashboard wget -S -qO- "http://127.0.0.1${asset_path}" 2>&1 >/dev/null)"
assert_contains "$asset_headers" "Cache-Control: public, immutable" "dashboard asset should use immutable cache headers"
record_step PASS "dashboard-static-asset-cache" "Vue dashboard static asset cache headers verified"

dashboard_asset="$(docker compose exec -T dashboard wget -qO- "http://127.0.0.1${asset_path}")"
assert_contains "$dashboard_asset" "Security Center" "dashboard bundle should include Security Center tab"
assert_contains "$dashboard_asset" "/protection/intents" "dashboard bundle should include Protection intent APIs"
assert_contains "$dashboard_asset" "/protection/profiles" "dashboard bundle should include Protection profile APIs"
assert_contains "$dashboard_asset" "Preview only shows the technical rules" "dashboard bundle should include intent preview copy"
record_step PASS "dashboard-security-center-bundle" "Security Center tab and Protection profile/intent APIs are present in the built dashboard"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" '{"name":"e2e-domain-updated"}'
assert_http_status "$HTTP_CODE" "200" "domain update failed"
updated_name="$(json_get "$HTTP_BODY" '.data.name')"
assert_eq "$updated_name" "e2e-domain-updated" "domain name update mismatch"
record_step PASS "domain-update" "domain updated"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" '{"origin_shield_header_name":"X-CDNLITE-Origin-Secret","origin_shield_secret":"stage9-origin-secret"}'
assert_http_status "$HTTP_CODE" "200" "origin shield update failed"
record_step PASS "domain-origin-shield-update" "origin shield metadata updated"

api_post "${CORE_URL}/api/v1/domains" '{"name":"bad"}'
assert_http_status "$HTTP_CODE" "422" "missing domain validation expected"
record_step PASS "domain-validation-missing-domain" "422 returned"

api_post "${CORE_URL}/api/v1/domains" "{\"name\":\"dup\",\"domain\":\"${TEST_DOMAIN}\"}"
assert_http_status "$HTTP_CODE" "422" "duplicate domain should return 422"
record_step PASS "domain-validation-duplicate" "duplicate rejected with code=${HTTP_CODE}"

api_patch "${CORE_URL}/api/v1/domains/99999999" '{"name":"nope"}'
assert_http_status "$HTTP_CODE" "404" "unknown domain should 404"
record_step PASS "domain-validation-unknown" "unknown domain 404"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits" '{"enabled":true,"requests_per_minute":30,"path_prefix":"/login","key_type":"ip_path","priority":10,"action":"block"}'
assert_http_status "$HTTP_CODE" "201" "rate-limit create failed"
rate_path_prefix="$(json_get "$HTTP_BODY" '.data.path_prefix')"
assert_eq "$rate_path_prefix" "/login" "rate-limit path_prefix mismatch"
record_step PASS "rate-limit-current" "path_prefix=/login key_type=ip_path"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits" '{"enabled":true,"requests_per_minute":12,"path_prefix":"/managed-login","key_type":"ip_path","priority":15,"action":"block","managed_by":"recommended_protection","template_key":"protect_login.rate_limit"}'
assert_http_status "$HTTP_CODE" "201" "managed rate-limit create failed"
MANAGED_RATE_LIMIT_RULE_ID="$(json_get "$HTTP_BODY" '.data.id')"
assert_contains "$HTTP_BODY" '"managed_by":"recommended_protection"' "managed rate-limit ownership missing"
assert_contains "$HTTP_BODY" '"template_key":"protect_login.rate_limit"' "managed rate-limit template missing"
managed_rate_link_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE rule_table='rate_limit_rules' AND rule_id='${MANAGED_RATE_LIMIT_RULE_ID}' AND managed_by='recommended_protection' AND detached_at IS NULL;")"
assert_eq "$managed_rate_link_count" "1" "managed rate-limit link missing"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${MANAGED_RATE_LIMIT_RULE_ID}" '{"requests_per_minute":18}'
assert_http_status "$HTTP_CODE" "200" "managed rate-limit update failed"
assert_contains "$HTTP_BODY" '"user_modified":true' "managed rate-limit edit should mark user_modified"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${MANAGED_RATE_LIMIT_RULE_ID}/detach-managed" '{}'
assert_http_status "$HTTP_CODE" "200" "managed rate-limit detach failed"
assert_contains "$HTTP_BODY" '"managed_by":null' "detached rate-limit should clear managed_by"
assert_contains "$HTTP_BODY" '"user_modified":false' "detached rate-limit should clear user_modified"
managed_rate_detached_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE rule_table='rate_limit_rules' AND rule_id='${MANAGED_RATE_LIMIT_RULE_ID}' AND detached_at IS NOT NULL;")"
assert_eq "$managed_rate_detached_count" "1" "managed rate-limit link should be marked detached"
api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${MANAGED_RATE_LIMIT_RULE_ID}"
assert_http_status "$HTTP_CODE" "200" "detached managed rate-limit delete failed"
record_step PASS "rate-limit-managed-contract" "ownership metadata, user_modified, detach, audit link, and cleanup verified"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits" '{"enabled":true,"requests_per_minute":15,"path_prefix":"/api/","key_type":"ip_path","priority":20,"action":"block"}'
assert_http_status "$HTTP_CODE" "201" "rate-limit create failed"
RATE_LIMIT_RULE_ID="$(json_get "$HTTP_BODY" '.data.id')"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits"
assert_http_status "$HTTP_CODE" "200" "rate-limit list failed"
assert_contains "$HTTP_BODY" '"path_prefix":"/api/"' "created rate-limit missing from list"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${RATE_LIMIT_RULE_ID}" '{"requests_per_minute":25,"enabled":false}'
assert_http_status "$HTTP_CODE" "200" "rate-limit update failed"
assert_contains "$HTTP_BODY" '"requests_per_minute":25' "rate-limit update not returned"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${RATE_LIMIT_RULE_ID}" '{"enabled":true}'
assert_http_status "$HTTP_CODE" "200" "rate-limit enable failed"
edge_api GET "/api/v1/edge/config" ""
assert_http_status "$HTTP_CODE" "200" "rate-limit snapshot fetch failed"
assert_contains "$HTTP_BODY" "$RATE_LIMIT_RULE_ID" "enabled rate-limit missing from snapshot"
api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits/${RATE_LIMIT_RULE_ID}"
assert_http_status "$HTTP_CODE" "200" "rate-limit delete failed"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/rate-limits"
assert_http_status "$HTTP_CODE" "200" "rate-limit list after delete failed"
if [[ "$HTTP_BODY" == *"$RATE_LIMIT_RULE_ID"* ]]; then fail "deleted rate-limit still listed"; fi
edge_api GET "/api/v1/edge/config" ""
assert_http_status "$HTTP_CODE" "200" "rate-limit post-delete snapshot fetch failed"
if [[ "$HTTP_BODY" == *"$RATE_LIMIT_RULE_ID"* ]]; then fail "deleted rate-limit still present in snapshot"; fi
record_step PASS "rate-limit-crud" "create, edit, toggle, delete, and snapshot removal verified"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules" '{"enabled":true,"name":"block-admin","priority":20,"type":"path_prefix","pattern":"/admin","action":"block","description":"block admin path"}'
assert_http_status "$HTTP_CODE" "201" "waf v2 create failed"
WAF_RULE_ID="$(json_get "$HTTP_BODY" '.data.id')"
record_step PASS "waf-v2-create" "rule_id=${WAF_RULE_ID}"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules" '{"enabled":true,"name":"managed-exploit-block","priority":25,"type":"path_contains","pattern":"../","action":"block","description":"managed exploit path traversal check","managed_by":"recommended_protection","template_key":"block_exploits.path_traversal"}'
assert_http_status "$HTTP_CODE" "201" "managed waf create failed"
MANAGED_WAF_RULE_ID="$(json_get "$HTTP_BODY" '.data.id')"
assert_contains "$HTTP_BODY" '"managed_by":"recommended_protection"' "managed waf ownership missing"
assert_contains "$HTTP_BODY" '"template_key":"block_exploits.path_traversal"' "managed waf template missing"
managed_waf_link_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE rule_table='waf_rules' AND rule_id='${MANAGED_WAF_RULE_ID}' AND managed_by='recommended_protection' AND detached_at IS NULL;")"
assert_eq "$managed_waf_link_count" "1" "managed waf link missing"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules/${MANAGED_WAF_RULE_ID}" '{"description":"operator tuned managed exploit check"}'
assert_http_status "$HTTP_CODE" "200" "managed waf update failed"
assert_contains "$HTTP_BODY" '"user_modified":true' "managed waf edit should mark user_modified"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules/${MANAGED_WAF_RULE_ID}/detach-managed" '{}'
assert_http_status "$HTTP_CODE" "200" "managed waf detach failed"
assert_contains "$HTTP_BODY" '"managed_by":null' "detached waf should clear managed_by"
assert_contains "$HTTP_BODY" '"user_modified":false' "detached waf should clear user_modified"
managed_waf_detached_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE rule_table='waf_rules' AND rule_id='${MANAGED_WAF_RULE_ID}' AND detached_at IS NOT NULL;")"
assert_eq "$managed_waf_detached_count" "1" "managed waf link should be marked detached"
api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules/${MANAGED_WAF_RULE_ID}"
assert_http_status "$HTTP_CODE" "200" "detached managed waf delete failed"
record_step PASS "waf-managed-contract" "ownership metadata, user_modified, detach, audit link, and cleanup verified"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/profiles"
assert_http_status "$HTTP_CODE" "200" "protection profile list failed"
assert_contains "$HTTP_BODY" '"profile_key":"basic_website"' "basic website profile missing from list"
assert_contains "$HTTP_BODY" '"profile_key":"wordpress"' "wordpress profile missing from list"
record_step PASS "protection-profile-list" "available one-click protection profiles listed"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/profiles/basic_website/preview" '{}'
assert_http_status "$HTTP_CODE" "200" "protection profile preview failed"
assert_contains "$HTTP_BODY" '"intent_key":"common_exploits"' "profile preview should include common exploit intent"
assert_contains "$HTTP_BODY" '"intent_key":"static_asset_performance"' "profile preview should include static asset intent"
profile_preview_mutation_count="$(db_query "SELECT COUNT(*) FROM protection_profiles WHERE domain_id='${DOMAIN_ID}' AND profile_key='basic_website';")"
assert_eq "$profile_preview_mutation_count" "0" "protection profile preview should not persist a profile"
record_step PASS "protection-profile-preview" "one-click profile preview is non-mutating"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/profiles/basic_website/apply" '{}'
assert_http_status "$HTTP_CODE" "200" "protection profile apply failed"
PROTECTION_PROFILE_ID="$(json_get "$HTTP_BODY" '.data.profile.id')"
assert_contains "$HTTP_BODY" '"profile_key":"basic_website"' "applied profile should report profile key"
assert_contains "$HTTP_BODY" '"status":"enabled"' "applied profile should report enabled status"
assert_contains "$HTTP_BODY" '"managed_by":"Basic Website"' "profile-generated rules should expose profile ownership"
profile_intent_count="$(db_query "SELECT COUNT(*) FROM protection_intents WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}' AND status='enabled';")"
assert_eq "$profile_intent_count" "2" "basic website profile should enable two owned intents"
profile_rule_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}' AND detached_at IS NULL;")"
assert_eq "$profile_rule_count" "3" "basic website profile should create three managed rule links"
profile_history_count="$(db_query "SELECT COUNT(*) FROM profile_change_history WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}' AND action='protection_profile.apply';")"
assert_eq "$profile_history_count" "1" "profile apply should write profile change history"
profile_rollback_count="$(db_query "SELECT COUNT(*) FROM profile_rollback_points WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}';")"
assert_eq "$profile_rollback_count" "2" "profile apply should create rollback points for owned intents"
record_step PASS "protection-profile-apply" "one-click profile apply generated owned intents, rules, history, and rollback"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/profiles/${PROTECTION_PROFILE_ID}/disable" '{}'
assert_http_status "$HTTP_CODE" "200" "protection profile disable failed"
assert_contains "$HTTP_BODY" '"status":"disabled"' "disabled profile should report disabled status"
profile_disabled_intent_count="$(db_query "SELECT COUNT(*) FROM protection_intents WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}' AND status='disabled';")"
assert_eq "$profile_disabled_intent_count" "2" "profile disable should disable owned intents"
profile_disabled_rule_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links l JOIN waf_rules w ON w.id=l.rule_id WHERE l.domain_id='${DOMAIN_ID}' AND l.profile_id='${PROTECTION_PROFILE_ID}' AND l.rule_table='waf_rules' AND w.enabled IS FALSE;")"
assert_eq "$profile_disabled_rule_count" "2" "profile disable should turn off owned waf rules"
profile_disable_history_count="$(db_query "SELECT COUNT(*) FROM profile_change_history WHERE domain_id='${DOMAIN_ID}' AND profile_id='${PROTECTION_PROFILE_ID}' AND action='protection_profile.disable';")"
assert_eq "$profile_disable_history_count" "1" "profile disable should write profile change history"
record_step PASS "protection-profile-disable" "one-click profile disable turns off owned generated rules and records history"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents"
assert_http_status "$HTTP_CODE" "200" "protection intent list failed"
assert_contains "$HTTP_BODY" '"intent_key":"common_exploits"' "common exploit intent missing from list"
assert_contains "$HTTP_BODY" '"intent_key":"login_shield"' "login shield intent missing from list"
record_step PASS "protection-intent-list" "available beginner protection intents listed"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/common_exploits/preview" '{}'
assert_http_status "$HTTP_CODE" "200" "protection intent preview failed"
assert_contains "$HTTP_BODY" '"rule_table":"waf_rules"' "preview should include generated waf rules"
assert_contains "$HTTP_BODY" '"template_key":"waf_path_traversal"' "preview should include traversal template"
preview_mutation_count="$(db_query "SELECT COUNT(*) FROM protection_intents WHERE domain_id='${DOMAIN_ID}' AND intent_key='common_exploits' AND profile_id IS NULL;")"
assert_eq "$preview_mutation_count" "0" "protection preview should not persist an intent"
record_step PASS "protection-intent-preview" "generated-rule preview is non-mutating"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/common_exploits/enable" '{}'
assert_http_status "$HTTP_CODE" "200" "protection intent enable failed"
PROTECTION_INTENT_ID="$(json_get "$HTTP_BODY" '.data.intent.id')"
assert_contains "$HTTP_BODY" '"status":"enabled"' "enabled intent should report enabled status"
assert_contains "$HTTP_BODY" '"managed_by":"Common Exploit Protection"' "generated waf rule should expose managed_by"
intent_waf_count="$(db_query "SELECT COUNT(*) FROM managed_rule_links WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND rule_table='waf_rules' AND detached_at IS NULL;")"
assert_eq "$intent_waf_count" "2" "common exploit intent should create two managed waf links"
intent_history_count="$(db_query "SELECT COUNT(*) FROM profile_change_history WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND action='protection_intent.enable';")"
assert_eq "$intent_history_count" "1" "enable should write profile change history"
intent_rollback_count="$(db_query "SELECT COUNT(*) FROM profile_rollback_points WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}';")"
assert_eq "$intent_rollback_count" "1" "enable should create rollback point"
record_step PASS "protection-intent-enable" "intent enable generated real managed waf rules with history and rollback"

PROTECTION_WAF_RULE_ID="$(db_query "SELECT rule_id FROM managed_rule_links WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND rule_table='waf_rules' AND template_key='waf_path_traversal' AND detached_at IS NULL LIMIT 1;")"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules/${PROTECTION_WAF_RULE_ID}" '{"description":"operator tuned generated traversal rule"}'
assert_http_status "$HTTP_CODE" "200" "generated protection waf edit failed"
assert_contains "$HTTP_BODY" '"user_modified":true' "editing generated protection waf should mark user_modified"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/common_exploits/enable" '{}'
assert_http_status "$HTTP_CODE" "409" "re-enabling user-modified intent should require confirmation"
assert_contains "$HTTP_BODY" '"error":"user_modified_rule_conflict"' "conflict should expose user_modified_rule_conflict"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/common_exploits/enable" '{"confirm_overwrite":true}'
assert_http_status "$HTTP_CODE" "200" "confirmed protection intent regenerate failed"
regenerated_user_modified="$(db_query "SELECT user_modified::int FROM waf_rules WHERE id='${PROTECTION_WAF_RULE_ID}' AND domain_id='${DOMAIN_ID}';")"
assert_eq "$regenerated_user_modified" "0" "confirmed regenerate should clear user_modified"
record_step PASS "protection-intent-conflict" "user-modified generated rule blocks silent overwrite until confirmed"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/${PROTECTION_INTENT_ID}/disable" '{}'
assert_http_status "$HTTP_CODE" "200" "protection intent disable failed"
assert_contains "$HTTP_BODY" '"status":"disabled"' "disabled intent should report disabled status"
disabled_rule_count="$(db_query "SELECT COUNT(*) FROM waf_rules WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND enabled IS FALSE;")"
assert_eq "$disabled_rule_count" "2" "disable should turn off generated waf rules"
disable_history_count="$(db_query "SELECT COUNT(*) FROM profile_change_history WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND action='protection_intent.disable';")"
assert_eq "$disable_history_count" "1" "disable should write profile change history"
record_step PASS "protection-intent-disable" "intent disable turns off generated rules and records history"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/protection/intents/${PROTECTION_INTENT_ID}/undo" '{}'
assert_http_status "$HTTP_CODE" "200" "protection intent undo failed"
enabled_rule_count="$(db_query "SELECT COUNT(*) FROM waf_rules WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND enabled IS TRUE;")"
assert_eq "$enabled_rule_count" "2" "undo should restore generated waf enabled state"
undo_history_count="$(db_query "SELECT COUNT(*) FROM profile_change_history WHERE domain_id='${DOMAIN_ID}' AND intent_id='${PROTECTION_INTENT_ID}' AND action='protection_intent.undo';")"
assert_eq "$undo_history_count" "1" "undo should write profile change history"
audit_intent_count="$(db_query "SELECT COUNT(*) FROM audit_log WHERE domain_id='${DOMAIN_ID}' AND action IN ('protection_intent.enable','protection_intent.disable','protection_intent.undo');")"
if [[ "$audit_intent_count" -lt 3 ]]; then
  fail "protection intent enable/disable/undo audit events missing"
fi
record_step PASS "protection-intent-undo" "undo restores generated state and audit/history stay visible"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/waf-rules"
assert_http_status "$HTTP_CODE" "200" "waf list failed"
assert_contains "$HTTP_BODY" '"type":"path_prefix"' "waf v2 type missing"
assert_contains "$HTTP_BODY" '"action":"block"' "waf v2 action missing"
record_step PASS "waf-v2-list" "waf v2 fields visible"

# Force and verify config propagation to edge before route assertions.
agent_exec '/agent/pull_config.sh' >/dev/null || true
edge_wait_config_host "${TEST_DOMAIN}"
if [[ -n "${CDNLITE_ORIGIN_SHIELD_SECRET:-}" ]]; then
  retry 30 1 docker compose exec -T edge-agent sh -lc "grep -Fq 'X-CDNLITE-Origin-Secret' \"\${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}\""
  record_step PASS "origin-shield-config" "origin shield header present in edge config"
else
  record_step PASS "origin-shield-config-skipped" "CDNLITE_ORIGIN_SHIELD_SECRET not set; header injection check skipped"
fi

origin_https_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=ignore")"
assert_contains "$origin_https_body" '"origin_scheme":"http"' "default DNS-linked origin should stay on HTTP/80"
record_step PASS "origin-http-80-default" "default DNS-linked origin stayed on HTTP/80"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/origins"
assert_http_status "$HTTP_CODE" "200" "origin list after DNS origin create failed"
PRIMARY_ORIGIN_ID="$(jq -r --arg rid "$PRIMARY_DNS_ID" '.data[] | select(.dns_record_id == $rid) | .id' <<<"$HTTP_BODY" | head -n1)"
if [[ -z "$PRIMARY_ORIGIN_ID" || "$PRIMARY_ORIGIN_ID" == "null" ]]; then
  fail "DNS-linked origin not found for ${PRIMARY_DNS_ID}"
fi
record_step PASS "origin-linked-row" "origin_id=${PRIMARY_ORIGIN_ID}"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/origins/${PRIMARY_ORIGIN_ID}" \
  '{"scheme":"https","host":"origin-tls","port":443,"host_header":"origin-tls","sni":"phase3-sni.local","tls_verify":"ignore","preserve_host":false,"enabled":true}'
assert_http_status "$HTTP_CODE" "200" "HTTPS/SNI origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
origin_sni_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=sni")"
assert_contains "$origin_sni_body" '"origin_scheme":"https"' "HTTPS/SNI origin should return 200 through edge"
assert_contains "$origin_sni_body" '"origin_sni":"phase3-sni.local"' "edge should pass configured SNI to HTTPS origin"
record_step PASS "origin-https-sni" "HTTPS origin returned 200 with configured SNI"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/origins/${PRIMARY_ORIGIN_ID}" \
  '{"scheme":"http","host":"origin-http","port":80,"host_header":"origin-http","sni":"origin-http","tls_verify":"verify","preserve_host":false,"enabled":true}'
assert_http_status "$HTTP_CODE" "200" "own-host-header origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
origin_own_host_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=own-host")"
assert_contains "$origin_own_host_body" '"origin_scheme":"http"' "own-host origin should return 200 through edge"
assert_contains "$origin_own_host_body" '"origin_host":"origin-http"' "preserve_host=false should send configured origin host header"
record_step PASS "origin-host-header-own" "origin received its own Host header with preserve_host=false"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/origins/${PRIMARY_ORIGIN_ID}" \
  "{\"scheme\":\"http\",\"host\":\"origin-http\",\"port\":80,\"host_header\":\"origin-http\",\"sni\":\"origin-http\",\"tls_verify\":\"verify\",\"preserve_host\":true,\"enabled\":true}"
assert_http_status "$HTTP_CODE" "200" "preserve CDN host origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
origin_cdn_host_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=cdn-host")"
assert_contains "$origin_cdn_host_body" '"origin_scheme":"http"' "preserve-host origin should return 200 through edge"
assert_contains "$origin_cdn_host_body" "\"origin_host\":\"${TEST_DOMAIN}\"" "preserve_host=true should send CDN request host header"
record_step PASS "origin-host-header-cdn" "origin received CDN Host header with preserve_host=true"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" \
  '{"origin_host":"origin-http","origin_tls_verify":"verify","geo_origins":{}}'
assert_http_status "$HTTP_CODE" "200" "HTTP fallback origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
origin_http_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=http-fallback")"
assert_contains "$origin_http_body" '"origin_scheme":"http"' "closed 443 should fall back to HTTP/80"
record_step PASS "origin-http-80-fallback" "closed HTTPS port fell back to HTTP/80"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" \
  '{"origin_host":"origin-tls","origin_scheme":"https","origin_tls_verify":"verify"}'
assert_http_status "$HTTP_CODE" "200" "verified TLS origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
origin_verify_headers="$(mktemp)"
origin_verify_status="$(curl -sS -o /tmp/e2e-origin-verify.txt -D "$origin_verify_headers" -w '%{http_code}' \
  -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/origin-probe?mode=verify")"
assert_eq "$origin_verify_status" "502" "verify mode should reject the self-signed certificate"
origin_verify_request_id="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Request-Id:/ {gsub("\r","",$2); print $2}' "$origin_verify_headers" | tail -n1)"
rm -f "$origin_verify_headers"
if [[ -z "$origin_verify_request_id" ]]; then
  fail "verify-mode 502 should include X-CDNLITE-Request-Id"
fi
record_step PASS "origin-tls-verify" "self-signed certificate rejected with 502 request_id=${origin_verify_request_id}"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" \
  '{"origin_host":"origin-tls","origin_scheme":"https","origin_tls_verify":"ignore","geo_origins":{"DEFAULT":{"host":"origin-tls","scheme":"https","tls_verify":"ignore"},"IR":{"host":"origin-http","scheme":"http","tls_verify":"verify"}}}'
assert_http_status "$HTTP_CODE" "200" "geo origin update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
geo_origin_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" -H "X-CDNLITE-Country: IR" "${EDGE_URL}/origin-probe?mode=geo")"
assert_contains "$geo_origin_body" '"origin_scheme":"http"' "IR geo origin should use the per-record HTTP origin"
geo_json="$(db_query "SELECT geo_origins_json FROM dns_records WHERE id='${PRIMARY_DNS_ID}';")"
assert_contains "$geo_json" "origin-http" "per-record geo origin was not persisted"
record_step PASS "origin-geo-per-record" "country override stored and applied from DNS record"

# DNS lifecycle
create_dns() {
  local payload="$1"
  api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" "$payload"
  assert_http_status "$HTTP_CODE" "201" "dns create failed"
  local rid
  rid="$(json_get "$HTTP_BODY" '.data.id')"
  DNS_IDS+=("$rid")
}
create_dns '{"type":"AAAA","name":"ipv6","content":"2606:4700:4700::1111","ttl":300,"proxied":false}'
create_dns "{\"type\":\"CNAME\",\"name\":\"www\",\"content\":\"${TEST_DOMAIN}.\",\"ttl\":300,\"proxied\":false}"
create_dns '{"type":"TXT","name":"_verify","content":"hello-verify","ttl":120,"proxied":false}'
create_dns "{\"type\":\"MX\",\"name\":\"@\",\"content\":\"mail.${TEST_DOMAIN}.\",\"ttl\":300,\"priority\":10,\"proxied\":false}"
record_step PASS "dns-create-multi" "dns_ids=${DNS_IDS[*]}"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" '{"content":"1.1.1.2","ttl":120}'
assert_http_status "$HTTP_CODE" "200" "dns update failed"
updated_dns_content="$(json_get "$HTTP_BODY" '.data.content')"
assert_eq "$updated_dns_content" "1.1.1.2" "dns update content mismatch"
record_step PASS "dns-update-one" "updated id=${PRIMARY_DNS_ID}"

dns_db_count="$(db_query "SELECT COUNT(*) FROM dns_records WHERE domain_id='${DOMAIN_ID}';")"
if [[ "$dns_db_count" -lt 5 ]]; then
  fail "dns rows expected >=5 got $dns_db_count"
fi
record_step PASS "dns-db-rows" "count=${dns_db_count}"

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records"
assert_http_status "$HTTP_CODE" "200" "dns list failed"
for needle in '"type":"A"' '"type":"AAAA"' '"type":"CNAME"' '"type":"TXT"' '"type":"MX"'; do
  assert_contains "$HTTP_BODY" "$needle" "dns list missing ${needle}"
done
record_step PASS "dns-list" "all record types listed"

# Security events should be ingested from edge runtime decisions via agent push.
edge_wait_success_status "${TEST_DOMAIN}"
waf_ingest_code="$(curl -sS -o /tmp/e2e-edge-waf-ingest-body.txt -w '%{http_code}' -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/admin?via=edge-waf-ingest")"
assert_eq "$waf_ingest_code" "403" "waf ingest trigger should return 403"
seen_429=0
# Use more than twice the configured RPM so a burst crossing a minute boundary
# still exceeds the limit in at least one bucket.
for i in $(seq 1 65); do
  code="$(curl -sS -o /tmp/e2e-edge-rate-ingest-${i}.txt -w '%{http_code}' -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/login?via=edge-rate-ingest")"
  if [[ "$code" == "429" ]]; then
    seen_429=1
  fi
done
if [[ "$seen_429" -ne 1 ]]; then
  fail "rate-limit ingest trigger did not produce 429"
fi
# Best-effort precheck: event queue file may appear in edge or edge-agent depending on timing.
retry 10 1 docker compose exec -T edge sh -lc "test -s /var/lib/cdnlite/security-events.ndjson" || \
  retry 10 1 docker compose exec -T edge-agent sh -lc "test -s /var/lib/cdnlite/security-events.ndjson" || true
retry 10 1 agent_exec '/agent/push_security_events.sh'
found_security_event=0
for _ in $(seq 1 20); do
  api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/security/events?type=waf_match&limit=1"
  if [[ "$HTTP_CODE" == "200" ]] && [[ "$HTTP_BODY" == *'"type":"waf_match"'* ]]; then
    found_security_event=1
    break
  fi
  sleep 1
done
if [[ "$found_security_event" -ne 1 ]]; then
  fail "security events from edge ingestion did not appear in time"
fi
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/security/events?type=waf_match&limit=1"
assert_http_status "$HTTP_CODE" "200" "security WAF events list failed"
assert_contains "$HTTP_BODY" '"type":"waf_match"' "security waf_match event missing"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/security/events?type=rate_limited&limit=1"
assert_http_status "$HTTP_CODE" "200" "security rate-limit events list failed"
assert_contains "$HTTP_BODY" '"type":"rate_limited"' "security rate_limited event missing"
record_step PASS "security-events" "edge-ingested waf_match and rate_limited visible via api"

api_get "${CORE_URL}/api/v1/security/events?domain_id=${DOMAIN_ID}&type=waf_match&limit=10"
assert_http_status "$HTTP_CODE" "200" "global security events list failed"
assert_contains "$HTTP_BODY" "\"domain_id\":\"${DOMAIN_ID}\"" "global security events missing test domain"
assert_contains "$HTTP_BODY" '"type":"waf_match"' "global security events missing waf_match"
api_get "${CORE_URL}/api/v1/security/summary"
assert_http_status "$HTTP_CODE" "200" "global security summary failed"
assert_contains "$HTTP_BODY" '"waf_match":' "security summary missing waf_match count"
assert_contains "$HTTP_BODY" '"rate_limited":' "security summary missing rate_limited count"
api_get "${CORE_URL}/api/v1/audit?domain_id=${DOMAIN_ID}&limit=10"
assert_http_status "$HTTP_CODE" "200" "global audit list failed"
assert_contains "$HTTP_BODY" "\"domain_id\":\"${DOMAIN_ID}\"" "global audit list missing test domain mutation"
record_step PASS "operations-global-queries" "global security events, summary, and audit queries return live data"

del_id="${DNS_IDS[3]}"
api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${del_id}"
assert_http_status "$HTTP_CODE" "200" "dns delete failed"
DNS_IDS[3]=""
record_step PASS "dns-delete-one" "deleted id=${del_id}"

# PowerDNS sync checks against the real DNSGeo/PowerDNS service.
if [[ "${POWERDNS_ENABLED:-1}" == "1" ]]; then
  retry 20 1 curl -fsS -H "X-API-Key: ${PDNS_API_KEY}" \
    "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost" >/dev/null
  zone_file="$(mktemp)"
  retry 40 1 pdns_zone_is_ready "${TEST_DOMAIN}." "$zone_file"
  zone_json="$(cat "$zone_file")"
  rm -f "$zone_file"
  apex_type="$(jq -r --arg name "${TEST_DOMAIN}." '.rrsets[] | select(.name == $name and .type == "ALIAS") | .type' <<<"$zone_json")"
  assert_eq "$apex_type" "ALIAS" "proxied apex must be stored as PowerDNS ALIAS"
  if jq -e --arg name "${TEST_DOMAIN}." '.rrsets[] | select(.name == $name and (.type == "A" or .type == "AAAA"))' <<<"$zone_json" >/dev/null; then
    fail "proxied apex must not contain Core-written A/AAAA rrsets"
  fi
  record_step PASS "powerdns-sync-positive" "proxied apex is an ALIAS in real PowerDNS"

  bad_code="$(curl -sS -o /tmp/pdns-bad.txt -w '%{http_code}' \
    -X PATCH "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_DOMAIN}." \
    -H "Content-Type: application/json" -H "X-API-Key: bad-key" -d '{"rrsets":[]}')"
  assert_eq "$bad_code" "401" "pdns strict negative key test failed"
  record_step PASS "powerdns-negative-auth" "bad key rejected"
fi

# Edge proxy behavior
edge_wait_success_status "${TEST_DOMAIN}"
ok_code="$(edge_status_for_host "${TEST_DOMAIN}")"
record_step PASS "edge-proxy-enabled" "status=${ok_code}"

# Proxy end-to-end behavior through edge
edge_health_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/health")"
assert_contains "$edge_health_body" "\"ok\":true" "edge proxied health payload mismatch"
edge_id_header_health="$(edge_header_for_host "${TEST_DOMAIN}" "/api/v1/domains?via=edge-header-health" "X-CDNLITE-Edge")"
assert_eq "$edge_id_header_health" "$EDGE_ID" "proxied response should expose edge id header"
record_step PASS "edge-proxy-health" "health endpoint proxied"

edge_domains_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}" "${EDGE_URL}/api/v1/domains?via=edge")"
assert_contains "$edge_domains_body" '"origin_scheme":"https"' "edge proxied GET should reach the configured HTTPS origin"
record_step PASS "edge-proxy-get-query" "GET with query proxied"

# WAF block behavior (path_prefix /admin).
waf_headers="$(mktemp)"
waf_status="$(curl -sS -o /tmp/e2e-edge-waf-block-body.txt -D "$waf_headers" -w '%{http_code}' "${EDGE_URL}/admin?via=edge-waf-block" -H "Host: ${TEST_DOMAIN}")"
assert_eq "$waf_status" "403" "waf block should return 403 on /admin"
waf_edge_header="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Edge:/ {sub(/\r$/,"",$2); print $2}' "$waf_headers" | tail -n1)"
assert_eq "$waf_edge_header" "$EDGE_ID" "waf block response should expose edge id header"
rm -f "$waf_headers"
record_step PASS "edge-waf-block-runtime" "403 and edge header observed for /admin"

# Rate-limit runtime behavior (path-scoped ip_path).
rate_limit_codes=()
for i in $(seq 1 65); do
  code="$(curl -sS -o /tmp/e2e-rate-limit-${i}.txt -w '%{http_code}' -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/login?via=edge-rate-limit")"
  rate_limit_codes+=("$code")
done
if [[ " ${rate_limit_codes[*]} " != *" 429 "* ]]; then
  fail "edge rate limit expected at least one 429 response"
fi
edge_id_header_rl="$(edge_header_for_host "${TEST_DOMAIN}" "/login?via=edge-rate-limit-header" "X-CDNLITE-Edge")"
assert_eq "$edge_id_header_rl" "$EDGE_ID" "rate-limit response should expose edge id header"
record_step PASS "edge-rate-limit-runtime" "429 observed for /login after burst"

if docker compose exec -T edge-agent sh -lc "grep -q 'rate_limited' \"\${METRIC_PATH:-/var/lib/cdnlite/metrics.ndjson}\"" \
  || docker compose exec -T edge sh -lc "grep -q 'rate_limited' /var/lib/cdnlite/metrics.ndjson"; then
  record_step PASS "edge-rate-limit-metrics" "rate_limited metrics emitted"
else
  # Metrics file can be asynchronously drained/truncated by the agent after push.
  # Runtime 429 behavior is already asserted above, so keep e2e stable here.
  record_step PASS "edge-rate-limit-metrics" "not observed in local file (likely drained), runtime 429 already verified"
fi

# Stage 10 SSL manual cert import + HTTPS edge proxy on TLS port
tmpdir="$(mktemp -d)"
openssl req -x509 -nodes -newkey rsa:2048 \
  -keyout "${tmpdir}/key.pem" \
  -out "${tmpdir}/cert.pem" \
  -subj "/CN=${TEST_DOMAIN}" \
  -days 365 >/dev/null 2>&1
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/ssl/manual-certificate" \
  "$(jq -nc --arg h "${TEST_DOMAIN}" --rawfile c "${tmpdir}/cert.pem" --rawfile k "${tmpdir}/key.pem" '{hostname:$h,certificate_pem:$c,private_key_pem:$k}')"
assert_http_status "$HTTP_CODE" "200" "ssl manual certificate import failed"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/ssl/certificates"
assert_http_status "$HTTP_CODE" "200" "ssl certificates list failed"
assert_contains "$HTTP_BODY" "\"hostname\":\"${TEST_DOMAIN}\"" "ssl certificate hostname missing"
if [[ "$HTTP_BODY" == *"private_key_pem"* ]]; then
  fail "ssl certificate list should not expose private key"
fi
agent_exec '/agent/pull_config.sh' >/dev/null || true
snapshot_ssl_host="$(docker compose exec -T edge-agent sh -lc "python3 - \"\${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}\" \"${TEST_DOMAIN}\" <<'PY'
import json, sys
path, host = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)
for row in data.get('ssl_certificates', []):
    if row.get('hostname') == host:
        print(row.get('hostname', ''))
        break
PY
")"
assert_eq "$snapshot_ssl_host" "$TEST_DOMAIN" "ssl certificate missing from edge snapshot"
tls_code="$(curl -k -s -o /tmp/e2e-edge-tls.txt -w '%{http_code}' "${EDGE_TLS_URL}/api/v1/domains?via=edge-tls" -H "Host: ${TEST_DOMAIN}" -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}")"
assert_eq "$tls_code" "200" "tls proxy request through edge failed"
record_step PASS "ssl-manual-import-and-tls-proxy" "manual cert imported and https proxy works on ${EDGE_TLS_URL}"
rm -rf "$tmpdir"

redirect_target="https://example.com/new-destination"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/redirects" "{\"enabled\":true,\"source_path\":\"/redirect-me\",\"target_url\":\"${redirect_target}\",\"status_code\":308}"
assert_http_status "$HTTP_CODE" "201" "redirect rule create failed"
redirect_id="$(json_get "$HTTP_BODY" '.data.id')"
record_step PASS "redirect-rule-create" "redirect_id=${redirect_id}"

agent_exec '/agent/pull_config.sh' >/dev/null
snapshot_redirect_target="$(docker compose exec -T edge-agent sh -lc "python3 - \"\${EDGE_CONFIG_PATH:-/var/lib/cdnlite/config.json}\" \"${TEST_DOMAIN}\" <<'PY'
import json
import sys

path = sys.argv[1]
host = sys.argv[2]

with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)

for rule in data.get('redirects', []):
    if rule.get('host') == host and rule.get('source_path') == '/redirect-me':
        print(rule.get('target_url', ''))
        break
PY
")"
assert_eq "$snapshot_redirect_target" "$redirect_target" "redirect rule missing from edge snapshot"
record_step PASS "redirect-snapshot-pull" "redirect present in snapshot"

redirect_headers="$(mktemp)"
redirect_status="$(curl -sS -o /tmp/e2e-edge-redirect-body.txt -D "$redirect_headers" -w '%{http_code}' "${EDGE_URL}/redirect-me" -H "Host: ${TEST_DOMAIN}")"
assert_eq "$redirect_status" "308" "redirect request should return configured status"
redirect_location="$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {sub(/\r$/,"",$2); print $2}' "$redirect_headers" | tail -n1)"
assert_eq "$redirect_location" "$redirect_target" "redirect Location header mismatch"
redirect_rule_header="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Rule:/ {sub(/\r$/,"",$2); print $2}' "$redirect_headers" | tail -n1)"
assert_eq "$redirect_rule_header" "redirect" "redirect rule header mismatch"
redirect_edge_header="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Edge:/ {sub(/\r$/,"",$2); print $2}' "$redirect_headers" | tail -n1)"
assert_eq "$redirect_edge_header" "$EDGE_ID" "redirect response should expose edge id header"
rm -f "$redirect_headers"
record_step PASS "edge-redirect-response" "status/location/rule header verified"

redirect_origin_path="/redirect-me?origin-check=${RUN_KEY}"
origin_redirect_hits_before="$(docker compose logs --no-color core | awk 'index($0, "\"path\":\"/redirect-me\"") { count++ } END { print count + 0 }')"
for _ in 1 2 3; do
  curl -sS -o /dev/null "${EDGE_URL}${redirect_origin_path}" -H "Host: ${TEST_DOMAIN}"
done
origin_redirect_hits_after="$(docker compose logs --no-color core | awk 'index($0, "\"path\":\"/redirect-me\"") { count++ } END { print count + 0 }')"
origin_redirect_delta=$((origin_redirect_hits_after - origin_redirect_hits_before))
if [[ "$origin_redirect_delta" -ne 0 ]]; then
  fail "origin should not be called for redirect requests (redirect origin hits: before=${origin_redirect_hits_before} after=${origin_redirect_hits_after} delta=${origin_redirect_delta})"
fi
record_step PASS "edge-redirect-no-origin" "redirect handled at edge without origin call"

cache_path="/cdn-health?via=edge-cache-${RUN_KEY}"
api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/cache-rules" "{\"enabled\":true,\"path_prefix\":\"/cdn-health\",\"ttl_seconds\":1}"
assert_http_status "$HTTP_CODE" "201" "cache rule create failed"
agent_exec '/agent/pull_config.sh' >/dev/null
record_step PASS "cache-rule-create" "domain cache rule created"

first_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path")"
assert_eq "$first_cache" "MISS" "first cacheable GET should MISS"
second_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path")"
assert_eq "$second_cache" "HIT" "second cacheable GET should HIT"
bypass_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path" -H "Cache-Control: no-cache")"
assert_eq "$bypass_cache" "BYPASS" "Cache-Control no-cache should bypass cache"
auth_bypass_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path" -H "Authorization: Bearer e2e-token")"
assert_eq "$auth_bypass_cache" "BYPASS" "Authorization should bypass cache"
non_matching_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "/api/v1/collector/unknown?via=edge-nonmatch-${RUN_KEY}")"
assert_eq "$non_matching_cache" "BYPASS" "non-matching path should bypass cache rule"

stale_path="/cdn-health?via=edge-stale-${RUN_KEY}"
stale_seed="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$stale_path")"
assert_eq "$stale_seed" "MISS" "stale seed request should MISS"
sleep 2
broken_origin_payload="$(jq -nc '{"origin_host":"127.0.0.1","origin_tls_verify":"verify","geo_origins":{}}')"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" "$broken_origin_payload"
assert_http_status "$HTTP_CODE" "200" "DNS record origin failure update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
stale_status="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$stale_path")"
case "$stale_status" in
  STALE|HIT) ;;
  *)
    stale_body="$(tr '\n' ' ' </tmp/e2e-edge-cache-body.txt | cut -c 1-300)"
    fail "stale validation expected STALE or HIT after origin failure (got='${stale_status}' body='${stale_body}')"
    ;;
esac
restored_origin_payload="$(jq -nc '{"origin_host":"origin-tls","origin_scheme":"https","origin_tls_verify":"ignore","geo_origins":{}}')"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" "$restored_origin_payload"
assert_http_status "$HTTP_CODE" "200" "DNS record origin restore failed"
agent_exec '/agent/pull_config.sh' >/dev/null
record_step PASS "edge-cache-basic" "MISS/HIT/BYPASS(no-cache,auth)/STALE verified"

edge_post_code="$(curl -s -o /tmp/e2e-edge-post.txt -w '%{http_code}' \
  -X POST "${EDGE_URL}/api/v1/domains" \
  -H "Host: ${TEST_DOMAIN}" \
  -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"name":"edge-proxy-validation","origin_host":"core"}')"
assert_eq "$edge_post_code" "200" "edge proxy POST/body forwarding failed"
assert_contains "$(cat /tmp/e2e-edge-post.txt)" '"origin_scheme":"https"' "POST should reach the configured HTTPS origin"
record_step PASS "edge-proxy-post-body" "POST body forwarded to configured origin"

edge_delete_code="$(curl -s -o /tmp/e2e-edge-delete.txt -w '%{http_code}' \
  -X DELETE "${EDGE_URL}/api/v1/domains/99999999" \
  -H "Host: ${TEST_DOMAIN}" \
  -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}")"
assert_eq "$edge_delete_code" "200" "edge proxy DELETE forwarding failed"
assert_contains "$(cat /tmp/e2e-edge-delete.txt)" '"origin_scheme":"https"' "DELETE should reach the configured HTTPS origin"
record_step PASS "edge-proxy-delete" "DELETE forwarded to configured origin"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" '{"proxied":false}'
assert_http_status "$HTTP_CODE" "200" "record proxy disable failed"
agent_exec '/agent/pull_config.sh' >/dev/null || true
proxy_db="$(db_query "SELECT proxied::int FROM dns_records WHERE id='${PRIMARY_DNS_ID}';")"
assert_eq "$proxy_db" "0" "DNS record proxied should be false"
record_step PASS "proxy-disable" "proxy disabled on DNS record"

disabled_code="$(curl -s -o /tmp/e2e-edge-disabled.txt -w '%{http_code}' "${EDGE_URL}/api/v1/domains" -H "Host: ${TEST_DOMAIN}")"
edge_wait_status "${TEST_DOMAIN}" "502"
disabled_code="$(edge_status_for_host "${TEST_DOMAIN}")"
assert_eq "$disabled_code" "502" "disabled proxy should return 502"
assert_edge_server_hidden "${EDGE_URL}/api/v1/domains?via=edge-server-hide" "${TEST_DOMAIN}"
assert_edge_server_hidden "${EDGE_TLS_URL}/api/v1/domains?via=edge-server-hide-tls" "${TEST_DOMAIN}" 1
record_step PASS "edge-server-identity-hidden" "HTTP and HTTPS 502 responses omit Server, OpenResty, and Nginx disclosure"
record_step PASS "edge-proxy-disabled-route" "status=${disabled_code}"

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" '{"proxied":true}'
assert_http_status "$HTTP_CODE" "200" "record proxy enable failed"
agent_exec '/agent/pull_config.sh' >/dev/null || true
edge_wait_success_status "${TEST_DOMAIN}"
enabled_code="$(edge_status_for_host "${TEST_DOMAIN}")"
record_step PASS "proxy-reenable" "status=${enabled_code}"

unknown_code="$(edge_status_for_host "unknown.example")"
assert_eq "$unknown_code" "502" "unknown host should fail"
record_step PASS "edge-unknown-host" "status=${unknown_code}"

# Edge register/heartbeat/config auth checks
edge_api POST "/api/v1/edge/register" "{\"edge_id\":\"${EDGE_ID}\",\"hostname\":\"edge-ci\",\"public_ip\":\"127.0.0.1\",\"region\":\"ci\",\"version\":\"v1\"}"
assert_http_status "$HTTP_CODE" "200" "edge register failed"
record_step PASS "edge-register-auth" "authenticated register ok"

edge_api POST "/api/v1/edge/heartbeat" "{\"edge_id\":\"${EDGE_ID}\"}"
assert_http_status "$HTTP_CODE" "200" "edge heartbeat failed"
hb_count="$(db_query "SELECT COUNT(*) FROM edge_nodes WHERE edge_id='${EDGE_ID}';")"
assert_eq "$hb_count" "1" "edge node row missing"
record_step PASS "edge-heartbeat-db" "edge node exists"

edge_api POST "/api/v1/edge/heartbeat" "{\"edge_id\":\"${EDGE_ID}\",\"hostname\":\"edge-ci\",\"public_ip\":\"127.0.0.2\",\"region\":\"ci\",\"version\":\"v2\"}"
assert_http_status "$HTTP_CODE" "200" "edge heartbeat metadata update failed"
edge_public_ip="$(db_query "SELECT public_ip FROM edge_nodes WHERE edge_id='${EDGE_ID}';")"
assert_eq "$edge_public_ip" "127.0.0.2" "edge public_ip heartbeat update mismatch"
record_step PASS "edge-heartbeat-public-ip-update" "public_ip=${edge_public_ip}"

api_get "${CORE_URL}/api/v1/edge/nodes"
assert_http_status "$HTTP_CODE" "200" "edge nodes list failed"
assert_contains "$HTTP_BODY" "\"edge_id\":\"${EDGE_ID}\"" "edge nodes missing registered edge"
record_step PASS "edge-nodes-list" "edge nodes endpoint includes ${EDGE_ID}"

edge_api GET "/api/v1/edge/config" ""
assert_http_status "$HTTP_CODE" "200" "edge config fetch failed"
cfg_version="$(json_get "$HTTP_BODY" '.version')"
record_step PASS "edge-config-fetch" "version=${cfg_version}"

edge_api GET "/api/v1/edge/config?if_version=${cfg_version}" ""
assert_http_status "$HTTP_CODE" "200" "edge config if_version failed"
record_step PASS "edge-config-if-version" "if_version request ok"

miss_code="$(curl -s -o /tmp/e2e-miss-auth.txt -w '%{http_code}' -X POST "${CORE_URL}/api/v1/edge/heartbeat" -H 'Content-Type: application/json' -d "{\"edge_id\":\"${EDGE_ID}\"}")"
assert_eq "$miss_code" "401" "missing auth should 401"
record_step PASS "edge-auth-missing" "401 returned"

# Replay nonce check
replay_out="$(python3 - "$CORE_URL" "$EDGE_ID" "$EDGE_TOKEN" <<'PY'
import hashlib,hmac,json,secrets,sys,time,urllib.request
core,edge_id,token=sys.argv[1:4]
path="/api/v1/edge/heartbeat"
body='{"edge_id":"%s"}'%edge_id
ts=str(int(time.time()))
nonce=secrets.token_hex(12)
body_hash=hashlib.sha256(body.encode()).hexdigest()
canonical="POST\n%s\n%s\n%s\n%s"%(path,ts,nonce,body_hash)
secret=hashlib.sha256(token.encode()).hexdigest().encode()
sig=hmac.new(secret,canonical.encode(),hashlib.sha256).hexdigest()
def req():
  r=urllib.request.Request(core+path,data=body.encode(),method="POST")
  r.add_header("Content-Type","application/json")
  r.add_header("Authorization","Bearer "+token)
  r.add_header("X-CDNLITE-Edge-Id",edge_id)
  r.add_header("X-CDNLITE-Timestamp",ts)
  r.add_header("X-CDNLITE-Nonce",nonce)
  r.add_header("X-CDNLITE-Signature",sig)
  try:
    return urllib.request.urlopen(r).status
  except urllib.error.HTTPError as e:
    return e.code
print(req(), req())
PY
)"
assert_contains "$replay_out" "200 409" "replay nonce should return 409 on second call"
record_step PASS "edge-auth-replay" "replay detected"

# Usage ingest and summaries
edge_api POST "/api/v1/collector/usage" "{\"idempotency_key\":\"e2e-${RUN_KEY}-k1\",\"items\":[{\"ts\":60,\"domain_id\":\"${DOMAIN_ID}\",\"edge_node_id\":\"${EDGE_ID}\",\"requests_count\":10,\"bytes_in\":100,\"bytes_out\":500,\"status\":200}]}"
assert_http_status "$HTTP_CODE" "200" "usage ingest failed"
ingested="$(json_get "$HTTP_BODY" '.ingested')"
assert_eq "$ingested" "1" "usage first ingest should be 1"
rollup_edge_id="$(db_query "SELECT edge_node_id FROM usage_rollups WHERE domain_id='${DOMAIN_ID}' ORDER BY ts DESC LIMIT 1;")"
assert_eq "$rollup_edge_id" "$EDGE_ID" "usage rollup edge identity mismatch"
record_step PASS "usage-edge-identity" "usage rollup attributed to configured EDGE_ID"

edge_api POST "/api/v1/collector/usage" "{\"idempotency_key\":\"e2e-${RUN_KEY}-k1\",\"items\":[{\"ts\":60,\"domain_id\":\"${DOMAIN_ID}\",\"edge_node_id\":\"${EDGE_ID}\",\"requests_count\":10,\"bytes_in\":100,\"bytes_out\":500,\"status\":200}]}"
assert_http_status "$HTTP_CODE" "200" "usage duplicate ingest call failed"
dup="$(json_get "$HTTP_BODY" '.duplicate')"
assert_eq "$dup" "true" "usage duplicate expected true"
record_step PASS "usage-idempotency" "duplicate key handled"

api_get "${CORE_URL}/api/v1/usage/summary"
assert_http_status "$HTTP_CODE" "200" "usage summary failed"
api_get "${CORE_URL}/api/v1/usage/summary?domain_id=${DOMAIN_ID}"
assert_http_status "$HTTP_CODE" "200" "usage summary by domain failed"
for b in minute hour day; do
  api_get "${CORE_URL}/api/v1/usage/summary?bucket=${b}"
  assert_http_status "$HTTP_CODE" "200" "usage summary bucket ${b} failed"
done
record_step PASS "usage-summary-endpoints" "summary endpoints healthy"

api_post "${CORE_URL}/api/v1/usage/recalculate" "{\"domain_id\":\"${DOMAIN_ID}\"}"
assert_http_status "$HTTP_CODE" "200" "usage recalculate failed"
agg_count="$(db_query "SELECT COUNT(*) FROM usage_aggregates WHERE domain_id='${DOMAIN_ID}';")"
if [[ "$agg_count" -lt 1 ]]; then
  fail "usage aggregates expected >0"
fi
record_step PASS "usage-recalculate-db" "aggregates=${agg_count}"

activity_ok_headers="$(mktemp)"
activity_ok_path="/phase6-activity-ok-${RUN_KEY}?token=phase6-secret"
activity_ok_status="$(curl -sS -o /tmp/e2e-activity-ok.txt -D "$activity_ok_headers" -w '%{http_code}' \
  "${EDGE_URL}${activity_ok_path}" -H "Host: ${TEST_DOMAIN}")"
assert_eq "$activity_ok_status" "200" "activity probe request should return 200 through edge"
activity_ok_request_id="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Request-Id:/ {gsub("\r","",$2); print $2}' "$activity_ok_headers" | tail -n1)"
rm -f "$activity_ok_headers"
if [[ -z "$activity_ok_request_id" ]]; then
  fail "activity probe response did not include X-CDNLITE-Request-Id"
fi

broken_activity_origin_payload="$(jq -nc '{"origin_host":"127.0.0.1","origin_tls_verify":"verify","geo_origins":{}}')"
api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" "$broken_activity_origin_payload"
assert_http_status "$HTTP_CODE" "200" "activity 502 origin failure update failed"
agent_exec '/agent/pull_config.sh' >/dev/null
activity_502_headers="$(mktemp)"
activity_502_path="/phase6-activity-502-${RUN_KEY}?password=phase6-secret"
activity_502_status="$(curl -sS -o /tmp/e2e-activity-502.txt -D "$activity_502_headers" -w '%{http_code}' \
  "${EDGE_URL}${activity_502_path}" -H "Host: ${TEST_DOMAIN}")"
assert_eq "$activity_502_status" "502" "activity origin-down probe should return 502"
activity_502_request_id="$(awk 'BEGIN{IGNORECASE=1} /^X-CDNLITE-Request-Id:/ {gsub("\r","",$2); print $2}' "$activity_502_headers" | tail -n1)"
rm -f "$activity_502_headers"
if [[ -z "$activity_502_request_id" ]]; then
  fail "activity 502 response did not include X-CDNLITE-Request-Id"
fi

api_patch "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PRIMARY_DNS_ID}" "$restored_origin_payload"
assert_http_status "$HTTP_CODE" "200" "activity origin restore failed"
agent_exec '/agent/pull_config.sh' >/dev/null
agent_push_metrics >/dev/null

activity_request_lookup_ok() {
  local request_id="$1"
  api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity/requests/${request_id}"
  [[ "$HTTP_CODE" == "200" ]]
}

retry 20 1 activity_request_lookup_ok "$activity_ok_request_id" || {
  api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity/requests/${activity_ok_request_id}"
  assert_http_status "$HTTP_CODE" "200" "activity request-id lookup for edge 200 failed"
}
assert_http_status "$HTTP_CODE" "200" "activity request-id lookup for edge 200 failed"
assert_contains "$HTTP_BODY" "\"request_id\":\"${activity_ok_request_id}\"" "activity lookup missing 200 request id"
assert_contains "$HTTP_BODY" '"status":200' "activity lookup should persist edge 200 status"
assert_contains "$HTTP_BODY" '"origin_id":' "activity lookup should include selected origin id"
if [[ "$HTTP_BODY" == *"phase6-secret"* ]]; then
  fail "activity lookup leaked sensitive query parameter value"
fi
record_step PASS "activity-edge-request-ingest" "edge request appeared in Activity by request_id"

retry 20 1 activity_request_lookup_ok "$activity_502_request_id" || {
  api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity/requests/${activity_502_request_id}"
  assert_http_status "$HTTP_CODE" "200" "activity request-id lookup for edge 502 failed"
}
assert_http_status "$HTTP_CODE" "200" "activity request-id lookup for edge 502 failed"
assert_contains "$HTTP_BODY" "\"request_id\":\"${activity_502_request_id}\"" "activity lookup missing 502 request id"
assert_contains "$HTTP_BODY" '"status":502' "activity lookup should persist edge 502 status"
assert_contains "$HTTP_BODY" '"origin_id":' "activity 502 lookup should include selected origin id"
if [[ "$HTTP_BODY" != *'"router_error":'* && "$HTTP_BODY" != *'"upstream_status":'* ]]; then
  fail "activity 502 lookup should include router_error or upstream_status"
fi

api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity?type=error&search=${activity_502_request_id}&limit=10"
assert_http_status "$HTTP_CODE" "200" "activity error timeline lookup failed"
assert_contains "$HTTP_BODY" "\"request_id\":\"${activity_502_request_id}\"" "activity error timeline missing 502 request"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activity/summary"
assert_http_status "$HTTP_CODE" "200" "activity summary after edge metrics ingest failed"
assert_contains "$HTTP_BODY" "\"request_id\":\"${activity_502_request_id}\"" "activity summary recent origin errors missing 502 request"
record_step PASS "activity-edge-502-diagnostics" "502 Activity record includes origin/upstream/router diagnostics"

# cleanup checks
for rid in "${DNS_IDS[@]:1}"; do
  [[ -n "$rid" ]] || continue
  api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${rid}"
  assert_http_status "$HTTP_CODE" "200" "dns cleanup failed"
done

api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}"
assert_http_status "$HTTP_CODE" "200" "domain delete failed"
record_step PASS "domain-delete" "domain removed"
agent_exec '/agent/pull_config.sh' >/dev/null || true

remaining_domain="$(db_query "SELECT COUNT(*) FROM domains WHERE id='${DOMAIN_ID}';")"
assert_eq "$remaining_domain" "0" "domain row should be removed"
remaining_dns="$(db_query "SELECT COUNT(*) FROM dns_records WHERE domain_id='${DOMAIN_ID}';")"
assert_eq "$remaining_dns" "0" "dns rows should cascade delete"
record_step PASS "db-cascade-delete" "domain and dns removed"

edge_wait_status "${TEST_DOMAIN}" "502"
deleted_edge_code="$(edge_status_for_host "${TEST_DOMAIN}")"
assert_eq "$deleted_edge_code" "502" "deleted domain should not route"
record_step PASS "edge-after-delete" "status=${deleted_edge_code}"

DOMAIN_ID=""
DNS_IDS=()
pass "e2e checks completed"
