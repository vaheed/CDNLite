# API Reference

[Back to docs index](index.md)

Base URL: `http://localhost:8080`. Responses are JSON. Edge registration, heartbeat, config, and collector usage require signed edge auth; other implemented endpoints do not have application auth.

## Common Errors

| Status | Example | Cause |
|---:|---|---|
| 400 | `{"error":"invalid_json","detail":"Syntax error"}` | Malformed JSON body. |
| 400 | `{"error":"invalid_json_object_expected"}` | JSON body is not an object. |
| 401 | `{"error":"edge_auth_required"}` | Missing edge auth fields. |
| 404 | `{"error":"not_found"}` | Unknown route. |
| 409 | `{"error":"edge_auth_replay_detected"}` | Reused nonce. |
| 422 | `{"error":"name_required"}` | Validation failure. |
| 500 | `{"error":"internal_server_error"}` | Unhandled server error. |

## Endpoint Summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | none | Core health. |
| GET | `http://localhost:8081/ready` | none | Edge readiness (`503` when config missing/invalid). |
| POST | `/api/v1/sites` | none | Create site. |
| GET | `/api/v1/sites` | none | List sites. |
| PATCH | `/api/v1/sites/{id}` | none | Update site. |
| DELETE | `/api/v1/sites/{id}` | none | Delete site. |
| POST | `/api/v1/sites/{id}/proxy/enable` | none | Enable proxy. |
| POST | `/api/v1/sites/{id}/proxy/disable` | none | Disable proxy. |
| POST | `/api/v1/sites/{id}/dns/records` | none | Create DNS record. |
| GET | `/api/v1/sites/{id}/dns/records` | none | List DNS records. |
| PATCH | `/api/v1/sites/{id}/dns/records/{recordId}` | none | Update DNS record. |
| DELETE | `/api/v1/sites/{id}/dns/records/{recordId}` | none | Delete DNS record. |
| GET | `/api/v1/edge/nodes` | none | List edge nodes. |
| POST | `/api/v1/edge/register` | edge signed | Register edge node. |
| POST | `/api/v1/edge/heartbeat` | edge signed | Mark edge online. |
| GET | `/api/v1/edge/config` | edge signed | Fetch config snapshot. |
| GET | `/api/v1/edge/config?if_version=...` | edge signed | Fetch if changed. |
| POST | `/api/v1/collector/usage` | edge signed | Ingest usage rows. |
| GET | `/api/v1/usage/summary` | none | Summarize raw usage. |
| GET | `/api/v1/usage/summary?site_id=...` | none | Summarize one site. |
| GET | `/api/v1/usage/summary?bucket=minute|hour|day` | none | Summarize aggregate bucket. |
| POST | `/api/v1/usage/recalculate` | none | Rebuild aggregates. |

## GET /health

Purpose: lightweight core health check. Auth is not required. There are no query parameters or request body fields.

```bash
curl -s http://localhost:8080/health
```

Success:

```json
{"ok":true,"time":1710000000}
```

## Sites

### POST /api/v1/sites

Required JSON fields: `name`, `domain`, `origin_host`. Optional: `user_id`, `origin_scheme` default `http`, `origin_port` default `8080`, `geo_origins`, `proxy_enabled` default `true`.

```bash
curl -s -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo","domain":"demo.local","origin_host":"core","origin_port":8080}'
```

Success `201`:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","user_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","name":"Demo","domain":"demo.local","origin_scheme":"http","origin_host":"core","origin_port":8080,"proxy_enabled":true,"status":"active","created_at":1710000000,"updated_at":1710000000,"geo_origins":[]}}
```

Errors: `name_required`, `domain_required`, `origin_host_required`, `domain_already_exists`; PowerDNS strict failures return 502 with the PowerDNS error string.

### GET /api/v1/sites

```bash
curl -s http://localhost:8080/api/v1/sites
```

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","domain":"demo.local","proxy_enabled":true}]}
```

### PATCH /api/v1/sites/{id}

Patchable fields: `name`, `domain`, `origin_scheme`, `origin_host`, `origin_port`, `geo_origins`, `proxy_enabled`, `status`.

```bash
curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111 \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo Updated","proxy_enabled":false}'
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"Demo Updated","proxy_enabled":false}}
```

Errors: `site_not_found` 404, `domain_already_exists` 422.

### DELETE /api/v1/sites/{id}

```bash
curl -s -X DELETE http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111
```

```json
{"ok":true}
```

Unknown site: `404 {"error":"site_not_found"}`.

### POST /api/v1/sites/{id}/proxy/enable
### POST /api/v1/sites/{id}/proxy/disable

Both return the updated site. Unknown site returns `404 {"error":"site_not_found"}`.

## DNS Records

DNS record fields: `id`, `site_id`, `type`, `name`, `content`, `origin_type`, `origin_content`, `public_type`, `public_content`, `ttl`, `priority`, `proxied`, `geo_policy_id`, `edge_target`, `status`, `created_at`, `updated_at`.

### POST /api/v1/sites/{id}/dns/records

Required: `type`, `name`, `content`. Optional: `ttl` default `300`, `priority`, `proxied` default `false`, `geo_policy_id`, `edge_target`.

```bash
curl -s -X POST http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}'
```

Success `201`:

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000000}}
```

Errors: `type_required`, `name_required`, `content_required`, `site_not_found`, PowerDNS strict 502 errors.

### GET /api/v1/sites/{id}/dns/records

```bash
curl -s http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records
```

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","type":"A","name":"@","proxied":true}]}
```

Unknown site IDs return an empty `data` array because the list query filters by `site_id` only.

### PATCH /api/v1/sites/{id}/dns/records/{recordId}

Patchable fields: `type`, `name`, `content`, `ttl`, `priority`, `proxied`, `geo_policy_id`, `edge_target`, and `status`.

```bash
curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 \
  -H 'Content-Type: application/json' \
  -d '{"content":"127.0.0.2","ttl":120}'
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","ttl":120,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000060}}
```

Empty patch bodies return `422 {"error":"dns_record_update_body_required"}`. Unknown site or record IDs return `404 {"error":"record_not_found"}`.

### DELETE /api/v1/sites/{id}/dns/records/{recordId}

```bash
curl -s -X DELETE http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222
```

```json
{"ok":true}
```

Unknown site or record: `404 {"error":"record_not_found"}`.

## Edge Nodes

### GET /api/v1/edge/nodes

```json
{"data":[{"id":"33333333-3333-4333-8333-333333333333","edge_id":"edge-local-1","hostname":"edge-local-1","public_ip":"203.0.113.10","public_ipv4":"203.0.113.10","public_ipv6":"","region":"local","country":"","continent":"","version":"v1","status":"online","is_enabled":true,"health_status":"unknown","last_heartbeat":1710000000,"last_heartbeat_at":1710000000,"created_at":1710000000,"updated_at":1710000000}]}
```

### Signed Edge Headers

| Header | Required value |
|---|---|
| `Authorization` | `Bearer <registered-token>` |
| `X-CDNLITE-Edge-Id` | Edge ID. |
| `X-CDNLITE-Timestamp` | Unix timestamp within 120 seconds. |
| `X-CDNLITE-Nonce` | Unique nonce for this edge. |
| `X-CDNLITE-Signature` | HMAC SHA-256 over canonical request. |

### POST /api/v1/edge/register

Body: `edge_id` required; `hostname`, `public_ip`, `public_ipv4`, `public_ipv6`, `region`, `country`, `continent`, and `version` optional. The edge agent sends a detected public IPv4 address automatically when `EDGE_PUBLIC_IP=auto`.

```json
{"data":{"id":"33333333-3333-4333-8333-333333333333","edge_id":"edge-local-1","hostname":"edge-local-1","public_ip":"203.0.113.10","public_ipv4":"203.0.113.10","region":"local","version":"v1","status":"online","is_enabled":true,"last_heartbeat":1710000000,"last_heartbeat_at":1710000000,"created_at":1710000000,"updated_at":1710000000}}
```

Header/body edge ID mismatch returns `401 {"error":"edge_auth_edge_id_mismatch"}`.

### POST /api/v1/edge/heartbeat

Body: `{"edge_id":"edge-local-1"}` is sufficient. The edge agent sends `hostname`, detected `public_ip`, `region`, and `version` too.

Success: `{"ok":true}`. When metadata fields are present and non-empty, core updates them on the existing edge row before recomputing only the platform edge DNS zone. Unknown registered node after successful auth returns `404 {"error":"edge_not_found"}`.

### GET /api/v1/edge/config

Optional query: `if_version` integer. HMAC signs path `/api/v1/edge/config`, not the query string.

```json
{"version":1,"generated_at":1710000000,"hosts":{"demo.local":{"site_id":"11111111-1111-4111-8111-111111111111","upstream":"http://core:8080","geo_upstreams":{},"headers":{"X-CDNLITE-Site":"11111111-1111-4111-8111-111111111111"},"dns_records":[]}},"cache_rules":[{"id":"44444444-4444-4444-8444-444444444444","site_id":"11111111-1111-4111-8111-111111111111","enabled":true,"path_prefix":"/api/v1/sites","ttl_seconds":60,"created_at":1710000000,"updated_at":1710000000,"host":"demo.local"}]}
```

Unchanged `if_version` example:

```json
{"not_modified":true,"version":1}
```

### Edge-to-Origin Request Forwarding

Request/response path:

`User -> CDNLite Edge -> Customer Origin -> CDNLite Edge -> User`

How forwarding works for configured hosts:

1. The edge matches the incoming `Host` to `hosts[host]` from the latest `/api/v1/edge/config` snapshot.
2. The router selects a target upstream from `geo_upstreams` (if country match exists) or falls back to `upstream`.
3. OpenResty proxies the request to that upstream and forwards origin response bytes/status back to the client (with normal cache/error-page behavior applied at the edge).

Headers sent from edge to origin:

- `Host: $host`
  Origin sees the customer/CDN hostname requested by the user.
- `X-Forwarded-For: $proxy_add_x_forwarded_for`
  Client/proxy IP chain. The left-most IP is the original user IP.
- `X-Forwarded-Proto: $scheme`
  Original scheme observed by the edge (`http` or `https`).
- `X-Real-IP: $remote_addr`
  Client IP address as seen by this edge hop.
- `X-CDNLITE-Client-IP: $remote_addr`
  CDNLite-specific client IP header as seen by this edge hop.

## Usage

### POST /api/v1/collector/usage

Signed edge-auth body:

```json
{"idempotency_key":"batch-1","items":[{"ts":1710000000,"site_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":10,"bytes_in":1000,"bytes_out":5000,"status":200}]}
```

Success:

```json
{"ingested":1,"duplicate":false,"idempotency_key":"batch-1"}
```

Duplicate key:

```json
{"ingested":0,"duplicate":true,"idempotency_key":"batch-1","item_count":1}
```

Validation errors: `items_must_be_array`, `idempotency_key_must_be_non_empty_string`.

### GET /api/v1/usage/summary

Optional query: `site_id`, `bucket=minute|hour|day`.

```json
{"data":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

Invalid bucket returns `422 {"error":"bucket_must_be_one_of_minute_hour_day"}`.

### POST /api/v1/usage/recalculate

Body is optional. Use `{"site_id":"11111111-1111-4111-8111-111111111111"}` for one site or `{}` for all sites.

```json
{"ok":true,"site_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1}}
```

Invalid `site_id` returns `422 {"error":"site_id_must_be_non_empty_string"}`.
