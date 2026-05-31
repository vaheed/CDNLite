# Quick Start

[Back to docs index](index.md)

## Prerequisites

- Docker with the Compose plugin.
- `curl` and optionally `jq`.

## Start

```bash
cp .env.example .env
docker compose up --build
```

Expected services are `postgres`, `core`, `edge`, and `edge-agent`. Core listens on `http://localhost:8080`; edge listens on `http://localhost:8081`; PostgreSQL is exposed on `localhost:5432`.

## Health Checks

```bash
curl -s http://localhost:8080/health
```

Example output:

```json
{"ok":true,"time":1710000000}
```

```bash
curl -s http://localhost:8081/health
```

Example output:

```json
{"ok":true}
```

## Register Local Edge Token

```bash
docker compose exec core php artisan cdn:edge:register-token \
  --edge_id=edge-local-1 \
  --token=edge-dev-token
```

Example output:

```json
{"ok":true,"edge_id":"edge-local-1"}
```

## Create A Site

```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo Site","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}' | jq
```

Example output:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","user_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","name":"Demo Site","domain":"demo.local","origin_scheme":"http","origin_host":"core","origin_port":8080,"proxy_enabled":true,"status":"active","created_at":1710000000,"updated_at":1710000000,"geo_origins":[]}}
```

```bash
SITE_ID="11111111-1111-4111-8111-111111111111"
```

## Add A DNS Record

```bash
curl -s -X POST "http://localhost:8080/api/v1/sites/$SITE_ID/dns/records" \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}' | jq
```

Example output:

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000000}}
```

## Pull Config And Test The Edge Proxy

The agent pulls config every 10 seconds. Force it during a quick start:

```bash
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
```

Because the demo origin is `core:8080`, the edge can proxy core health with the site host:

```bash
curl -s -H 'Host: demo.local' http://localhost:8081/health
```

Example output:

```json
{"ok":true}
```

Test a proxied API path:

```bash
curl -s -H 'Host: demo.local' http://localhost:8081/api/v1/sites | jq
```

Example output:

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","name":"Demo Site","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}]}
```

## Cleanup

```bash
curl -s -X DELETE "http://localhost:8080/api/v1/sites/$SITE_ID/dns/records/22222222-2222-4222-8222-222222222222"
curl -s -X DELETE "http://localhost:8080/api/v1/sites/$SITE_ID"
docker compose down -v
```

Example delete output:

```json
{"ok":true}
```
