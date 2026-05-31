# Deployment Guide

## Deployment Model
Single-stack deployment with:
- `postgres`
- `core`
- `edge`
- `edge-agent`

Reference file:
- `docker-compose.yml`

## Environment Variables
### Core
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `APP_LOG_ENABLED`
- `APP_LOG_LEVEL`
- `APP_DEBUG`
- `POWERDNS_ENABLED`
- `POWERDNS_STRICT`
- `POWERDNS_API_URL`
- `POWERDNS_API_KEY`
- `POWERDNS_SERVER_ID`
- `POWERDNS_ZONE_KIND`
- `POWERDNS_ZONE_NAMESERVERS`

### Edge-Agent
- `CORE_URL`
- `EDGE_ID`
- `EDGE_HOSTNAME`
- `EDGE_PUBLIC_IP`
- `EDGE_REGION`
- `EDGE_VERSION`
- `EDGE_TOKEN`
- `EDGE_CONFIG_PATH`
- `METRIC_PATH`

## Local Bring-Up
```bash
cp .env.example .env
docker compose up -d --build
```

Notes:
- Docker Compose automatically loads variables from `.env`.
- DNS record lifecycle is always persisted in `core` (PostgreSQL-backed).
- If `POWERDNS_ENABLED=1`:
  - Site create auto-creates zone in PowerDNS.
  - DNS create/delete operations are synced to PowerDNS.
  - `proxied=true` + `type=A` publishes all online edge `public_ip` values.
  - Edge register/heartbeat with changed IP automatically refreshes proxied `A` rrsets.

## Verification
```bash
./ci/smoke.sh
./ci/e2e.sh
```

## Runtime Verification (PowerDNS)
```bash
# 1) create a site and capture UUID ids
SITE_JSON=$(curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"verify","domain":"verify.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}')
SITE_ID=$(printf "%s" "$SITE_JSON" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')

# 2) create proxied A record (content is ignored for PowerDNS sync when edges are online)
curl -s -X POST http://localhost:8080/api/v1/sites/$SITE_ID/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"10.10.10.10","ttl":120,"proxied":true}'

# 3) change edge public IP (example) and let automatic refresh update proxied records
# (edge auth headers omitted here for brevity; use edge-agent or signed edge register call)

# 4) verify PowerDNS zone contains updated edge IP(s)
docker compose exec -T core sh -lc 'curl -sk -H "X-API-Key: $POWERDNS_API_KEY" "$POWERDNS_API_URL/api/v1/servers/$POWERDNS_SERVER_ID/zones/verify.local."'
```

## Production Baseline Checklist
- Use non-default secrets for DB and edge token.
- Restrict inbound access by network policy/firewall.
- Persist PostgreSQL volume backups.
- Configure central log collection.
- Set container restart policies as needed.

## Image Build and Push
CI workflow:
- `.github/workflows/ci.yml`

Published tags:
- `ghcr.io/<owner>/cdnlite-core:*`
- `ghcr.io/<owner>/cdnlite-edge:*`
- `ghcr.io/<owner>/cdnlite-edge-agent:*`
