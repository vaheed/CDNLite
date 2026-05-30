# CDNLite

This repository contains a runnable end-to-end CDN baseline:
- `core/public_index.php`: core API
- `core/app/Modules/*`: modular core implementation
- `core/artisan`: CLI command runner
- `storage/cdnlite.sqlite`: persistent SQLite database
- `edge/openresty`: OpenResty + Lua host routing and proxying
- `edge/agent`: register/heartbeat/config pull/metrics push loops
- `docker-compose.yml`: one-command local deployment

## Runtime files
- Runtime stages: [docs/02-runtime-stages.md](docs/02-runtime-stages.md)
- Change log: [docs/03-change-log.md](docs/03-change-log.md)
- Root agent policy: [AGENTS.md](AGENTS.md)
- Core agent policy: [core/AGENTS.md](core/AGENTS.md)
- Edge agent policy: [edge/AGENTS.md](edge/AGENTS.md)

## Run

```bash
docker compose up --build
```

Services:
- Core API: `http://localhost:8080`
- Edge Proxy: `http://localhost:8081`

## API quick test

```bash
curl -s http://localhost:8080/health
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo2","domain":"demo2.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}'
curl -i http://localhost:8081/api/v1/sites -H 'Host: demo.local'
```

## CLI examples

```bash
php core/artisan cdn:site:create --name=demo --domain=demo.local --origin_host=core --origin_port=8080
php core/artisan cdn:site:list
php core/artisan cdn:dns:add-record --site_id=1 --type=A --name=@ --content=1.1.1.1 --proxied=1
php core/artisan cdn:dns:list-records --site_id=1
php core/artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
php core/artisan cdn:edge:rotate-token --edge_id=edge-local-1
php core/artisan cdn:edge:sync-config
php core/artisan cdn:edge:sync-config --if_version=3
php core/artisan cdn:usage:ingest --site_id=1 --edge_node_id=edge-local-1 --requests_count=50 --bytes_in=1200 --bytes_out=4800 --status=200 --idempotency_key=usage-batch-1
php core/artisan cdn:usage:summary
```

## Implemented v1 endpoints

- `POST /api/v1/sites`
- `GET /api/v1/sites`
- `PATCH /api/v1/sites/{id}`
- `DELETE /api/v1/sites/{id}`
- `POST /api/v1/sites/{id}/proxy/enable`
- `POST /api/v1/sites/{id}/proxy/disable`
- `POST /api/v1/sites/{id}/dns/records`
- `GET /api/v1/sites/{id}/dns/records`
- `DELETE /api/v1/sites/{id}/dns/records/{recordId}`
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/nodes`
- `GET /api/v1/edge/config`
- `GET /api/v1/edge/config?if_version=<n>`
- `POST /api/v1/collector/usage` (supports optional `idempotency_key`)
- `GET /api/v1/usage/summary`

## Edge auth headers

The following edge endpoints require token auth plus replay-protection headers:
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/config`
- `POST /api/v1/collector/usage`

Required headers:
- `Authorization: Bearer <edge-token>`
- `X-CDNLITE-Edge-Id: <edge-id>`
- `X-CDNLITE-Timestamp: <unix-seconds>`
- `X-CDNLITE-Nonce: <unique-per-request>`
