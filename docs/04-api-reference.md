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

ID format:
- `id` (site id): UUID string
- `user_id`: UUID string

Example success response:
```json
{"data":{"id":"20106ae3-352e-4d1e-aa76-235e54f81b53","user_id":"ee3a9b6e-4544-4758-bcc0-57f7e4edca76","name":"demo","domain":"demo.local","origin_scheme":"http","origin_host":"core","origin_port":8080,"proxy_enabled":true,"status":"active","created_at":1780245069,"updated_at":1780245069,"geo_origins":[]}}
```

## DNS
- `POST /api/v1/sites/{siteId}/dns/records`
- `GET /api/v1/sites/{siteId}/dns/records`
- `DELETE /api/v1/sites/{siteId}/dns/records/{recordId}`

`siteId` and `recordId` are UUID-style string identifiers.

PowerDNS sync:
- If `POWERDNS_ENABLED=1`, DNS create/delete calls are synced to PowerDNS via its HTTP API.
- If `POWERDNS_ENABLED=1`, site create also attempts to create the matching PowerDNS zone automatically.
- Zone creation uses `POWERDNS_ZONE_KIND` and `POWERDNS_ZONE_NAMESERVERS`.
- For `proxied=true` with `type=A`, CDNLite automatically publishes a PowerDNS `LUA` record built from online edge nodes.
- Country conditions are generated from edge `region` country codes (for example `IR`, `NL`, `US`) without hardcoded country lists.
- When edge nodes register/heartbeat with changed `public_ip`, proxied `A` rrsets are auto-refreshed without user API calls.

## Edge
- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/nodes`
- `GET /api/v1/edge/config`
- `GET /api/v1/edge/config?if_version=<n>`

## Collector and Usage
- `POST /api/v1/collector/usage`
- `GET /api/v1/usage/summary`
- `GET /api/v1/usage/summary?site_id=<site_uuid>`
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
