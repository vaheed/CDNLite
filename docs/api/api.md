# API Reference

Base URL in local Compose: `http://localhost:8080`.

All responses are JSON. Route handlers commonly return either `{ "data": ... }`, `{ "ok": true }`, or `{ "error": "error_code" }`.

Machine-readable contract: [OpenAPI YAML](openapi.yaml).

## Client Integration Tips

- Treat all IDs as opaque strings. They are UUID-like today, but client code should not parse or derive meaning from them.
- Preserve unknown response fields. The dashboard and API can add fields as features grow; dropping unknown fields in client models makes upgrades easier.
- Use `Content-Type: application/json` for every request with a body.
- Implement retry only for idempotent reads and operationally safe actions. Do not blindly retry create/update/delete calls without checking the response.
- For long-running workflows such as ACME issuance, call the request endpoint, then poll status endpoints and readiness warnings instead of holding a single request open.
- For dashboards or CLIs, surface backend `error` values directly in advanced details. They are stable enough to be useful during troubleshooting.
- Use the OpenAPI document for generated clients, but keep edge HMAC signing as a small handwritten helper because it depends on exact raw-body hashing.

## Response And Error Shape

Most successful resource responses use:

```json
{
  "data": {
    "id": "resource-id"
  }
}
```

List responses use:

```json
{
  "data": []
}
```

Simple command responses use:

```json
{
  "ok": true
}
```

Errors use:

```json
{
  "error": "domain_not_found",
  "field": "domain"
}
```

Common statuses:

| Status | Meaning |
| --- | --- |
| `200` | Read or update succeeded. |
| `201` | Resource was created. |
| `202` | Async-style action was accepted. |
| `400` | JSON body could not be parsed. |
| `401` | Missing/invalid API auth or edge auth. |
| `404` | Resource or route was not found. |
| `409` | Replay nonce or conflicting state. |
| `422` | Validation failed. |
| `502` | Upstream integration failure, often DNS/PowerDNS/ACME/origin. |
| `503` | Readiness failure. |

## Authentication

Control-plane endpoints marked as protected require:

```http
Authorization: Bearer <CDNLITE_API_TOKEN or admin session token>
```

When `CDNLITE_API_TOKEN` is empty, protected control-plane routes are open for local development. Do not run production that way.

Admin sessions are also bearer tokens. A browser login calls `/api/v1/admin/login` and then sends the returned token on protected routes. API-token clients and admin-session clients therefore share the same `Authorization` header shape.

Edge endpoints require bearer token plus replay-protection and HMAC headers:

```http
Authorization: Bearer <edge-token>
X-CDNLITE-Edge-Id: edge-local-1
X-CDNLITE-Timestamp: 1710000000
X-CDNLITE-Nonce: random-unique-value
X-CDNLITE-Signature: <hex-hmac>
```

Canonical string:

```text
UPPERCASE_METHOD
PATH_WITHOUT_QUERY
UNIX_TIMESTAMP
NONCE
SHA256_RAW_BODY_HEX
```

### Edge HMAC Pseudocode

```text
body_hash = sha256(raw_body).hex
canonical = method.upper + "\n" + path_without_query + "\n" + timestamp + "\n" + nonce + "\n" + body_hash
key = sha256(raw_edge_token).hex
signature = hmac_sha256(key, canonical).hex
```

The timestamp should be close to core time, and each nonce must be unique for the edge within the replay window.

### curl With API Token

```bash
API=http://localhost:8080
TOKEN=replace-with-token

curl -s "$API/api/v1/domains" \
  -H "Authorization: Bearer $TOKEN"
```

### curl With Admin Session

```bash
SESSION=$(curl -s -X POST http://localhost:8080/api/v1/admin/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin"}' | jq -r '.data.token')

curl -s http://localhost:8080/api/v1/readiness \
  -H "Authorization: Bearer $SESSION"
```

## Health

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/health` | No | Core liveness. |
| `GET` | `/cdn-health` | No | Structured Core, edge, PowerDNS API, and persisted DNS sync health. |
| `GET` | `/ready` | No | PostgreSQL, schema, config generation, and API token readiness. |
| `GET` | `/api/v1/readiness` | Protected | Detailed readiness model for dashboard. |

Example:

```bash
curl -s http://localhost:8080/health
```

```json
{"ok":true,"time":1710000000}
```

## Admin Auth

| Method | Route | Auth | Body |
| --- | --- | --- | --- |
| `POST` | `/api/v1/admin/login` | No | `{ "username": "admin", "password": "admin" }` |
| `GET` | `/api/v1/admin/me` | Protected | none |
| `POST` | `/api/v1/admin/logout` | Protected | none |

Login response includes a bearer token for dashboard use.

Useful admin login response fields:

| Field | Purpose |
| --- | --- |
| `data.token` | Bearer session token. |
| `data.user.username` | Operator username. |
| `data.expires_at` | Session expiration time when returned by the backend. |

## Overview And Operations

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/overview` | Aggregate operations summary. |
| `GET` | `/api/v1/overview/warnings` | Readiness and risk warnings. |
| `GET` | `/api/v1/security/events` | Paginated global security events. |
| `GET` | `/api/v1/security/summary` | Security event aggregates. |
| `GET` | `/api/v1/audit` | Audit history. |

Common query parameters: `domain_id`, `limit`, `offset`, `type`, `action`, and time filters where supported.

Operational APIs are designed for dashboards and reports. Use them for human-facing status pages, but prefer domain-specific APIs when automating configuration changes.

## Domains

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains` | List domains. |
| `POST` | `/api/v1/domains` | Create a domain. |
| `GET` | `/api/v1/domains/{domainId}` | Show a domain. |
| `PATCH` | `/api/v1/domains/{domainId}` | Update editable domain fields. |
| `DELETE` | `/api/v1/domains/{domainId}` | Delete a domain. |
| `POST` | `/api/v1/domains/{domainId}/verify-nameservers` | Verify registrar delegation. |
| `POST` | `/api/v1/domains/{domainId}/activate` | Activate domain after verification or override. |

Create request:

```json
{
  "name": "Demo",
  "domain": "demo.local",
  "origin_shield_header_name": "X-CDNLite-Origin-Shield",
  "origin_shield_secret": "replace-me"
}
```

Create response:

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "name": "Demo",
    "domain": "demo.local",
    "status": "pending_nameserver"
  }
}
```

Domain status values commonly seen in workflows:

| Status | Meaning | Next action |
| --- | --- | --- |
| `pending_nameserver` | Domain exists but delegation is not verified. | Set registrar nameservers and verify. |
| `active` | Domain can be used by edge config. | Add DNS/origins/rules and watch readiness. |
| `disabled` | Domain is intentionally not serving. | Re-enable only after validating config. |

Tips:

- Create the domain first, then add origins and DNS records. This keeps error messages focused.
- Use `verify-nameservers` before `activate` unless this is a local lab with override.
- Avoid changing the domain hostname after traffic is live; create a new domain entry and migrate instead.

## DNS And Routing

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/dns/records` | List records. |
| `POST` | `/api/v1/domains/{domainId}/dns/records` | Create record. |
| `PATCH` | `/api/v1/domains/{domainId}/dns/records/{recordId}` | Update record. |
| `DELETE` | `/api/v1/domains/{domainId}/dns/records/{recordId}` | Delete record. |
| `GET` | `/api/v1/domains/{domainId}/routing` | Show domain routing settings. |
| `PATCH` | `/api/v1/domains/{domainId}/routing` | Update routing mode and health options. |
| `POST` | `/api/v1/domains/{domainId}/dns/records/{recordId}/preview-routing` | Preview routing result. |
| `GET` | `/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes` | List Geo DNS routes. |
| `PUT` | `/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes` | Replace Geo DNS routes. |

Record request:

```json
{
  "type": "A",
  "name": "www",
  "content": "203.0.113.10",
  "ttl": 300,
  "proxied": true,
  "routing_policy": "standard"
}
```

Routing mode values include `geo`, `anycast`, and `dns_only`.

DNS tips:

- Keep MX, TXT verification, SPF, DKIM, and DMARC records DNS-only.
- Use proxied records only for HTTP/HTTPS traffic intended for the CDN edge.
- A proxied apex (`@`) publishes `ALIAS` to the domain's stable CDN site
  target. A proxied subdomain publishes `CNAME` to the same target.
- A DNS-only apex `CNAME` is rejected with `apex_cname_not_allowed`.
- Keep TTL low during migration, then increase it after a stable cutover.
- Use `preview-routing` before changing Geo DNS routes on a production record.
- For apex anycast records, make sure global anycast settings exist before switching policy.

## Origins

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/origins` | List origins. |
| `POST` | `/api/v1/domains/{domainId}/origins` | Create origin. |
| `PATCH` | `/api/v1/domains/{domainId}/origins/{originId}` | Update origin. |
| `DELETE` | `/api/v1/domains/{domainId}/origins/{originId}` | Delete origin. |
| `POST` | `/api/v1/domains/{domainId}/origins/{originId}/check` | Run manual health check. |

Example:

```json
{
  "host": "origin.example.com",
  "scheme": "https",
  "port": 443,
  "role": "primary",
  "enabled": true
}
```

Origin tips:

- Configure at least one enabled primary origin before sending traffic.
- Add a backup origin before aggressive cache or WAF changes, so edge failover has somewhere to go.
- Use the manual health-check route after every origin update.
- The edge may emit `X-CDNLITE-Origin: primary|backup`; capture this header in incident reports.

## Traffic Rules

| Feature | Routes |
| --- | --- |
| Redirects | `/api/v1/domains/{domainId}/redirects`, import/export/test variants. |
| Legacy rate limit | `PUT`, `GET`, `DELETE /api/v1/domains/{domainId}/rate-limit`. |
| Rate-limit rules | CRUD `/api/v1/domains/{domainId}/rate-limits`. |
| WAF rules | CRUD `/api/v1/domains/{domainId}/waf-rules`. |
| Header rules | CRUD `/api/v1/domains/{domainId}/headers`. |
| IP rules | CRUD `/api/v1/domains/{domainId}/ip-rules`. |
| Cache rules | CRUD `/api/v1/domains/{domainId}/cache-rules`. |
| Page rules | CRUD `/api/v1/domains/{domainId}/page-rules` plus `/test`. |

Rule responses follow the same `{ "data": ... }` pattern and use `422` for validation errors.

Traffic-rule rollout strategy:

1. Add rules disabled or in log-only mode where supported.
2. Test against a staging host or low-risk path.
3. Enable the rule for a narrow match.
4. Watch security events, audit log, and analytics.
5. Broaden the rule only after confirming expected behavior.

## Cache

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/cache/settings` | Show cache settings. |
| `PUT` | `/api/v1/domains/{domainId}/cache/settings` | Replace cache settings. |
| `POST` | `/api/v1/domains/{domainId}/cache/purge` | Create purge request. |
| `GET` | `/api/v1/domains/{domainId}/cache/purge-requests` | List purge requests. |
| `GET` | `/api/v1/domains/{domainId}/cache/purge-requests/{requestId}` | Show purge request. |
| `GET` | `/api/v1/analytics/cache` | Global or domain cache analytics. |

Purge request:

```json
{
  "scope": "url",
  "value": "https://example.com/assets/app.css"
}
```

Cache tips:

- Prefer `url` or `prefix` purges over `everything`.
- Use short TTLs while validating an origin migration.
- Check cache analytics after rule changes; a sudden hit-ratio drop usually means a bypass condition was introduced.
- Requests with authorization or explicit no-cache headers should not be used to judge normal cache behavior.

## SSL

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/ssl` | Show SSL settings. |
| `PATCH` | `/api/v1/domains/{domainId}/ssl/settings` | Update SSL settings. |
| `GET` | `/api/v1/domains/{domainId}/ssl/certificates` | List certificates. |
| `POST` | `/api/v1/domains/{domainId}/ssl/request` | Request SSL flow. |
| `POST` | `/api/v1/domains/{domainId}/ssl/acme/issue` | Issue ACME certificate. |
| `POST` | `/api/v1/domains/{domainId}/ssl/request-cert` | Request automated certificate. |
| `POST` | `/api/v1/domains/{domainId}/ssl/renew` | Force renewal. |
| `GET` | `/api/v1/domains/{domainId}/ssl/acme-status` | Show ACME status. |
| `POST` | `/api/v1/domains/{domainId}/ssl/check` | Check certificates. |
| `POST` | `/api/v1/domains/{domainId}/ssl/manual-certificate` | Import manual certificate. |

SSL tips:

- Use the ACME staging directory until DNS-01 automation is proven.
- Keep DNS-only `_acme-challenge` records available during issuance.
- Do not enable force HTTPS until an active certificate exists for the domain.
- Keep `CDNLITE_SSL_SECRET_KEY` stable; changing it can break stored certificate material.

## Edge And Collector

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/edge/nodes` | Protected | List edges. |
| `GET` | `/api/v1/edges/pools` | Protected | Edge pools. |
| `GET` | `/api/v1/edges/dns` | Protected | Shared proxy rrsets, eligible edge state, CDN zone, proxy host, and sync status. |
| `GET` | `/api/v1/dns/operations` | Protected | PowerDNS setup, DNSGeo capability, and sync summary. |
| `GET` | `/api/v1/dns/zones` | Protected | Per-zone convergence, hashes, pending changes, timestamps, and errors. |
| `GET` | `/api/v1/dns/desired` | Protected | Desired CDNLite-owned RRsets; accepts optional `zone`. |
| `GET` | `/api/v1/dns/zones/{zone}/actual` | Protected | Current raw PowerDNS zone response. |
| `POST` | `/api/v1/dns/dry-run` | Protected | Build desired DNS state without writes. |
| `POST` | `/api/v1/dns/force-sync` | Protected | Run an immediate forced reconciliation. |
| `GET` | `/api/v1/domains/{domainId}/dns/status` | Protected | Domain-zone sync state and last error. |
| `POST` | `/api/v1/edge/register` | Edge signed | Register edge. |
| `POST` | `/api/v1/edge/heartbeat` | Edge signed | Heartbeat edge. |

The bundled edge agent includes `health_status: "healthy"` in successful
heartbeats so fresh local nodes become eligible for the shared DNS edge pool.
| `GET` | `/api/v1/edge/config` | Edge signed | Fetch config snapshot. |
| `POST` | `/api/v1/collector/usage` | Edge signed | Ingest usage rows. |
| `POST` | `/api/v1/collector/security-events` | Edge signed | Ingest security events. |

Heartbeat request:

```json
{
  "edge_id": "edge-local-1",
  "hostname": "edge-local-1",
  "public_ip": "198.51.100.10",
  "region": "local",
  "version": "v1"
}
```

Usage ingest request:

```json
{
  "events": [
    {
      "domain": "example.com",
      "path": "/index.html",
      "status": 200,
      "bytes": 1024,
      "cache_status": "HIT",
      "timestamp": 1710000000
    }
  ]
}
```

Edge proxy responses include an origin marker such as `X-CDNLITE-Origin: primary|backup` when upstream failover state is known.

Edge endpoint notes:

- Register and heartbeat requests must have the same `edge_id` in the header and JSON body.
- `GET /api/v1/edge/config` accepts `if_version` as a query parameter to avoid unnecessary config writes.
- Usage and security-event ingest are queue-friendly. If ingest fails, the agent should keep local payloads for retry.

## Config Snapshots

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/config/snapshots` | List versions. |
| `GET` | `/api/v1/config/snapshots/{version}` | Show version. |
| `POST` | `/api/v1/config/snapshots/diff` | Diff JSON paths between versions. |
| `POST` | `/api/v1/config/snapshots/{version}/rollback` | Activate old version. |
| `POST` | `/api/v1/config/snapshots/rebuild` | Rebuild from database state. |

Diff request:

```json
{
  "from_version": 1,
  "to_version": 2
}
```

Snapshot safety tips:

- Diff before rollback.
- Prefer rebuild after normal database-backed changes.
- Use rollback for a known-bad config version, then investigate why the bad state was generated.
- Edge nodes pull snapshots; rollback is not an instant push to every edge.

## Settings

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/settings` | List settings grouped by area. |
| `GET` | `/api/v1/settings/{group}` | Show one settings group. |
| `PATCH` | `/api/v1/settings/{group}` | Update settings group. |
| `POST` | `/api/v1/settings/validate` | Validate settings payload. |
| `POST` | `/api/v1/settings/test/powerdns` | Test PowerDNS connection. |
| `GET` | `/api/v1/edge-countries` | List edge country data. |

Settings tips:

- Validate settings payloads before saving when building custom admin tooling.
- Test PowerDNS after changing API URL, server ID, or API key.
- Record the actor when settings are changed through automation so audit trails remain useful.

## Analytics

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/usage/summary` | Global or domain usage summary. |
| `POST` | `/api/v1/usage/recalculate` | Rebuild minute/hour/day aggregates. |
| `GET` | `/api/v1/domains/{domainId}/analytics/summary` | Domain usage summary. |
| `GET` | `/api/v1/domains/{domainId}/analytics/cache` | Domain cache analytics. |
| `GET` | `/api/v1/domains/{domainId}/security/events` | Domain security event list. |

Analytics tips:

- Use `bucket=minute` for fresh debugging, `hour` for incident windows, and `day` for trends.
- Recalculate aggregates after bulk ingest, test fixture loading, or suspected aggregation drift.
- Domain-filtered analytics are safer for customer-facing reports than global analytics.
## Operations Logs

`GET /api/v1/security/events` and `GET /api/v1/audit` return:

```json
{
  "items": [],
  "total": 0,
  "limit": 50,
  "offset": 0
}
```

Both endpoints accept `domain_id`, `from`, `to`, `limit`, and `offset`.
Security events additionally support `edge_id`, `type`, `ip`, and `search`.
Audit entries support `actor`, `action`, `resource_type`, and `search`.
The `search` filter matches serialized details plus action, resource type, and
event type. Use `domain_id` for the per-domain Activity viewer.
