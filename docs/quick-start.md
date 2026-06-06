# Quick Start

[Back to docs index](index.md)

## Prerequisites

- Docker with the Compose plugin.
- `curl` and optionally `jq`.

## Start

```bash
cp .env.dev.example .env
docker compose up --build
```

Expected services are `postgres`, `core`, `edge`, `edge-agent`, and `dashboard`. Core listens on `http://localhost:8080`; edge listens on `http://localhost:8081`; the dashboard listens on `http://localhost:8082`; PostgreSQL is exposed on `localhost:5432`.

The core container applies pending SQL migrations automatically before starting
the API server. After pulling schema changes, rebuild or recreate the core service.

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

The default dev template also enables edge-token bootstrap, so this manual command is only needed after disabling `CDNLITE_BOOTSTRAP_EDGE_TOKEN`.

## Open The Admin Dashboard

Visit `http://localhost:8082` and sign in with the local bootstrap admin:

```text
Username: admin
Password: admin
```

The bootstrap account is controlled by `CDNLITE_BOOTSTRAP_ADMIN_USER`, `CDNLITE_BOOTSTRAP_ADMIN_USERNAME`, `CDNLITE_BOOTSTRAP_ADMIN_PASSWORD`, and `CDNLITE_BOOTSTRAP_ADMIN_DISPLAY_NAME` in `.env`. For production or shared environments, start from `.env.production.example`, keep `CDNLITE_BOOTSTRAP_ADMIN_USER=0`, and create an operator account explicitly:

```bash
docker compose exec core php artisan cdn:admin:create \
  --username=admin \
  --password='replace-with-a-long-password'
```

To use the API examples below after an admin exists, create a session token:

```bash
ADMIN_SESSION_TOKEN="$(curl -s -X POST http://localhost:8080/api/v1/admin/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin"}' | jq -r '.data.token')"
```

## Create A Domain

```bash
curl -s -X POST http://localhost:8080/api/v1/domains \
  -H "Authorization: Bearer $ADMIN_SESSION_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"zone_name":"demo.local","display_name":"Demo Domain"}' | jq
```

Example output:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","user_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","name":"Demo Domain","domain":"demo.local","status":"pending_nameserver","created_at":1710000000,"updated_at":1710000000}}
```

```bash
DOMAIN_ID="11111111-1111-4111-8111-111111111111"
```

## Add A DNS Record

```bash
curl -s -X POST "http://localhost:8080/api/v1/domains/$DOMAIN_ID/dns/records" \
  -H "Authorization: Bearer $ADMIN_SESSION_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}' | jq
```

Example output:

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000000}}
```

## Pull Config And Test The Edge Proxy

The agent pulls config every 10 seconds. Force it during a quick start:

```bash
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
```

Because the demo origin is `core:8080`, the edge can proxy core health with the domain host:

```bash
curl -s -H 'Host: demo.local' http://localhost:8081/health
```

Example output:

```json
{"ok":true}
```

Test a proxied API path:

```bash
curl -s -H 'Host: demo.local' http://localhost:8081/api/v1/domains | jq
```

Example output:

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","name":"Demo Domain","domain":"demo.local","status":"active"}]}
```

## Cleanup

```bash
curl -s -X DELETE "http://localhost:8080/api/v1/domains/$DOMAIN_ID/dns/records/22222222-2222-4222-8222-222222222222"
curl -s -X DELETE "http://localhost:8080/api/v1/domains/$DOMAIN_ID"
docker compose down -v
```

Example delete output:

```json
{"ok":true}
```
