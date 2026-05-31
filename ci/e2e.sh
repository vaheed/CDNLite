#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL:-http://localhost:8081}"
POWERDNS_API_URL="${POWERDNS_API_URL:-http://localhost:8089}"
POWERDNS_API_KEY="${POWERDNS_API_KEY:-test-key}"
EDGE_ID="${EDGE_ID:-edge-local-1}"
EDGE_TOKEN="${EDGE_TOKEN:-edge-dev-token}"
CI_ENV_NAME="${CI_ENV_NAME:-e2e}"
export CORE_URL EDGE_URL POWERDNS_API_URL POWERDNS_API_KEY EDGE_ID EDGE_TOKEN CI_ENV_NAME

RUN_KEY="${GITHUB_RUN_ID:-local}-$RANDOM"
TEST_DOMAIN="e2e-${RUN_KEY}.test.local"
TEST_DOMAIN_2="e2e-${RUN_KEY}-b.test.local"
SITE_ID=""
DNS_IDS=()

init_report

on_error() {
  local rc=$?
  local line="${1:-unknown}"
  local cmd="${2:-unknown}"
  echo "e2e: error rc=$rc at line=$line cmd=$cmd, printing diagnostics"
  docker compose ps || true
  docker compose logs --no-color || true
  for svc in core edge edge-agent postgres powerdns; do
    if compose_has_service "$svc"; then
      echo "----- ${svc} (tail 200) -----"
      docker compose logs --no-color --tail=200 "$svc" || true
    fi
  done
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

cleanup() {
  if [[ -n "$SITE_ID" ]]; then
    for rid in "${DNS_IDS[@]}"; do
      curl -sS -X DELETE "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records/${rid}" >/dev/null || true
    done
    curl -sS -X DELETE "${CORE_URL}/api/v1/sites/${SITE_ID}" >/dev/null || true
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
  curl -s -o /tmp/e2e-edge-status.txt -w '%{http_code}' "${EDGE_URL}/api/v1/sites" -H "Host: ${host}"
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

retry 40 2 curl -fsS "$CORE_URL/health" >/dev/null
retry 40 2 curl -fsS "$EDGE_URL/health" >/dev/null
wait_for_postgres
retry 40 2 db_query "SELECT 1;" >/dev/null
record_step PASS "stack-ready" "core and edge health passed"

docker compose exec -T core php artisan cdn:edge:register-token --edge_id="$EDGE_ID" --token="$EDGE_TOKEN" >/dev/null
record_step PASS "edge-token-register" "edge token provisioned"

# Site lifecycle
api_post "${CORE_URL}/api/v1/sites" \
  "{\"name\":\"e2e-site-${RUN_KEY}\",\"domain\":\"${TEST_DOMAIN}\",\"origin_host\":\"core\",\"origin_port\":8080,\"proxy_enabled\":true,\"geo_origins\":{\"DEFAULT\":{\"scheme\":\"http\",\"host\":\"core\",\"port\":8080},\"IR\":{\"scheme\":\"http\",\"host\":\"core\",\"port\":8080}}}"
assert_http_status "$HTTP_CODE" "201" "site create failed"
SITE_ID="$(json_get "$HTTP_BODY" '.data.id')"
record_step PASS "site-create" "site_id=${SITE_ID} domain=${TEST_DOMAIN}"

site_count="$(db_query "SELECT COUNT(*) FROM sites WHERE id='${SITE_ID}' AND domain='${TEST_DOMAIN}';")"
assert_eq "$site_count" "1" "site missing in db"
record_step PASS "site-db-row" "site persisted"

api_get "${CORE_URL}/api/v1/sites"
assert_http_status "$HTTP_CODE" "200" "site list failed"
assert_contains "$HTTP_BODY" "$TEST_DOMAIN" "site not listed"
record_step PASS "site-list" "site listed"

api_patch "${CORE_URL}/api/v1/sites/${SITE_ID}" '{"name":"e2e-site-updated","origin_host":"core","origin_port":8080}'
assert_http_status "$HTTP_CODE" "200" "site update failed"
updated_name="$(json_get "$HTTP_BODY" '.data.name')"
assert_eq "$updated_name" "e2e-site-updated" "site name update mismatch"
record_step PASS "site-update" "site updated"

api_post "${CORE_URL}/api/v1/sites" '{"name":"bad","origin_host":"core"}'
assert_http_status "$HTTP_CODE" "422" "missing domain validation expected"
record_step PASS "site-validation-missing-domain" "422 returned"

api_post "${CORE_URL}/api/v1/sites" "{\"name\":\"dup\",\"domain\":\"${TEST_DOMAIN}\",\"origin_host\":\"core\"}"
assert_http_status "$HTTP_CODE" "422" "duplicate domain should return 422"
record_step PASS "site-validation-duplicate" "duplicate rejected with code=${HTTP_CODE}"

api_patch "${CORE_URL}/api/v1/sites/99999999" '{"name":"nope"}'
assert_http_status "$HTTP_CODE" "404" "unknown site should 404"
record_step PASS "site-validation-unknown" "unknown site 404"

# Force and verify config propagation to edge before route assertions.
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true
edge_wait_config_host "${TEST_DOMAIN}"

# DNS lifecycle
create_dns() {
  local payload="$1"
  api_post "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records" "$payload"
  assert_http_status "$HTTP_CODE" "201" "dns create failed"
  local rid
  rid="$(json_get "$HTTP_BODY" '.data.id')"
  DNS_IDS+=("$rid")
}
create_dns '{"type":"A","name":"@","content":"1.1.1.1","ttl":300,"proxied":true}'
create_dns '{"type":"AAAA","name":"@","content":"2606:4700:4700::1111","ttl":300,"proxied":true}'
create_dns "{\"type\":\"CNAME\",\"name\":\"www\",\"content\":\"${TEST_DOMAIN}.\",\"ttl\":300,\"proxied\":false}"
create_dns '{"type":"TXT","name":"_verify","content":"hello-verify","ttl":120,"proxied":false}'
create_dns "{\"type\":\"MX\",\"name\":\"@\",\"content\":\"mail.${TEST_DOMAIN}.\",\"ttl\":300,\"priority\":10,\"proxied\":false}"
record_step PASS "dns-create-multi" "dns_ids=${DNS_IDS[*]}"

api_patch "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records/${DNS_IDS[0]}" '{"content":"1.1.1.2","ttl":120}'
assert_http_status "$HTTP_CODE" "200" "dns update failed"
updated_dns_content="$(json_get "$HTTP_BODY" '.data.content')"
assert_eq "$updated_dns_content" "1.1.1.2" "dns update content mismatch"
record_step PASS "dns-update-one" "updated id=${DNS_IDS[0]}"

dns_db_count="$(db_query "SELECT COUNT(*) FROM dns_records WHERE site_id='${SITE_ID}';")"
if [[ "$dns_db_count" -lt 5 ]]; then
  fail "dns rows expected >=5 got $dns_db_count"
fi
record_step PASS "dns-db-rows" "count=${dns_db_count}"

api_get "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records"
assert_http_status "$HTTP_CODE" "200" "dns list failed"
for needle in '"type":"A"' '"type":"AAAA"' '"type":"CNAME"' '"type":"TXT"' '"type":"MX"'; do
  assert_contains "$HTTP_BODY" "$needle" "dns list missing ${needle}"
done
record_step PASS "dns-list" "all record types listed"

del_id="${DNS_IDS[0]}"
api_delete "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records/${del_id}"
assert_http_status "$HTTP_CODE" "200" "dns delete failed"
record_step PASS "dns-delete-one" "deleted id=${del_id}"

# PowerDNS sync checks (mock service in CI override)
if [[ "${POWERDNS_ENABLED:-0}" == "1" ]]; then
  retry 20 1 curl -fsS "${POWERDNS_API_URL}/health" >/dev/null
  zone_json="$(pdns_get "/api/v1/servers/localhost/zones/${TEST_DOMAIN}.")"
  assert_contains "$zone_json" "\"name\":\"${TEST_DOMAIN}.\"" "pdns zone lookup failed"
  assert_contains "$zone_json" "\"type\":\"ALIAS\"" "pdns missing proxied ALIAS"
  record_step PASS "powerdns-sync-positive" "records present in pdns mock"

  bad_code="$(curl -sS -o /tmp/pdns-bad.txt -w '%{http_code}' \
    -X PATCH "${POWERDNS_API_URL}/api/v1/servers/localhost/zones/${TEST_DOMAIN}." \
    -H "Content-Type: application/json" -H "X-API-Key: bad-key" -d '{"rrsets":[]}')"
  assert_eq "$bad_code" "403" "pdns strict negative key test failed"
  record_step PASS "powerdns-negative-auth" "bad key rejected"
fi

# Edge proxy behavior
edge_wait_success_status "${TEST_DOMAIN}"
ok_code="$(edge_status_for_host "${TEST_DOMAIN}")"
record_step PASS "edge-proxy-enabled" "status=${ok_code}"

# Proxy end-to-end behavior through edge
edge_health_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/health")"
assert_contains "$edge_health_body" "\"ok\":true" "edge proxied health payload mismatch"
record_step PASS "edge-proxy-health" "health endpoint proxied"

edge_sites_body="$(curl -sS -H "Host: ${TEST_DOMAIN}" "${EDGE_URL}/api/v1/sites?via=edge")"
assert_contains "$edge_sites_body" "$TEST_DOMAIN" "edge proxied sites list missing test domain"
record_step PASS "edge-proxy-get-query" "GET with query proxied"

cache_path="/api/v1/sites?via=edge-cache-${RUN_KEY}"
first_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path")"
assert_eq "$first_cache" "MISS" "first cacheable GET should MISS"
second_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path")"
assert_eq "$second_cache" "HIT" "second cacheable GET should HIT"
bypass_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$cache_path" -H "Cache-Control: no-cache")"
assert_eq "$bypass_cache" "BYPASS" "Cache-Control no-cache should bypass cache"

stale_path="/api/v1/sites?via=edge-stale-${RUN_KEY}"
stale_seed="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$stale_path")"
assert_eq "$stale_seed" "MISS" "stale seed request should MISS"
api_patch "${CORE_URL}/api/v1/sites/${SITE_ID}" '{"origin_host":"cdnlite-missing-origin","origin_port":8080}'
assert_http_status "$HTTP_CODE" "200" "site origin failure update failed"
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true
stale_cache="$(edge_cache_header_for_host "${TEST_DOMAIN}" "$stale_path")"
assert_eq "$stale_cache" "STALE" "origin failure should serve stale cache"
api_patch "${CORE_URL}/api/v1/sites/${SITE_ID}" '{"origin_host":"core","origin_port":8080}'
assert_http_status "$HTTP_CODE" "200" "site origin restore failed"
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true
record_step PASS "edge-cache-basic" "MISS/HIT/BYPASS/STALE verified"

edge_post_code="$(curl -s -o /tmp/e2e-edge-post.txt -w '%{http_code}' \
  -X POST "${EDGE_URL}/api/v1/sites" \
  -H "Host: ${TEST_DOMAIN}" \
  -H "Content-Type: application/json" \
  -d '{"name":"edge-proxy-validation","origin_host":"core"}')"
assert_eq "$edge_post_code" "422" "edge proxy POST/body forwarding failed"
record_step PASS "edge-proxy-post-body" "POST with json body proxied"

edge_delete_code="$(curl -s -o /tmp/e2e-edge-delete.txt -w '%{http_code}' \
  -X DELETE "${EDGE_URL}/api/v1/sites/99999999" \
  -H "Host: ${TEST_DOMAIN}")"
assert_eq "$edge_delete_code" "404" "edge proxy DELETE forwarding failed"
record_step PASS "edge-proxy-delete" "DELETE proxied"

api_post "${CORE_URL}/api/v1/sites/${SITE_ID}/proxy/disable" '{}'
assert_http_status "$HTTP_CODE" "200" "proxy disable failed"
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true
proxy_db="$(db_query "SELECT proxy_enabled::int FROM sites WHERE id='${SITE_ID}';")"
assert_eq "$proxy_db" "0" "proxy_enabled should be false"
record_step PASS "proxy-disable" "proxy disabled in db"

disabled_code="$(curl -s -o /tmp/e2e-edge-disabled.txt -w '%{http_code}' "${EDGE_URL}/api/v1/sites" -H "Host: ${TEST_DOMAIN}")"
edge_wait_status "${TEST_DOMAIN}" "502"
disabled_code="$(edge_status_for_host "${TEST_DOMAIN}")"
assert_eq "$disabled_code" "502" "disabled proxy should return 502"
record_step PASS "edge-proxy-disabled-route" "status=${disabled_code}"

api_post "${CORE_URL}/api/v1/sites/${SITE_ID}/proxy/enable" '{}'
assert_http_status "$HTTP_CODE" "200" "proxy enable failed"
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true
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
edge_api POST "/api/v1/collector/usage" "{\"idempotency_key\":\"e2e-${RUN_KEY}-k1\",\"items\":[{\"ts\":60,\"site_id\":\"${SITE_ID}\",\"edge_node_id\":\"${EDGE_ID}\",\"requests_count\":10,\"bytes_in\":100,\"bytes_out\":500,\"status\":200}]}"
assert_http_status "$HTTP_CODE" "200" "usage ingest failed"
ingested="$(json_get "$HTTP_BODY" '.ingested')"
assert_eq "$ingested" "1" "usage first ingest should be 1"

edge_api POST "/api/v1/collector/usage" "{\"idempotency_key\":\"e2e-${RUN_KEY}-k1\",\"items\":[{\"ts\":60,\"site_id\":\"${SITE_ID}\",\"edge_node_id\":\"${EDGE_ID}\",\"requests_count\":10,\"bytes_in\":100,\"bytes_out\":500,\"status\":200}]}"
assert_http_status "$HTTP_CODE" "200" "usage duplicate ingest call failed"
dup="$(json_get "$HTTP_BODY" '.duplicate')"
assert_eq "$dup" "true" "usage duplicate expected true"
record_step PASS "usage-idempotency" "duplicate key handled"

api_get "${CORE_URL}/api/v1/usage/summary"
assert_http_status "$HTTP_CODE" "200" "usage summary failed"
api_get "${CORE_URL}/api/v1/usage/summary?site_id=${SITE_ID}"
assert_http_status "$HTTP_CODE" "200" "usage summary by site failed"
for b in minute hour day; do
  api_get "${CORE_URL}/api/v1/usage/summary?bucket=${b}"
  assert_http_status "$HTTP_CODE" "200" "usage summary bucket ${b} failed"
done
record_step PASS "usage-summary-endpoints" "summary endpoints healthy"

api_post "${CORE_URL}/api/v1/usage/recalculate" "{\"site_id\":\"${SITE_ID}\"}"
assert_http_status "$HTTP_CODE" "200" "usage recalculate failed"
agg_count="$(db_query "SELECT COUNT(*) FROM usage_aggregates WHERE site_id='${SITE_ID}';")"
if [[ "$agg_count" -lt 1 ]]; then
  fail "usage aggregates expected >0"
fi
record_step PASS "usage-recalculate-db" "aggregates=${agg_count}"

# cleanup checks
for rid in "${DNS_IDS[@]:1}"; do
  api_delete "${CORE_URL}/api/v1/sites/${SITE_ID}/dns/records/${rid}"
  assert_http_status "$HTTP_CODE" "200" "dns cleanup failed"
done

api_delete "${CORE_URL}/api/v1/sites/${SITE_ID}"
assert_http_status "$HTTP_CODE" "200" "site delete failed"
record_step PASS "site-delete" "site removed"
docker compose exec -T edge-agent sh -lc '/agent/pull_config.sh' >/dev/null || true

remaining_site="$(db_query "SELECT COUNT(*) FROM sites WHERE id='${SITE_ID}';")"
assert_eq "$remaining_site" "0" "site row should be removed"
remaining_dns="$(db_query "SELECT COUNT(*) FROM dns_records WHERE site_id='${SITE_ID}';")"
assert_eq "$remaining_dns" "0" "dns rows should cascade delete"
record_step PASS "db-cascade-delete" "site and dns removed"

edge_wait_status "${TEST_DOMAIN}" "502"
deleted_edge_code="$(edge_status_for_host "${TEST_DOMAIN}")"
assert_eq "$deleted_edge_code" "502" "deleted domain should not route"
record_step PASS "edge-after-delete" "status=${deleted_edge_code}"

SITE_ID=""
DNS_IDS=()
pass "e2e checks completed"
