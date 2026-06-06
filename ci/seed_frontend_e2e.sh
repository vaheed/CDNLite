#!/usr/bin/env bash
set -euo pipefail

domain_a="11111111-1111-4111-8111-111111111117"
domain_b="22222222-2222-4222-8222-222222222227"
now="$(date +%s)"

docker compose exec -T postgres psql -U cdnlite -d cdnlite -v ON_ERROR_STOP=1 \
  -c "INSERT INTO domains (id, user_id, name, domain, origin_scheme, origin_host, origin_port, geo_origins_json, proxy_enabled, status, created_at, updated_at)
      VALUES
        ('$domain_a', NULL, 'Analytics Alpha', 'analytics-alpha.local', 'http', 'core', 8080, NULL, true, 'active', $now, $now),
        ('$domain_b', NULL, 'Analytics Beta', 'analytics-beta.local', 'http', 'core', 8080, NULL, true, 'active', $now, $now)
      ON CONFLICT (id) DO NOTHING;"

ingest() {
  local domain_id="$1"
  local suffix="$2"
  local requests="$3"
  local bytes_in="$4"
  local bytes_out="$5"
  local cache_status="$6"
  docker compose exec -T core php artisan cdn:usage:ingest \
    --domain_id="$domain_id" \
    --edge_node_id=edge-analytics-e2e \
    --requests_count="$requests" \
    --bytes_in="$bytes_in" \
    --bytes_out="$bytes_out" \
    --status=200 \
    --cache_status="$cache_status" \
    --ts="$((now + suffix))" \
    --idempotency_key="frontend-analytics-${domain_id}-${suffix}" >/dev/null
}

ingest "$domain_a" 1 12 1200 12000 HIT
ingest "$domain_a" 2 4 400 4000 MISS
ingest "$domain_a" 3 3 300 3000 BYPASS
ingest "$domain_a" 4 1 100 1000 UNKNOWN
ingest "$domain_b" 5 5 500 5000 HIT
ingest "$domain_b" 6 5 500 5000 MISS

docker compose exec -T core php artisan cdn:usage:recalculate >/dev/null
