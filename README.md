# CDNT Lite CDN

This repository contains a runnable end-to-end CDN baseline:
- `core/public_index.php`: core API
- `core/app/Modules/*`: modular core implementation
- `core/artisan`: CLI command runner
- `core/storage/cdnt.sqlite`: persistent SQLite database
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
php core/artisan cdn:edge:sync-config
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
- `POST /api/v1/collector/usage`
- `GET /api/v1/usage/summary`
