#!/usr/bin/env bash
set -Eeuo pipefail
set -o errtrace

source "$(dirname "$0")/lib.sh"

CORE_URL="${CORE_URL:-http://localhost:${CORE_HOST_PORT:-8080}}"
POWERDNS_PUBLIC_API_URL="${POWERDNS_PUBLIC_API_URL:-http://localhost:${PDNS_API_HOST_PORT:-8089}}"
PDNS_API_KEY="${PDNS_API_KEY:-test-key}"
PDNS_DNS_HOST_PORT="${PDNS_DNS_HOST_PORT:-5353}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"
CI_ENV_NAME="${CI_ENV_NAME:-dns-e2e}"
CDN_ZONE="${CDNLITE_CDN_ZONE:-cdn.example.net}"
PROXY_HOST="${CDNLITE_CDN_PROXY_HOST:-proxy.cdn.example.net}"
RUN_KEY="${GITHUB_RUN_ID:-local}-${RANDOM}"
TEST_DOMAIN="dns-${RUN_KEY}.test.local"
TEST_ZONE="${TEST_DOMAIN}."
CDN_ZONE_FQDN="${CDN_ZONE%.}."
PROXY_FQDN="${PROXY_HOST%.}."
EDGE_EU="198.51.100.41"
EDGE_US="203.0.113.42"
DOMAIN_ID=""
APEX_ID=""
WWW_ID=""
PLAIN_ID=""
ADMIN_SESSION_TOKEN=""
export CORE_URL POWERDNS_PUBLIC_API_URL PDNS_API_KEY CI_ENV_NAME ADMIN_SESSION_TOKEN

init_report

cleanup() {
  if [[ -n "$DOMAIN_ID" ]]; then
    curl -sS -X DELETE "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" \
      -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}" >/dev/null || true
  fi
  db_query "DELETE FROM edge_nodes WHERE edge_id IN ('dns-e2e-eu','dns-e2e-us');" >/dev/null || true
  docker compose exec -T core php artisan cdn:dns:reconcile --force >/dev/null 2>&1 || true
}

finish() {
  local rc=$?
  cleanup
  if [[ $rc -ne 0 ]]; then
    collect_diagnostics
  fi
  write_reports
  exit "$rc"
}
trap finish EXIT

login() {
  local code
  code="$(curl -sS -o /tmp/dns-e2e-login.json -w '%{http_code}' \
    -X POST "${CORE_URL}/api/v1/admin/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"${ADMIN_USERNAME}\",\"password\":\"${ADMIN_PASSWORD}\"}")"
  assert_eq "$code" "200" "admin login should succeed"
  ADMIN_SESSION_TOKEN="$(json_get "$(cat /tmp/dns-e2e-login.json)" '.data.token')"
  export ADMIN_SESSION_TOKEN
}

force_sync() {
  api_post "${CORE_URL}/api/v1/dns/force-sync" '{}'
}

purge_dns_caches() {
  docker compose exec -T pdns-auth pdns_control purge >/dev/null
  docker compose exec -T pdns-recursor rec_control wipe-cache "${CDN_ZONE%.}" >/dev/null
  docker compose exec -T pdns-recursor rec_control wipe-cache "${TEST_DOMAIN%.}" >/dev/null
}

zone_json() {
  pdns_get "/api/v1/servers/localhost/zones/${1}"
}

rrset_content() {
  local json="$1"
  local name="$2"
  local type="$3"
  jq -r --arg name "$name" --arg type "$type" \
    '.rrsets[] | select(.name == $name and .type == $type) | .records[].content' <<<"$json"
}

answer_set() {
  local name="$1"
  local type="${2:-A}"
  dig @127.0.0.1 -p "$PDNS_DNS_HOST_PORT" "$name" "$type" +short |
    sed '/^$/d' | sort -u
}

retry 60 2 curl -fsS "$CORE_URL/health" >/dev/null
retry 60 2 curl -fsS -H "X-API-Key: ${PDNS_API_KEY}" \
  "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost" >/dev/null
wait_for_postgres
login

api_patch "${CORE_URL}/api/v1/settings/platform.powerdns" \
  "{\"values\":{\"enabled\":true,\"strict\":true,\"api_url\":\"http://pdns-auth:8081\",\"api_key\":\"${PDNS_API_KEY}\",\"server_id\":\"localhost\"}}"
assert_http_status "$HTTP_CODE" "200" "PowerDNS settings bootstrap failed"

docker compose exec -T pdns-auth sh -ec \
  "grep -qx 'expand-alias=yes' /etc/powerdns/pdns.d/00-local.conf &&
   grep -Eq '^resolver=.+:5300$' /etc/powerdns/pdns.d/00-local.conf"
record_step PASS "alias-runtime-config" "PowerDNS has expand-alias=yes and a separate resolver"

docker compose exec -T core php artisan cdn:powerdns:doctor >/tmp/dns-e2e-doctor.json
assert_eq "$(jq -r '.data.api.ok' /tmp/dns-e2e-doctor.json)" "true" "PowerDNS doctor should pass"
record_step PASS "powerdns-doctor" "Core PowerDNS doctor passed"

now="$(date +%s)"
db_query "
INSERT INTO edge_nodes (
  id, edge_id, hostname, public_ip, public_ipv4, public_ipv6, region, country, continent,
  version, status, is_enabled, last_heartbeat, last_heartbeat_at, health_status,
  weight, priority, geo_enabled, anycast_enabled, created_at, updated_at
) VALUES
  ('31000000-0000-4000-8000-000000000001','dns-e2e-eu','dns-e2e-eu','$EDGE_EU','$EDGE_EU','','eu','DE','EU',
   'e2e','online',true,$now,$now,'healthy',100,100,true,false,$now,$now),
  ('31000000-0000-4000-8000-000000000002','dns-e2e-us','dns-e2e-us','$EDGE_US','$EDGE_US','','us','US','NA',
   'e2e','online',true,$now,$now,'healthy',100,100,true,false,$now,$now)
ON CONFLICT (edge_id) DO UPDATE SET
  public_ip=EXCLUDED.public_ip, public_ipv4=EXCLUDED.public_ipv4, region=EXCLUDED.region,
  status='online', is_enabled=true, last_heartbeat=$now, last_heartbeat_at=$now,
  health_status='healthy', updated_at=$now;" >/dev/null
record_step PASS "edge-state-seed" "two healthy regional edges seeded"

api_post "${CORE_URL}/api/v1/domains" \
  "{\"name\":\"DNS E2E ${RUN_KEY}\",\"domain\":\"${TEST_DOMAIN}\"}"
assert_http_status "$HTTP_CODE" "201" "DNS e2e domain create failed"
DOMAIN_ID="$(json_get "$HTTP_BODY" '.data.id')"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/activate" '{"override":true}'
assert_http_status "$HTTP_CODE" "200" "DNS e2e domain activation failed"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"@","content":"192.0.2.10","ttl":60,"proxied":true,"origin_host":"origin-tls"}'
assert_http_status "$HTTP_CODE" "201" "proxied apex create failed"
APEX_ID="$(json_get "$HTTP_BODY" '.data.id')"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"www","content":"192.0.2.10","ttl":60,"proxied":true,"origin_host":"origin-tls"}'
assert_http_status "$HTTP_CODE" "201" "proxied www create failed"
WWW_ID="$(json_get "$HTTP_BODY" '.data.id')"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"direct","content":"192.0.2.99","ttl":60,"proxied":false}'
assert_http_status "$HTTP_CODE" "201" "unproxied A create failed"
PLAIN_ID="$(json_get "$HTTP_BODY" '.data.id')"

force_sync
assert_http_status "$HTTP_CODE" "200" "forced DNS sync failed"
assert_eq "$(json_get "$HTTP_BODY" '.data.ok')" "true" "forced DNS sync should report success"
purge_dns_caches

customer_zone="$(zone_json "$TEST_ZONE")"
cdn_zone="$(zone_json "$CDN_ZONE_FQDN")"
site_target="$(rrset_content "$customer_zone" "$TEST_ZONE" ALIAS)"
assert_eq "$(rrset_content "$customer_zone" "$TEST_ZONE" ALIAS)" "$site_target" "apex ALIAS missing"
assert_eq "$(rrset_content "$customer_zone" "www.${TEST_ZONE}" CNAME)" "$site_target" "www CNAME target mismatch"
assert_eq "$(rrset_content "$customer_zone" "direct.${TEST_ZONE}" A)" "192.0.2.99" "unproxied A mismatch"
if jq -e --arg name "$TEST_ZONE" '.rrsets[] | select(.name == $name and (.type == "A" or .type == "AAAA"))' <<<"$customer_zone" >/dev/null; then
  fail "Core wrote flattened A/AAAA records at the proxied apex"
fi
assert_eq "$(rrset_content "$cdn_zone" "$site_target" CNAME)" "$PROXY_FQDN" "site target CNAME mismatch"
lua_a="$(rrset_content "$cdn_zone" "$PROXY_FQDN" LUA)"
assert_contains "$lua_a" "$EDGE_EU" "shared Lua record missing EU edge"
assert_contains "$lua_a" "$EDGE_US" "shared Lua record missing US edge"
record_step PASS "raw-zone-model" "raw zones contain ALIAS, CNAME, unproxied A, site CNAME, and shared Lua"

apex_answers="$(answer_set "$TEST_DOMAIN")"
site_answers="$(answer_set "${site_target%.}")"
proxy_answers="$(answer_set "${PROXY_FQDN%.}")"
www_answers="$(answer_set "www.${TEST_DOMAIN}")"
[[ -n "$apex_answers" ]] || fail "apex ALIAS returned no A answers (site='${site_answers}' proxy='${proxy_answers}' www='${www_answers}')"
assert_eq "$apex_answers" "$site_answers" "apex ALIAS and site target answer sets differ"
assert_eq "$site_answers" "$proxy_answers" "site target and shared proxy answer sets differ"
assert_eq "$www_answers" "$site_answers" "www CNAME and site target answer sets differ"
record_step PASS "alias-dig-equivalence" "apex, www, site target, and proxy resolve to the same A set"

customer_before="$(jq -S '[.rrsets[] | {name,type,ttl,records}]' <<<"$customer_zone")"
db_query "UPDATE edge_nodes SET health_status='unhealthy', updated_at=$(date +%s) WHERE edge_id='dns-e2e-us';" >/dev/null
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after edge health transition failed"
purge_dns_caches
cdn_after="$(zone_json "$CDN_ZONE_FQDN")"
lua_after="$(rrset_content "$cdn_after" "$PROXY_FQDN" LUA)"
assert_contains "$lua_after" "$EDGE_EU" "healthy edge disappeared from shared Lua record"
if [[ "$lua_after" == *"$EDGE_US"* ]]; then
  fail "unhealthy edge remains in shared Lua record"
fi
customer_after="$(jq -S '[.rrsets[] | {name,type,ttl,records}]' <<<"$(zone_json "$TEST_ZONE")")"
assert_eq "$customer_after" "$customer_before" "edge health transition rewrote customer rrsets"
record_step PASS "edge-health-reconcile" "unhealthy edge removed only from the shared CDN Lua record"

api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PLAIN_ID}"
assert_http_status "$HTTP_CODE" "200" "unproxied record deletion failed"
PLAIN_ID=""
customer_zone="$(zone_json "$TEST_ZONE")"
if jq -e --arg name "direct.${TEST_ZONE}" '.rrsets[] | select(.name == $name and .type == "A")' <<<"$customer_zone" >/dev/null; then
  fail "deleted DNS record remains in PowerDNS"
fi
record_step PASS "stale-rrset-delete" "deleted Core record was removed from PowerDNS"

api_patch "${CORE_URL}/api/v1/settings/platform.powerdns" \
  '{"values":{"enabled":true,"strict":true,"api_url":"http://pdns-auth:9","api_key":"test-key","server_id":"localhost"}}'
assert_http_status "$HTTP_CODE" "200" "failed to install temporary broken PowerDNS settings"
force_sync
assert_http_status "$HTTP_CODE" "200" "force-sync endpoint should return its structured failure"
assert_eq "$(json_get "$HTTP_BODY" '.data.ok')" "false" "broken PowerDNS endpoint should fail synchronization"
api_get "${CORE_URL}/cdn-health"
assert_http_status "$HTTP_CODE" "200" "cdn-health should remain readable during DNS failure"
assert_contains "$HTTP_BODY" '"status":"failed"' "cdn-health should expose failed DNS sync state"
record_step PASS "sync-failure-visible" "PowerDNS failure is persisted and visible through cdn-health"

api_patch "${CORE_URL}/api/v1/settings/platform.powerdns" \
  "{\"values\":{\"enabled\":true,\"strict\":true,\"api_url\":\"http://pdns-auth:8081\",\"api_key\":\"${PDNS_API_KEY}\",\"server_id\":\"localhost\"}}"
assert_http_status "$HTTP_CODE" "200" "failed to restore PowerDNS settings"
force_sync
assert_http_status "$HTTP_CODE" "200" "DNS recovery sync failed"
assert_eq "$(json_get "$HTTP_BODY" '.data.ok')" "true" "DNS recovery sync should succeed"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/status"
assert_http_status "$HTTP_CODE" "200" "domain DNS status failed after recovery"
assert_eq "$(json_get "$HTTP_BODY" '.data.converged')" "true" "domain DNS status should converge after recovery"
record_step PASS "sync-recovery" "PowerDNS synchronization recovered and converged"

pass "live DNS e2e checks completed"
