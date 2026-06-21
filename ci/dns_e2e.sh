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
MX_ID=""
ADMIN_SESSION_TOKEN=""
NAMESERVER_SCHEDULER_PAUSED=0
export CORE_URL POWERDNS_PUBLIC_API_URL PDNS_API_KEY CI_ENV_NAME ADMIN_SESSION_TOKEN

init_report

cleanup() {
  if [[ -n "$DOMAIN_ID" ]]; then
    curl -sS -X DELETE "${CORE_URL}/api/v1/domains/${DOMAIN_ID}" \
      -H "Authorization: Bearer ${ADMIN_SESSION_TOKEN}" >/dev/null || true
  fi
  db_query "DELETE FROM edge_nodes WHERE edge_id IN ('dns-e2e-eu','dns-e2e-us');" >/dev/null || true
  docker compose exec -T core php artisan cdn:dns:reconcile --force >/dev/null 2>&1 || true
  if [[ "$NAMESERVER_SCHEDULER_PAUSED" == "1" ]]; then
    docker compose start nameserver-scheduler >/dev/null 2>&1 || true
  fi
}

finish() {
  local rc=$?
  cleanup
  if [[ $rc -ne 0 ]]; then
    if [[ "$FAILED_STEPS" == "0" ]]; then
      record_step FAIL "dns-e2e" "script exited with status ${rc}; see diagnostics artifacts"
    fi
    collect_diagnostics
  fi
  write_reports
  if [[ $rc -ne 0 && -s "$REPORT_MD" ]]; then
    echo "----- ${REPORT_MD} -----" >&2
    cat "$REPORT_MD" >&2 || true
  fi
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
  local n queued
  for n in $(seq 1 20); do
    api_post "${CORE_URL}/api/v1/dns/force-sync" '{}'
    if [[ "$HTTP_CODE" != "200" ]]; then
      return 0
    fi
    queued="$(jq -r '.data.queued // false' <<<"$HTTP_BODY" 2>/dev/null || printf 'false')"
    if [[ "$queued" != "true" ]]; then
      return 0
    fi
    sleep 1
  done
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

rrset_exists() {
  local zone="$1"
  local name="$2"
  local type="$3"
  zone_json "$zone" | jq -e --arg name "$name" --arg type "$type" \
    '.rrsets[] | select(.name == $name and .type == $type)' >/dev/null
}

answer_set() {
  local name="$1"
  local type="${2:-A}"
  dig @127.0.0.1 -p "$PDNS_DNS_HOST_PORT" "$name" "$type" +short |
    awk -v type="$type" '
      type == "A" && $0 ~ /^([0-9]{1,3}\.){3}[0-9]{1,3}$/ { print; next }
      type == "AAAA" && $0 ~ /:/ { print }
    ' | sort -u
}

retry 60 2 curl -fsS "$CORE_URL/health" >/dev/null
retry 60 2 curl -fsS -H "X-API-Key: ${PDNS_API_KEY}" \
  "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost" >/dev/null
wait_for_postgres
login

if compose_has_service nameserver-scheduler; then
  docker compose stop nameserver-scheduler >/dev/null
  NAMESERVER_SCHEDULER_PAUSED=1
fi

api_patch "${CORE_URL}/api/v1/settings/platform.powerdns" \
  "{\"values\":{\"enabled\":true,\"strict\":true,\"api_url\":\"http://pdns-auth:8081\",\"api_key\":\"${PDNS_API_KEY}\",\"server_id\":\"localhost\"}}"
assert_http_status "$HTTP_CODE" "200" "PowerDNS settings bootstrap failed"

docker compose exec -T pdns-auth sh -ec \
  "grep -qx 'expand-alias=yes' /etc/powerdns/pdns.d/00-local.conf &&
   grep -Eq '^resolver=.+:5300$' /etc/powerdns/pdns.d/00-local.conf"
record_step PASS "alias-runtime-config" "PowerDNS has expand-alias=yes and a separate resolver"

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

if ! retry 20 2 docker compose exec -T core php artisan cdn:powerdns:doctor >/tmp/dns-e2e-doctor.json 2>/tmp/dns-e2e-doctor.err; then
  doctor_detail="stdout=$(cat /tmp/dns-e2e-doctor.json 2>/dev/null) stderr=$(cat /tmp/dns-e2e-doctor.err 2>/dev/null)"
  record_step FAIL "powerdns-doctor" "$doctor_detail"
  fail "PowerDNS doctor command failed ${doctor_detail}"
fi
doctor_api_ok="$(jq -r '.data.api.ok // false' /tmp/dns-e2e-doctor.json 2>/dev/null || printf 'false')"
if [[ "$doctor_api_ok" != "true" ]]; then
  doctor_detail="body=$(cat /tmp/dns-e2e-doctor.json 2>/dev/null) stderr=$(cat /tmp/dns-e2e-doctor.err 2>/dev/null)"
  record_step FAIL "powerdns-doctor" "$doctor_detail"
  fail "PowerDNS doctor should pass ${doctor_detail}"
fi
record_step PASS "powerdns-doctor" "Core PowerDNS doctor passed"

api_post "${CORE_URL}/api/v1/domains" \
  "{\"name\":\"DNS E2E ${RUN_KEY}\",\"domain\":\"${TEST_DOMAIN}\"}"
assert_http_status "$HTTP_CODE" "201" "DNS e2e domain create failed"
DOMAIN_ID="$(json_get "$HTTP_BODY" '.data.id')"

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
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records"
assert_http_status "$HTTP_CODE" "200" "pre-verification DNS list failed"
assert_eq "$(jq -r '[.data[] | select(.readonly != true) | .effective_status] | unique | join(",")' <<<"$HTTP_BODY")" "disabled" \
  "records must remain effectively disabled before nameserver verification"
assert_eq "$(jq -r '[.data[] | select(.readonly != true) | .disabled_reason] | unique | join(",")' <<<"$HTTP_BODY")" "nameservers_not_verified" \
  "pre-verification records must explain the nameserver dependency"
if ! jq -e '.data[] | select(.readonly == true and .managed_by == "platform_nameservers" and .type == "NS")' <<<"$HTTP_BODY" >/dev/null; then
  fail "platform NS records must remain visible as readonly managed DNS records"
fi
preverify_zone_code="$(curl -sS -o /tmp/dns-e2e-preverify-zone.json -w '%{http_code}' \
  -H "X-API-Key: ${PDNS_API_KEY}" \
  "${POWERDNS_PUBLIC_API_URL}/api/v1/servers/localhost/zones/${TEST_ZONE}")"
assert_eq "$preverify_zone_code" "200" "pending domain zone should exist with authority records only"
if jq -e '.rrsets[] | select(.type != "SOA" and .type != "NS")' /tmp/dns-e2e-preverify-zone.json >/dev/null; then
  fail "pending domain published user rrsets before nameserver verification"
fi
record_step PASS "preverification-records-disabled" "records are stored while PowerDNS keeps only NS/SOA before delegation verification"

# dns_get_record cannot resolve the private test.local delegation through Docker's
# system resolver, so seed the state that a successful resolver check persists.
now="$(date +%s)"
db_query "UPDATE domains SET status='active', nameserver_status='verified', last_ns_check_at=$now, updated_at=$now WHERE id='${DOMAIN_ID}';" >/dev/null
db_query "UPDATE domain_nameservers SET observed=true, last_checked_at=$now WHERE domain_id='${DOMAIN_ID}';" >/dev/null
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after verified delegation failed"
assert_eq "$(json_get "$HTTP_BODY" '.data.ok')" "true" "verified delegation sync should pass"
purge_dns_caches
retry 40 1 rrset_exists "$TEST_ZONE" "$TEST_ZONE" LUA

customer_zone="$(zone_json "$TEST_ZONE")"
cdn_zone="$(zone_json "$CDN_ZONE_FQDN")"
site_host="site-${DOMAIN_ID}.${CDN_ZONE_FQDN}"
site_cname="$(rrset_content "$cdn_zone" "$site_host" CNAME)"
apex_lua="$(rrset_content "$customer_zone" "$TEST_ZONE" LUA)"
assert_contains "$apex_lua" "$EDGE_EU" "apex LUA missing EU edge"
assert_contains "$apex_lua" "$EDGE_US" "apex LUA missing US edge"
assert_eq "$(rrset_content "$customer_zone" "www.${TEST_ZONE}" CNAME)" "$site_host" "www CNAME target mismatch"
assert_eq "$(rrset_content "$customer_zone" "direct.${TEST_ZONE}" A)" "192.0.2.99" "unproxied A mismatch"
if jq -e --arg name "$TEST_ZONE" '.rrsets[] | select(.name == $name and (.type == "ALIAS" or .type == "CNAME"))' <<<"$customer_zone" >/dev/null; then
  fail "Core wrote ALIAS or CNAME at the proxied apex"
fi
assert_eq "$site_cname" "$PROXY_FQDN" "site target CNAME mismatch"
lua_a="$(rrset_content "$cdn_zone" "$PROXY_FQDN" LUA)"
assert_contains "$lua_a" "$EDGE_EU" "shared Lua record missing EU edge"
assert_contains "$lua_a" "$EDGE_US" "shared Lua record missing US edge"
record_step PASS "raw-zone-model" "raw zones contain apex Lua, CNAME, unproxied A, site CNAME, and shared Lua"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  '{"type":"A","name":"@","content":"192.0.2.11","ttl":60,"proxied":true,"origin_host":"origin-backup"}'
assert_http_status "$HTTP_CODE" "201" "duplicate proxied target should create a second origin record"
if [[ "$(json_get "$HTTP_BODY" '.data.id')" == "$APEX_ID" ]]; then
  fail "second proxied target must not reuse the first DNS record"
fi
assert_eq "$(db_query "SELECT COUNT(*) FROM dns_records WHERE domain_id='${DOMAIN_ID}' AND name='@';")" "2" \
  "duplicate proxied target should create a second public DNS record row"
assert_eq "$(db_query "SELECT COUNT(*) FROM domain_origins WHERE domain_id='${DOMAIN_ID}' AND host='origin-backup' AND enabled=true;")" "1" \
  "second proxied target was not stored as an enabled origin"
record_step PASS "duplicate-proxy-becomes-second-origin" "second proxied apex target stored as its own origin"

api_post "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records" \
  "{\"type\":\"MX\",\"name\":\"@\",\"content\":\"mail.${TEST_DOMAIN}.\",\"ttl\":60,\"priority\":10,\"proxied\":false}"
assert_http_status "$HTTP_CODE" "201" "apex MX must coexist with proxied LUA"
MX_ID="$(json_get "$HTTP_BODY" '.data.id')"
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after apex MX create failed"
customer_zone="$(zone_json "$TEST_ZONE")"
assert_contains "$(rrset_content "$customer_zone" "$TEST_ZONE" LUA)" "$EDGE_EU" "apex LUA disappeared after MX create"
assert_eq "$(rrset_content "$customer_zone" "$TEST_ZONE" MX)" "10 mail.${TEST_ZONE}" "apex MX missing beside LUA"
record_step PASS "apex-lua-mx-coexist" "PowerDNS apex contains both LUA and MX rrsets"

apex_answers="$(answer_set "$TEST_DOMAIN")"
site_answers="$(answer_set "${site_host%.}")"
proxy_answers="$(answer_set "${PROXY_FQDN%.}")"
www_answers="$(answer_set "www.${TEST_DOMAIN}")"
[[ -n "$apex_answers" ]] || fail "apex LUA returned no A answers (site='${site_answers}' proxy='${proxy_answers}' www='${www_answers}')"
assert_eq "$apex_answers" "$proxy_answers" "apex LUA and shared proxy answer sets differ"
assert_eq "$site_answers" "$proxy_answers" "site target and shared proxy answer sets differ"
assert_eq "$www_answers" "$site_answers" "www CNAME and site target answer sets differ"
record_step PASS "lua-dig-equivalence" "apex, www, site target, and proxy resolve to the same A set"

docker compose exec -T core php artisan cdn:domains:verify-all >/tmp/dns-e2e-verify-all.json
assert_eq "$(jq -r --arg id "$DOMAIN_ID" '[.data.domains[] | select(.id == $id)] | length' /tmp/dns-e2e-verify-all.json)" \
  "1" "daily verifier did not check the test domain"
assert_eq "$(db_query "SELECT status || ':' || nameserver_status FROM domains WHERE id='${DOMAIN_ID}';")" \
  "pending_nameserver:not_configured" "lost delegation must disable the domain automatically"
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after delegation loss failed"
customer_zone="$(zone_json "$TEST_ZONE")"
if jq -e '.rrsets[] | select(.type != "SOA" and .type != "NS")' <<<"$customer_zone" >/dev/null; then
  fail "customer rrsets remain published after nameserver delegation loss"
fi
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records"
assert_eq "$(jq -r '[.data[] | select(.readonly != true) | .effective_status] | unique | join(",")' <<<"$HTTP_BODY")" "disabled" \
  "records must become effectively disabled after delegation loss"
record_step PASS "delegation-loss-withdrawal" "daily verifier disabled domain and reconciler withdrew all customer rrsets"

now="$(date +%s)"
db_query "UPDATE domains SET status='active', nameserver_status='verified', last_ns_check_at=$now, updated_at=$now WHERE id='${DOMAIN_ID}';" >/dev/null
db_query "UPDATE domain_nameservers SET observed=true, last_checked_at=$now WHERE domain_id='${DOMAIN_ID}';" >/dev/null
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after delegation restoration failed"
customer_zone="$(zone_json "$TEST_ZONE")"
assert_contains "$(rrset_content "$customer_zone" "$TEST_ZONE" LUA)" "$EDGE_EU" "apex LUA did not republish"
assert_eq "$(rrset_content "$customer_zone" "$TEST_ZONE" MX)" "10 mail.${TEST_ZONE}" "apex MX did not republish"
api_get "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records"
assert_eq "$(jq -r '[.data[] | select(.readonly != true and .status == "active") | .effective_status] | unique | join(",")' <<<"$HTTP_BODY")" "active" \
  "desired-active records did not reactivate after delegation restoration"
record_step PASS "delegation-restoration" "all desired-active records republished after verification was restored"

customer_non_apex_before="$(jq -S --arg apex "$TEST_ZONE" '[.rrsets[] | select(.type != "SOA" and .type != "NS" and .name != $apex) | {name,type,ttl,records}]' <<<"$customer_zone")"
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
customer_after_zone="$(zone_json "$TEST_ZONE")"
apex_lua_after="$(rrset_content "$customer_after_zone" "$TEST_ZONE" LUA)"
assert_contains "$apex_lua_after" "$EDGE_EU" "healthy edge disappeared from apex Lua record"
if [[ "$apex_lua_after" == *"$EDGE_US"* ]]; then
  fail "unhealthy edge remains in apex Lua record"
fi
customer_non_apex_after="$(jq -S --arg apex "$TEST_ZONE" '[.rrsets[] | select(.type != "SOA" and .type != "NS" and .name != $apex) | {name,type,ttl,records}]' <<<"$customer_after_zone")"
assert_eq "$customer_non_apex_after" "$customer_non_apex_before" "edge health transition rewrote non-apex customer rrsets"
record_step PASS "edge-health-reconcile" "unhealthy edge removed from shared and managed apex Lua records without rewriting non-apex customer rrsets"

api_delete "${CORE_URL}/api/v1/domains/${DOMAIN_ID}/dns/records/${PLAIN_ID}"
assert_http_status "$HTTP_CODE" "200" "unproxied record deletion failed"
PLAIN_ID=""
force_sync
assert_http_status "$HTTP_CODE" "200" "sync after unproxied record deletion failed"
assert_eq "$(json_get "$HTTP_BODY" '.data.ok')" "true" "unproxied record deletion sync should pass"
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
assert_eq "$(json_get "$HTTP_BODY" '.powerdns.api.ok')" "false" "cdn-health should expose failed PowerDNS connectivity"
assert_eq "$(json_get "$HTTP_BODY" '.powerdns.api.error')" "powerdns_api_error" "cdn-health should expose the PowerDNS API error"
record_step PASS "sync-failure-visible" "PowerDNS connectivity failure is visible through cdn-health"

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
