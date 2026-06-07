# API Reference

## DNS routing

- `GET|PUT /api/v1/admin/edge-network/anycast` reads or replaces the global two-IPv4/two-IPv6 Anycast VIP set.
- `GET /api/v1/edge-countries` lists countries represented by enabled, online Geo edge nodes.
- `GET|PUT /api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes` reads or transactionally replaces per-record Geo routes. Each route maps a visitor `country_code` to an `edge_country_code`; CDNLite dynamically uses the healthy enabled nodes in that edge country. A default route with a null/empty `country_code` is required.
- DNS record create/update accepts `routing_policy`: `standard`, `geo`, `anycast`, or `geo_anycast`. The default is `standard`, including when `proxied=true`. Anycast policies require `proxied=true` and a complete global VIP set.

[Back to docs index](index.md)

Base URL: `http://localhost:8080`. Responses are JSON. Non-edge `/api/v1/*` endpoints accept either a static `CDNLITE_API_TOKEN` bearer token or a dashboard admin session bearer token. If neither `CDNLITE_API_TOKEN` nor any admin users are configured, control-plane routes remain open for local development. Edge registration, heartbeat, config, and collector usage require signed edge auth.

The backend server-rendered `/dashboard/*` routes have been removed. The official dashboard is the static SPA served by the root Compose `dashboard` service.

Browser clients must call core from an origin listed in `CDNLITE_CORS_ALLOWED_ORIGINS`. Local Compose allows `http://localhost:8082` and `http://127.0.0.1:8082` by default.

## Common Errors

| Status | Example | Cause |
|---:|---|---|
| 400 | `{"error":"invalid_json","detail":"Syntax error"}` | Malformed JSON body. |
| 400 | `{"error":"invalid_json_object_expected"}` | JSON body is not an object. |
| 401 | `{"error":"api_auth_required"}` | Missing or invalid control-plane bearer token. |
| 401 | `{"error":"admin_invalid_credentials"}` | Dashboard admin login failed. |
| 503 | `{"error":"admin_user_not_configured"}` | Dashboard login attempted before creating an admin user. |
| 401 | `{"error":"edge_auth_required"}` | Missing edge auth fields. |
| 404 | `{"error":"not_found"}` | Unknown route. |
| 409 | `{"error":"edge_auth_replay_detected"}` | Reused nonce. |
| 422 | `{"error":"name_required"}` | Validation failure. |
| 500 | `{"error":"internal_server_error"}` | Unhandled server error. |

## Endpoint Summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | none | Core health. |
| GET | `/cdn-health` | none | Origin health endpoint for proxied edge checks. |
| GET | `/ready` | none | Core readiness (includes API token production guard). |
| GET | `/api/v1/readiness` | bearer when auth is configured | Structured core and edge readiness checks for the dashboard. |
| GET | `/api/v1/overview` | bearer when auth is configured | Aggregate 24-hour metrics, top domains, and recent config snapshots. |
| GET | `/api/v1/overview/warnings` | bearer when auth is configured | Actionable domain, edge, and SSL warnings. |
| GET | `http://localhost:8081/ready` | none | Edge readiness (`503` only when no valid config exists). |
| POST | `/api/v1/admin/login` | none | Create dashboard admin session. |
| GET | `/api/v1/admin/me` | admin session bearer | Return current dashboard admin user. |
| POST | `/api/v1/admin/logout` | admin session bearer | Revoke current dashboard admin session. |
| POST | `/api/v1/domains` | bearer when `CDNLITE_API_TOKEN` is set | Create domain. |
| GET | `/api/v1/domains` | bearer when `CDNLITE_API_TOKEN` is set | List domains. |
| PATCH | `/api/v1/domains/{id}` | bearer when `CDNLITE_API_TOKEN` is set | Update domain. |
| DELETE | `/api/v1/domains/{id}` | bearer when `CDNLITE_API_TOKEN` is set | Delete domain. |
| POST | `/api/v1/domains/{id}/proxy/enable` | bearer when `CDNLITE_API_TOKEN` is set | Enable proxy. |
| POST | `/api/v1/domains/{id}/proxy/disable` | bearer when `CDNLITE_API_TOKEN` is set | Disable proxy. |
| POST | `/api/v1/domains/{id}/dns/records` | bearer when `CDNLITE_API_TOKEN` is set | Create DNS record. |
| GET | `/api/v1/domains/{id}/dns/records` | bearer when `CDNLITE_API_TOKEN` is set | List DNS records. |
| PATCH | `/api/v1/domains/{id}/dns/records/{recordId}` | bearer when `CDNLITE_API_TOKEN` is set | Update DNS record. |
| DELETE | `/api/v1/domains/{id}/dns/records/{recordId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete DNS record. |
| POST | `/api/v1/domains/{id}/redirects` | bearer when `CDNLITE_API_TOKEN` is set | Create redirect rule. |
| GET | `/api/v1/domains/{id}/redirects` | bearer when `CDNLITE_API_TOKEN` is set | List redirect rules. |
| PATCH | `/api/v1/domains/{id}/redirects/{redirectId}` | bearer when `CDNLITE_API_TOKEN` is set | Update redirect rule. |
| DELETE | `/api/v1/domains/{id}/redirects/{redirectId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete redirect rule. |
| POST | `/api/v1/domains/{id}/redirects/import` | bearer when `CDNLITE_API_TOKEN` is set | Bulk import redirect rules. |
| GET | `/api/v1/domains/{id}/redirects/export` | bearer when `CDNLITE_API_TOKEN` is set | Export redirect rules. |
| POST | `/api/v1/domains/{id}/redirects/test` | bearer when `CDNLITE_API_TOKEN` is set | Test redirect matching for a path/query. |
| PUT | `/api/v1/domains/{id}/rate-limit` | bearer when `CDNLITE_API_TOKEN` is set | Create/update domain rate limit rule. |
| GET | `/api/v1/domains/{id}/rate-limit` | bearer when `CDNLITE_API_TOKEN` is set | Get domain rate limit rule. |
| DELETE | `/api/v1/domains/{id}/rate-limit` | bearer when `CDNLITE_API_TOKEN` is set | Disable domain rate limit rule. |
| POST | `/api/v1/domains/{id}/rate-limits` | bearer when `CDNLITE_API_TOKEN` is set | Create a rate limit rule. |
| GET | `/api/v1/domains/{id}/rate-limits` | bearer when `CDNLITE_API_TOKEN` is set | List all rate limit rules. |
| PATCH | `/api/v1/domains/{id}/rate-limits/{ruleId}` | bearer when `CDNLITE_API_TOKEN` is set | Update or enable/disable one rule. |
| DELETE | `/api/v1/domains/{id}/rate-limits/{ruleId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete one rule. |
| POST | `/api/v1/domains/{id}/waf-rules` | bearer when `CDNLITE_API_TOKEN` is set | Create WAF rule. |
| GET | `/api/v1/domains/{id}/waf-rules` | bearer when `CDNLITE_API_TOKEN` is set | List WAF rules. |
| PATCH | `/api/v1/domains/{id}/waf-rules/{wafId}` | bearer when `CDNLITE_API_TOKEN` is set | Update WAF rule. |
| DELETE | `/api/v1/domains/{id}/waf-rules/{wafId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete WAF rule. |
| POST | `/api/v1/domains/{id}/cache-rules` | bearer when `CDNLITE_API_TOKEN` is set | Create cache rule. |
| GET | `/api/v1/domains/{id}/cache-rules` | bearer when `CDNLITE_API_TOKEN` is set | List cache rules. |
| PATCH | `/api/v1/domains/{id}/cache-rules/{cacheRuleId}` | bearer when `CDNLITE_API_TOKEN` is set | Update cache rule. |
| DELETE | `/api/v1/domains/{id}/cache-rules/{cacheRuleId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete cache rule. |
| GET | `/api/v1/domains/{id}/cache/settings` | bearer when `CDNLITE_API_TOKEN` is set | Get domain cache defaults and policy controls. |
| PUT | `/api/v1/domains/{id}/cache/settings` | bearer when `CDNLITE_API_TOKEN` is set | Create/update domain cache defaults and policy controls. |
| POST | `/api/v1/domains/{id}/cache/purge` | bearer when `CDNLITE_API_TOKEN` is set | Create a cache purge request and bump soft purge namespace version. |
| GET | `/api/v1/domains/{id}/cache/purge-requests` | bearer when `CDNLITE_API_TOKEN` is set | List cache purge requests. |
| GET | `/api/v1/domains/{id}/cache/purge-requests/{requestId}` | bearer when `CDNLITE_API_TOKEN` is set | Get one cache purge request. |
| POST | `/api/v1/domains/{id}/page-rules` | bearer when `CDNLITE_API_TOKEN` is set | Create page rule. |
| GET | `/api/v1/domains/{id}/page-rules` | bearer when `CDNLITE_API_TOKEN` is set | List page rules. |
| PATCH | `/api/v1/domains/{id}/page-rules/{ruleId}` | bearer when `CDNLITE_API_TOKEN` is set | Update page rule. |
| DELETE | `/api/v1/domains/{id}/page-rules/{ruleId}` | bearer when `CDNLITE_API_TOKEN` is set | Delete page rule. |
| POST | `/api/v1/domains/{id}/page-rules/test` | bearer when `CDNLITE_API_TOKEN` is set | Test page rule matching. |
| GET | `/api/v1/domains/{id}/ssl/certificates` | bearer when `CDNLITE_API_TOKEN` is set | List SSL certificate metadata rows for domain hostnames. |
| GET | `/api/v1/domains/{id}/ssl` | bearer when `CDNLITE_API_TOKEN` is set | Get force-HTTPS and minimum-TLS settings. |
| PATCH | `/api/v1/domains/{id}/ssl/settings` | bearer when `CDNLITE_API_TOKEN` is set | Update force-HTTPS and minimum-TLS settings. |
| POST | `/api/v1/domains/{id}/ssl/acme/issue` | bearer when `CDNLITE_API_TOKEN` is set | Issue an ACME certificate with DNS-01 via PowerDNS for an active proxied domain host. |
| POST | `/api/v1/domains/{id}/ssl/request` | bearer when `CDNLITE_API_TOKEN` is set | Request SSL metadata provisioning for an active proxied domain host. |
| POST | `/api/v1/domains/{id}/ssl/check` | bearer when `CDNLITE_API_TOKEN` is set | Refresh/create SSL metadata rows for hostnames. |
| POST | `/api/v1/domains/{id}/ssl/manual-certificate` | bearer when `CDNLITE_API_TOKEN` is set | Import manual certificate and private key for hostname. |
| GET | `/api/v1/domains/{id}/security/events` | bearer when `CDNLITE_API_TOKEN` is set | List domain security events from audit log. |
| GET | `/api/v1/analytics/cache` | bearer when `CDNLITE_API_TOKEN` is set | Cache effectiveness analytics for all domains or one `domain_id`. |
| GET | `/api/v1/domains/{id}/analytics/summary` | bearer when `CDNLITE_API_TOKEN` is set | Usage summary and time-series points for one domain. |
| GET | `/api/v1/domains/{id}/analytics/cache` | bearer when `CDNLITE_API_TOKEN` is set | Cache effectiveness analytics for one domain. |
| GET | `/api/v1/edge/nodes` | bearer when `CDNLITE_API_TOKEN` is set | List edge nodes. Each row includes `identity_status` (`ok` or `warning`); empty, `unknown`, and `openresty` identities are warnings. |
| POST | `/api/v1/edge/register` | edge signed | Register edge node. |
| POST | `/api/v1/edge/heartbeat` | edge signed | Mark edge online. |
| GET | `/api/v1/edge/config` | edge signed | Fetch config snapshot. |
| GET | `/api/v1/edge/config?if_version=...` | edge signed | Fetch if changed. |
| POST | `/api/v1/collector/usage` | edge signed | Ingest usage rows. |
| POST | `/api/v1/collector/security-events` | edge signed | Ingest edge security events (`waf_match`, `rate_limited`) into audit log. |
| GET | `/api/v1/usage/summary` | bearer when `CDNLITE_API_TOKEN` is set | Summarize raw usage. |
| GET | `/api/v1/usage/summary?domain_id=...` | bearer when `CDNLITE_API_TOKEN` is set | Summarize one domain. |
| GET | `/api/v1/usage/summary?bucket=minute|hour|day` | bearer when `CDNLITE_API_TOKEN` is set | Summarize aggregate bucket. |
| POST | `/api/v1/usage/recalculate` | bearer when `CDNLITE_API_TOKEN` is set | Rebuild aggregates. |

### GET /api/v1/domains/{id}/analytics/cache

Returns cache-status breakdown rows and summary totals for all domains or one domain.

```bash
curl -s "http://localhost:8080/api/v1/analytics/cache?domain_id=11111111-1111-4111-8111-111111111111"
```

### GET /api/v1/domains/{id}/analytics/summary

Returns the same payload as `/api/v1/usage/summary?domain_id=...`. Add `?bucket=minute|hour|day` to include scoped time-series points for dashboard charts.

## Admin Auth

Local quickstart can bootstrap an admin from `CDNLITE_BOOTSTRAP_ADMIN_*` settings. The default dev template credentials are `admin` / `admin`.

Create or update a deliberate admin user with the CLI:

```bash
docker compose exec core php artisan cdn:admin:create --username=admin --password='replace-with-a-long-password'
```

### POST /api/v1/admin/login

```bash
curl -s -X POST http://localhost:8080/api/v1/admin/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin"}'
```

Success:

```json
{"data":{"token":"<session-token>","expires_at":1710000000,"user":{"id":"...","username":"admin","status":"active"}}}
```

Use the token as `Authorization: Bearer <session-token>` for control-plane API calls. The dashboard stores this token in browser memory only.

### GET /api/v1/admin/me
### POST /api/v1/admin/logout

Both require an admin session bearer token. Logout revokes the current session token.

```json
{"data":{"rows":[{"cache_status":"HIT","count":8200,"bytes_out":60108},{"cache_status":"MISS","count":1200,"bytes_out":12000},{"cache_status":"BYPASS","count":500,"bytes_out":2048},{"cache_status":"STALE","count":100,"bytes_out":512}],"total_requests":10000,"bytes_out":74668,"hit":8200,"miss":1200,"expired":0,"stale":100,"bypass":500,"unknown":0,"hit_ratio":0.82}}
```

## GET /health

Purpose: lightweight core health check. Auth is not required. There are no query parameters or request body fields.

```bash
curl -s http://localhost:8080/health
```

Success:

```json
{"ok":true,"time":1710000000}
```

## GET /cdn-health

Purpose: lightweight origin health check for edge-proxied requests. Auth is not required. It returns the same JSON shape as `/health`, but is not intercepted by the edge container's own `/health` endpoint.

## Domains

`POST /api/v1/domains` starts onboarding and requires only `zone_name`; `display_name` is optional. The new domain starts as `pending_nameserver` and returns the platform nameservers to configure. Origin settings can be added later with `PATCH /api/v1/domains/{id}`.

`POST /api/v1/domains/{id}/verify-nameservers` resolves the public NS set and updates `nameserver_status` to `verified`, `partial`, or `not_configured`.

`POST /api/v1/domains/{id}/activate` activates a verified domain. An admin may send `{"override":true}` for local development where public delegation is unavailable.

### POST /api/v1/domains

Required JSON fields: `name`, `domain`. Optional: `user_id`, `origin_shield_header_name`, `origin_shield_secret`. Origin and proxy options are configured on DNS records.
If `origin_shield_secret` is provided, core stores only its SHA-256 hash and never stores the plaintext in the database.

```bash
curl -s -X POST http://localhost:8080/api/v1/domains \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo","domain":"demo.local"}'
```

Success `201`:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","user_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","name":"Demo","domain":"demo.local","status":"pending_nameserver","created_at":1710000000,"updated_at":1710000000}}
```

Errors: `name_required`, `domain_required`, `domain_already_exists`; legacy payloads containing `origin_port` return `origin_port_not_supported`.

### GET /api/v1/domains

```bash
curl -s http://localhost:8080/api/v1/domains
```

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","domain":"demo.local","proxy_enabled":true}]}
```

### PATCH /api/v1/domains/{id}

Patchable fields: `name`, `domain`, `status`, `origin_shield_header_name`, `origin_shield_secret`.

DNS record create/update accepts `origin_host`, `proxied`, `origin_tls_verify` (`verify` or `ignore`), and `geo_origins`. Proxied origins always try HTTPS on port 443 first, then HTTP on port 80 after a connection or TLS handshake failure. Custom origin ports are rejected. `ignore` disables certificate verification only for the edge-to-origin TLS handshake.

```bash
curl -s -X PATCH http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111 \
  -H 'Content-Type: application/json' \
  -d '{"name":"Demo Updated","proxy_enabled":false}'
```

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"Demo Updated","proxy_enabled":false}}
```

Errors: `domain_not_found` 404, `domain_already_exists` 422.

### DELETE /api/v1/domains/{id}

```bash
curl -s -X DELETE http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111
```

```json
{"ok":true}
```

Unknown domain: `404 {"error":"domain_not_found"}`.

### POST /api/v1/domains/{id}/proxy/enable
### POST /api/v1/domains/{id}/proxy/disable

Both return the updated domain. Unknown domain returns `404 {"error":"domain_not_found"}`.

## DNS Records

DNS record fields: `id`, `domain_id`, `type`, `name`, `content`, `origin_type`, `origin_content`, `public_type`, `public_content`, `ttl`, `priority`, `proxied`, `geo_policy_id`, `edge_target`, `status`, `created_at`, `updated_at`.

### Domain DNS routing

`GET /api/v1/domains/{domainId}/routing` returns the domain routing settings. `PATCH` accepts `routing_mode` (`geo`, `anycast`, or `dns_only`), `geo_health_port`, `geo_selector`, `anycast_ipv4`, `anycast_ipv6`, and `anycast_cname`. Changing settings republishes all saved DNS records for the domain.

`POST /api/v1/domains/{domainId}/dns/records/{recordId}/preview-routing` returns the generated `type`, `content`, `routing_mode`, and readable `powerdns` line without changing the record.

For proxied records, geo mode publishes a PowerDNS `LUA` record using active enabled edge IPs and `ifportup`. If no eligible edge address exists yet, CDNLite preserves the original record and returns the `no_eligible_edge_ips` preview warning; edge registration later republishes it as LUA automatically. Anycast mode publishes A/AAAA targets or a CNAME for subdomains. DNS-only mode and records with `proxied=false` preserve the original type and content.

### POST /api/v1/domains/{id}/dns/records

Required: `type`, `name`, `content`. Optional: `ttl` default `300`, `priority`, `proxied` default `false`, `geo_policy_id`, `edge_target`.

```bash
curl -s -X POST http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}'
```

Success `201`:

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000000}}
```

Errors: `type_required`, `name_required`, `content_required`, `domain_not_found`, PowerDNS strict 502 errors.

### GET /api/v1/domains/{id}/dns/records

```bash
curl -s http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records
```

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","type":"A","name":"@","proxied":true}]}
```

Unknown domain IDs return an empty `data` array because the list query filters by `domain_id` only.

### PATCH /api/v1/domains/{id}/dns/records/{recordId}

Patchable fields: `type`, `name`, `content`, `ttl`, `priority`, `proxied`, `geo_policy_id`, `edge_target`, and `status`.

```bash
curl -s -X PATCH http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 \
  -H 'Content-Type: application/json' \
  -d '{"content":"127.0.0.2","ttl":120}'
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","ttl":120,"priority":null,"proxied":true,"status":"active","created_at":1710000000,"updated_at":1710000060}}
```

Empty patch bodies return `422 {"error":"dns_record_update_body_required"}`. Unknown domain or record IDs return `404 {"error":"record_not_found"}`.

### DELETE /api/v1/domains/{id}/dns/records/{recordId}

```bash
curl -s -X DELETE http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222
```

```json
{"ok":true}
```

Unknown domain or record: `404 {"error":"record_not_found"}`.

## Redirect Rules

Redirect rule fields: `id`, `domain_id`, `enabled`, `source_path`, `target_url`, `status_code`, `priority`, `match_type`, `preserve_query`, `created_at`, `updated_at`.

`status_code` only allows `301`, `302`, `307`, or `308`.

### POST /api/v1/domains/{id}/redirects

Required: `source_path`, `target_url`. Optional: `enabled` (default `true`), `status_code` (default `302`), `priority` (default `100`), `match_type` (`exact_path|prefix|wildcard_simple`, default `exact_path`), `preserve_query` (default `true`).
Validation: `source_path` must start with `/`; `status_code` must be one of `301|302|307|308`.

```bash
curl -s -X POST http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/redirects \
  -H 'Content-Type: application/json' \
  -d '{"enabled":true,"source_path":"/old-path","target_url":"https://example.com/new-path","status_code":308}'
```

### GET /api/v1/domains/{id}/redirects

```bash
curl -s http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/redirects
```

### PATCH /api/v1/domains/{id}/redirects/{redirectId}

Patchable fields: `enabled`, `source_path`, `target_url`, `status_code`, `priority`, `match_type`, `preserve_query`.

### DELETE /api/v1/domains/{id}/redirects/{redirectId}

Success: `{"ok":true}`. Unknown domain/rule: `404 {"error":"redirect_not_found"}`.

### POST /api/v1/domains/{id}/redirects/import

Body: `{"items":[...redirect objects...]}`.

### GET /api/v1/domains/{id}/redirects/export

Returns the domain redirects as `data`.

### POST /api/v1/domains/{id}/redirects/test

Body: `{"path":"/old-post","query":"utm_source=x"}`.

## Rate Limit Rules

### PUT /api/v1/domains/{id}/rate-limit

Creates or updates a domain rate-limit rule.
Validation: `requests_per_minute` must be an integer between `1` and `100000`.
Optional fields: `priority` (`1..100000`), `path_prefix` (must start with `/`), `key_type` (`ip` or `ip_path`), `action` (`block`).

### GET /api/v1/domains/{id}/rate-limit

Returns the current domain rate-limit rule.

### DELETE /api/v1/domains/{id}/rate-limit

Disables/removes the active domain rate-limit rule.

### Rate-limit collection CRUD

`POST /api/v1/domains/{id}/rate-limits` creates a rule and `GET` lists every rule ordered by priority. Use `PATCH /api/v1/domains/{id}/rate-limits/{ruleId}` to edit fields or toggle `enabled`; `DELETE` permanently removes that rule. The rule shape is `enabled`, `priority`, `path_prefix`, `key_type`, `requests_per_minute`, and `action`.

The singular `/rate-limit` endpoints remain available for CLI compatibility and address the first rule by priority.

## WAF Rules

### POST /api/v1/domains/{id}/waf-rules

Creates a WAF rule for the domain.
Validation: `type` must be one of `path_contains`, `path_prefix`, `user_agent_contains`, `ip_cidr`, `country_is`, `method_is`, `header_contains`; `action` (optional) must be one of `block`, `log`, `allow`; `priority` (optional) must be `1..100000`; `pattern` must be a non-empty string.
Patch validation: same constraints apply to provided fields.

### GET /api/v1/domains/{id}/waf-rules

Lists WAF rules for the domain.

### PATCH /api/v1/domains/{id}/waf-rules/{wafId}

## Domain Cache Settings

### GET /api/v1/domains/{id}/cache/settings

Returns domain-level cache defaults. If settings do not exist yet, defaults are created and returned.

### PUT /api/v1/domains/{id}/cache/settings

Creates/updates domain-level cache defaults and policy controls.

Allowed fields:
- `enabled` (boolean)
- `default_edge_ttl_seconds` (integer `1..31536000`)
- `default_browser_ttl_seconds` (integer `1..31536000` or `null`)
- `cache_query_string_mode` (`include_all`, `ignore_all`, `include_allowlist`)
- `respect_origin_cache_control` (boolean)
- `cache_authorized_requests` (boolean)
- `stale_if_error_seconds` (integer `0..31536000`)

## Cache Purge Requests

### POST /api/v1/domains/{id}/cache/purge

Creates a purge request and increments a soft purge namespace version.

Allowed `type`: `url`, `prefix`, `domain`, `everything`.
`value` is required for `url` and `prefix`.
Each request also writes an audit event (`cache_purge_requested`) into `audit_log` with `type`, `value`, `scope`, `scope_value`, `version_before`, and `version_after`.

### GET /api/v1/domains/{id}/cache/purge-requests

Lists purge requests for the domain, newest first.

### GET /api/v1/domains/{id}/cache/purge-requests/{requestId}

Returns one purge request. Unknown ID returns `404 {"error":"cache_purge_request_not_found"}`.

Updates one WAF rule.

### DELETE /api/v1/domains/{id}/waf-rules/{wafId}

Deletes one WAF rule.

## SSL

### POST /api/v1/domains/{id}/ssl/request-cert

Starts ACME DNS-01 issuance for the domain and returns `202` with the completed attempt state. Core persists `verifying`, `issued`, or `error` progress and a renewal-history row.

### POST /api/v1/domains/{id}/ssl/renew

Forces renewal of the domain's non-revoked ACME certificates. Returns `202`; use the ACME status endpoint to inspect the result and history.

### GET /api/v1/domains/{id}/ssl/acme-status

Returns per-hostname ACME progress and the latest 50 issuance or renewal attempts:

```json
{"data":{"progress":[{"hostname":"demo.local","status":"issued","error":null}],"history":[{"action":"forced_renewal","status":"issued"}]}}
```

### POST /api/v1/domains/{id}/ssl/acme/issue

Issues an ACME certificate for an active proxied domain using DNS-01 through PowerDNS. The body may include `hostnames`; when omitted, core uses the domain domain. Hostnames must be the domain domain or a subdomain of it.

Required runtime configuration: `CDNLITE_SSL_SECRET_KEY`, ACME account configuration, and an enabled PowerDNS group with API URL and API key in Platform Settings.

```bash
curl -s -X POST http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/ssl/acme/issue \
  -H 'Content-Type: application/json' \
  -d '{"hostnames":["demo.local"]}'
```

Success returns active certificate metadata; private key material is never returned:

```json
{"data":[{"hostname":"demo.local","provider":"acme","status":"active","last_error":null}]}
```

### POST /api/v1/domains/{id}/ssl/request

Requests SSL metadata provisioning for an active proxied domain. The body may include `hostnames`; when omitted, core uses the domain domain. If the domain is not active and proxied, the endpoint returns `422 {"error":"proxy_required"}`.

```bash
curl -s -X POST http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/ssl/request \
  -H 'Content-Type: application/json' \
  -d '{"hostnames":["demo.local"]}'
```

Example pending response:

```json
{"data":[{"hostname":"demo.local","provider":"cdnlite","status":"pending","last_error":null}]}
```

### POST /api/v1/domains/{id}/ssl/check

Refreshes or creates SSL metadata rows for `hostnames`. Missing certificates are marked with `status:"missing"` and `last_error:"certificate_not_provisioned"`.

### POST /api/v1/domains/{id}/ssl/manual-certificate

Imports PEM certificate material for a hostname. `CDNLITE_SSL_SECRET_KEY` must be configured so the private key can be encrypted at rest.

`PATCH /api/v1/domains/{id}/ssl/settings` also accepts `auto_renew`. The hourly scheduler only renews ACME certificates for domains where this setting is enabled.

## Security Events

### GET /api/v1/domains/{id}/security/events

Returns recent events from `audit_log` for the domain, newest first.

Optional query params:
- `type`: filter by event type (exact match).
- `limit`: max rows to return (`1..500`, default `100`).

Collector security-event items with an empty or unknown `domain_id` are skipped; ingest responses include `skipped_unknown_domains`.

## Cache Rules

### POST /api/v1/domains/{id}/cache-rules

Creates a cache rule for the domain.
Validation: `path_prefix` must start with `/` when provided; `ttl_seconds` must be an integer between `1` and `31536000`.
Patch validation: same constraints apply to provided fields.

### GET /api/v1/domains/{id}/cache-rules

Lists cache rules for the domain.

### PATCH /api/v1/domains/{id}/cache-rules/{cacheRuleId}

Updates one cache rule.

### DELETE /api/v1/domains/{id}/cache-rules/{cacheRuleId}

Deletes one cache rule.

## Edge Nodes

### GET /api/v1/edge/nodes

```json
{"data":[{"id":"33333333-3333-4333-8333-333333333333","edge_id":"edge-local-1","hostname":"edge-local-1","public_ip":"203.0.113.10","public_ipv4":"203.0.113.10","public_ipv6":"","region":"local","country":"","continent":"","version":"v1","status":"online","is_enabled":true,"health_status":"unknown","last_heartbeat":1710000000,"last_heartbeat_at":1710000000,"created_at":1710000000,"updated_at":1710000000}]}
```

### GET /api/v1/edges/pools

Returns configured geo and anycast pools with their enabled member edges and weights.

```json
{"data":[{"id":"pool-1","name":"Primary geo","mode":"geo","description":"Production edges","members":[{"id":"member-1","edge_id":"edge-local-1","hostname":"edge-local-1","status":"online","public_ipv4":"203.0.113.10","public_ipv6":"","enabled":true,"weight":100}]}]}
```

### GET /api/v1/edges/dns

Returns the generated per-edge, geo aggregate, and anycast platform DNS plan plus the most recent sync state and invalid-edge warnings.

```json
{"data":{"base_domain":"vaheed.net","zone_prefix":"edge","powerdns_enabled":true,"records":[{"name":"edge-edge-local-1","fqdn":"edge-edge-local-1.vaheed.net.","type":"A","ttl":60,"content":"203.0.113.10","mode":"edge"}],"warnings":[],"effective_hash":"sha256","synced_at":1710000000}}
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
{"version":1,"generated_at":1710000000,"hosts":{"demo.local":{"domain_id":"11111111-1111-4111-8111-111111111111","upstream":"http://core:8080","geo_upstreams":{},"headers":{"X-CDNLITE-Domain":"11111111-1111-4111-8111-111111111111"},"dns_records":[]}},"cache_rules":[{"id":"44444444-4444-4444-8444-444444444444","domain_id":"11111111-1111-4111-8111-111111111111","enabled":true,"path_prefix":"/api/v1/domains","ttl_seconds":60,"created_at":1710000000,"updated_at":1710000000,"host":"demo.local"}]}
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
3. Before proxying, OpenResty checks enabled `redirects` entries for exact host + path match. When matched, it returns `301|302|307|308` with `Location: <target_url>` and `X-CDNLITE-Rule: redirect`.
4. If no redirect matches, OpenResty proxies the request to the chosen upstream and forwards origin response bytes/status back to the client (with normal cache/error-page behavior applied at the edge).

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
{"idempotency_key":"batch-1","items":[{"ts":1710000000,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":10,"bytes_in":1000,"bytes_out":5000,"status":200}]}
```

Success:

```json
{"ingested":1,"skipped_unknown_domains":0,"duplicate":false,"idempotency_key":"batch-1"}
```

Duplicate key:

```json
{"ingested":0,"duplicate":true,"idempotency_key":"batch-1","item_count":1}
```

Items with an empty or unknown `domain_id` are skipped instead of failing the batch; `skipped_unknown_domains` reports how many were ignored.

Validation errors: `items_must_be_array`, `idempotency_key_must_be_non_empty_string`.

### GET /api/v1/usage/summary

Optional query: `domain_id`, `bucket=minute|hour|day`.

```json
{"data":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1,"points":[{"bucket_ts":1710000000,"requests_count":10,"bytes_in":1000,"bytes_out":5000}]}}

When `bucket=minute|hour|day` is present, `points` contains the ordered time series used by the dashboard bandwidth and request graphs. Each point combines all status, cache-status, and edge rows sharing the same `bucket_ts`.
```

Invalid bucket returns `422 {"error":"bucket_must_be_one_of_minute_hour_day"}`.

### POST /api/v1/usage/recalculate

Body is optional. Use `{"domain_id":"11111111-1111-4111-8111-111111111111"}` for one domain or `{}` for all domains.

```json
{"ok":true,"domain_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1}}
```

Invalid `domain_id` returns `422 {"error":"domain_id_must_be_non_empty_string"}`.
# Platform settings

Operational settings are authenticated admin endpoints. Database values override the documented
environment variables; unset database values continue to use environment defaults.

- `GET /api/v1/settings` lists all setting groups.
- `GET /api/v1/settings/{group}` returns values, field metadata, and redacted audit history.
- `PATCH /api/v1/settings/{group}` accepts `{ "values": { ... } }`.
- `POST /api/v1/settings/validate` validates `{ "group": "...", "values": { ... } }`.
- `POST /api/v1/settings/test/powerdns` tests the effective PowerDNS configuration.

Supported groups are `platform.powerdns`, `platform.nameservers`, `platform.edge_dns`,
`platform.cache`, `platform.analytics`, and `platform.security`. Secret values are never returned;
their response shape is `{ "configured": true, "updated_at": 1710000000 }`.
### Domain SSL settings

```http
GET   /api/v1/domains/{domainId}/ssl
PATCH /api/v1/domains/{domainId}/ssl/settings
```

The settings payload contains `force_https`, `auto_renew`, and `min_tls_version` (`1.2` or `1.3`). Force HTTPS defaults to `false`. Enabling it requires an active, unexpired certificate for the domain hostname; otherwise the API returns `422 {"error":"valid_ssl_certificate_required"}`. On enable, core creates a managed `308` HTTP-to-HTTPS redirect that preserves the request path and query. Disabling Force HTTPS removes that managed redirect without changing user-created redirect rules.
