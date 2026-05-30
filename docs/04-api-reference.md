# API Reference

Base URL (local): `http://localhost:8080`

## Health
- `GET /health`

## Sites
- `POST /api/v1/sites`
- `GET /api/v1/sites`
- `PATCH /api/v1/sites/{id}`
- `DELETE /api/v1/sites/{id}`
- `POST /api/v1/sites/{id}/proxy/enable`
- `POST /api/v1/sites/{id}/proxy/disable`

Example create:
```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true,"geo_origins":{"US":{"scheme":"http","host":"us-origin","port":8080},"DEFAULT":{"scheme":"http","host":"core","port":8080}}}'
```

`geo_origins` is optional. When present, keys are country codes (for example `US`, `DE`, `IR`) plus optional `DEFAULT`.

## DNS
- `POST /api/v1/sites/{id}/dns/records`
- `GET /api/v1/sites/{id}/dns/records`
- `DELETE /api/v1/sites/{id}/dns/records/{recordId}`

PowerDNS sync:
- If `POWERDNS_ENABLED=1`, DNS create/delete calls are synced to PowerDNS via its HTTP API.

## Edge
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/nodes`
- `GET /api/v1/edge/config`
- `GET /api/v1/edge/config?if_version=<n>`

## Collector and Usage
- `POST /api/v1/collector/usage`
- `GET /api/v1/usage/summary`
- `GET /api/v1/usage/summary?site_id=<id>`
- `GET /api/v1/usage/summary?bucket=minute|hour|day`
- `POST /api/v1/usage/recalculate`

## Edge-Authenticated Endpoints
Required headers:
- `Authorization: Bearer <edge-token>`
- `X-CDNLITE-Edge-Id: <edge-id>`
- `X-CDNLITE-Timestamp: <unix-seconds>`
- `X-CDNLITE-Nonce: <nonce>`
- `X-CDNLITE-Signature: <signature>`

Endpoints requiring these headers:
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/config`
- `POST /api/v1/collector/usage`

## Common Error Patterns
- `422`: validation failure (`<field>_required`, etc.)
- `401`: edge auth required/invalid
- `409`: replay detected
- `404`: resource not found
