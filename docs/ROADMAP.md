# CDNLite Roadmap

**Repo:** `vaheed/CDNLite`
**Date:** 2026-06-06
**Strategy:** Greenfield reset. No backward compatibility. DB can be dropped and recreated. Each phase is one coding-agent shot.
**Audience:** Admin / ops only.

---

## Status

| Phase | State | Verification |
|---|---|---|
| Phase 1 — Schema and naming reset | Complete | Clean Compose rebuild, 33 core tests, 8 dashboard tests, smoke, and E2E passed |
| Phase 2 — Cache metrics fix | Complete | Focused pytest, dashboard Vitest, and dashboard build passed; Docker-backed smoke/E2E could not run in this sandbox |
| Phase 3 — Rate limit full CRUD | Complete | PHP lint, shell syntax, focused pytest, dashboard typecheck/build, Compose smoke, and E2E passed |
| Phase 4 — Edge identity fix | Complete | PHP/shell lint, focused contracts, dashboard build, agent checks, Compose smoke, and E2E passed |
| Phase 5+ | Planned | Not started |

## Rules

- No `site`, `site_id`, `SiteService`, `/api/v1/sites` anywhere after Phase 1.
- DB reset is acceptable. Drop volumes, recreate schema, bootstrap seed data.
- Backend (PHP / PostgreSQL / OpenResty / Lua) and frontend (Vue 3 / TypeScript / TanStack Query / Tailwind) change together per phase.
- Every phase ships tests: unit, smoke/API, and E2E.
- Simple wins over clever.

## Test stack

| Layer | Tool | Location |
|---|---|---|
| Backend unit | Python pytest | `core/tests/unit/` |
| Backend API / smoke | Python pytest + httpx | `core/tests/api/` |
| Frontend unit | Vitest | `dash/src/test/` |
| E2E | Playwright | `dash/tests/e2e/` |
| CI entry | shell | `./ci/smoke.sh`, `./ci/e2e.sh` |

Every phase adds test files to these folders. CI must pass before phase is considered done.

---

## Phase 1 — Schema and naming reset

**Status:** Complete (2026-06-06)

**Goal:** Replace `sites` with `domains` everywhere. All subsequent phases depend on this.

### Completion record

- Recreated PostgreSQL from a clean Compose volume with the domain-only schema.
- Removed active `site` / `site_id` API, CLI, backend, edge, dashboard, CI, and documentation contracts.
- Consolidated rate limits into the canonical v2-shaped `rate_limit_rules` table.
- Removed the `geo_policies` table and its runtime database dependency.
- Verified `docker compose up -d --build`, PHP lint, shell syntax, 33 core tests, 8 dashboard tests, dashboard typecheck/build, smoke, and E2E.

### What changes

**Database** — Drop and recreate schema. Replace `sites` with `domains`. Rename all `site_id` FKs to `domain_id`. Consolidate `rate_limit_rules` + `rate_limit_rules_v2` into one canonical table (use v2 shape). Remove `geo_policies` table.

```sql
CREATE TABLE domains (
  id TEXT PRIMARY KEY,
  user_id TEXT NULL,
  name TEXT NOT NULL,
  zone_name TEXT NOT NULL UNIQUE,
  display_name TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  routing_mode TEXT NOT NULL DEFAULT 'geo',
  proxy_enabled BOOLEAN NOT NULL DEFAULT true,
  origin_scheme TEXT NOT NULL DEFAULT 'http',
  origin_host TEXT NOT NULL,
  origin_port INTEGER NOT NULL DEFAULT 80,
  powerdns_zone_created BOOLEAN NOT NULL DEFAULT false,
  last_error TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (routing_mode IN ('geo', 'anycast', 'dns_only'))
);
```

**Backend** — Rename `SiteService/Controller/Repository` → `Domain*`. Replace `/api/v1/sites` routes with `/api/v1/domains`. Update collector/usage ingest and config snapshot builder to use `domain_id`. Drop all compatibility shims.

**Frontend** — Rename `sites.ts` → `domains.ts`. Replace `Site` type with `Domain`. Rename `SitesView.vue` → `DomainsView.vue`. Update router and sidebar nav.

### Files changed
```
core/database/schema.sql
core/src/Controllers/DomainController.php
core/src/Services/DomainService.php
core/src/Repositories/DomainRepository.php
core/routes/api.php
core/src/Services/CollectorService.php
core/src/Services/ConfigSnapshotService.php
dash/src/types.ts
dash/src/lib/api/domains.ts
dash/src/router/index.ts
dash/src/views/DomainsView.vue
dash/src/components/layout/nav.ts
```

### Tests
- **Unit:** `test_domain_service.py` — create, list, update, delete domain; verify no site vocabulary in responses.
- **Smoke:** `test_domains_api.py` — `POST /api/v1/domains`, `GET /api/v1/domains`, `GET /api/v1/domains/{id}`, `DELETE /api/v1/domains/{id}`; assert `/api/v1/sites` returns 404.
- **E2E:** `domains_list.spec.ts` — load dashboard, navigate to Domains, see domain list, no "Site" text visible anywhere.

### Acceptance criteria
- `docker compose down -v && docker compose up --build` succeeds.
- `GET /api/v1/domains` returns domain list.
- No active route answers under `/api/v1/sites`.
- Dashboard loads with no "Site" label visible.

### Agent prompt
```
CDNLite has no backward compatibility requirement. DB can be wiped.

1. Replace sites table with domains in schema.sql. Rename all site_id FKs to domain_id.
2. Consolidate rate_limit_rules and rate_limit_rules_v2 into one rate_limit_rules (v2 shape).
3. Rename SiteService/SiteController/SiteRepository to Domain equivalents.
4. Replace /api/v1/sites routes with /api/v1/domains. Delete old routes.
5. Update CLI, CollectorService, ConfigSnapshotService to use domain_id.
6. In dashboard: rename sites.ts to domains.ts, replace Site type, update router and nav.
7. Add pytest unit and API tests. Add Playwright test verifying no "Site" text appears.

Return list of every file changed.
```

---

## Phase 2 — Cache metrics fix

**Status:** Complete (2026-06-06)

**Goal:** Make the cache hit ratio real. Currently `upstream_cache_status` is not sent with metrics, so the dashboard always shows 0 HIT.

### What changes

**Edge (OpenResty/Lua)** — Read `ngx.var.upstream_cache_status`. If empty, store `UNKNOWN`, never `BYPASS` as default. Add `X-CDNLITE-Cache` response header.

```lua
local cache_status = ngx.var.upstream_cache_status or ''
if cache_status == '' then cache_status = 'UNKNOWN' end
ngx.header['X-CDNLITE-Cache'] = cache_status
```

**Backend** — Add `cache_status TEXT NOT NULL DEFAULT 'UNKNOWN'` to `usage_aggregates`. Update UNIQUE constraint to include `cache_status`. Update aggregate rebuild to GROUP BY `cache_status`. Add `/api/v1/analytics/cache` endpoint.

**Frontend** — Add cache status breakdown chart (HIT/MISS/BYPASS/UNKNOWN). Show cache hit ratio as `HIT / (HIT + MISS + EXPIRED + STALE)`. BYPASS shown separately.

### Files changed
```
edge/openresty/lua/metrics.lua
edge/openresty/nginx.conf
core/database/schema.sql
core/src/Services/AnalyticsService.php
core/routes/api.php
dash/src/views/UsageAnalyticsView.vue
dash/src/lib/api/usage.ts
```

### Tests
- **Unit:** `test_analytics_service.py` — verify aggregate groups by cache_status; verify UNKNOWN is stored when status missing, not BYPASS.
- **Smoke:** `test_analytics_api.py` — `GET /api/v1/analytics/cache` returns `[{cache_status, count, bytes_out}]`; verify BYPASS and HIT are separate rows.
- **E2E:** `cache_metrics.spec.ts` — make two requests to a cacheable path, open Analytics page, assert "Cache hit ratio" card shows a non-zero value.

### Acceptance criteria
- Two requests to same static path: first `X-CDNLITE-Cache: MISS`, second `X-CDNLITE-Cache: HIT`.
- Dashboard cache hit ratio > 0 after HIT traffic exists.
- BYPASS not in hit ratio denominator.

### Agent prompt
```
Fix CDNLite cache metrics so HIT/MISS/BYPASS are tracked correctly.

1. In lua/metrics.lua, read ngx.var.upstream_cache_status. Empty → 'UNKNOWN', never 'BYPASS'.
2. Add X-CDNLITE-Cache response header with actual cache_status.
3. Add cache_status column to usage_aggregates. Update UNIQUE constraint to include it.
4. Update aggregate rebuild to GROUP BY cache_status.
5. Add GET /api/v1/analytics/cache?domain_id=&from=&to= returning [{cache_status, count, bytes_out}].
6. In UsageAnalyticsView: add cache breakdown chart and hit ratio card.
   Hit ratio = HIT / (HIT + MISS + EXPIRED + STALE). Show BYPASS count separately.
7. Add unit test for UNKNOWN defaulting. Add API test for cache endpoint. Add E2E for hit ratio card.
```

---

## Phase 3 — Rate limit full CRUD

**Status:** Complete (2026-06-06)

### Completion record

- Added per-domain rate-limit collection create, list, update, toggle, and delete APIs while preserving singular CLI-compatible endpoints.
- Updated config snapshots to publish every enabled rule, not only the first rule.
- Added dashboard table CRUD with edit, enable/disable, and delete confirmation.
- Added focused contracts and an E2E CRUD cycle that verifies deletion is reflected in the next snapshot.

**Goal:** Admins can create, edit, disable, and delete rate limit rules. Currently only create exists.

### What changes

**Backend** — The canonical `rate_limit_rules` table (from Phase 1) has the v2 shape. Add missing endpoints:
```
PATCH  /api/v1/domains/{domainId}/rate-limits/{ruleId}
DELETE /api/v1/domains/{domainId}/rate-limits/{ruleId}
```
DELETE triggers config snapshot regeneration.

**Frontend** — Rules table with Edit button (drawer), Delete button (confirmation dialog), enable/disable toggle. Toast on success/error.

### Files changed
```
core/src/Controllers/RateLimitController.php
core/routes/api.php
dash/src/lib/api/rateLimit.ts
dash/src/views/SiteFeatureView.vue (or current rate-limit section)
```

### Tests
- **Unit:** `test_rate_limit_service.py` — create rule, update path_prefix and limit, delete rule, verify config snapshot regenerated on delete.
- **Smoke:** `test_rate_limits_api.py` — full CRUD cycle; assert deleted rule absent from `GET` list; assert 404 on deleted rule ID.
- **E2E:** `rate_limits_crud.spec.ts` — open domain, navigate to Rate Limits tab, create rule, edit limit, delete with confirmation, assert rule gone from list.

### Acceptance criteria
- Admin can create, edit, delete, enable/disable a rate limit rule.
- Deleted rule is absent from the next config snapshot.
- Delete requires confirmation dialog.

### Agent prompt
```
Add full CRUD for rate limit rules in CDNLite.

1. Canonical table: rate_limit_rules (domain_id, enabled, priority, path_prefix, key_type, requests_per_minute, action).
2. Add PATCH and DELETE endpoints under /api/v1/domains/{domainId}/rate-limits/{ruleId}.
3. DELETE triggers config snapshot regeneration.
4. In dashboard: replace rate limit section with table showing all rules, edit drawer, delete confirmation.
5. Add pytest CRUD cycle test. Add Playwright test for create→edit→delete flow.
```

---

## Phase 4 — Edge identity fix

**Status:** Complete (2026-06-06)

### Completion record

- Added one shared OpenResty identity source and preserved `EDGE_ID` in Nginx workers.
- Applied the configured identity to every response, usage metric, and security event.
- Rejected empty edge identities at runtime unless `DEV_MODE=1`.
- Added API identity health status and a warning badge in the Edge Nodes identity column.
- Verified the response header and persisted `usage_rollups.edge_node_id` through Compose smoke and E2E.

**Goal:** `X-CDNLITE-Edge` always shows the real configured edge ID. Metrics are attributed correctly.

### What changes

**Edge (OpenResty/Lua)** — Read `EDGE_ID` env at startup. Set on every response. Metrics Lua reads from same source.

```lua
local edge_id = os.getenv('EDGE_ID') or 'unknown'
ngx.header['X-CDNLITE-Edge'] = edge_id
ngx.ctx.edge_id = edge_id
```

**Edge agent** — Refuse to start if `EDGE_ID` is empty unless `DEV_MODE=1`.

**Backend** — Add readiness check: flag edge nodes with default/suspicious identity (`unknown`, `openresty`).

**Frontend** — Add Identity column to edge nodes table. Highlight rows with suspicious identity.

### Files changed
```
edge/openresty/lua/metrics.lua
edge/openresty/lua/proxy.lua
edge/openresty/entrypoint.sh
edge/agent/agent.sh
core/src/Services/HealthService.php
dash/src/views/EdgeNodesView.vue
```

### Tests
- **Unit:** `test_health_service.py` — edge node with `edge_id='unknown'` triggers identity warning check.
- **Smoke:** `test_edge_identity.sh` — `curl -I http://localhost:8081/` asserts `X-CDNLITE-Edge: edge-local-1`; assert usage rollup row `edge_node_id = 'edge-local-1'`.
- **E2E:** `edge_identity_warning.spec.ts` — open Edge Nodes page, assert Identity column exists, assert no "unknown" or "openresty" values for the local dev edge.

### Acceptance criteria
- `X-CDNLITE-Edge` header matches configured `EDGE_ID`.
- Usage rollups reference the same edge ID.
- Dashboard warns when edge_id is `unknown` or `openresty`.

### Agent prompt
```
Fix CDNLite edge identity so X-CDNLITE-Edge always shows the real configured EDGE_ID.

1. In OpenResty entrypoint, inject EDGE_ID as nginx variable via env directive.
2. In all Lua response handlers, set ngx.header['X-CDNLITE-Edge'] = edge_id from env.
3. In metrics.lua, attribute rollups using same edge_id.
4. In edge agent, refuse start if EDGE_ID empty unless DEV_MODE=1.
5. Add identity readiness check in HealthService.
6. In EdgeNodesView, add Identity column and flag suspicious values.
7. Add smoke test for response header. Add E2E for identity column.
```

---

## Phase 5 — Readiness API and clickable cards

**Goal:** Core Ready and Edge Ready badges in the dashboard are clickable and show specific warnings with links to fix pages.

### What changes

**Backend** — `GET /api/v1/readiness` returns structured checks:

```json
{
  "core": { "status": "warning", "checks": [
    {"key": "postgres", "status": "ok", "message": "PostgreSQL reachable"},
    {"key": "powerdns_config", "status": "warning", "message": "PowerDNS API key not set", "fix": "Add key in Settings", "link": "/settings"}
  ]},
  "edge": { "status": "ok", "checks": [
    {"key": "heartbeat", "status": "ok", "message": "1 active edge node"},
    {"key": "identity", "status": "warning", "message": "1 edge has default identity", "link": "/edge-nodes"}
  ]}
}
```

Checks: PostgreSQL reachable, PowerDNS config present, PowerDNS reachable, ≥1 edge heartbeat in last 5 min, no default-identity edges, config snapshot not stale.

**Frontend** — Replace static badges with `ReadinessBadge`. Click opens `ReadinessDrawer` — grouped checks with status icons, message, fix text, and clickable link. Refresh button + last-checked timestamp. Poll every 60s.

### Files changed
```
core/src/Services/ReadinessService.php
core/src/Controllers/ReadinessController.php
core/routes/api.php
dash/src/lib/api/health.ts
dash/src/components/layout/TopStatusBar.vue
dash/src/components/health/ReadinessBadge.vue
dash/src/components/health/ReadinessDrawer.vue
```

### Tests
- **Unit:** `test_readiness_service.py` — mock missing PowerDNS env key → warning check; mock no edge heartbeat in 5 min → warning; mock all ok → status ok.
- **Smoke:** `test_readiness_api.py` — `GET /api/v1/readiness` returns `{core, edge}` with `checks[]`; each check has `key`, `status`, `message`.
- **E2E:** `readiness_drawer.spec.ts` — click Core Ready badge, assert drawer opens with at least one check row; click a link in a warning, assert navigation.

### Acceptance criteria
- Clicking Core Ready opens drawer with individual check results.
- Each warning includes a fix link to the relevant page.
- Refresh button re-fetches immediately.

### Agent prompt
```
Add structured readiness API and clickable cards to CDNLite.

1. Create ReadinessService: postgres ping, powerdns key present, powerdns api reachable, edge heartbeat <5min, edge identity check, config snapshot freshness.
2. Add GET /api/v1/readiness returning {core: {status, checks[]}, edge: {status, checks[]}}.
   Each check: {key, status (ok|warning|error), message, fix?, link?}.
3. Replace TopStatusBar static badges with ReadinessBadge → ReadinessDrawer.
4. Drawer groups checks by core/edge. Each warning shows message + fix + clickable link.
5. Poll every 60s using TanStack Query.
6. Add unit tests for each check scenario. Add smoke test for API shape. Add E2E for drawer open + link click.
```

---

## Phase 6 — Settings dashboard

**Goal:** Move operational config out of `.env` into a database-backed settings dashboard. Secrets stay masked.

### What changes

**Database:**
```sql
CREATE TABLE platform_settings (
  key TEXT PRIMARY KEY,
  group_name TEXT NOT NULL,
  value_json JSONB NOT NULL,
  is_secret BOOLEAN NOT NULL DEFAULT false,
  description TEXT NULL,
  updated_by TEXT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE platform_settings_audit (
  id TEXT PRIMARY KEY,
  key TEXT NOT NULL,
  actor TEXT NULL,
  old_redacted JSONB NULL,
  new_redacted JSONB NULL,
  created_at BIGINT NOT NULL
);
```

**Backend** — `SettingsRepository` with typed getters, falls back to env if DB value not set. Settings groups: `platform.powerdns`, `platform.nameservers`, `platform.edge_dns`, `platform.cache`, `platform.analytics`, `platform.security`. Secret fields: GET returns `{configured: true, updated_at: N}`, never plaintext. `PowerDnsService` reads from `SettingsRepository` instead of env.

API: `GET /api/v1/settings`, `GET /api/v1/settings/{group}`, `PATCH /api/v1/settings/{group}`, `POST /api/v1/settings/test/powerdns`, `POST /api/v1/settings/validate`.

**Frontend** — Replace read-only `SettingsView.vue` with tabbed editor. Groups: PowerDNS, Nameservers, Edge DNS, Cache Defaults, Analytics, Security. Secret fields show `••••• (configured)` with Update button. Dirty-state tracking. "Test PowerDNS connection" button. Audit log timeline per group.

### Files changed
```
core/database/schema.sql
core/src/Repositories/SettingsRepository.php
core/src/Controllers/SettingsController.php
core/src/Services/PowerDnsService.php
core/routes/api.php
dash/src/views/SettingsView.vue
dash/src/lib/api/settings.ts
dash/src/components/settings/SettingsSection.vue
dash/src/components/settings/SettingField.vue
dash/src/components/settings/SecretSettingField.vue
```

### Tests
- **Unit:** `test_settings_repository.py` — get existing key, get missing key falls back to env, patch key stores in DB, secret key never returned as plaintext.
- **Smoke:** `test_settings_api.py` — `GET /api/v1/settings` returns groups; `PATCH /api/v1/settings/platform.powerdns` updates api_url; `GET` after PATCH returns new value; secret key returns `{configured: true}` not plaintext.
- **E2E:** `settings_edit.spec.ts` — open Settings → PowerDNS tab, change API URL, save, reload page, assert new value persisted; click Test Connection button.

### Acceptance criteria
- PowerDNS URL and API key configurable from dashboard without `.env`.
- API key never returned in GET response.
- Test connection button pings PowerDNS and shows success/failure.
- Audit log shows who changed what.

### Agent prompt
```
Implement database-backed settings dashboard for CDNLite.

1. Add platform_settings and platform_settings_audit tables.
2. Create SettingsRepository with typed getters. If DB value missing, fall back to env.
3. Add GET/PATCH /api/v1/settings/{group}, POST /api/v1/settings/test/powerdns.
4. Secret fields: GET returns {configured: true, updated_at}. Writes store the value.
5. Make PowerDnsService read its config from SettingsRepository instead of env.
6. Replace SettingsView.vue with tabbed editor. Tabs: PowerDNS, Nameservers, Edge DNS, Cache Defaults, Analytics, Security.
7. Secret fields: masked display with Update button. Dirty-state tracking. Audit log timeline.
8. Add unit tests (secret masking, fallback). Add smoke tests (CRUD cycle). Add E2E (edit + save + reload).
```

---

## Phase 7 — Analytics by domain

**Goal:** All analytics filterable by domain. Domain detail gets its own Analytics tab.

### What changes

**Backend** — All `/api/v1/analytics/*` endpoints accept `?domain_id=` (optional, omit = all). Add `/api/v1/domains/{domainId}/analytics/summary` and `/api/v1/domains/{domainId}/analytics/cache`.

**Frontend** — `UsageAnalyticsView.vue`: add domain dropdown (All / per domain). `DomainAnalyticsTab.vue` — same charts hardcoded to domain_id from route. Wire tab into domain detail router.

### Files changed
```
core/src/Controllers/AnalyticsController.php
core/routes/api.php
dash/src/lib/api/usage.ts
dash/src/views/UsageAnalyticsView.vue
dash/src/views/DomainAnalyticsTab.vue
dash/src/router/index.ts
```

### Tests
- **Unit:** `test_analytics_service.py` — sum of per-domain analytics equals all-domain total.
- **Smoke:** `test_analytics_domain_filter.py` — ingest traffic for two domains; `GET /api/v1/analytics/summary?domain_id=A` returns only domain A traffic; domain B traffic not included.
- **E2E:** `analytics_domain_filter.spec.ts` — open Analytics, select a domain from dropdown, assert charts/cards update; open a domain detail, navigate to Analytics tab, assert filtered view.

### Acceptance criteria
- Selecting a domain in global analytics updates all charts and cards.
- Domain detail Analytics tab is pre-filtered to that domain.
- All-domains view equals sum of all domain views.

### Agent prompt
```
Add domain filtering to CDNLite analytics.

1. All analytics API endpoints accept optional domain_id query param.
2. Add /api/v1/domains/{domainId}/analytics/summary and /analytics/cache.
3. In UsageAnalyticsView: add domain dropdown. Default = all. Changing domain reruns all queries.
4. Create DomainAnalyticsTab.vue — domain_id taken from route param.
5. Add DomainAnalyticsTab to domain detail router.
6. Add pytest test asserting domain filter isolates traffic. Add E2E for dropdown + tab.
```

---

## Phase 8 — Domain onboarding and nameserver verification

**Goal:** Adding a domain is Cloudflare-style: enter name, see assigned nameservers, verify delegation, then activate. No origin required at creation.

### What changes

**Database:**
```sql
ALTER TABLE domains ADD COLUMN nameserver_status TEXT NOT NULL DEFAULT 'unknown';
ALTER TABLE domains ADD COLUMN verification_token TEXT NULL;
ALTER TABLE domains ADD COLUMN last_ns_check_at BIGINT NULL;

CREATE TABLE domain_nameservers (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  hostname TEXT NOT NULL,
  expected BOOLEAN NOT NULL DEFAULT true,
  observed BOOLEAN NOT NULL DEFAULT false,
  last_checked_at BIGINT NULL
);
```

**Backend** — `POST /api/v1/domains` body: `{zone_name, display_name?}`. Origin optional, status starts as `pending_nameserver`. `DomainVerificationService` resolves NS records, compares against settings nameservers. `POST /api/v1/domains/{id}/verify-nameservers`. `POST /api/v1/domains/{id}/activate` (blocked unless verified or admin override). Lazy PowerDNS zone in `DomainService::ensureZoneReady()`.

**Frontend** — `AddDomainWizard.vue` — 4 steps: enter domain, show nameservers with copy buttons, Check Nameservers button, Activate button (or "Skip for dev"). Status timeline on domain overview tab.

### Files changed
```
core/database/schema.sql
core/src/Services/DomainVerificationService.php
core/src/Services/DomainService.php
core/src/Controllers/DomainController.php
core/routes/api.php
dash/src/components/domains/AddDomainWizard.vue
dash/src/views/DomainsView.vue
dash/src/views/DomainDetailView.vue
dash/src/lib/api/domains.ts
```

### Tests
- **Unit:** `test_domain_verification_service.py` — mock DNS resolver returning expected NS → status `verified`; returning wrong NS → `partial`; returning nothing → `not_configured`.
- **Smoke:** `test_domain_onboarding_api.py` — `POST /api/v1/domains` with only `zone_name` succeeds; `POST /activate` on unverified domain returns 422; PowerDNS not called during domain creation.
- **E2E:** `add_domain_wizard.spec.ts` — open Add Domain, enter domain name, assert nameservers shown with copy buttons, click Check (mocked to return verified), assert Activate button enabled, click Activate.

### Acceptance criteria
- Domain created with only `zone_name`. Status = `pending_nameserver`.
- Activation blocked until nameserver_status = `verified` (or override on).
- PowerDNS zone NOT created at domain creation time.
- Wizard shows copy buttons for each nameserver.

### Agent prompt
```
Implement Cloudflare-style domain onboarding for CDNLite.

1. POST /api/v1/domains requires only zone_name. Origin optional. Status = pending_nameserver.
2. Add domain_nameservers table. On create, insert expected NS rows from platform_settings.
3. Create DomainVerificationService: resolve NS for zone_name, compare to expected, update nameserver_status.
4. Add POST /api/v1/domains/{id}/verify-nameservers and POST /api/v1/domains/{id}/activate.
5. DomainService::ensureZoneReady() creates PowerDNS zone lazily before first DNS record write.
6. Build AddDomainWizard.vue — 4 steps: enter domain, show nameservers + copy, verify button, activate button.
7. Add unit tests for verification logic. Add smoke tests for onboarding flow. Add E2E for full wizard.
```

---

## Phase 9 — DNS routing: geo and anycast

**Goal:** Each domain can use Geo (PowerDNS LUA `pickclosest`) or Anycast (plain A/CNAME). Proxy mode lives on DNS records.

### What changes

**Database:**
```sql
CREATE TABLE domain_routing_settings (
  domain_id TEXT PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  routing_mode TEXT NOT NULL DEFAULT 'geo',
  geo_health_port INTEGER NOT NULL DEFAULT 443,
  geo_selector TEXT NOT NULL DEFAULT 'pickclosest',
  anycast_ipv4 TEXT NULL,
  anycast_ipv6 TEXT NULL,
  anycast_cname TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (routing_mode IN ('geo', 'anycast', 'dns_only'))
);
```

**Backend** — `DnsPublishingPlanner`:
- `proxied=true + geo` → LUA A: `ifportup(443, ['ip1','ip2'], {selector='pickclosest', backupSelector='random'})`
- `proxied=true + anycast` → plain A/AAAA from `anycast_ipv4/ipv6`, or CNAME from `anycast_cname` for subdomains
- `proxied=false` → original type/content unchanged

Trigger planner on: domain routing mode change, DNS record save, edge node enable/disable.

API: `GET/PATCH /api/v1/domains/{domainId}/routing`, `POST .../dns/records/{recordId}/preview-routing`.

**Frontend** — DNS tab: Proxied toggle per record. Routing Mode selector at domain level. PowerDNS preview callout in edit drawer. Confirmation dialog when switching routing mode.

### Files changed
```
core/database/schema.sql
core/src/Services/DnsPublishingPlanner.php
core/src/Services/PowerDnsSyncService.php
core/src/Controllers/DnsController.php
core/routes/api.php
dash/src/components/dns/DnsRecordTable.vue
dash/src/components/dns/DnsProxyToggle.vue
dash/src/components/dns/PowerDnsPreview.vue
dash/src/components/domains/RoutingModeSelector.vue
dash/src/lib/api/dns.ts
```

### Tests
- **Unit:** `test_dns_publishing_planner.py` — geo mode + proxied → assert LUA string contains `ifportup` and all geo-eligible edge IPs; anycast mode + proxied → assert plain A, no LUA; `proxied=false` → original content unchanged.
- **Smoke:** `test_routing_mode_api.py` — `PATCH /api/v1/domains/{id}/routing` changes mode; `GET .../dns/records/{id}/preview-routing` returns generated PowerDNS record string; switching geo→anycast returns CNAME preview.
- **E2E:** `dns_routing.spec.ts` — open DNS tab, toggle a record proxied, see routing mode badge change; open routing settings, switch geo→anycast, confirm dialog, assert preview shows CNAME.

### Acceptance criteria
- Geo mode proxied A record in PowerDNS is a LUA record with ifportup and edge IPs.
- Anycast mode proxied A record is plain A with anycast IP or CNAME.
- DNS-only record stores original user-specified content.
- Preview shows generated record before saving.

### Agent prompt
```
Implement geo and anycast routing modes for CDNLite DNS records.

1. Add domain_routing_settings table.
2. Create DnsPublishingPlanner:
   - proxied + geo → LUA A: ifportup(port, [edge_ipv4s], {selector='pickclosest', backupSelector='random'})
   - proxied + anycast → plain A/AAAA from domain_routing_settings, or CNAME for subdomains
   - proxied=false → original record content
3. Trigger planner on domain routing mode change, dns record save, edge enable/disable.
4. Add GET/PATCH /api/v1/domains/{domainId}/routing.
5. Add POST .../dns/records/{recordId}/preview-routing.
6. DNS tab: per-record proxied toggle, domain-level routing mode selector, preview callout.
7. Add unit tests for LUA generation. Add smoke tests for routing mode switch. Add E2E for proxy toggle + preview.
```

---

## Phase 10 — Domain feature tabs

**Goal:** Replace generic `SiteFeatureView` with dedicated domain tabs for cache, WAF, SSL, redirects, and page rules — all with full CRUD.

### What changes

**Frontend** — Create `DomainDetailView.vue` with tabs: Overview · DNS · SSL · Cache · Redirects · Page Rules · WAF · Rate Limits · Analytics. Every tab has: summary section, rules table, Add/Edit/Delete actions, enable/disable toggle, empty state, toast on success/error.

SSL tab: cert status, expiry, issuer, ACME status, force HTTPS toggle, min TLS version, warning badge if expiry < 30 days.

Cache tab: enabled toggle, default TTL, respect origin Cache-Control toggle, bypass rules table, purge actions (all/URL/prefix) with confirmation, recent purge history.

**Backend** — Verify all feature endpoints are domain-scoped. Add any missing: `PATCH /api/v1/domains/{domainId}/cache/settings`, `GET /api/v1/domains/{domainId}/ssl`, `PATCH /api/v1/domains/{domainId}/ssl/settings`.

Delete `SiteFeatureView.vue`.

### Files changed
```
core/src/Controllers/ (ssl, cache, waf, redirects, page rules)
core/routes/api.php
dash/src/views/DomainDetailView.vue
dash/src/views/domain-tabs/DomainCacheTab.vue
dash/src/views/domain-tabs/DomainSslTab.vue
dash/src/views/domain-tabs/DomainRedirectsTab.vue
dash/src/views/domain-tabs/DomainPageRulesTab.vue
dash/src/views/domain-tabs/DomainWafTab.vue
dash/src/lib/api/ (ssl, cache, purge, waf, redirects, pageRules)
```

### Tests
- **Unit:** `test_domain_tabs.py` — CRUD for each feature type; purge-all clears cache purge version; SSL expiry warning check fires when cert within 30 days.
- **Smoke:** `test_domain_features_api.py` — full CRUD cycle for redirects, WAF rules, cache rules, page rules; purge endpoint returns 202; SSL endpoint returns cert status.
- **E2E:** `domain_tabs.spec.ts` — open domain, navigate each tab, create/edit/delete a rule in each, assert persistence; open SSL tab and assert expiry visible.

### Acceptance criteria
- Domain detail has all 9 tabs.
- Every rule type supports create, edit, delete, enable/disable.
- SSL tab shows cert expiry with warning if < 30 days.
- Cache purge (all/URL/prefix) works with confirmation.
- No `site_id` reference in any tab component.
- `SiteFeatureView.vue` deleted.

### Agent prompt
```
Replace SiteFeatureView with domain feature tabs in CDNLite.

1. Create DomainDetailView.vue with tabs: Overview, DNS, SSL, Cache, Redirects, Page Rules, WAF, Rate Limits, Analytics.
2. Create one Vue file per tab under dash/src/views/domain-tabs/.
3. Each tab: summary card, rules table with edit/delete, add-rule button, enable/disable toggle, empty state.
4. SSL tab: cert status, expiry, ACME status, force HTTPS, min TLS, warn if expiry < 30 days.
5. Cache tab: settings form, bypass rules, purge actions with confirmation, recent purge history.
6. Ensure all backend feature endpoints are domain-scoped. No site_id anywhere.
7. Delete SiteFeatureView.vue.
8. Add smoke tests for each feature CRUD. Add E2E covering each tab.
```

---

## Phase 11 — Overview dashboard and report exports

**Goal:** Main dashboard shows a useful operational summary from aggregate endpoints. Key pages can export Markdown reports.

### What changes

**Backend:**
```
GET /api/v1/overview          — {domains_count, active_domains, total_requests_24h, bandwidth_24h_bytes, cache_hit_ratio_24h, edge_online, edge_offline, security_events_24h, ssl_expiring_count}
GET /api/v1/overview/warnings — [{severity, message, link}]
```

**Frontend** — `OverviewView.vue`: metric cards, "Needs attention" panel with fix links, top 5 domains by requests table, recent config snapshots list. No per-domain fetch loops.

`ReportExportButton.vue` — takes title + data object, generates Markdown, copies to clipboard. Add to: Overview, Domain detail, Edge Nodes, Analytics, Settings.

### Files changed
```
core/src/Controllers/OverviewController.php
core/routes/api.php
dash/src/views/OverviewView.vue
dash/src/lib/api/overview.ts
dash/src/components/reports/ReportExportButton.vue
```

### Tests
- **Unit:** `test_overview_service.py` — overview aggregates across two domains; warnings list includes SSL expiry warning when cert < 30 days.
- **Smoke:** `test_overview_api.py` — `GET /api/v1/overview` returns all expected fields; `GET /api/v1/overview/warnings` returns array (may be empty on clean stack).
- **E2E:** `overview_dashboard.spec.ts` — load overview, assert metric cards visible; click "Copy Markdown report" on overview page, assert clipboard contains "# CDNLite Report".

### Acceptance criteria
- Overview loads with two API calls (no domain loops).
- "Needs attention" panel has actionable warnings with links.
- Markdown export copies correctly to clipboard.

### Agent prompt
```
Build the CDNLite overview dashboard and report export.

1. Add GET /api/v1/overview and GET /api/v1/overview/warnings aggregate endpoints.
2. Build OverviewView.vue: metric cards, warnings panel, top-5 domains table, recent config snapshots.
3. No per-domain API loops.
4. Create ReportExportButton.vue: takes title + data, generates Markdown, copies to clipboard.
5. Add to: OverviewView, DomainDetailView, EdgeNodesView, AnalyticsView, SettingsView.
6. Add unit tests for aggregation. Add smoke tests for both endpoints. Add E2E for report copy.
```

---

## Phase 12 — Edge network expanded and platform DNS

**Goal:** Admin can see edge pools, pool membership, and platform edge DNS records.

### What changes

**Database:**
```sql
CREATE TABLE edge_pools (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  mode TEXT NOT NULL CHECK (mode IN ('geo', 'anycast')),
  description TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);

CREATE TABLE edge_pool_members (
  id TEXT PRIMARY KEY,
  pool_id TEXT NOT NULL REFERENCES edge_pools(id) ON DELETE CASCADE,
  edge_node_id TEXT NOT NULL REFERENCES edge_nodes(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  weight INTEGER NOT NULL DEFAULT 100,
  UNIQUE(pool_id, edge_node_id)
);
```

Add to `edge_nodes`: `anycast_enabled BOOLEAN DEFAULT false`, `geo_enabled BOOLEAN DEFAULT true`.

**Backend** — `EdgeDnsPublisher`: per-edge A/AAAA records, aggregate geo LUA A/AAAA record, anycast A/AAAA from settings. Call on edge register/update/disable. Add `GET /api/v1/edges/pools`.

**Frontend** — Expand `EdgeNodesView.vue` → `EdgeNetworkView.vue`: nodes table, pools panel, platform DNS panel showing generated records + sync status. Warning when edge missing IP or has default identity.

### Files changed
```
core/database/schema.sql
core/src/Services/EdgeDnsPublisher.php
core/src/Controllers/EdgeController.php
core/routes/api.php
dash/src/views/EdgeNetworkView.vue
dash/src/lib/api/edges.ts
```

### Tests
- **Unit:** `test_edge_dns_publisher.py` — adding edge with IPv4 → A record in plan; disabling edge → removed from geo LUA aggregate; anycast settings → plain A/AAAA no LUA.
- **Smoke:** `test_edge_pools_api.py` — `GET /api/v1/edges/pools` returns list; edge registration triggers DNS plan update.
- **E2E:** `edge_network.spec.ts` — open Edge Network, assert Nodes table, Pools panel, Platform DNS panel all visible; assert no warning when dev edge has known identity.

### Acceptance criteria
- Every enabled edge with IPv4 has an A record in edge DNS zone.
- Disabling edge removes it from geo LUA aggregate.
- Edge pools table shows member edges.
- Warning when edge has no public IP.

### Agent prompt
```
Expand CDNLite edge management with pools and platform DNS.

1. Add edge_pools and edge_pool_members tables. Add geo_enabled/anycast_enabled to edge_nodes.
2. Create EdgeDnsPublisher:
   - Per-edge A record at edge-{edge_id}.{base_domain} when public_ipv4 present.
   - Per-edge AAAA when public_ipv6 present.
   - Aggregate geo LUA A/AAAA with all geo_enabled edge IPs.
   - Anycast A/AAAA from platform_settings.
3. Call EdgeDnsPublisher on edge register/update/disable.
4. Add GET /api/v1/edges/pools.
5. EdgeNetworkView: nodes table, pools panel, platform DNS records panel.
6. Warn when edge missing public IP.
7. Add unit tests for DNS plan generation. Add smoke tests for pools API. Add E2E for all three panels.
```

---

## Phase 13 — Security events dashboard

**Goal:** Admin can search and filter security events by domain, edge, IP, event type, and time range. Threat summary on overview.

### What changes

The edge agent already pushes security events. This phase adds proper filtering API and a dedicated view.

**Backend** — Add `GET /api/v1/security/events?domain_id=&edge_id=&type=&ip=&from=&to=&limit=` with pagination. Add `GET /api/v1/security/summary?from=&to=` returning `{total, by_type: {waf_block, rate_limit, geo_block}, top_ips[], top_domains[]}`.

**Frontend** — `SecurityEventsView.vue`: filter bar (domain, edge, event type, severity, time range, free-text IP/path search), events table with columns (time, domain, edge, event type, IP, path, action), pagination. Summary cards at top.

Add a "Security Events" count card to `OverviewView.vue` (already has a placeholder from Phase 11 — wire it up).

### Files changed
```
core/src/Controllers/SecurityEventsController.php
core/routes/api.php
dash/src/views/SecurityEventsView.vue
dash/src/lib/api/securityEvents.ts
dash/src/router/index.ts
dash/src/views/OverviewView.vue
```

### Tests
- **Unit:** `test_security_events_service.py` — filter by domain_id returns only that domain's events; filter by IP matches prefix; time range filter excludes out-of-range events.
- **Smoke:** `test_security_events_api.py` — push mock WAF event; `GET /api/v1/security/events?type=waf_block` returns it; `GET /api/v1/security/summary` returns by_type count.
- **E2E:** `security_events.spec.ts` — open Security Events page, assert filter bar and events table visible; apply domain filter, assert table updates.

### Acceptance criteria
- Admin can filter events by domain, edge, event type, IP, and time range.
- Summary shows total events and breakdown by type.
- Pagination works for large event sets.

### Agent prompt
```
Build the CDNLite security events dashboard.

1. Add GET /api/v1/security/events with filters: domain_id, edge_id, type, ip, from, to, limit, offset.
2. Add GET /api/v1/security/summary returning {total, by_type, top_ips, top_domains}.
3. Build SecurityEventsView.vue: filter bar, events table, pagination, summary cards.
4. Wire the security_events_24h card in OverviewView to /api/v1/security/summary.
5. Add unit tests for filter logic. Add smoke tests for both endpoints. Add E2E for filter bar interaction.
```

---

## Phase 14 — Audit log dashboard

**Goal:** Admin can search the audit log by actor, action, resource type, domain, and time. The `audit_log` table already exists.

### What changes

**Backend** — Add `GET /api/v1/audit?actor=&action=&resource_type=&domain_id=&from=&to=&limit=&offset=`. Update `audit_log.site_id` → `audit_log.domain_id` (done in Phase 1 schema but assert it here). Ensure all domain/settings/rate-limit mutations write audit rows (many already do; fill gaps).

**Frontend** — `AuditLogView.vue`: filter bar (actor, action, resource type, domain, time range), events table (time, actor, action, resource type, resource ID, domain, before/after diff button), pagination. Export CSV button.

### Files changed
```
core/src/Controllers/AuditLogController.php
core/routes/api.php
dash/src/views/AuditLogView.vue
dash/src/lib/api/auditLog.ts
dash/src/router/index.ts
```

### Tests
- **Unit:** `test_audit_log_service.py` — update domain routing mode → audit row written with actor and before/after; delete rate limit rule → audit row written.
- **Smoke:** `test_audit_log_api.py` — perform a domain PATCH; `GET /api/v1/audit?action=domain.update` returns the entry; filter by domain_id returns only that domain's entries.
- **E2E:** `audit_log.spec.ts` — open Audit Log page, assert table visible; apply action filter, assert filtered results.

### Acceptance criteria
- Every domain mutation (create/update/delete), settings change, and rule change produces an audit log entry.
- Admin can filter by actor, action, resource type, domain.
- Export CSV downloads a file.

### Agent prompt
```
Build the CDNLite audit log dashboard.

1. Add GET /api/v1/audit with filters: actor, action, resource_type, domain_id, from, to, limit, offset.
2. Ensure all domain, settings, rate-limit, WAF, redirect, page-rule mutations write audit_log rows.
3. Build AuditLogView.vue: filter bar, events table with before/after diff, pagination, CSV export.
4. Add unit tests asserting audit rows written on mutations. Add smoke tests for filter API. Add E2E for page load and filter.
```

---

## Phase 15 — Config snapshot diff and rollback

**Goal:** Admin can view config version history, diff between versions, and roll back to a previous version. The `config_snapshots` table already exists.

### What changes

**Backend** — Add:
```
GET  /api/v1/config/snapshots              — list of {version, generated_at, content_hash}
GET  /api/v1/config/snapshots/{version}    — full snapshot payload
POST /api/v1/config/snapshots/diff         — body: {from_version, to_version} → diff object
POST /api/v1/config/snapshots/{version}/rollback — set config_state.version to this version
POST /api/v1/config/snapshots/rebuild      — force regenerate from current DB state
```

Rollback increments `config_state.version` to trigger edge pull; it does NOT overwrite the snapshot — edges pull the rolled-back version payload.

**Frontend** — `ConfigSnapshotsView.vue`: version list table (version number, timestamp, content hash, size), "View" button opens JSON modal, "Diff" button between two selected versions (unified diff view), "Rollback" button with confirmation, "Rebuild" button.

### Files changed
```
core/src/Controllers/ConfigSnapshotController.php
core/src/Services/ConfigSnapshotService.php
core/routes/api.php
dash/src/views/ConfigSnapshotsView.vue
dash/src/lib/api/configSnapshots.ts
dash/src/router/index.ts
```

### Tests
- **Unit:** `test_config_snapshot_service.py` — rebuild generates snapshot containing all enabled domains; rollback sets config_state to target version; diff between two versions returns changed keys.
- **Smoke:** `test_config_snapshots_api.py` — `GET /api/v1/config/snapshots` returns list; POST diff returns diff object; POST rollback returns 200 and edge pulls rolled-back payload.
- **E2E:** `config_snapshots.spec.ts` — open Config Snapshots page, view latest snapshot JSON, click Diff between two versions, assert diff visible, click Rollback with confirmation.

### Acceptance criteria
- Admin can view any previous config snapshot.
- Diff view shows what changed between two versions.
- Rollback causes edge to pull the selected version.
- Rebuild regenerates from current DB state.

### Agent prompt
```
Add config snapshot diff and rollback to CDNLite.

1. Add list, view, diff, rollback, and rebuild endpoints under /api/v1/config/snapshots.
2. Rollback: set config_state.version to trigger edge pull of the rolled-back snapshot payload.
3. Diff: compare two snapshot payloads and return changed/added/removed keys.
4. Build ConfigSnapshotsView.vue: version list, JSON viewer, diff viewer (unified format), rollback confirmation, rebuild button.
5. Add unit tests for rollback and diff logic. Add smoke tests. Add E2E for diff and rollback flows.
```

---

## Phase 16 — Custom response headers

**Goal:** Admin can define per-domain header rules (add/remove/replace headers on response). Common CDN use case for security headers, CORS, and custom branding.

### What changes

**Database:**
```sql
CREATE TABLE domain_header_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  priority INTEGER NOT NULL DEFAULT 100,
  operation TEXT NOT NULL,        -- 'set', 'remove', 'append'
  header_name TEXT NOT NULL,
  header_value TEXT NULL,         -- null for 'remove'
  path_pattern TEXT NOT NULL DEFAULT '/*',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

**Backend** — CRUD API: `GET/POST/PATCH/DELETE /api/v1/domains/{domainId}/headers`. Include header rules in config snapshot. Validate header name and operation.

**Edge (OpenResty/Lua)** — Read header rules from config snapshot. Apply in `header_filter_by_lua_block` sorted by priority.

**Frontend** — Domain Header Rules tab (add to DomainDetailView): rules table with operation, header name, value, path pattern, priority. Edit drawer, delete confirmation. Quick-add buttons for common security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options).

### Files changed
```
core/database/schema.sql
core/src/Controllers/HeaderRulesController.php
core/src/Services/ConfigSnapshotService.php
core/routes/api.php
edge/openresty/lua/header_rules.lua
edge/openresty/nginx.conf
dash/src/views/domain-tabs/DomainHeadersTab.vue
dash/src/lib/api/headerRules.ts
```

### Tests
- **Unit:** `test_header_rules.py` — 'set' operation adds header; 'remove' operation removes it; 'append' adds if not present; priority ordering respected when two rules match same header.
- **Smoke:** `test_header_rules_api.py` — create HSTS rule; `GET .../headers` returns it; config snapshot includes rule; delete rule and assert absent from snapshot.
- **E2E:** `header_rules.spec.ts` — open domain Headers tab, click "Add HSTS" quick-add, save, assert rule in table; make a request through edge and assert `Strict-Transport-Security` header in response.

### Acceptance criteria
- Header rules apply to edge responses in correct priority order.
- Quick-add buttons exist for HSTS, CSP, X-Frame-Options, X-Content-Type-Options.
- Rules scoped by path pattern.
- Config snapshot includes header rules.

### Agent prompt
```
Add per-domain custom response header rules to CDNLite.

1. Add domain_header_rules table: domain_id, enabled, priority, operation (set/remove/append), header_name, header_value, path_pattern.
2. CRUD API: GET/POST/PATCH/DELETE /api/v1/domains/{domainId}/headers.
3. Include header rules in config snapshot under domain section.
4. In OpenResty, add header_filter_by_lua_block that reads header rules from config and applies them sorted by priority.
5. Domain Headers tab: rules table, edit drawer, delete confirmation, quick-add buttons for common security headers.
6. Add unit tests for Lua rule application. Add smoke tests for CRUD. Add E2E for quick-add and verify header in response.
```

---

## Phase 17 — IP access control

**Goal:** Per-domain IP allowlist and blocklist with CIDR support. Managed in dashboard, enforced in Lua.

### What changes

**Database:**
```sql
CREATE TABLE domain_ip_rules (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  enabled BOOLEAN NOT NULL DEFAULT true,
  rule_type TEXT NOT NULL,   -- 'allow', 'block'
  cidr TEXT NOT NULL,
  description TEXT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  CHECK (rule_type IN ('allow', 'block'))
);
```

**Backend** — CRUD API: `GET/POST/PATCH/DELETE /api/v1/domains/{domainId}/ip-rules`. Validate CIDR format. Include in config snapshot. Logic: if any allow rules exist, only those CIDRs are allowed (default deny); block rules always deny matched IPs.

**Edge (OpenResty/Lua)** — Read ip_rules from config snapshot. Use `ngx.var.remote_addr` and CIDR match in `access_by_lua_block`. Return 403 for blocked/unallowed IPs.

**Frontend** — Domain IP Rules tab: rules table with type badge (Allow/Block), CIDR, description, enabled toggle. Add rule form, delete confirmation. Import from text (one CIDR per line).

### Files changed
```
core/database/schema.sql
core/src/Controllers/IpRulesController.php
core/src/Services/ConfigSnapshotService.php
core/routes/api.php
edge/openresty/lua/ip_rules.lua
dash/src/views/domain-tabs/DomainIpRulesTab.vue
dash/src/lib/api/ipRules.ts
```

### Tests
- **Unit:** `test_ip_rules_lua.py` — blocked CIDR returns 403; allowed CIDR passes; non-matching block CIDR passes; non-matching allow CIDR returns 403 when allow list active.
- **Smoke:** `test_ip_rules_api.py` — create block rule for `192.0.2.0/24`; config snapshot includes rule; CIDR validation rejects `not-a-cidr`.
- **E2E:** `ip_rules.spec.ts` — open domain IP Rules tab, add a block rule for a CIDR, save, assert in table, delete with confirmation.

### Acceptance criteria
- Block rule returns 403 for matching IPs.
- Allow-list mode (any allow rule present) denies all non-matching IPs.
- CIDR validation rejects malformed input.
- Bulk import via text works (one CIDR per line).

### Agent prompt
```
Add per-domain IP access control to CDNLite.

1. Add domain_ip_rules table: domain_id, enabled, rule_type (allow/block), cidr, description.
2. Validate CIDR format on create/update.
3. CRUD API under /api/v1/domains/{domainId}/ip-rules.
4. Include ip_rules in config snapshot.
5. OpenResty access_by_lua_block: read ip_rules, match remote_addr against CIDRs, block if rule_type=block or no allow match when allow rules exist.
6. Domain IP Rules tab: type badge, CIDR, description, toggle, bulk import (text area, one CIDR per line).
7. Add unit tests for Lua CIDR matching. Add smoke tests for CRUD + validation. Add E2E for tab interaction.
```

---

## Phase 18 — SSL certificate automation

**Goal:** ACME-based auto-renewal works, expiry is monitored, and cert status is actionable in the dashboard. The `ssl_acme_accounts` and `ssl_certificates` tables already exist.

### What changes

**Backend** — Add `CertRenewalScheduler` as a recurring CLI command (`cdn:ssl:renew-due`). Checks certificates where `renewal_due_at < now + 14 days` and `status != 'revoked'`. Triggers ACME DNS-01 challenge using PowerDNS API (already integrated). Updates cert record on success/failure.

Add:
```
POST /api/v1/domains/{domainId}/ssl/request-cert   — trigger ACME issuance
POST /api/v1/domains/{domainId}/ssl/renew           — force renewal
GET  /api/v1/domains/{domainId}/ssl/acme-status     — ACME challenge progress
```

Add readiness check: any cert expiring in < 14 days → warning in readiness API (Phase 5).

**Frontend** — SSL tab improvements (Phase 10 already has the tab):
- Auto-renew toggle.
- "Request Certificate" button if no cert exists.
- "Force Renew" button.
- ACME challenge status progress (pending DNS-01 → verifying → issued).
- Renewal history list.
- Readiness card warning links to SSL tab when cert expiring.

**Docker Compose** — Add a `cdn:ssl:renew-due` cron entry (or a separate `ssl-scheduler` service running the command every hour).

### Files changed
```
core/src/Services/CertRenewalService.php
core/src/Console/Commands/SslRenewDueCommand.php
core/src/Controllers/SslController.php
core/routes/api.php
core/src/Services/ReadinessService.php
dash/src/views/domain-tabs/DomainSslTab.vue
docker-compose.yml
```

### Tests
- **Unit:** `test_cert_renewal_service.py` — cert with `renewal_due_at` in past triggers renewal attempt; cert with `not_after` > 90 days does not trigger; ACME failure updates cert status to `error`.
- **Smoke:** `test_ssl_api.py` — `POST .../ssl/request-cert` returns 202 with ACME status; `GET .../ssl/acme-status` returns progress object; `POST .../ssl/renew` returns 202.
- **E2E:** `ssl_tab.spec.ts` — open SSL tab, assert cert status shown; assert auto-renew toggle visible; click "Request Certificate" on domain with no cert, assert ACME progress visible.

### Acceptance criteria
- Certs renewing within 14 days automatically get renewal triggered.
- ACME challenge status is visible in real time.
- Readiness warning fires for expiring certs.
- Force renew works from dashboard.

### Agent prompt
```
Add SSL certificate automation to CDNLite.

1. Create CertRenewalService: check renewal_due_at, trigger ACME DNS-01 using PowerDNS API, update cert record.
2. Add cdn:ssl:renew-due artisan command to be run hourly.
3. Add POST /api/v1/domains/{domainId}/ssl/request-cert, /renew, GET /acme-status.
4. Add cert expiry warning (< 14 days) to ReadinessService.
5. SSL tab: auto-renew toggle, Request Certificate button, Force Renew button, ACME progress, renewal history.
6. Add ssl-scheduler service or cron entry to docker-compose.yml.
7. Add unit tests for renewal eligibility logic. Add smoke tests for cert endpoints. Add E2E for SSL tab actions.
```

---

## Phase 19 — Origin health monitoring

**Goal:** CDNLite actively checks origin health per domain. If the origin is unreachable, the domain shows a warning and edge can failover to a backup origin.

### What changes

**Database:**
```sql
CREATE TABLE domain_origins (
  id TEXT PRIMARY KEY,
  domain_id TEXT NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  scheme TEXT NOT NULL DEFAULT 'http',
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 80,
  is_primary BOOLEAN NOT NULL DEFAULT true,
  health_check_path TEXT NOT NULL DEFAULT '/',
  health_check_interval_seconds INTEGER NOT NULL DEFAULT 30,
  health_check_timeout_seconds INTEGER NOT NULL DEFAULT 5,
  health_status TEXT NOT NULL DEFAULT 'unknown',
  last_check_at BIGINT NULL,
  last_error TEXT NULL,
  enabled BOOLEAN NOT NULL DEFAULT true,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
);
```

Migrate `domains.origin_host/port/scheme` into `domain_origins` as primary origin on Phase 1 bootstrap.

**Backend** — `OriginHealthService`: HTTP GET to `health_check_path` with timeout. Update `health_status` (healthy/unhealthy/unknown). Add `cdn:origins:health-check` command (runs every 30s). Include primary origin and backup origin in config snapshot for edge failover. Add readiness warning when primary origin unreachable.

API:
```
GET   /api/v1/domains/{domainId}/origins
POST  /api/v1/domains/{domainId}/origins
PATCH /api/v1/domains/{domainId}/origins/{originId}
DELETE /api/v1/domains/{domainId}/origins/{originId}
POST  /api/v1/domains/{domainId}/origins/{originId}/check
```

**Edge (OpenResty/Lua)** — Config snapshot includes `{primary_origin, backup_origin}`. If primary upstream returns 502/503/504, try backup origin if configured. Add `X-CDNLITE-Origin: primary|backup` response header.

**Frontend** — Domain Origins tab (add to DomainDetailView): origins table (primary/backup badge, host, port, health status, last checked), health status indicator (green/red/grey), add backup origin form, manual health check button.

### Files changed
```
core/database/schema.sql
core/src/Services/OriginHealthService.php
core/src/Console/Commands/OriginsHealthCheckCommand.php
core/src/Controllers/OriginController.php
core/src/Services/ConfigSnapshotService.php
core/src/Services/ReadinessService.php
core/routes/api.php
edge/openresty/lua/proxy.lua
dash/src/views/domain-tabs/DomainOriginsTab.vue
dash/src/lib/api/origins.ts
docker-compose.yml
```

### Tests
- **Unit:** `test_origin_health_service.py` — healthy origin → status `healthy`; 503 response → status `unhealthy`; timeout → status `unhealthy`; unhealthy primary → readiness warning fires.
- **Smoke:** `test_origins_api.py` — add backup origin; `GET .../origins` returns both; `POST .../origins/{id}/check` triggers immediate check and returns result.
- **E2E:** `origins_tab.spec.ts` — open domain Origins tab, assert primary origin and health status shown; click Check Now, assert status updates; add backup origin, save, assert in table.

### Acceptance criteria
- Origin health checked every 30s automatically.
- Unhealthy primary origin shows warning in readiness card.
- Edge attempts backup origin when primary returns 5xx.
- `X-CDNLITE-Origin` header shows which origin served the request.

### Agent prompt
```
Add origin health monitoring and failover to CDNLite.

1. Add domain_origins table. Migrate existing domain origin fields to domain_origins on bootstrap.
2. Create OriginHealthService: HTTP health check with configurable path, interval, timeout.
3. Add cdn:origins:health-check artisan command (run every 30s via scheduler or compose service).
4. Include primary and backup origins in config snapshot for edge failover.
5. In OpenResty proxy.lua: on 502/503/504 from primary, retry with backup origin if configured. Add X-CDNLITE-Origin header.
6. Add origins CRUD API and POST .../check endpoint.
7. Domain Origins tab: origins table with health status, manual check button, add backup origin form.
8. Add unit tests for health check logic. Add smoke tests for CRUD + manual check. Add E2E for origins tab.
```

---

## Phase 20 — CLI completeness

**Goal:** Every major admin operation is available via CLI. Useful for headless/automated ops and debugging without the dashboard.

### What changes

**Backend** — Add or complete CLI commands covering all phases:

```
cdn:domain:list
cdn:domain:create --zone_name= --display_name=
cdn:domain:show --id=
cdn:domain:activate --id=
cdn:domain:verify-ns --id=
cdn:domain:delete --id= --force

cdn:dns:list --domain_id=
cdn:dns:create --domain_id= --type= --name= --content= --ttl= --proxied=
cdn:dns:delete --id=

cdn:settings:get --group=
cdn:settings:set --key= --value=
cdn:settings:test-powerdns

cdn:edge:list
cdn:edge:show --id=
cdn:edge:disable --id=
cdn:edge:register-token --edge_id= --token=   (already exists)

cdn:cache:purge --domain_id= --type=all|url|prefix --value=
cdn:cache:settings --domain_id=

cdn:ssl:list --domain_id=
cdn:ssl:request --domain_id=
cdn:ssl:renew-due                               (added in Phase 18)

cdn:analytics:summary --domain_id= --bucket= --from= --to=

cdn:origins:health-check                        (added in Phase 19)
cdn:origins:list --domain_id=

cdn:readiness:check

cdn:db:fresh --force                            (destructive reset)
cdn:bootstrap:fresh --seed-settings=dev
```

All commands output JSON by default. Add `--format=table` for human-readable output.

### Files changed
```
core/src/Console/Commands/ (new commands per area)
core/artisan
```

### Tests
- **Unit:** `test_cli_commands.py` — `cdn:domain:list` returns JSON array; `cdn:domain:create` inserts row; `cdn:settings:set` updates DB; `cdn:cache:purge --type=all` inserts purge request; `cdn:readiness:check` returns structured output.
- **Smoke:** `test_cli_smoke.sh` — run each command against a running stack, assert zero exit code and valid JSON output.
- **E2E:** Not applicable for CLI — covered by smoke script in CI.

### Acceptance criteria
- Every command exits 0 on success, non-zero on error.
- All commands output valid JSON by default.
- `--format=table` gives human-readable output.
- `cdn:db:fresh --force` resets and re-bootstraps cleanly.
- `cdn:readiness:check` output matches `/api/v1/readiness` API response.

### Agent prompt
```
Complete CDNLite CLI coverage for all admin operations.

1. Add artisan commands for: domain CRUD, domain verify-ns, domain activate, dns CRUD, settings get/set/test, edge list/disable, cache purge, ssl list/request, analytics summary, origins health-check/list, readiness check, db:fresh, bootstrap:fresh.
2. All commands output JSON by default. Add --format=table option for human-readable output.
3. cdn:db:fresh --force drops all CDNLite tables, recreates schema, inserts default settings, optionally seeds a dev edge and admin user.
4. cdn:readiness:check output matches the /api/v1/readiness API response structure.
5. Add pytest test for each command. Add smoke.sh that runs every command and asserts zero exit code.
```

---

## Implementation order

| Phase | What | Key dependency |
|---|---|---|
| 1 | Schema + naming reset | None — must be first |
| 2 | Cache metrics fix | Phase 1 (domain_id in rollups) |
| 3 | Rate limit full CRUD | Phase 1 (domain_id FK) |
| 4 | Edge identity fix | Independent |
| 5 | Readiness API + cards | Phase 4 (identity check) |
| 6 | Settings dashboard | Phase 1 (schema) |
| 7 | Analytics by domain | Phases 1 + 2 (domain_id + cache_status) |
| 8 | Domain onboarding + NS verify | Phase 6 (nameserver settings) |
| 9 | DNS routing geo/anycast | Phases 6 + 12 (edge DNS settings + pools) |
| 10 | Domain feature tabs | Phase 1 (domain model) |
| 11 | Overview + report exports | Phases 2, 5, 7, 10 (data sources) |
| 12 | Edge network + platform DNS | Phase 9 (routing) |
| 13 | Security events dashboard | Phase 1 (domain_id on events) |
| 14 | Audit log dashboard | Phase 1 (domain rename) |
| 15 | Config snapshot diff + rollback | Phase 1 (clean snapshots) |
| 16 | Custom response headers | Phase 10 (domain tabs pattern) |
| 17 | IP access control | Phase 10 (domain tabs pattern) |
| 18 | SSL certificate automation | Phase 10 (SSL tab) |
| 19 | Origin health monitoring | Phase 8 (domain model mature) |
| 20 | CLI completeness | All prior phases |

---

## Definition of done

- No code uses `site_id`, `SiteService`, `/api/v1/sites`, or `SitesView`.
- Cache hit ratio shows real HIT/MISS values.
- Rate limits have create, edit, delete UI.
- `X-CDNLITE-Edge` matches configured `EDGE_ID`.
- Readiness badges are clickable with structured warnings and fix links.
- PowerDNS and nameserver settings configurable from dashboard.
- Analytics filterable by domain.
- Domain onboarding requires only a domain name.
- Proxied DNS records publish LUA (geo) or plain A/CNAME (anycast).
- Domain detail has tabs for all features with full CRUD.
- Security events filterable by domain/edge/IP/type.
- Audit log searchable and exportable.
- Config snapshots viewable, diffable, and rollbackable.
- Custom response headers configurable per domain.
- IP allowlist/blocklist configurable per domain.
- SSL auto-renewal works via scheduler.
- Origin health monitored; edge attempts backup on 5xx.
- Every admin operation available via CLI with JSON output.
- All phases have unit, smoke, and E2E tests passing in CI.
- `docker compose down -v && docker compose up --build` produces a clean working stack.
