#!/usr/bin/env bash
set -euo pipefail

require_json_field() {
  local payload="$1"
  local key="$2"
  python3 - "$payload" "$key" <<'PY'
import json
import sys

payload = json.loads(sys.argv[1])
key = sys.argv[2]
if key not in payload:
    raise SystemExit(1)
PY
}

core_health=$(curl -fsS http://localhost:8080/health)
edge_health=$(curl -fsS http://localhost:8081/health)
require_json_field "$core_health" "ok"
require_json_field "$edge_health" "ok"

api_sites=$(curl -fsS http://localhost:8080/api/v1/sites)
require_json_field "$api_sites" "data"

api_usage=$(curl -fsS http://localhost:8080/api/v1/usage/summary)
require_json_field "$api_usage" "data"

docker compose exec -T core php artisan list | grep -q '^cdn:site:create$'
docker compose exec -T core php artisan list | grep -q '^cdn:usage:ingest$'
docker compose exec -T core php artisan list | grep -q '^cdn:edge:sync-config$'

echo "smoke: ok"
