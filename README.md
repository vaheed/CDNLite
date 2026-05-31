# CDNLite

CDNLite is a lightweight modular CDN platform with a PHP control plane, PostgreSQL database, OpenResty/Lua edge proxy, and shell-based edge agent.

It manages sites, DNS records, edge nodes, config snapshots, edge usage ingest, and usage summaries. It is useful for first-time CDN learners, developers testing CDN workflows, operators running a small local stack, maintainers, and agents working in this repository.

## Key Features

- Site lifecycle API and CLI.
- Site-scoped DNS records with create, update, list, delete, and optional PowerDNS sync.
- Host-based OpenResty edge proxy using a JSON config snapshot.
- Edge agent registration, heartbeat, config pull, and metric push.
- Automatic edge public IPv4 discovery with platform-owned PowerDNS edge-zone routing.
- Edge-authenticated endpoints using bearer token, edge ID, timestamp, nonce, and HMAC signature.
- Usage ingest with optional idempotency key and minute/hour/day aggregate rebuilds.
- Docker Compose local stack and CI smoke/e2e scripts.

## Architecture Summary

`core/` is the control plane. It serves HTTP from `core/public_index.php`, registers CLI commands in `core/artisan`, stores data in PostgreSQL, builds edge config, and ingests usage. `edge/openresty/` is the data plane. It reads `/var/lib/cdnlite/config.json`, routes by `Host`, proxies to origins, and writes metrics. `edge/agent/` signs calls to core and keeps the edge registered, configured, and reporting metrics.

## Services And Ports

| Service | Compose name | Container port | Host port default | Purpose |
|---|---|---:|---:|---|
| PostgreSQL | `postgres` | `5432` | `5432` | Persistent state. |
| Core API | `core` | `8080` | `8080` | PHP API and CLI runtime. |
| Edge proxy | `edge` | `8081` | `8081` | OpenResty proxy. |
| Edge agent | `edge-agent` | none | none | Background sync loop. |

## Quick Start

```bash
cp .env.example .env
docker compose up --build
```

Health checks:

```bash
curl -s http://localhost:8080/health
curl -s http://localhost:8081/health
```

Example output:

```json
{"ok":true,"time":1710000000}
```

Edge health returns:

```json
{"ok":true}
```

Register the local edge token:

```bash
docker compose exec core php artisan cdn:edge:register-token \
  --edge_id=edge-local-1 \
  --token=edge-dev-token
```

## First API Example

```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}'
```

Example output:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","user_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","name":"Demo","domain":"demo.local","origin_scheme":"http","origin_host":"core","origin_port":8080,"proxy_enabled":true,"status":"active","created_at":1710000000,"updated_at":1710000000,"geo_origins":[]}}
```

## First CLI Example

```bash
docker compose exec core php artisan cdn:site:list
```

Example output:

```json
{"data":[]}
```

## Documentation Map

Start at [docs/index.md](docs/index.md). Key pages: [quick start](docs/quick-start.md), [API reference](docs/api-reference.md), [CLI reference](docs/cli-reference.md), [edge agent](docs/edge-agent.md), [security](docs/security.md), and [operations runbook](docs/operations-runbook.md).

## Development And Test Commands

```bash
docker compose config
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
./ci/smoke.sh
./ci/e2e.sh
```

The CI scripts expect the Compose stack to be running.

## Current Limitations And Non-Goals

- No dashboard UI, user auth layer, TLS automation, cache storage, purge API, or billing system is implemented.
- Public site, DNS, edge list, usage summary, and recalculate endpoints do not require application auth.
- Edge auth protects only edge registration, heartbeat, config fetch, and usage ingest.
- Config changes reach edge nodes by polling/pull, not push.

## Security Note

Do not use the development token `edge-dev-token` outside local development. Edge-authenticated endpoints require a registered token and valid HMAC signature. See [docs/security.md](docs/security.md) and [docs/examples/edge-auth-signing.md](docs/examples/edge-auth-signing.md).
