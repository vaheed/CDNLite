# API Workflow

[Back to docs index](../index.md)

Start a fresh stack:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
```

## Create Site

```bash
curl -s -X POST http://localhost:8080/api/v1/sites -H 'Content-Type: application/json' -d '{"name":"API Demo","domain":"api-demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}'
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"API Demo","domain":"api-demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}}
```

## List And Update

```bash
curl -s http://localhost:8080/api/v1/sites
curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111 -H 'Content-Type: application/json' -d '{"name":"API Demo Updated"}'
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"API Demo Updated"}}
```

## Enable And Disable Proxy

```bash
curl -s -X POST http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/proxy/disable
curl -s -X POST http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/proxy/enable
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","proxy_enabled":true}}
```

## DNS

```bash
curl -s -X POST http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records -H 'Content-Type: application/json' -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}'
curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 -H 'Content-Type: application/json' -d '{"content":"127.0.0.2","ttl":120}'
curl -s http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records
```

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","ttl":120,"proxied":true}]}
```

## Edge Proxy

```bash
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
curl -s -H 'Host: api-demo.local' http://localhost:8081/health
```

```json
{"ok":true}
```

## Usage With Auth

Use [Edge Auth Signing](edge-auth-signing.md) to build headers. Example body:

```json
{"idempotency_key":"api-demo-1","items":[{"ts":1710000000,"site_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":10,"bytes_in":1000,"bytes_out":5000,"status":200}]}
```

Expected response:

```json
{"ingested":1,"duplicate":false,"idempotency_key":"api-demo-1"}
```

## Summary And Cleanup

```bash
curl -s -X POST http://localhost:8080/api/v1/usage/recalculate -H 'Content-Type: application/json' -d '{"site_id":"11111111-1111-4111-8111-111111111111"}'
curl -s 'http://localhost:8080/api/v1/usage/summary?site_id=11111111-1111-4111-8111-111111111111&bucket=minute'
curl -s -X DELETE http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222
curl -s -X DELETE http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111
```

```json
{"data":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```
