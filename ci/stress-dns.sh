#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DOMAINS="${STRESS_DOMAINS:-10000}"
RECORDS_PER_DOMAIN="${STRESS_RECORDS_PER_DOMAIN:-1000}"
EDGE_NODES="${STRESS_EDGE_NODES:-10}"
FLAP_ITERATIONS="${STRESS_FLAP_ITERATIONS:-10}"
SMALL_SYNC_LIMIT_SECONDS="${STRESS_SMALL_SYNC_LIMIT_SECONDS:-10}"
CORE_URL="${CORE_URL:-http://127.0.0.1:8080}"
REPORT_DIR="${REPORT_DIR:-ci/reports}"
REPORT_JSON="${STRESS_REPORT_JSON:-$REPORT_DIR/dns-stress-report.json}"
REPORT_MD="${STRESS_REPORT_MD:-$REPORT_DIR/dns-stress-report.md}"
DB_USER="${DB_USERNAME:-cdnlite}"
DB_NAME="${DB_DATABASE:-cdnlite}"
PDNS_API_KEY="${PDNS_API_KEY:-test-key}"
PDNS_API_URL="${POWERDNS_PUBLIC_API_URL:-http://127.0.0.1:8089}"
TOTAL_RECORDS=$((DOMAINS * RECORDS_PER_DOMAIN))

mkdir -p "$REPORT_DIR"

log() {
  printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

fail() {
  log "FAIL: $*"
  exit 1
}

db() {
  docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$DB_NAME" "$@"
}

db_value() {
  db -qAt -c "$1"
}

seconds_since() {
  local started="$1"
  awk -v start="$started" -v finish="$(date +%s.%N)" 'BEGIN { printf "%.3f", finish - start }'
}

wait_for_stack() {
  local attempt
  for attempt in $(seq 1 120); do
    if curl --max-time 2 -fsS "$CORE_URL/health" >/dev/null 2>&1 &&
      curl --max-time 2 -fsS -H "X-API-Key: $PDNS_API_KEY" \
        "$PDNS_API_URL/api/v1/servers/localhost" >/dev/null 2>&1; then
      log "Core and PowerDNS are ready"
      return
    fi
    if ((attempt % 10 == 0)); then
      log "Waiting for Core and PowerDNS readiness (${attempt}/120)"
      docker compose ps core pdns-auth
    fi
    sleep 2
  done
  docker compose ps -a core pdns-auth
  fail "Core or PowerDNS did not become ready"
}

fresh_database() {
  log "Resetting Core and PowerDNS data for a destructive fresh-install stress run"
  docker compose exec -T core php artisan cdn:db:fresh --force >/dev/null
  docker compose exec -T pdns-postgres psql -v ON_ERROR_STOP=1 -U pdns -d pdns -c \
    "TRUNCATE comments, cryptokeys, domainmetadata, records, domains RESTART IDENTITY CASCADE" >/dev/null
}

configure_powerdns() {
  local now
  now="$(date +%s)"
  db >/dev/null <<SQL
INSERT INTO platform_settings
  (key, group_name, value_json, is_secret, description, updated_by, updated_at)
VALUES
  ('platform.powerdns.enabled', 'platform.powerdns', 'true'::jsonb, false, 'Enable PowerDNS synchronization.', 'dns-stress', $now),
  ('platform.powerdns.strict', 'platform.powerdns', 'true'::jsonb, false, 'Require PowerDNS synchronization.', 'dns-stress', $now),
  ('platform.powerdns.api_url', 'platform.powerdns', '"http://pdns-auth:8081"'::jsonb, false, 'PowerDNS API base URL.', 'dns-stress', $now),
  ('platform.powerdns.api_key', 'platform.powerdns', to_jsonb('$PDNS_API_KEY'::text), true, 'PowerDNS API key.', 'dns-stress', $now),
  ('platform.powerdns.server_id', 'platform.powerdns', '"localhost"'::jsonb, false, 'PowerDNS server identifier.', 'dns-stress', $now)
ON CONFLICT (key) DO UPDATE SET
  value_json = EXCLUDED.value_json,
  updated_by = EXCLUDED.updated_by,
  updated_at = EXCLUDED.updated_at;
SQL
}

seed_dataset() {
  local now
  now="$(date +%s)"
  log "Seeding $DOMAINS domains, $TOTAL_RECORDS DNS records, and $EDGE_NODES edges"
  db >/dev/null <<SQL
SET synchronous_commit = off;
INSERT INTO domains
  (id, user_id, name, domain, status, nameserver_status, created_at, updated_at)
SELECT
  'stress-domain-' || lpad(i::text, 5, '0'),
  'stress-user',
  'Stress ' || i,
  'stress-' || lpad(i::text, 5, '0') || '.invalid',
  'active',
  'verified',
  $now,
  $now
FROM generate_series(1, $DOMAINS) AS i;

INSERT INTO dns_records
  (id, domain_id, type, name, content, ttl, priority, proxied, status, created_at, updated_at)
SELECT
  'stress-record-' || lpad(d::text, 5, '0') || '-' || lpad(r::text, 4, '0'),
  'stress-domain-' || lpad(d::text, 5, '0'),
  CASE WHEN r = 1 THEN 'A' WHEN r % 10 = 0 THEN 'A' ELSE 'TXT' END,
  CASE WHEN r = 1 THEN '@' WHEN r % 10 = 0 THEN 'www-' || r ELSE 'record-' || r END,
  CASE WHEN r % 10 = 0 OR r = 1 THEN '192.0.2.' || ((r % 250) + 1) ELSE 'stress-' || d || '-' || r END,
  60,
  NULL,
  (r = 1 OR r % 10 = 0),
  'active',
  $now,
  $now
FROM generate_series(1, $DOMAINS) AS d
CROSS JOIN generate_series(1, $RECORDS_PER_DOMAIN) AS r;

INSERT INTO edge_nodes
  (id, edge_id, hostname, public_ip, public_ipv4, public_ipv6, region, country, continent,
   version, status, is_enabled, last_heartbeat, last_heartbeat_at, health_status,
   anycast_enabled, created_at, updated_at)
SELECT
  'stress-edge-' || i,
  'stress-edge-' || i,
  'stress-edge-' || i,
  '198.51.100.' || (i + 10),
  '198.51.100.' || (i + 10),
  '2001:db8::' || i,
  CASE i % 3 WHEN 0 THEN 'eu' WHEN 1 THEN 'us' ELSE 'ap' END,
  CASE i % 3 WHEN 0 THEN 'DE' WHEN 1 THEN 'US' ELSE 'SG' END,
  CASE i % 3 WHEN 0 THEN 'EU' WHEN 1 THEN 'NA' ELSE 'AS' END,
  'stress',
  'online',
  true,
  $now,
  $now,
  'healthy',
  (i = 1),
  $now,
  $now
FROM generate_series(1, $EDGE_NODES) AS i;
ANALYZE domains;
ANALYZE dns_records;
ANALYZE edge_nodes;
SQL
}

reconcile() {
  docker compose exec -T core php -d memory_limit=-1 artisan cdn:dns:reconcile "$@"
}

zone_serials() {
  docker compose exec -T pdns-postgres psql -U pdns -d pdns -qAt -F '|' -c \
    "SELECT d.name, COALESCE(MAX(r.change_date), 0)
     FROM domains d LEFT JOIN records r ON r.domain_id = d.id
     WHERE d.name LIKE 'stress-%'
     GROUP BY d.name ORDER BY d.name"
}

assert_indexes() {
  local missing plan
  missing="$(db_value "SELECT count(*) FROM (
    VALUES
      ('dns_records_active_domain_order_idx'),
      ('dns_records_domain_status_idx'),
      ('desired_dns_rrsets_owner_generation_idx'),
      ('desired_dns_rrsets_zone_owner_idx')
  ) AS required(index_name)
  LEFT JOIN pg_indexes i
    ON i.schemaname='public' AND i.indexname=required.index_name
  WHERE i.indexname IS NULL")"
  [[ "$missing" == "0" ]] || fail "$missing required DNS scale indexes are missing"

  plan="$(db -qAt -c "SET LOCAL enable_seqscan=off;
    EXPLAIN SELECT r.* FROM dns_records r
    WHERE r.status='active' AND r.domain_id='stress-domain-00001'
    ORDER BY r.name,r.id")"
  grep -q 'dns_records_active_domain_order_idx' <<<"$plan" ||
    fail "Active DNS record index is not usable by the desired-state access pattern"
}

assert_dataset() {
  [[ "$(db_value "SELECT count(*) FROM domains WHERE id LIKE 'stress-domain-%'")" == "$DOMAINS" ]] ||
    fail "Domain count mismatch"
  [[ "$(db_value "SELECT count(*) FROM dns_records WHERE id LIKE 'stress-record-%'")" == "$TOTAL_RECORDS" ]] ||
    fail "DNS record count mismatch"
  [[ "$(db_value "SELECT count(DISTINCT region) FROM edge_nodes WHERE id LIKE 'stress-edge-%'")" -ge 3 ]] ||
    fail "Edge dataset has fewer than three regions"
}

assert_no_stale_or_duplicate_rrsets() {
  local duplicates stale
  duplicates="$(db_value "SELECT count(*) FROM (
    SELECT zone_name,rrset_name,rrset_type,owner,count(*)
    FROM desired_dns_rrsets GROUP BY 1,2,3,4 HAVING count(*) > 1
  ) x")"
  stale="$(db_value "SELECT count(*) FROM desired_dns_rrsets d
    LEFT JOIN dns_desired_generations g ON g.id=d.generation_id WHERE g.id IS NULL")"
  [[ "$duplicates" == "0" ]] || fail "Duplicate desired rrsets detected"
  [[ "$stale" == "0" ]] || fail "Stale desired rrsets detected"
}

measure_api() {
  local output
  output="$(curl -sS -o /dev/null -w '%{http_code} %{time_total}' "$CORE_URL/cdn-health")"
  [[ "${output%% *}" == "200" ]] || fail "cdn-health was unavailable during stress"
  printf '%s' "${output#* }"
}

main() {
  wait_for_stack
  fresh_database
  configure_powerdns
  seed_dataset
  assert_dataset
  assert_indexes

  local full_started full_seconds full_result baseline_file after_file
  full_started="$(date +%s.%N)"
  full_result="$(reconcile --force)"
  full_seconds="$(seconds_since "$full_started")"
  jq -e '.data.ok == true' <<<"$full_result" >/dev/null || fail "Full reconciliation failed: $full_result"
  baseline_file="$(mktemp)"
  after_file="$(mktemp)"
  zone_serials >"$baseline_file"

  local small_started small_seconds small_result changed_customer_zones changed_rrsets
  db -c "UPDATE edge_nodes SET public_ip='198.51.100.250', public_ipv4='198.51.100.250', updated_at=EXTRACT(EPOCH FROM NOW())::bigint WHERE id='stress-edge-2'" >/dev/null
  small_started="$(date +%s.%N)"
  small_result="$(reconcile)"
  small_seconds="$(seconds_since "$small_started")"
  jq -e '.data.ok == true' <<<"$small_result" >/dev/null || fail "Edge IP reconciliation failed: $small_result"
  changed_rrsets="$(jq -r '.data.changes' <<<"$small_result")"
  zone_serials >"$after_file"
  changed_customer_zones="$(join -t '|' "$baseline_file" "$after_file" | awk -F '|' '$2 != $3 {n++} END {print n+0}')"
  [[ "$changed_customer_zones" == "0" ]] || fail "Edge IP change rewrote $changed_customer_zones customer zones"
  awk -v actual="$small_seconds" -v limit="$SMALL_SYNC_LIMIT_SECONDS" 'BEGIN { exit !(actual < limit) }' ||
    fail "Small sync took ${small_seconds}s, limit is ${SMALL_SYNC_LIMIT_SECONDS}s"

  local concurrent_failures=0 flap
  for flap in $(seq 1 "$FLAP_ITERATIONS"); do
    db -c "UPDATE edge_nodes SET health_status=CASE WHEN health_status='healthy' THEN 'unhealthy' ELSE 'healthy' END, updated_at=EXTRACT(EPOCH FROM NOW())::bigint WHERE id='stress-edge-3'" >/dev/null
    reconcile >/tmp/cdnlite-stress-flap.json &
    local reconcile_pid=$!
    db -c "UPDATE dns_records SET content='concurrent-$flap', updated_at=EXTRACT(EPOCH FROM NOW())::bigint WHERE id='stress-record-00001-0002'" >/dev/null
    wait "$reconcile_pid" || concurrent_failures=$((concurrent_failures + 1))
  done
  [[ "$concurrent_failures" == "0" ]] || fail "$concurrent_failures concurrent reconciliations failed"
  reconcile >/dev/null

  assert_no_stale_or_duplicate_rrsets
  local failed_syncs api_latency pdns_health
  failed_syncs="$(db_value "SELECT count(*) FROM dns_sync_state WHERE status='failed' OR in_progress=true")"
  [[ "$failed_syncs" == "0" ]] || fail "Failed or stuck DNS sync state remains"
  api_latency="$(measure_api)"
  pdns_health="$(curl -fsS -H "X-API-Key: $PDNS_API_KEY" "$PDNS_API_URL/api/v1/servers/localhost" | jq -r '.type // "Authoritative"')"

  jq -n \
    --argjson domains "$DOMAINS" \
    --argjson records_per_domain "$RECORDS_PER_DOMAIN" \
    --argjson logical_records "$TOTAL_RECORDS" \
    --argjson edge_nodes "$EDGE_NODES" \
    --argjson flap_iterations "$FLAP_ITERATIONS" \
    --arg full_sync_seconds "$full_seconds" \
    --arg small_sync_seconds "$small_seconds" \
    --argjson changed_rrsets "$changed_rrsets" \
    --argjson changed_customer_zones "$changed_customer_zones" \
    --arg api_latency_seconds "$api_latency" \
    --arg pdns_health "$pdns_health" \
    '{
      passed: true,
      dataset: {domains: $domains, records_per_domain: $records_per_domain, logical_records: $logical_records, edge_nodes: $edge_nodes},
      measurements: {
        full_sync_seconds: ($full_sync_seconds | tonumber),
        edge_change_sync_seconds: ($small_sync_seconds | tonumber),
        edge_change_rrsets: $changed_rrsets,
        changed_customer_zones: $changed_customer_zones,
        flap_iterations: $flap_iterations,
        cdn_health_latency_seconds: ($api_latency_seconds | tonumber),
        powerdns_health: $pdns_health
      }
    }' >"$REPORT_JSON"

  {
    echo "# DNS Production Stress Report"
    echo
    echo "- Result: PASS"
    echo "- Domains: $DOMAINS"
    echo "- Records per domain: $RECORDS_PER_DOMAIN"
    echo "- Logical records: $TOTAL_RECORDS"
    echo "- Full sync: ${full_seconds}s"
    echo "- Edge-change sync: ${small_seconds}s"
    echo "- Changed rrsets: $changed_rrsets"
    echo "- Changed customer zones: $changed_customer_zones"
    echo "- Health flaps: $FLAP_ITERATIONS"
    echo "- Core health latency: ${api_latency}s"
    echo "- PowerDNS health: $pdns_health"
  } >"$REPORT_MD"

  rm -f "$baseline_file" "$after_file"
  log "PASS: DNS stress qualification completed; report: $REPORT_JSON"
}

main "$@"
