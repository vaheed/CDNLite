# CDNLite Simple-First Improvement Roadmap

Generated: 2026-06-01

Repository reviewed: `vaheed/CDNLite`

Mindset: **simple first, useful first, no unnecessary complexity**.

---

## Progress audit (2026-06-01)

Status legend: `done`, `in progress`, `pending`.

| Stage | Status | Notes |
|---|---|---|
| Stage 1 — Documentation truth and baseline tests | done | README limitations, API reference coverage, production-readiness page, website-cdn-features page, and baseline contract tests are present. |
| Stage 2 — Simple control-plane API auth | done | `App\Support\ApiAuth`, `requireApiAuth()`, route protection, production readiness guard, and auth route contract tests are present. |
| Stage 3 — Validation without framework complexity | done (v1 scope) | `App\Support\Validator` exists and route contracts cover invalid site/DNS/traffic-rule payloads. |
| Stage 4 — Small router and response cleanup | done | Tiny `Request`/`Response`/`Router` support classes and route-table dispatch are in place, with route-focused contract tests added (`core/tests/test_router_contract.py`). |
| Stage 5 — Database migrations and indexes | done (v1 scope) | Migration directory SQL set and `cdn:migrate` runner are now present with schema tracking and core index migrations. |
| Stage 6 — Edge agent hardening | done (v1 scope) | `doctor.sh`, sync `last_error` status, heartbeat `config_version`, and retry backoff with jitter are now implemented and documented. |
| Stage 7 — OpenResty edge reliability | done (v1 scope) | Config schema versioning guard, request-id propagation to responses/metrics/error page, and reliability contract tests are now in place. |
| Stage 8 | done (v1 scope) | Site cache settings, cache purge requests/versions, redirects v2, page rules v1, and SSL metadata APIs are implemented. |
| Stage 10 | in progress | Manual SSL import + edge TLS runtime path implemented; hardening and deeper lifecycle automation remain. |

Next development target: **Stage 10 hardening completion (TLS lifecycle safety and e2e depth)**.

---

## 0. Product direction

CDNLite should become a **small website CDN control plane + edge runtime**.

The right target is:

- Add a website.
- Point DNS to CDNLite edge.
- Proxy traffic to origin.
- Cache static/content paths.
- Purge cache safely.
- Add redirects/page rules.
- Add basic WAF/rate limits.
- Track SSL certificate metadata and renewal status.
- Keep edge nodes alive with simple signed agent calls.
- Keep everything understandable by one developer.

The wrong target, for now:

- No Kubernetes requirement.
- No Laravel/Symfony rewrite.
- No React dashboard first.
- No billing first.
- No complex RBAC first.
- No full Cloudflare Ruleset Engine clone.
- No global Anycast promise.

---

## 1. Current CDNLite snapshot

### Current architecture

CDNLite currently has:

- PHP control plane in `core/`.
- PostgreSQL schema in `core/database/schema.sql`.
- OpenResty/Lua edge proxy in `edge/openresty/`.
- Shell edge agent in `edge/agent/`.
- Docker Compose local stack.
- CI smoke/e2e scripts.
- Site lifecycle API.
- DNS records and optional PowerDNS sync.
- Edge registration, heartbeat, config pull, and usage ingest.
- Config snapshots.
- Usage rollups and aggregates.

### Current website-CDN primitives already present

CDNLite already has early versions of these tables/features:

- `redirect_rules`
- `rate_limit_rules`
- `waf_rules`
- `cache_rules`
- `config_snapshots`
- OpenResty `proxy_cache_path`
- `X-CDNLITE-Cache` response header
- cache bypass for non-GET/HEAD, `Authorization`, and `Cache-Control: no-cache/no-store`
- stale cache serving for origin failure cases

So the roadmap below should **harden and complete** these features instead of rebuilding them.

### Biggest current risks

| Risk | Why it matters | First fix |
|---|---|---|
| Control-plane API auth is missing for many endpoints | Anyone who can reach the API can mutate sites/DNS/rules/usage | Add simple bearer API token auth |
| Validation is too light | Bad config can reach DB and edge | Add small validator helper |
| Cache docs and behavior need alignment | Confusion for operators | Update README/API/edge docs together |
| Rule models are too small | Useful start, but not enough for real website operators | Add priority, action, matching, enable/disable, audit |
| No purge API | Website CDN is incomplete without purge | Add soft purge versioning first |
| No TLS/cert metadata | Operators cannot track certificate readiness/expiry | Add certificate metadata table first |
| No dashboard | Usability is API/CLI-only | Add tiny server-rendered UI later |

---

## 2. Website only

Useful ideas, simply:

1. **Cache Rules**: cache eligibility, edge TTL, browser TTL, bypass behavior.
2. **Page Rules**: user-friendly URL pattern + actions.
3. **Redirect Rules**: single/bulk redirects, priority, preview/test.
4. **WAF Custom Rules**: simple conditions and block/log actions.
5. **Certificate lifecycle visibility**: status, expiry, renewal window, errors.
6. **Cache purge requests**: track purge request status, not just fire-and-forget.

Useful ideas to avoid for now:

- Full expression language.
- Managed WAF rulesets.
- Bot scoring.
- Workers-like edge compute.
- Terraform provider.
- Multi-account/team permission system.
- Advanced certificate manager.

---

# Roadmap overview

## Stage 1 — Documentation truth and baseline tests

Priority: **highest**

Goal: make the project state honest and testable.

### Tasks

- [ ] Update README limitations:
  - current cache storage statement is outdated if OpenResty cache is enabled.
  - say: “basic OpenResty cache exists; purge API and advanced cache policy are missing.”
- [ ] Update API docs to show all current endpoints:
  - sites
  - DNS records
  - redirects
  - rate limit
  - WAF rules
  - cache rules
  - edge nodes
  - usage
- [ ] Add a `docs/production-readiness.md` page:
  - safe for local/dev
  - not safe for internet exposure until API auth exists
  - required secrets
  - PowerDNS risk
  - TLS status
  - cache/purge status
- [ ] Add a `docs/website-cdn-features.md` page showing what works and what is planned.
- [ ] Add tests that document current behavior before changing it.

### Definition of done

- README does not contradict edge runtime behavior.
- API docs match actual routes.
- Tests pass before and after later security changes.

### Suggested PR

`docs: align current CDN feature status and production limitations`

---

## Stage 2 — Simple control-plane API auth

Priority: **highest**

Goal: protect site, DNS, rule, edge-list, and usage admin endpoints.

### Simple design

Use one environment variable first:

```env
CDNLITE_API_TOKEN=change-me-long-random-token
```

Require:

```http
Authorization: Bearer <token>
```

Public endpoints remain public:

- `GET /health`
- `GET /ready`

Edge HMAC endpoints keep their current edge auth:

- `POST /api/v1/edge/register`
- `POST /api/v1/edge/heartbeat`
- `GET /api/v1/edge/config`
- `POST /api/v1/collector/usage`

### Tasks

- [ ] Add `App\Support\ApiAuth`.
- [ ] Add `requireApiAuth()` helper in `public_index.php`.
- [ ] Protect all non-edge admin API routes.
- [ ] Add production boot guard:
  - if `APP_ENV=production` and `CDNLITE_API_TOKEN` is missing, fail readiness.
- [ ] Add tests:
  - unauthenticated site create returns 401.
  - authenticated site create works.
  - unauthenticated DNS mutate returns 401.
  - edge HMAC endpoints still work.

### Later, not now

- DB-backed API tokens.
- Token scopes.
- Users and teams.

### Definition of done

No mutation endpoint works without API auth, except signed edge endpoints.

### Suggested PR

`feat: require simple bearer auth for control-plane API`

---

## Stage 3 — Validation without framework complexity

Priority: **highest**

Goal: keep bad data out of PostgreSQL and edge config.

### Simple design

Create:

```text
core/app/Support/Validator.php
```

Methods:

```php
requiredString($body, $key, $max = 255)
optionalString($body, $key, $max = 255)
bool($body, $key, $default = false)
intRange($body, $key, $min, $max, $default = null)
enum($body, $key, $allowed, $default = null)
domain($body, $key)
hostname($body, $key)
url($body, $key)
pathPrefix($body, $key)
```

Return simple errors:

```json
{"error":"invalid_field","field":"origin_port","detail":"must be between 1 and 65535"}
```

### Validate sites

- [ ] `name`: required, max 120.
- [ ] `domain`: required valid hostname/domain.
- [ ] `origin_scheme`: `http` or `https`.
- [ ] `origin_host`: required hostname/IP/container name.
- [ ] `origin_port`: 1-65535.
- [ ] `proxy_enabled`: bool.
- [ ] `status`: `active`, `paused`, `disabled`.
- [ ] `geo_origins`: object with country/default keys and safe origin values.

### Validate DNS records

- [ ] `type`: `A`, `AAAA`, `CNAME`, `TXT`, `MX`, `CAA` initially.
- [ ] `name`: relative host or `@`.
- [ ] `content`: type-specific validation.
- [ ] `ttl`: 60-86400.
- [ ] `priority`: required for MX, optional otherwise.
- [ ] `proxied`: bool.

### Validate current traffic rules

Redirects:

- [ ] `source_path` starts with `/`.
- [ ] `target_url` is absolute `http/https` or site-relative path.
- [ ] status code: 301/302/307/308.

WAF:

- [ ] type in supported list.
- [ ] pattern max length.
- [ ] no empty pattern.

Cache:

- [ ] path prefix starts with `/`.
- [ ] TTL range, for example 1-31536000.

Rate limit:

- [ ] requests per minute range, for example 1-100000.

### Definition of done

Every create/update route validates before writing.

### Suggested PRs

- `feat: add small validation helper`
- `feat: validate site and dns payloads`
- `feat: validate traffic rule payloads`

---

## Stage 4 — Small router and response cleanup

Priority: high

Goal: keep `public_index.php` readable without adopting a big framework.

### Simple design

Add:

```text
App\Support\Request
App\Support\Response
App\Support\Router
```

Route style:

```php
$router->get('/health', $healthHandler, auth: false);
$router->post('/api/v1/sites', [$siteController, 'store'], auth: true);
$router->post('/api/v1/edge/register', [$edgeController, 'register'], edgeAuth: true);
```

### Tasks

- [ ] Keep old behavior.
- [ ] Move route matching to a route table.
- [ ] Normalize error responses.
- [ ] Preserve current JSON shape.
- [ ] Add route tests.

### Definition of done

Adding a new endpoint means adding one route line, not another large `if` block.

### Suggested PR

`refactor: introduce tiny router and consistent json responses`

---

## Stage 5 — Database migrations and indexes

Priority: high

Goal: allow safe upgrades.

### Simple design

Keep `schema.sql` as the full baseline, then add:

```text
core/database/migrations/
  001_api_auth_and_audit.sql
  002_rule_indexes.sql
  003_ssl_metadata.sql
  004_page_rules.sql
  005_cache_purge.sql
```

Add command:

```bash
php core/artisan cdn:migrate
```

### Add table: schema migrations

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
  version TEXT PRIMARY KEY,
  applied_at BIGINT NOT NULL
);
```

### Add important indexes

```sql
CREATE INDEX IF NOT EXISTS idx_sites_domain ON sites(domain);
CREATE INDEX IF NOT EXISTS idx_dns_records_site_name_type ON dns_records(site_id, name, type);
CREATE INDEX IF NOT EXISTS idx_redirect_rules_site_enabled ON redirect_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_waf_rules_site_enabled ON waf_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_cache_rules_site_enabled ON cache_rules(site_id, enabled);
CREATE INDEX IF NOT EXISTS idx_edge_request_nonces_expires ON edge_request_nonces(expires_at);
CREATE INDEX IF NOT EXISTS idx_usage_aggregates_lookup ON usage_aggregates(site_id, bucket, bucket_ts);
```

### Add audit log

```sql
CREATE TABLE IF NOT EXISTS audit_log (
  id TEXT PRIMARY KEY,
  actor_type TEXT NOT NULL,
  action TEXT NOT NULL,
  resource_type TEXT NOT NULL,
  resource_id TEXT NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  created_at BIGINT NOT NULL
);
```

### Definition of done

A fresh DB and an existing DB can both reach the latest schema.

### Suggested PR

`feat: add migration runner and first production indexes`

---

## Stage 6 — Edge agent hardening

Priority: medium-high

Goal: make edge sync boring and observable.

### Tasks

- [ ] Add `/agent/doctor.sh`.
- [ ] Check required env vars.
- [ ] Check core reachability.
- [ ] Check config JSON validity.
- [ ] Check metrics file writability.
- [ ] Add retry backoff with jitter.
- [ ] Add `last_error` to edge sync status JSON.
- [ ] Add current config version to heartbeat.
- [ ] Add shellcheck in CI.

### Suggested `doctor.sh` output

```json
{
  "ok": true,
  "checks": {
    "env": "ok",
    "core": "ok",
    "config": "ok",
    "metrics": "ok",
    "signing": "ok"
  }
}
```

### Definition of done

An operator can run one command and know why an edge is not syncing.

### Suggested PR

`feat: add edge agent doctor and sync status errors`

---

## Stage 7 — OpenResty edge reliability

Priority: medium-high

Goal: make the data plane predictable.

### Tasks

- [ ] Add config schema version:

```json
{
  "schema_version": 1,
  "version": 123,
  "hosts": {}
}
```

- [ ] Add safe config limits:
  - max hosts
  - max rules per site
  - max redirect length
  - max WAF pattern length
  - max cache TTL
- [ ] Add request ID:
  - response header
  - metrics row
  - access log
  - error page
- [ ] Add Lua tests for:
  - host normalization
  - geo upstream selection
  - redirect matching
  - cache longest-prefix matching
  - unknown host handling
  - bad config fallback

### Definition of done

Bad config should not crash the edge; it should fail closed or keep the last good config.

### Suggested PR

`edge: add schema version, request id, and lua rule tests`

---

# Better CDN stages

The next three stages are the Cloudflare-inspired website-CDN improvements you asked for. They are intentionally simple and useful.

---

## Stage 8 — Website CDN Essentials

Priority: high after auth/validation

Goal: make CDNLite feel like a useful CDN for one website.

This stage covers:

- cache settings
- cache purge requests
- redirects
- page rules v1
- SSL certificate metadata visibility

---

### 8.1 Site-level cache settings

Current `cache_rules` support path prefix and TTL. Add site defaults and common controls.

#### Add table

```sql
CREATE TABLE IF NOT EXISTS site_cache_settings (
  site_id TEXT PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  default_edge_ttl_seconds INTEGER NOT NULL DEFAULT 3600,
  default_browser_ttl_seconds INTEGER NULL,
  cache_query_string_mode TEXT NOT NULL DEFAULT 'include_all',
  respect_origin_cache_control BOOLEAN NOT NULL DEFAULT true,
  cache_authorized_requests BOOLEAN NOT NULL DEFAULT false,
  stale_if_error_seconds INTEGER NOT NULL DEFAULT 86400,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

#### Modes

`cache_query_string_mode`:

- `include_all`
- `ignore_all`
- `include_allowlist`

Keep allowlist later.

#### API

```http
GET /api/v1/sites/{site_id}/cache/settings
PUT /api/v1/sites/{site_id}/cache/settings
```

#### Edge behavior

- If cache disabled, bypass and no-store.
- If no path rule matches, use site default TTL.
- Keep bypass for `Authorization` unless explicitly allowed.
- Keep bypass for `Cache-Control: no-cache/no-store`.

#### Definition of done

A user can enable/disable cache and set default TTL without creating path rules.

---

### 8.2 Cache purge requests, simple soft-purge design

Do **not** delete Nginx cache files directly first. That is fragile because Nginx stores cache files by hashed keys.

Use **soft purge by cache namespace version**.

#### Idea

Each site has a cache namespace version. The edge includes that version in the cache key.

Current-ish key:

```nginx
$scheme|$host|$request_uri|$http_accept_encoding|$http_x_cdnlite_country|$http_cf_ipcountry
```

New key:

```nginx
$cdnlite_cache_namespace|$scheme|$host|$request_uri|$http_accept_encoding|$http_x_cdnlite_country|$http_cf_ipcountry
```

A purge increments the namespace for a site, URL, or prefix. Old cache files remain but become unused and expire naturally.

#### Add tables

```sql
CREATE TABLE IF NOT EXISTS cache_purge_requests (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  type TEXT NOT NULL,
  value TEXT NULL,
  status TEXT NOT NULL,
  requested_by TEXT NULL,
  edge_seen_count INTEGER NOT NULL DEFAULT 0,
  error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  completed_at BIGINT NULL
);

CREATE TABLE IF NOT EXISTS cache_purge_versions (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  scope TEXT NOT NULL,
  value TEXT NOT NULL,
  version BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(site_id, scope, value)
);
```

#### Purge types

- `url`
- `prefix`
- `site`
- `everything`

For v1, `site` and `everything` can behave the same for one site.

#### API

```http
POST /api/v1/sites/{site_id}/cache/purge
GET /api/v1/sites/{site_id}/cache/purge-requests
GET /api/v1/sites/{site_id}/cache/purge-requests/{id}
```

Example body:

```json
{
  "type": "prefix",
  "value": "/blog/"
}
```

#### Config snapshot addition

```json
{
  "cache_purge_versions": [
    {"host":"example.com","scope":"site","value":"*","version":7},
    {"host":"example.com","scope":"prefix","value":"/blog/","version":3}
  ]
}
```

#### Edge matching order

1. Exact URL purge version.
2. Longest prefix purge version.
3. Site purge version.
4. Default `1`.

#### Definition of done

- Purge by site works.
- Purge by prefix works.
- Purge by URL works.
- Purge request is visible in API.
- Edge cache key changes after purge.
- Old cache does not need immediate deletion.

---

### 8.3 Redirects v2

Current redirect rules are exact path matches. Improve them lightly.

#### Add columns

```sql
ALTER TABLE redirect_rules ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 100;
ALTER TABLE redirect_rules ADD COLUMN IF NOT EXISTS match_type TEXT NOT NULL DEFAULT 'exact_path';
ALTER TABLE redirect_rules ADD COLUMN IF NOT EXISTS preserve_query BOOLEAN NOT NULL DEFAULT true;
```

#### Match types

- `exact_path`
- `prefix`
- `wildcard_simple`

Avoid regex in v1.

#### API additions

```http
POST /api/v1/sites/{site_id}/redirects/import
GET /api/v1/sites/{site_id}/redirects/export
POST /api/v1/sites/{site_id}/redirects/test
```

#### Test body

```json
{"path":"/old-post","query":"utm_source=x"}
```

#### Definition of done

A user can create simple redirects, test them, and import/export many redirects.

---

### 8.4 Page rules v1

Page rules are a friendly wrapper for common per-path behavior.

#### Add table

```sql
CREATE TABLE IF NOT EXISTS page_rules (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  pattern TEXT NOT NULL,
  actions_json TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

#### Supported patterns

- `/admin/*`
- `/blog/*`
- `/assets/*`
- exact path `/old-page`

#### Supported actions v1

```json
{
  "cache": "bypass",
  "edge_ttl_seconds": 3600,
  "browser_ttl_seconds": 300,
  "redirect_to": "https://example.com/new",
  "redirect_status": 301,
  "waf": "enabled",
  "origin_host": "origin2.internal",
  "add_response_headers": {
    "X-CDNLite-Rule": "assets"
  }
}
```

Keep action support small at first:

- `cache=bypass|eligible`
- `edge_ttl_seconds`
- `redirect_to`
- `redirect_status`
- `waf=enabled|disabled`

#### Rule behavior

Use **one winning page rule** per request, based on priority and specificity.

Simple order:

1. Lower `priority` number wins.
2. If same priority, longest pattern wins.
3. If same length, oldest rule wins.

#### API

```http
POST /api/v1/sites/{site_id}/page-rules
GET /api/v1/sites/{site_id}/page-rules
PATCH /api/v1/sites/{site_id}/page-rules/{id}
DELETE /api/v1/sites/{site_id}/page-rules/{id}
POST /api/v1/sites/{site_id}/page-rules/test
```

#### Definition of done

A normal website owner can say:

- cache `/assets/*` for 1 day
- bypass cache for `/admin/*`
- redirect `/old/*` to `/new/*`

without understanding separate internal tables.

---

### 8.5 SSL certificate metadata v1

Do **not** automate certificates first. Start with visibility.

#### Add table

```sql
CREATE TABLE IF NOT EXISTS ssl_certificates (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  provider TEXT NOT NULL DEFAULT 'manual',
  status TEXT NOT NULL,
  issuer TEXT NULL,
  serial_number TEXT NULL,
  not_before BIGINT NULL,
  not_after BIGINT NULL,
  days_until_expiry INTEGER NULL,
  renewal_due_at BIGINT NULL,
  last_checked_at BIGINT NULL,
  last_error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(site_id, hostname)
);
```

#### Statuses

- `missing`
- `pending`
- `active`
- `expiring_soon`
- `expired`
- `failed`

#### API

```http
GET /api/v1/sites/{site_id}/ssl/certificates
POST /api/v1/sites/{site_id}/ssl/check
```

#### CLI

```bash
php core/artisan cdn:ssl:check --site=<id>
php core/artisan cdn:ssl:list
```

#### Definition of done

CDNLite can tell the operator:

- certificate exists or is missing
- expiry date
- renewal needed soon
- last check error

No private key storage yet.

---

## Stage 9 — Website Security Pack

Priority: medium

Goal: provide useful basic protection without pretending to be enterprise WAF.

This stage covers:

- WAF rules v2
- path-scoped rate limits
- origin protection header
- security analytics events

---

### 9.1 WAF rules v2

Current WAF rules support very small matching. Expand carefully.

#### Add columns

```sql
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS name TEXT NULL;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 100;
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS action TEXT NOT NULL DEFAULT 'block';
ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS description TEXT NULL;
```

#### Supported rule types v2

- `path_contains`
- `path_prefix`
- `user_agent_contains`
- `ip_cidr`
- `country_is`
- `method_is`
- `header_contains`

#### Supported actions v2

- `block`
- `log`
- `allow`

Avoid `challenge` until there is a real challenge implementation.

#### Block response

Default:

```json
{"error":"blocked_by_waf","request_id":"..."}
```

Add custom HTML later.

#### API

```http
POST /api/v1/sites/{site_id}/waf-rules/test
GET /api/v1/sites/{site_id}/security/events
```

#### Definition of done

A user can block:

- bad user agents
- dangerous paths
- countries
- methods
- known bad IP CIDRs

and can see what was blocked.

---

### 9.2 Path-scoped rate limits

Current rate limit is site-wide. Add per-path limits.

#### Replace or extend table

```sql
CREATE TABLE IF NOT EXISTS rate_limit_rules_v2 (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  path_prefix TEXT NOT NULL DEFAULT '/',
  key_type TEXT NOT NULL DEFAULT 'ip',
  requests_per_minute INTEGER NOT NULL,
  action TEXT NOT NULL DEFAULT 'block',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

#### Keep key types simple

- `ip`
- `ip_path`

#### Definition of done

A user can protect `/login`, `/wp-login.php`, `/api/`, and admin paths without throttling the whole website.

---

### 9.3 Origin protection header

Goal: help prevent direct origin access.

#### Site setting

```sql
ALTER TABLE sites ADD COLUMN IF NOT EXISTS origin_shield_header_name TEXT NULL;
ALTER TABLE sites ADD COLUMN IF NOT EXISTS origin_shield_header_value_hash TEXT NULL;
```

But do not store raw secret in DB if possible. Store raw secret in env or a mounted secret file per edge later.

#### Simple v1

- Generate an origin secret.
- Store hashed value in DB.
- Edge receives secret through local env or protected config only if acceptable for your threat model.
- Edge adds header:

```http
X-CDNLITE-Origin-Secret: <secret>
```

Origin server checks this header.

#### Definition of done

Docs show Nginx/Apache examples for rejecting requests without the origin secret header.

---

### 9.4 Security events

Add a small table:

```sql
CREATE TABLE IF NOT EXISTS security_events (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  edge_node_id TEXT NULL,
  request_id TEXT NULL,
  event_type TEXT NOT NULL,
  action TEXT NOT NULL,
  rule_id TEXT NULL,
  client_ip TEXT NULL,
  country TEXT NULL,
  method TEXT NULL,
  path TEXT NULL,
  user_agent TEXT NULL,
  created_at BIGINT NOT NULL
);
```

Events:

- `waf_match`
- `rate_limited`
- `blocked`

#### Definition of done

Security actions are visible, not silent.

---

## Stage 10 — Website Operations Pack

Priority: medium

Goal: make CDNLite easier to operate for real sites.

This stage covers:

- certificate lifecycle v2
- cache analytics
- purge analytics
- small dashboard
- release process

---

### 10.1 SSL lifecycle v2: manual cert import first

After metadata exists, add manual certificate import.

#### API

```http
POST /api/v1/sites/{site_id}/ssl/manual-certificate
```

Body:

```json
{
  "hostname": "example.com",
  "certificate_pem": "-----BEGIN CERTIFICATE-----...",
  "private_key_pem": "-----BEGIN PRIVATE KEY-----..."
}
```

#### Security rule

Prefer storing cert/key on disk or secret manager, not in raw DB.

Simple local path:

```text
/var/lib/cdnlite/certs/{site_id}/{hostname}.crt
/var/lib/cdnlite/certs/{site_id}/{hostname}.key
```

Permissions:

```text
0600 key
0644 cert
```

#### Edge change

OpenResty needs TLS listener and SNI certificate loading. This is bigger than metadata, so keep it after metadata and tests.

#### Definition of done

CDNLite can serve HTTPS with manually imported certs in local/controlled deployments.

---

### 10.2 SSL lifecycle v3: optional ACME automation

Only after manual certs work.

#### Add ACME provider support

- HTTP-01 if edge can serve challenge path.
- DNS-01 if PowerDNS API is available.

#### Add tables

```sql
CREATE TABLE IF NOT EXISTS ssl_orders (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  provider TEXT NOT NULL DEFAULT 'acme',
  challenge_type TEXT NOT NULL,
  status TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

#### Renewal policy

- renew when `not_after` is within 30 days
- retry with backoff
- never delete old certificate until new one is active

#### Definition of done

ACME renews certificates without downtime in a test environment.

---

### 10.3 Cache analytics

Current usage metrics track request counts and bytes. Add cache outcome.

#### Add fields to metrics

- `cache_status`: `HIT`, `MISS`, `BYPASS`, `STALE`, `EXPIRED`, `UPDATING`
- `rule_id`
- `request_id`
- `origin_status`
- `origin_time_ms`

#### API

```http
GET /api/v1/sites/{site_id}/analytics/cache
```

Return:

```json
{
  "hit_ratio": 0.82,
  "requests": 10000,
  "hit": 8200,
  "miss": 1200,
  "bypass": 500,
  "stale": 100
}
```

#### Definition of done

A user can answer: “Is cache helping my website?”

---

### 10.4 Purge analytics and audit

Add to audit log:

- who purged
- what type
- what value
- config version before/after

API:

```http
GET /api/v1/sites/{site_id}/cache/purge-requests
```

Fields:

- type
- value
- status
- created_at
- completed_at
- edge_seen_count

#### Definition of done

Operators can see purge history and know whether edges have picked up the purge namespace.

---

### 10.5 Tiny dashboard

Do this only after API auth, validation, and CDN essentials.

Use server-rendered PHP, not a SPA.

Pages:

```text
/sites
/sites/{id}
/sites/{id}/dns
/sites/{id}/cache
/sites/{id}/redirects
/sites/{id}/page-rules
/sites/{id}/waf
/sites/{id}/ssl
/edges
/usage
```

Features:

- list sites
- create/edit site
- manage DNS
- enable proxy
- manage cache settings
- create purge request
- manage redirects
- manage page rules
- manage simple WAF rules
- see SSL expiry/status
- see edge health

#### Definition of done

A non-code operator can manage a basic website through the dashboard.

---

## Stage 11 — DNS and PowerDNS safety

Priority: medium

Goal: avoid hidden DNS breakage.

### Tasks

- [ ] Add DNS sync status per record:
  - `pending`
  - `synced`
  - `failed`
  - `last_error`
- [ ] Add reconcile command:

```bash
php core/artisan cdn:dns:reconcile --site=<id>
php core/artisan cdn:dns:reconcile --site=<id> --apply
```

- [ ] Add dry-run:

```bash
php core/artisan cdn:dns:sync --dry-run
```

- [ ] Add safer rollback if PowerDNS strict mode fails.

### Definition of done

DB and PowerDNS differences are visible before they break production.

---

## Stage 12 — Usage, metrics, and logs

Priority: medium

Goal: make traffic behavior understandable.

### Tasks

- [ ] Add request ID to every metrics row.
- [ ] Add edge ID to every response header.
- [ ] Add cache status to metrics.
- [ ] Add WAF/rate-limit event metrics.
- [ ] Add usage retention command:

```bash
php core/artisan cdn:usage:prune --older-than=90d
```

- [ ] Add filters:
  - site
  - edge
  - status class
  - cache status
  - from/to

### Definition of done

Operators can debug cache, origin, WAF, and edge behavior from one place.

---

## Stage 13 — Security hardening v2

Priority: medium

Goal: make it safer after the simple API token works.

### Tasks

- [ ] Move API tokens to DB with hashes.
- [ ] Add token labels.
- [ ] Add token scopes:
  - `sites:read`
  - `sites:write`
  - `dns:write`
  - `rules:write`
  - `usage:read`
  - `admin`
- [ ] Add CORS default off.
- [ ] Add rate limit on control API.
- [ ] Add secret scan in CI.
- [ ] Add `SECURITY.md` with disclosure process.
- [ ] Add audit log for all mutations.

### Definition of done

Internet-exposed control-plane deployments have a basic security model.

---

## Stage 14 — Release and operations process

Priority: medium

Goal: make upgrades safe.

### Tasks

- [ ] Add `CHANGELOG.md`.
- [ ] Publish first version tags.
- [ ] Add release checklist.
- [ ] Add backup/restore docs.
- [ ] Add rollback docs.
- [ ] Add image tags per release.

### Suggested versions

```text
v0.1.0-local-baseline
v0.2.0-authenticated-control-plane
v0.3.0-validated-rules-and-routing
v0.4.0-website-cdn-essentials
v0.5.0-security-pack
v0.6.0-ssl-lifecycle
v1.0.0-small-production
```

---

# Suggested first 20 PRs

1. `docs: align README with current cache and rule behavior`
2. `test: add current endpoint auth expectation tests`
3. `feat: require bearer auth for control-plane routes`
4. `test: keep signed edge endpoints working with api auth`
5. `feat: add simple validation helper`
6. `feat: validate site create and update payloads`
7. `feat: validate dns record create and update payloads`
8. `feat: validate redirect waf cache and rate-limit payloads`
9. `refactor: introduce small router and response helpers`
10. `feat: add migration runner and schema_migrations table`
11. `db: add indexes for sites dns rules config and usage`
12. `edge: add request id to response metrics and error page`
13. `ci: add shellcheck and openresty config validation`
14. `agent: add doctor command and last_error sync status`
15. `feat: add site cache settings api and config snapshot support`
16. `edge: add site default cache settings support`
17. `feat: add cache purge request and purge version tables`
18. `edge: add cache namespace version to cache key`
19. `feat: add redirect priority prefix wildcard and test endpoint`
20. `feat: add ssl certificate metadata table and check command`

---

# Implementation notes by feature

## API auth route policy

Protect:

- sites CRUD
- proxy enable/disable
- DNS CRUD
- redirects CRUD
- rate limit CRUD
- WAF CRUD
- cache rules CRUD
- page rules CRUD
- SSL APIs
- purge APIs
- edge node list
- usage summary/recalculate

Public:

- health
- readiness

Edge HMAC:

- edge register
- edge heartbeat
- edge config
- collector usage

---

## Recommended request pipeline at the edge

Simple order:

1. Load valid config.
2. Normalize host/path.
3. Generate request ID.
4. Find site by host.
5. Apply WAF block/log rules.
6. Apply rate limit.
7. Apply redirect/page-rule redirect.
8. Decide cache eligibility and TTL.
9. Choose upstream.
10. Proxy request.
11. Add response headers.
12. Write metrics/security/cache events.

---

## Page rules vs existing rules

Do not delete existing tables.

Use `page_rules` as a user-friendly layer.

For v1, a page rule can compile into edge config directly. Later, it can also create/update lower-level rules if needed.

Example page rule:

```json
{
  "pattern": "/assets/*",
  "actions": {
    "cache": "eligible",
    "edge_ttl_seconds": 86400
  }
}
```

Compiled config:

```json
{
  "page_rules": [
    {
      "host": "example.com",
      "pattern": "/assets/*",
      "priority": 100,
      "actions": {
        "cache": "eligible",
        "edge_ttl_seconds": 86400
      }
    }
  ]
}
```

---

## Cache purge v1 design choice

Use soft purge because it is simpler and safer.

Pros:

- no fragile cache-file deletion
- works with normal Nginx cache
- easy to replicate via config snapshots
- easy to audit
- simple rollback possible

Cons:

- old cache files remain until inactive cleanup
- cache disk may temporarily use more space

This is acceptable for CDNLite because simplicity matters more than perfect instant physical deletion.

---

## WAF v2 design choice

Avoid arbitrary expressions in v1/v2.

Good:

```json
{"type":"path_prefix","pattern":"/wp-admin","action":"block"}
```

Avoid for now:

```text
(http.request.uri.path contains "/admin" and ip.geoip.country eq "RU") or ...
```

Reason: expression languages are powerful but add parser, validation, security, and debugging complexity.

---

## SSL lifecycle design choice

Start with certificate metadata before certificate serving.

Why:

- easy to build
- useful immediately
- no private key handling first
- reveals expiry problems
- prepares schema for manual certs and ACME later

Recommended order:

1. Metadata and checking.
2. Manual cert import.
3. TLS listener/SNI serving.
4. ACME DNS-01/HTTP-01 automation.
5. Renewal and zero-downtime rotation.

---

# Simple database additions summary

## `site_cache_settings`

Site default cache behavior.

## `cache_purge_requests`

Operator-visible purge actions.

## `cache_purge_versions`

Soft-purge namespace values used by edge cache key.

## `page_rules`

User-friendly URL pattern actions.

## `ssl_certificates`

Certificate metadata and expiry state.

## `security_events`

WAF/rate-limit/block logs.

## `audit_log`

Control-plane mutation history.

---

# Minimum useful website-CDN v1 checklist

CDNLite becomes a useful small website CDN when this checklist is done:

- [ ] Control API requires auth.
- [ ] Sites and DNS payloads are validated.
- [ ] Edge config has schema version.
- [ ] Cache can be enabled/disabled per site.
- [ ] Cache has default TTL and path TTLs.
- [ ] Cache purge by site works.
- [ ] Cache purge by URL/prefix works.
- [ ] Redirects support exact and prefix match.
- [ ] Page rules support cache bypass/TTL and redirect.
- [ ] WAF can block by path, user agent, IP/CIDR, method, and country.
- [ ] Rate limits can protect specific paths.
- [ ] SSL certificate metadata shows active/expiring/expired.
- [ ] Dashboard or CLI can show cache status and SSL status.
- [ ] Docs clearly say what is supported and what is not.

---

# Things to explicitly avoid until after v1.0

- Full Ruleset Engine.
- JavaScript challenge pages.
- Bot scoring.
- Image optimization.
- Workers/edge functions.
- Multi-tenant billing.
- Enterprise RBAC.
- Terraform provider.
- Kubernetes-only deployment.
- Anycast marketing.

---

# Source notes

This roadmap was based on a live review of the public repository docs.

Repository evidence used:

- CDNLite README: project architecture, features, and current limitations.
- CDNLite `public_index.php`: current routes for sites, DNS, redirects, WAF, rate limit, cache rules, edge auth, usage.
- CDNLite `schema.sql`: current tables including sites, DNS, edge nodes, edge tokens, usage, redirect rules, rate limit rules, WAF rules, cache rules, config snapshots.
- CDNLite edge runtime docs and OpenResty config: current cache behavior and proxy cache settings.
- CDNLite security docs: edge token/HMAC model.
- CDNLite edge-agent docs: agent registration, heartbeat, config pull, metrics push, sync status.
