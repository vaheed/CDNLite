# CDNLite

CDNLite is a lightweight modular CDN platform designed for small teams that want a clear, operable stack without heavyweight distributed infrastructure.

## What This Project Includes
- `core/`: API + CLI control plane (PHP, PostgreSQL)
- `edge/openresty/`: OpenResty + Lua edge data plane
- `edge/agent/`: edge control-loop scripts (register, heartbeat, config sync, metrics push)
- `docker-compose.yml`: local/runtime stack for core + edge + edge-agent + postgres
- `ci/`: smoke and e2e validation scripts
- `docs/`: full technical and operational documentation

## Key Capabilities
- Site CRUD and proxy enable/disable
- DNS record management per site
- Edge node token registration, node register/heartbeat, and config sync
- Deterministic config snapshot versioning
- Usage ingest with idempotency support
- Usage summary and aggregate rebuild (`minute`, `hour`, `day`)
- Edge auth + replay protection for control-plane endpoints
- Modern edge error/status page for upstream failures

## Quick Start

### Prerequisites
- Docker + Docker Compose

### Run Locally
```bash
cp .env.example .env
docker compose up --build
```

Services:
- Core API: `http://localhost:8080`
- Edge Proxy: `http://localhost:8081`
- PostgreSQL: `localhost:5432`

Environment config:
- `.env.example` contains all supported runtime variables for local/dev startup.
- PowerDNS sync is optional and disabled by default. Set `POWERDNS_ENABLED=1` and provide PowerDNS API variables to enable external DNS sync.

### Health Check
```bash
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
```

## API Quick Test
```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo2","domain":"demo2.local","origin_host":"core","origin_port":8080,"proxy_enabled":true,"geo_origins":{"IR":{"scheme":"http","host":"core-ir","port":8080},"DEFAULT":{"scheme":"http","host":"core","port":8080}}}'

curl -s -X POST http://localhost:8080/api/v1/sites/1/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"1.1.1.1","ttl":300,"proxied":true}'

curl -i http://localhost:8081/api/v1/sites -H 'Host: demo2.local'
```

## CLI Quick Test
```bash
php core/artisan cdn:site:create --name=demo --domain=demo.local --origin_host=core --origin_port=8080
php core/artisan cdn:site:list
php core/artisan cdn:dns:add-record --site_id=1 --type=A --name=@ --content=1.1.1.1 --proxied=1
php core/artisan cdn:dns:list-records --site_id=1
php core/artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
php core/artisan cdn:edge:sync-config
php core/artisan cdn:usage:summary
```

## Edge Auth Requirements
Required on edge control and ingest endpoints:
- `Authorization: Bearer <edge-token>`
- `X-CDNLITE-Edge-Id: <edge-id>`
- `X-CDNLITE-Timestamp: <unix-seconds>`
- `X-CDNLITE-Nonce: <unique nonce>`
- `X-CDNLITE-Signature: <hmac signature>`

## Documentation Map
- [Agent Governance](docs/AGENTS.md)
- [Development Guide](docs/DEVELOPMENT.md)
- [Roadmap](docs/ROADMAP.md)
- [Skills Guide](docs/SKILLS.md)
- [Architecture and Principles](docs/00-architecture-and-principles.md)
- [Core Design](docs/01-core-design.md)
- [Runtime Stages](docs/02-runtime-stages.md)
- [Change Log](docs/03-change-log.md)
- [API Reference](docs/04-api-reference.md)
- [CLI Reference](docs/05-cli-reference.md)
- [Deployment Guide](docs/06-deployment-guide.md)
- [Operations Runbook](docs/07-operations-runbook.md)
- [Security Model](docs/08-security-model.md)
- [Troubleshooting](docs/09-troubleshooting.md)

## Current Delivery State
Roadmap and implementation state live in [docs/ROADMAP.md](docs/ROADMAP.md).
