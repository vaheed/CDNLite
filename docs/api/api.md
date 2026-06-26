---
title: API Reference
description: CDNLite control plane API reference for domains, DNS records, origins, cache rules, WAF rules, rate limits, SSL, edge nodes, analytics, and audit logs.
---

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
| `409` | Replay nonce, conflicting state, or an exact duplicate DNS record. |
| `422` | Validation failed. |
| `502` | Upstream integration failure, often DNS/PowerDNS/ACME/origin. |
| `503` | Readiness failure. |

DNS record creates and updates return `dns_record_duplicate` with status `409`
when the same domain already has an identical type, name, and content. Records
with the same name and type but different content remain valid and are
published as one multi-value RRset where the DNS type permits it. A CNAME or
proxied subdomain cannot share its name with another record type; those
conflicts return `dns_record_name_conflict` with status `409`.

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
| `GET` | `/api/v1/readiness` | Protected | Detailed dashboard readiness model. `core` covers platform infrastructure checks such as PostgreSQL, PowerDNS, and config snapshots; domain/application warnings such as unhealthy origins and expiring certificates are returned separately as domain action items. |

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
| `GET` | `/api/v1/reports/summary` | CDN operations KPIs, compare-mode deltas, and ranked warnings. Accepts `domain_id`, `from`, `to`, `bucket`, `compare`, and `limit`. |
| `GET` | `/api/v1/reports/traffic` | Request, bandwidth, cache-ratio, status, top domain/path/visitor-country/edge, and problem-request reports. |
| `GET` | `/api/v1/reports/cache` | Cache status distribution, hit-ratio trend, cache/origin bytes, uncached paths, and purge timeline. Unsupported ingest fields are returned as `null` with an `unavailable` note. |
| `GET` | `/api/v1/reports/edge` | Edge online/offline counts, geography, heartbeat age, config drift, config errors, traffic, error rates, and node table. |
| `GET` | `/api/v1/reports/security` | Security events over time, severity/type/action distributions, top attacking IP hashes, attacked domains, and recent events. |
| `GET` | `/api/v1/reports/reliability` | SSL, ACME jobs, DNS convergence, PowerDNS sync, nameserver verification, DNS errors, pending DNS changes, and origin health. |
| `GET` | `/api/v1/reports/operations` | Job queues, failed-job timeline, recent jobs, operations timeline, audit activity, actor/resource rankings, and config snapshots. |
| `GET` | `/api/v1/recommendations` | Active recommendations across domains. |
| `POST` | `/api/v1/recommendations/generate` | Generate recommendations across domains. |
| `GET` | `/api/v1/security/events` | Paginated global security events. |
| `GET` | `/api/v1/security/summary` | Security event aggregates. |
| `GET` | `/api/v1/audit` | Audit history. |

Common query parameters: `domain_id`, `limit`, `offset`, `type`, `action`, and time filters where supported.

Operational APIs are designed for dashboards and reports. Use them for human-facing status pages, but prefer domain-specific APIs when automating configuration changes.

### Recommendation Engine

Recommendations are generated from Activity, request diagnostics, cache status,
SSL state, origin errors, security events, and current configuration. Each item
includes `confidence`, `risk`, `impact`, `preview_payload`, `one_click_action`,
`status`, and a human-readable `why` explanation.

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/recommendations` | List active recommendations for a domain. |
| `POST` | `/api/v1/domains/{domainId}/recommendations/generate` | Generate recommendations for one domain. |
| `POST` | `/api/v1/domains/{domainId}/recommendations/{recommendationId}/apply` | Apply the safe one-click action. |
| `POST` | `/api/v1/domains/{domainId}/recommendations/{recommendationId}/dismiss` | Dismiss and suppress immediate regeneration. |
| `POST` | `/api/v1/domains/{domainId}/recommendations/{recommendationId}/snooze` | Hide temporarily; body accepts `{ "seconds": 86400 }`. |

The generator command is:

```bash
php artisan cdn:recommendations:generate
php artisan cdn:recommendations:generate --domain_id=<domain-id>
```

Current recommendation types cover login protection, API protection, 502 origin
diagnostics, low cache hit ratio, bot protection, common exploit protection,
and SSL review. One-click actions reuse the existing protection intent, cache
settings, and origin health-check services.

### Guided Onboarding

Guided onboarding stores first-setup answers per domain, recommends a protection
profile, previews the generated rules, and can apply the selected profile
through the same Protection profile engine used by Security Center.

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/onboarding` | Load onboarding state, recommendation, and setup progress. |
| `POST` | `/api/v1/domains/{domainId}/onboarding/answers` | Store answers and refresh the recommended profile. |
| `POST` | `/api/v1/domains/{domainId}/onboarding/preview` | Preview the recommended profile without mutating rules. Responses include the full generated rule payloads so operators can inspect exact WAF, rate-limit, cache, and ownership fields before applying. |
| `POST` | `/api/v1/domains/{domainId}/onboarding/apply` | Apply the recommended profile and mark onboarding complete. |
| `POST` | `/api/v1/domains/{domainId}/onboarding/skip` | Skip onboarding for this domain. The dashboard hides the guided panel after skip while preserving state for API-level resume. |
| `POST` | `/api/v1/domains/{domainId}/onboarding/resume` | Resume a skipped wizard through API or operator tooling. |

Answers include `site_type`, `has_login`, `has_api`, `sells_products`,
`countries`, `framework`, `under_attack`, and `enable_now`. Recommendation
priority is emergency, WordPress, e-commerce, API, SaaS app, then Basic Website.
The dashboard uses themed country selection chips instead of free-text country
entry. The progress payload covers domain added, nameservers, origin, SSL,
protection profile, and edge readiness.

## Domains

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains` | List domains. |
| `POST` | `/api/v1/domains` | Create a domain. |
| `GET` | `/api/v1/domains/{domainId}` | Show a domain. |
| `PATCH` | `/api/v1/domains/{domainId}` | Update editable domain fields. |
| `DELETE` | `/api/v1/domains/{domainId}` | Delete a domain. |
| `POST` | `/api/v1/domains/{domainId}/nameservers/verify` | Immediately verify registrar delegation and return trace details. |
| `POST` | `/api/v1/domains/{domainId}/verify-nameservers` | Backward-compatible alias for immediate nameserver verification. |
| `POST` | `/api/v1/domains/{domainId}/nameservers/force-verify` | Admin-session-only override that marks delegation verified with an audit reason. |
| `POST` | `/api/v1/domains/{domainId}/nameservers/reseed-expected` | Admin-session-only action that replaces expected nameservers from current platform settings without deleting the domain. |
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
- Use `nameservers/verify` to run an immediate DNS resolver check. Responses include `expected_nameservers`, `observed_nameservers`, `matched_nameservers`, `missing_nameservers`, `checked_at`, `status`, and `resolver_errors`.
- Use `nameservers/force-verify` only as an operator override. It requires a browser admin session token, rejects generic API tokens, requires `{ "reason": "..." }`, writes `domain.nameserver.force_verify` to audit history, invalidates edge config, and reconciles DNS.
- Use `nameservers/reseed-expected` after changing `platform.nameservers`; it requires an admin session, updates this domain's expected nameserver rows from current settings, preserves observed matches, writes `domain.nameserver.reseed_expected`, invalidates edge config, and reconciles DNS.
- Avoid changing the domain hostname after traffic is live; create a new domain entry and migrate instead.

## DNS And Routing

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/domains/{domainId}/dns/records` | List records. |
| `POST` | `/api/v1/domains/{domainId}/dns/records` | Create record. |
| `PATCH` | `/api/v1/domains/{domainId}/dns/records/{recordId}` | Update record. |
| `DELETE` | `/api/v1/domains/{domainId}/dns/records/{recordId}` | Delete record. |
| `POST` | `/api/v1/domains/{domainId}/dns/records/{recordId}/reconcile` | Retry PowerDNS reconciliation for one record. |
| `GET` | `/api/v1/domains/{domainId}/routing` | Show domain routing settings. |
| `PATCH` | `/api/v1/domains/{domainId}/routing` | Update routing mode and health options. |
| `POST` | `/api/v1/domains/{domainId}/dns/records/{recordId}/preview-routing` | Preview routing result. |
| `GET` | `/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes` | List raw GeoDNS answer routes. |
| `PUT` | `/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes` | Replace raw GeoDNS answer routes. |

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

Raw GeoDNS records are DNS-only A/AAAA records with a required default route and optional country or continent routes. Proxy and GeoDNS are mutually exclusive; attempts to save both return `proxy_and_geodns_are_mutually_exclusive`.

```json
{
  "type": "A",
  "name": "www",
  "content": "203.0.113.10",
  "ttl": 300,
  "proxied": false,
  "geo_routes": [
    { "route_scope": "default", "answer_type": "A", "answer_value": "203.0.113.10", "enabled": true },
    { "route_scope": "country", "country_code": "US", "answer_type": "A", "answer_value": "198.51.100.10", "enabled": true },
    { "route_scope": "continent", "continent_code": "EU", "answer_type": "A", "answer_value": "198.51.100.20", "enabled": true }
  ]
}
```

DNS tips:

- Domain DNS record lists include readonly platform `NS` rows derived from current nameserver settings. They are published for the customer zone even while user records wait for delegation verification.
- Keep MX, TXT verification, SPF, DKIM, and DMARC records DNS-only.
- Use proxied records only for HTTP/HTTPS traffic intended for the CDN edge.
- Raw GeoDNS records publish an internal PowerDNS `LUA` rrset. Users provide
  only typed DNS answers; country rules win over continent rules, and continent
  rules win over the default answer.
- Additional proxied records at the same DNS name are stored and returned as
  DNS records. CDNLite no longer silently converts them into hidden backup
  origins or returns an earlier record ID.
- A proxied apex (`@`) publishes direct managed apex records from the canonical
  edge pool: static anycast `A`/`AAAA` when configured, otherwise PowerDNS
  `LUA` `A`/`AAAA`. A proxied subdomain publishes `CNAME` to the stable site
  target.
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
| `POST` | `/api/v1/domains/{domainId}/origins/{originId}/test` | Run a non-mutating origin diagnostic with DNS, TCP, TLS, and HTTP timing details. |
| `POST` | `/api/v1/domains/{domainId}/route-debug` | Preview the selected origin, origin pool size, cache/rule counts, and SSL state for a host/path/country using the active config snapshot. |

Example:

```json
{
  "host": "origin.example.com",
  "scheme": "http",
  "port": 80,
  "host_header": "origin.example.com",
  "sni": "origin.example.com",
  "tls_verify": "ignore",
  "preserve_host": true,
  "health_check_enabled": false,
  "role": "origin",
  "enabled": true
}
```

Origin tips:

- Configure at least one enabled origin before sending traffic.
- Proxied DNS records create visible origins with `source: "dns_record"` and
  `dns_record_id`; manual origins use `source: "manual"`.
- Duplicate manual origin hosts are allowed as separate rows because routing
  identity is the origin id, not only host and scheme.
- When the scheme is omitted for a DNS-linked origin, CDNLite keeps the
  backend on plain HTTP/80 unless you explicitly set `scheme: "https"`.
- For shared hosting or cPanel origins, point the origin `host` at the server
  IP, keep `preserve_host: true`, and let `host_header` and `sni` default to
  the requested site hostname. TLS verification defaults to `ignore` until you
  deliberately enable strict verification.
- Edge routing uses the explicit `scheme`, `host`, and `port`. It sends the
  original requested Host when `preserve_host` is true, still forwards
  `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`, and
  `X-CDNLITE-Client-IP`, and never falls back to an origin IP for SNI.
- Add another enabled origin before aggressive cache or WAF changes, so the edge has more than one healthy option.
- Health checks are off by default. With `health_check_enabled: false`,
  `unknown` health does not block config snapshots or edge traffic. When
  enabled, checked unhealthy origins are avoided and surfaced as warnings.
- Use the manual health-check route when you intentionally monitor an origin.
- Use the non-mutating origin diagnostic route when debugging 502s. It reports
  DNS resolution, TCP connect, TLS handshake, HTTP status, timing, configured
  host header, and SNI without changing the stored origin health state.
- Use `route-debug` before or during incidents to confirm which configured
  origin CDNLite would select for `{ "host": "www.example.com", "path": "/",
  "country": "US" }`. The response is admin-safe and does not expose origin
  secrets or request headers.
- The edge emits `X-CDNLITE-Origin: origin`; capture this header in incident reports.
  Edge access logs and metrics also include `request_id`, `origin_id`,
  upstream status/time, and router errors for 5xx diagnosis.

## Traffic Rules

| Feature | Routes |
| --- | --- |
| Redirects | `/api/v1/domains/{domainId}/redirects`, import/export/test variants. |
| Rate-limit rules | CRUD `/api/v1/domains/{domainId}/rate-limits`, dry-run `POST /api/v1/domains/{domainId}/rate-limits/dry-run`, plus `POST /api/v1/domains/{domainId}/rate-limits/{ruleId}/detach-managed`. |
| WAF rules | CRUD `/api/v1/domains/{domainId}/waf-rules`, plus `POST /api/v1/domains/{domainId}/waf-rules/{ruleId}/detach-managed`. |
| Header rules | CRUD `/api/v1/domains/{domainId}/headers`. |
| IP rules | CRUD `/api/v1/domains/{domainId}/ip-rules`. |
| Cache rules | CRUD `/api/v1/domains/{domainId}/cache-rules`. |
| Page rules | CRUD `/api/v1/domains/{domainId}/page-rules` plus `/test`. |
| Protection profiles | `GET /api/v1/domains/{domainId}/protection/profiles`, `POST /api/v1/domains/{domainId}/protection/profiles/{profileKey}/preview`, `POST /api/v1/domains/{domainId}/protection/profiles/{profileKey}/apply`, and `POST /api/v1/domains/{domainId}/protection/profiles/{profileId}/disable`. |
| Managed WAF preset catalog | `GET /api/v1/domains/{domainId}/protection/waf-presets`. |
| Smart Rate Limiting template catalog | `GET /api/v1/domains/{domainId}/protection/rate-limit-templates`. |
| Protection intents | `GET /api/v1/domains/{domainId}/protection/intents`, `POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/preview`, `POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/enable`, `POST /api/v1/domains/{domainId}/protection/intents/{intentId}/disable`, and `POST /api/v1/domains/{domainId}/protection/intents/{intentId}/undo`. |
| API protection discovery | `GET /api/v1/domains/{domainId}/protection/api-paths` returns likely API prefixes from recent Activity plus recommended methods and the default `Authorization` header key. |

Rule responses follow the same `{ "data": ... }` pattern and use `422` for validation errors. Managed rules generated by simple protection flows expose `profile_id`, `intent_id`, `template_key`, `managed_by`, `user_modified`, `last_generated_at`, and `last_applied_at` so advanced users can inspect ownership. Detaching a managed WAF or rate-limit rule preserves the technical rule, clears managed ownership, writes audit history, and invalidates edge config.

WAF rules support `country_is` for country allow, log, challenge, or block
decisions. The edge prefers `X-CDNLITE-Country` or `CF-IPCountry` from a
trusted upstream proxy when present, otherwise it resolves the client
`remote_addr` through the mounted GeoIP MMDB configured by
`CDNLITE_EDGE_MMDB_FILE`.

Rate-limit rules support `key_type` values `ip`, `ip_path`, `header`, and `header_path`; use `header/header_path` for token-aware API limits. Header-based keys require `key_header_name` such as `Authorization`; when the configured header is missing, the edge falls back to the client IP rather than grouping all unauthenticated requests into one bucket.

Dry-run mode lets operators preview the exact rate-limit payload and a 24-hour traffic estimate before they save a new rule. Use it for login, API, form spam, and expensive-path tuning when you want to see blast radius first.

Protection intents are the beginner/simple API layer. Preview does not mutate stored rules and returns the exact WAF, rate-limit, or cache rules that enable would create. Current built-in intents are `common_exploits`, `login_shield`, `protect_api`, `smart_rate_limiting`, `bot_shield`, `wordpress_hardening`, `checkout_protection`, `emergency_protection`, and `static_asset_performance`. Enable creates real advanced rules, writes audit and profile history, stores a rollback point, and invalidates edge config. Disable turns generated rules off instead of deleting them, then writes audit/history and invalidates config. Undo restores the latest rollback point. User-modified managed rules return `409 user_modified_rule_conflict` unless the caller sends an explicit overwrite confirmation.

API Shield (`protect_api`) generates real advanced rules for `/api/`: IP/path rate limits, `Authorization` header/path limits for token-aware APIs, and `path_method_not_allowed` WAF rules that block unsupported methods only inside the API prefix. The generated method rule uses a pattern such as `/api/:GET,POST,PUT,PATCH,DELETE,OPTIONS`, so advanced users can edit the prefix or method set without losing ownership metadata.

Cache settings additionally support `static_asset_cache_enabled`, `ignore_query_strings_for_static`, and `bypass_logged_in_users`. Static caching covers CSS, JavaScript, common image formats, fonts, MP4, and PDF. When enabled, query-string stripping applies only to those static extensions; common session/authentication cookies always bypass cache by default.

Protection profiles are one-click bundles over the same intent engine. One-click profiles compose protection intents for Basic Website, WordPress, API, SaaS App, E-commerce, and Emergency Protection presets. Preview returns the per-intent generated rules without mutating state. Apply creates or updates a profile record, enables the profile-owned intents, writes profile/audit history, stores rollback points, and invalidates edge config. Disable turns off the generated rules for intents owned by that profile while preserving the underlying advanced rules for inspection and rollback.

Managed WAF presets add inspection metadata to generated WAF rules. WAF rules created by Security Center intents can include `waf_group_id`, `waf_severity`, `waf_confidence`, and `waf_safe_reason`; edge `waf_match` security events emit matching `group_id`, `severity`, `confidence`, and `safe_reason` fields so Activity can explain why a managed rule acted. These fields are additive metadata on the advanced WAF rule, so operators can still edit, detach, or override the generated rule.

Bot Shield rules additionally carry `bot_class`, `bot_score`, and `bot_action`. A matched bot policy emits a `bot_match` security event with those fields and the request ID. A claimed search crawler is not trusted from its User-Agent alone: the built-in policy challenges it unless the edge config includes an enabled `verified_bot_sources` entry whose CIDR and User-Agent pattern both match the request. Advanced WAF rules may use `challenge`, which returns a 403 JSON `bot_challenge_required` response and records the event.

The Managed WAF preset catalog is read-only and returns available WAF modes, group definitions, and currently generated rule templates grouped by `waf_group_id`. Use it to inspect SQL injection, XSS, traversal, inclusion, command injection, PHP/WordPress, scanner, encoding, and bad user-agent coverage before enabling or tightening rules through the preview/apply protection flows.

The Smart Rate Limiting template catalog is read-only and returns safe defaults for login protection, API protection, form spam, expensive pages, and emergency traffic limiting. Each template includes the generated `rate_limit_rules` shape, recommended mode, default paths, and `preview_impact.would_have_matched_24h` from recent Activity so operators can judge blast radius before enabling a rule through the existing preview/apply flows.

The dashboard exposes these APIs through each domain's Security Center tab. That tab is the simple-mode entry point for outcome-based protection, while the WAF, Rate Limits, Cache, Headers, and IP Access tabs remain the advanced inspection and override surfaces for generated rules.

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
| `POST` | `/api/v1/domains/{domainId}/ssl/request` | Queue managed SSL flow and return `{ job_id, status, message }`. Defaults to apex plus wildcard hostnames. |
| `GET` | `/api/v1/domains/{domainId}/ssl/jobs/{jobId}` | Show SSL job progress. |
| `POST` | `/api/v1/domains/{domainId}/ssl/acme/issue` | Issue ACME certificate. |
| `POST` | `/api/v1/domains/{domainId}/ssl/request-cert` | Synchronously request automated certificate issuance. |
| `POST` | `/api/v1/domains/{domainId}/ssl/renew` | Force renewal. |
| `GET` | `/api/v1/domains/{domainId}/ssl/acme-status` | Show ACME status. |
| `POST` | `/api/v1/domains/{domainId}/ssl/check` | Check certificates. |
| `POST` | `/api/v1/domains/{domainId}/ssl/manual-certificate` | Import manual certificate. |

SSL tips:

- Use the ACME staging directory until DNS-01 automation is proven.
- When nameserver verification marks a domain active, CDNLite automatically queues a non-blocking managed ACME DNS-01 job for `domain.com` and `*.domain.com`, enables auto-renew, and exposes progress through the SSL status endpoints and dashboard tab. ACME DNS-01 challenge records are written directly through PowerDNS as ephemeral TXT records, so SSL queueing does not create customer traffic records.
- When an active, verified domain receives or updates a proxied DNS record, CDNLite also ensures the default managed apex and wildcard SSL job exists.
- The dashboard uses `/ssl/request` and polls `/ssl/jobs/{jobId}` so operators can see queued, DNS-checking, issuing, installing, issued, or failed states without refreshing.
- The `cdn:ssl:request` CLI follows the queued `/ssl/request` behavior and returns the scheduler job metadata.
- Queued jobs include `scheduler_stale`, `stale_seconds`, and `scheduler_hint` when `ssl-scheduler` has not claimed them after the configured scheduler interval.
- ACME DNS-01 TXT records are short-lived PowerDNS records, not durable dashboard DNS rows. The issuer verifies the TXT through the PowerDNS API, then asks ACME to validate. Set `CDNLITE_ACME_PUBLIC_DNS_PRECHECK=true` to require a recursive public DNS precheck before ACME validation.
- DNS-01 issuance requests require an active, nameserver-verified domain and PowerDNS challenge publishing; automatic initial bootstrap is queued asynchronously after verification and does not require any customer DNS record to be proxied.
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
Register and heartbeat calls update local edge state only; run
`cdn:dns:reconcile` or use DNS force sync when you need immediate PowerDNS
publication.
| `GET` | `/api/v1/edge/config` | Edge signed | Fetch config snapshot. |
| `POST` | `/api/v1/collector/usage` | Edge signed | Ingest usage rows. Edge metrics include `client_ip` and `client_country` when the edge resolves a visitor country from `X-CDNLITE-Country`, `CF-IPCountry`, or the configured MMDB. |
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

Edge proxy responses include an origin marker such as `X-CDNLITE-Origin: origin` for routed requests.

Edge endpoint notes:

- Register and heartbeat requests must have the same `edge_id` in the header and JSON body.
- `GET /api/v1/edge/config` accepts `if_version` as a query parameter to avoid unnecessary config writes.
- Usage and security-event ingest are queue-friendly. If ingest fails, the agent should keep local payloads for retry.

## Config Snapshots

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/config/snapshots` | List versions. |
| `GET` | `/api/v1/config/snapshots/latest` | Return the latest snapshot summary without the snapshot payload. |
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
- `platform.edge_dns.anycast_ipv4` and `platform.edge_dns.anycast_ipv6` are optional static proxy anycast IP lists. Values may be arrays or comma/space/newline-separated strings. When set, the shared proxy host and managed records with proxy enabled at the zone apex publish plain A/AAAA records containing all configured addresses for those families and bypass DNSGeo Lua routing. DNS-only records and proxied subdomain CNAME records are not rewritten to anycast addresses.
- Record the actor when settings are changed through automation so audit trails remain useful.

## Analytics

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/usage/summary` | Global or domain usage summary with bounded range, point-count, freshness, watermark, partial-data, query-id, and cache-status metadata when `bucket` is supplied. |
| `POST` | `/api/v1/usage/recalculate` | Queue an asynchronous aggregate refresh and return `202 Accepted` with a `job_id`. Accepts optional `domain_id` and `bucket`; bucket-scoped requests refresh the bounded range used by the dashboard chart. |
| `GET` | `/api/v1/usage/recalculate/{jobId}` | Read asynchronous aggregate refresh job status, progress, and failure details. |
| `GET` | `/api/v1/domains/{domainId}/analytics/summary` | Domain usage summary with the same bounded analytics metadata. |
| `GET` | `/api/v1/domains/{domainId}/analytics/cache` | Domain cache analytics. |
| `GET` | `/api/v1/domains/{domainId}/activity` | Paginated mixed domain activity timeline with request, error, audit, and security events. Supports `limit`, `offset`, `type`, `search`, `from`, and `to`; each item includes a `friendly` label/category/intent for Simple Activity while preserving raw `details`. |
| `GET` | `/api/v1/domains/{domainId}/activity/summary` | Activity KPIs, status breakdown, top paths, visitor countries, origins, edges, recent origin errors, and a beginner summary with grouped WAF, rate-limit, bot, origin, SSL, DNS, cache, and audit counts. |
| `GET` | `/api/v1/domains/{domainId}/activity/requests` | Paginated edge request and origin diagnostics, including `client_ip` and `client_country` when resolved or `DEFAULT` when unresolved. Supports `limit`, `offset`, `type=request/error`, `search`, `from`, and `to`; search includes request ID, host, path, visitor IP, visitor country, origin, and router error text. |
| `GET` | `/api/v1/domains/{domainId}/activity/requests/{requestId}` | Find one edge request by request ID from a 5xx page or edge log. |
| `GET` | `/api/v1/domains/{domainId}/activity/export` | Export the current mixed activity filter as JSON. |
| `GET` | `/api/v1/domains/{domainId}/security/events` | Domain security event list. |

Analytics tips:

- Use `bucket=minute` for fresh debugging, `hour` for incident windows, and `day` for trends.
- Recalculate aggregates after bulk ingest, test fixture loading, or suspected aggregation drift.
- Domain-filtered analytics are safer for customer-facing reports than global analytics.
- Activity endpoints store edge query data only after the edge-side redaction step. Use request-id lookup to correlate the public 5xx page, Docker-visible edge logs, and dashboard Activity details without exposing raw secret query values.
- Simple Activity is derived from the same raw request, audit, and security rows as Advanced Activity. API clients can use `friendly.category`, `friendly.intent`, and `summary.beginner.recommendations` for beginner dashboards without losing request IDs or exportable raw details.
- Prune raw detailed request rows with `php artisan cdn:usage:prune --dry-run` followed by `php artisan cdn:usage:prune --days=30` or the value configured in `CDNLITE_ANALYTICS_RETENTION_DAYS`.
- Review full retention counts with `php artisan cdn:usage:prune --all --dry-run` before enabling `CDNLITE_RETENTION_PRUNE_ENABLED=true`. The full pass uses bounded batches for raw requests, high-volume security events, successful DNS sync events, terminal SSL jobs, expired edge nonces, and old ingest idempotency keys.
- Security-event ingest stores `client_ip` as a SHA-256 hash by default. Set `CDNLITE_STORE_FULL_CLIENT_IP=true` only when your deployment has an explicit policy for full IP retention.
- `rate_limited` security-event details include `rate_limit_id`, `limit_key_type`, `threshold`, `current_count`, `window_seconds`, `action` as `decision`, and `retry_after` so Activity can explain which Smart Rate Limiting rule fired and how far over the limit the request was.
## Operations Logs

`GET /api/v1/events`, `GET /api/v1/jobs`, `GET /api/v1/security/events`,
and `GET /api/v1/audit` return:

```json
{
  "items": [],
  "total": 0,
  "limit": 50,
  "offset": 0
}
```

All four endpoints accept `domain_id`, `from`, `to`, `limit`, and `offset`.
`/api/v1/events` is the central Event Viewer feed and combines audit entries,
security decisions, DNS sync attempts, and SSL job lifecycle rows.
`/api/v1/jobs` is the central Job Queue feed for durable system jobs and
currently lists SSL certificate jobs across every domain; it also accepts
`status`, `active`, and `search`.
Security events additionally support `edge_id`, `type`, `ip`, and `search`.
Audit entries support `actor`, `action`, `resource_type`, and `search`.
The `search` filter matches serialized details plus action, resource type, and
event type. Use `domain_id` for the per-domain Activity viewer.
Nameserver verification updates lifecycle automatically. A verified domain
becomes active; a domain with missing, partial, or changed delegation becomes
`pending_nameserver`. DNS records may be created at any time. Record responses
include `effective_status` and `disabled_reason` so clients can distinguish a
desired-active record waiting for delegation from an explicitly disabled record.
Creating a second proxied target at the same name stores and returns the new DNS
record. When proxying is enabled, CDNLite also creates or updates a visible
linked origin row with `source: "dns_record"` and `dns_record_id`.
