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
docker compose up -d --build
```

## Verification
```bash
./ci/smoke.sh
./ci/e2e.sh
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
