# API Reference

Base URL (local): `http://localhost:8080`

If you changed `CORE_HOST_PORT` in `.env` (for example `CORE_HOST_PORT=8085`), use that port in all API calls.

## Health
- `GET /health`

## Sites
- `POST /api/v1/sites`
- `GET /api/v1/sites`
- `PATCH /api/v1/sites/{siteId}`
- `DELETE /api/v1/sites/{siteId}`
- `POST /api/v1/sites/{siteId}/proxy/enable`
- `POST /api/v1/sites/{siteId}/proxy/disable`

Example create:
```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true,"geo_origins":{"US":{"scheme":"http","host":"us-origin","port":8080},"DEFAULT":{"scheme":"http","host":"core","port":8080}}}'
```

`geo_origins` is optional. When present, keys are country codes (for example `US`, `DE`, `IR`) plus optional `DEFAULT`.

## DNS
- `POST /api/v1/sites/{siteId}/dns/records`
- `GET /api/v1/sites/{siteId}/dns/records`
- `DELETE /api/v1/sites/{siteId}/dns/records/{recordId}`

`siteId` and `recordId` are UUID-style string identifiers for newly created records.

PowerDNS sync:
- If `POWERDNS_ENABLED=1`, DNS create/delete calls are synced to PowerDNS via its HTTP API.
- PowerDNS zones must already exist (for example `demo.local.`). CDNLite does not auto-create zones.

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
- `400`: malformed JSON body (`invalid_json`, `invalid_json_object_expected`)
- `422`: validation failure (`<field>_required`, etc.)
- `401`: edge auth required/invalid
- `409`: replay detected
- `404`: resource not found
