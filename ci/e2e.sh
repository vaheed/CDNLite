#!/usr/bin/env bash
set -euo pipefail

extract_json() {
  local payload="$1"
  local expr="$2"
  python3 - "$payload" "$expr" <<'PY'
import json
import sys

obj = json.loads(sys.argv[1])
expr = sys.argv[2]
parts = expr.split(".")
for part in parts:
    if part.isdigit():
        obj = obj[int(part)]
    else:
        obj = obj[part]
if isinstance(obj, bool):
    print("true" if obj else "false")
else:
    print(obj)
PY
}

# API: create site
site=$(curl -fsS -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo2","domain":"demo2.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}')
site_id=$(extract_json "$site" "data.id")
site_domain=$(extract_json "$site" "data.domain")
[[ "$site_domain" == "demo2.local" ]]

# API: update site
site_updated=$(curl -fsS -X PATCH "http://localhost:8080/api/v1/sites/${site_id}" \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo2-updated"}')
updated_name=$(extract_json "$site_updated" "data.name")
[[ "$updated_name" == "demo2-updated" ]]

# API: create and list DNS records
rec=$(curl -fsS -X POST "http://localhost:8080/api/v1/sites/${site_id}/dns/records" \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"1.1.1.1","ttl":300,"proxied":true}')
record_id=$(extract_json "$rec" "data.id")
record_type=$(extract_json "$rec" "data.type")
[[ "$record_type" == "A" ]]

dns_list=$(curl -fsS "http://localhost:8080/api/v1/sites/${site_id}/dns/records")
echo "$dns_list" | grep -q '1.1.1.1'

# CLI: verify site and dns commands operate on same persisted data
cli_sites=$(docker compose exec -T core php artisan cdn:site:list)
echo "$cli_sites" | grep -q 'demo2.local'
cli_dns=$(docker compose exec -T core php artisan cdn:dns:list-records "--site_id=${site_id}")
echo "$cli_dns" | grep -q '"content":"1.1.1.1"'

# Edge proxy request succeeds when enabled
code=$(curl -s -o /tmp/e2e_enabled.txt -w '%{http_code}' http://localhost:8081/api/v1/sites -H 'Host: demo2.local')
if [[ "$code" -lt 200 || "$code" -ge 400 ]]; then
  echo "e2e: expected successful proxy status, got $code"
  exit 1
fi

# Disable proxy and verify edge no longer routes this host
curl -fsS -X POST "http://localhost:8080/api/v1/sites/${site_id}/proxy/disable" >/dev/null
code_disabled=$(curl -s -o /tmp/e2e_disabled.txt -w '%{http_code}' http://localhost:8081/api/v1/sites -H 'Host: demo2.local')
if [[ "$code_disabled" -ne 502 ]]; then
  echo "e2e: expected 502 when proxy disabled, got $code_disabled"
  exit 1
fi

# Re-enable proxy
curl -fsS -X POST "http://localhost:8080/api/v1/sites/${site_id}/proxy/enable" >/dev/null

# CLI: usage ingest and recalculate
docker compose exec -T core php artisan cdn:usage:ingest \
  "--site_id=${site_id}" \
  --edge_node_id=edge-ci-1 \
  --requests_count=11 \
  --bytes_in=101 \
  --bytes_out=1001 \
  --status=200 \
  --idempotency_key=e2e-usage-1 >/dev/null
docker compose exec -T core php artisan cdn:usage:recalculate >/dev/null

# API: usage summary should include ingested record
usage=$(curl -fsS http://localhost:8080/api/v1/usage/summary)
usage_requests=$(extract_json "$usage" "data.requests_count")
if [[ "$usage_requests" -lt 11 ]]; then
  echo "e2e: expected usage requests >= 11, got $usage_requests"
  exit 1
fi

# Let agent push heartbeat/metrics
sleep 12

# API: edge nodes endpoint should return at least one node
nodes=$(curl -fsS http://localhost:8080/api/v1/edge/nodes)
echo "$nodes" | grep -q '"data":'

# CLI: edge list and sync config
edge_list=$(docker compose exec -T core php artisan cdn:edge:list)
echo "$edge_list" | grep -q '"data":'
edge_cfg=$(docker compose exec -T core php artisan cdn:edge:sync-config)
echo "$edge_cfg" | grep -q '"version":'

# DB persistence: verify rows exist for site, dns, and usage rollups
db_counts=$(docker compose exec -T postgres psql -U cdnlite -d cdnlite -t -A -F, -c \
  "SELECT \
    (SELECT COUNT(*) FROM sites WHERE id = ${site_id}), \
    (SELECT COUNT(*) FROM dns_records WHERE id = ${record_id}), \
    (SELECT COUNT(*) FROM usage_rollups WHERE site_id = ${site_id});")
site_count=$(echo "$db_counts" | cut -d',' -f1)
dns_count=$(echo "$db_counts" | cut -d',' -f2)
rollup_count=$(echo "$db_counts" | cut -d',' -f3)
[[ "$site_count" == "1" ]]
[[ "$dns_count" == "1" ]]
if [[ "$rollup_count" -lt 1 ]]; then
  echo "e2e: expected usage_rollups >= 1, got $rollup_count"
  exit 1
fi

# API + CLI delete DNS and site
curl -fsS -X DELETE "http://localhost:8080/api/v1/sites/${site_id}/dns/records/${record_id}" >/dev/null
docker compose exec -T core php artisan cdn:site:delete "--id=${site_id}" >/dev/null

sites_after_delete=$(curl -fsS http://localhost:8080/api/v1/sites)
echo "$sites_after_delete" | grep -q '"data":\[\]'

echo "e2e: ok"
