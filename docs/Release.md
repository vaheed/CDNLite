# CDNLite v1.0.0 Production Cleanup Prompt

https://github.com/vaheed/CDNLite

## Goal

Prepare CDNLite for a clean, production-ready `v1.0.0` release.

## Project Context

CDNLite is a lightweight modular CDN platform with:

- PHP control plane
- PostgreSQL database
- OpenResty/Lua edge proxy
- Shell-based edge agent
- Vue 3 / TypeScript dashboard
- Docker Compose local stack
- pytest, Vitest, Playwright, smoke, and E2E test coverage

## Hard Release Policy

This is a greenfield reset. There is no backward compatibility requirement.

The database may be dropped and recreated.

Do not preserve legacy APIs, legacy names, compatibility aliases, duplicate flows, obsolete routes, obsolete migrations, dead UI, or deprecated config shapes.

## Primary Objective

Clean up the whole project, remove unused and duplicated code, eliminate all backward compatibility, finish every unfinished roadmap item, and make the repository ready for release `v1.0.0`.

---

## Non-Negotiable Cleanup Requirements

### 1. Remove all legacy “site” vocabulary and compatibility

There must be no active use of:

- `site_id`
- `SiteService`
- `SiteController`
- `SiteRepository`
- `/api/v1/sites`
- `SitesView`
- User-facing “Site” text unless it is historical documentation being intentionally removed

Existing compatibility routes such as `/api/v1/sites/...` must be deleted, not redirected.

### 2. Remove unused, duplicate, and obsolete implementation

Delete unused:

- PHP classes
- Controllers
- Services
- Repositories
- Commands
- Tests
- Vue views
- API clients
- Lua files
- Shell scripts
- Docs
- Env variables
- Docker/CI artifacts

Remove duplicated CRUD paths, duplicate settings paths, duplicate SSL paths, old singular/plural route variants, and any route kept only for compatibility.

Remove obsolete migration/backfill logic that exists only for old pre-1.0 schemas.

Prefer one canonical schema and one canonical API surface.

### 3. Eliminate backward compatibility

Do not:

- Accept old payload shapes
- Silently translate old fields
- Keep compatibility aliases
- Support old route paths

Old inputs should return clear validation errors only where useful for operator feedback.

### 4. Finish all roadmap items required for v1.0.0

Complete and verify:

- Phase 16: custom response headers
- Phase 17: IP access control
- Phase 19: origin health monitoring and failover, unless superseded by the Phase 21 origin refactor
- Phase 20: CLI completeness
- Phase 21: origin configuration refactor
- Any partially implemented roadmap item

Every completed item must be:

- Implemented
- Tested
- Documented
- Wired through backend, edge, dashboard, and CLI where applicable

### 5. Reconcile roadmap inconsistencies

The roadmap status table and later phase sections must agree.

Update `docs/ROADMAP.md` so the following are internally consistent:

- Status table
- Implementation order
- Completion records
- Acceptance criteria
- Release checklist

Remove stale “planned” or “in progress” text for completed work.

Add a final `v1.0.0` release checklist.

---

## Technical Release Scope

## A. Schema and Data Model

Produce one clean `v1.0.0` PostgreSQL schema.

Remove compatibility `ALTER TABLE` statements when they exist only to support old schemas.

Remove `origin_port` completely from:

- UI
- API
- Database
- Config
- Validation
- Docs
- Tests
- Examples

Origin configuration must be DNS-record scoped, not globally domain-scoped.

Each proxied DNS record must support:

- `origin_host`
- proxy on/off
- autodetected origin scheme/status
- origin TLS verification mode: `verify` or `ignore`
- per-record geo origin/routing options

Do not allow custom origin ports.

Origin autodetection behavior:

1. Try HTTPS on port `443` first.
2. If unavailable, fall back to HTTP on port `80`.
3. Support HTTPS origin connections with invalid, self-signed, expired, or hostname-mismatched certificates when TLS verification mode is `ignore`.
4. Keep relaxed verification scoped only to proxy-to-origin connections, never client-facing TLS.

---

## B. Backend API

Keep only canonical `/api/v1/domains/...` and related v1 APIs.

Delete all `/api/v1/sites/...` routes.

Delete compatibility aliases and duplicate singular endpoints where plural canonical endpoints exist, unless the roadmap explicitly says the singular endpoint is still canonical.

Strictly validate all request bodies.

Return clear `4xx` validation errors for unsupported legacy fields such as `origin_port`.

Ensure all mutating operations write audit log rows.

Ensure all config-impacting operations regenerate or mark config snapshots appropriately.

Ensure readiness warnings cover:

- Missing production API token
- Stale config snapshot
- Unhealthy edge
- Expiring certificate
- Unhealthy origin
- Invalid critical settings

---

## C. Edge / OpenResty / Lua

Apply config snapshots strictly using the canonical `v1.0.0` shape.

Implement and enforce:

- Custom response header rules
- IP allow/block rules with CIDR support
- Per-record origin selection
- Origin TLS verification behavior
- Origin autodetection and status reporting
- Backup/failover behavior if origin health monitoring remains in final design

Emit useful headers:

- `X-CDNLITE-Edge`
- `X-CDNLITE-Cache`
- `X-CDNLITE-Origin`, or an equivalent canonical origin status header

Ensure metrics and security events include the real configured edge identity.

---

## D. Dashboard

Remove all:

- Dead views
- Old route names
- Old API clients
- Old text labels
- Unused components

Domain detail must expose all final feature tabs with full CRUD:

- DNS records
- Routing / geo routing
- Cache rules/settings/purge
- Redirects
- Rate limits
- WAF/page/security rules
- SSL
- Custom response headers
- IP access control
- Origins / origin status, if retained after Phase 21 refactor

Every table must have:

- Loading state
- Empty state
- Error state
- Success state
- Delete confirmation
- Validation state

No dev-only tokens or edge tools should be enabled by default in production builds.

Dashboard must not compile production secrets into browser assets.

---

## D1. Full User Journey and UX Redesign

Redesign the complete admin/operator user journey for `v1.0.0`.

The goal is not only to make features available, but to make CDNLite understandable, guided, consistent, and ready for real operators.

### User Journey Goals

Design the product around the full lifecycle of a CDN operator:

1. First visit and login
2. Initial readiness check
3. First domain onboarding
4. Nameserver delegation guidance
5. DNS record creation
6. Proxy enablement
7. Origin configuration
8. Cache configuration
9. SSL certificate setup
10. Force HTTPS enablement when safe
11. Security rules setup
12. Rate limits / WAF / IP access controls
13. Custom response headers
14. Geo routing / edge routing
15. Usage analytics review
16. Security event investigation
17. Audit log review
18. Config snapshot diff / rollback
19. Edge node monitoring
20. Production readiness validation
21. Troubleshooting and recovery

### Required UX Deliverables

Create or update a complete UX flow for:

- Empty-state dashboard for a new install
- First-run setup checklist
- Domain onboarding wizard
- DNS delegation verification screen
- DNS records screen with proxy on/off explanation
- Origin setup flow with automatic scheme detection status
- SSL setup flow with clear ACME progress
- Force HTTPS flow with safe precondition checks
- Cache rules and purge flow
- Security rules flow
- IP allow/block flow
- Custom headers flow
- Analytics overview flow
- Readiness warnings with direct fix links
- Error recovery and troubleshooting flows

### UX Quality Requirements

Every major screen must provide:

- Clear page title
- Short explanation of purpose
- Primary action
- Empty state
- Loading state
- Error state
- Success feedback
- Validation feedback
- Destructive action confirmation
- Links to relevant docs
- Consistent terminology
- Accessible labels and keyboard-friendly controls

### Product Navigation Requirements

Redesign navigation so operators can clearly understand:

- What is global/platform-level
- What is domain-level
- What is DNS-record-level
- What is edge-level
- What is security/operations-level

Recommended navigation structure:

- Overview
- Domains
- DNS / Routing
- Edge Network
- Analytics
- Security Events
- Audit Log
- Config Snapshots
- Settings
- Readiness
- Documentation / Help

Domain detail should be organized by tabs or sections:

- Overview
- DNS Records
- Origins
- SSL
- Cache
- Redirects
- Page Rules
- Rate Limits
- WAF / Security
- IP Access
- Headers
- Analytics
- Events
- Audit

### Design System Requirements

Create or normalize a lightweight internal design system:

- Buttons
- Inputs
- Selects
- Tables
- Badges
- Cards
- Tabs
- Modals
- Drawers
- Toasts
- Empty states
- Error banners
- Code blocks
- Diff viewers
- Status indicators
- Confirmation dialogs

Ensure visual consistency across all dashboard pages.

Do not leave each feature tab with unrelated layouts or inconsistent behavior.

### UX Acceptance Criteria

The dashboard is ready for `v1.0.0` only when:

- A new operator can complete first domain onboarding without reading source code.
- Every readiness warning links to the exact screen or documentation needed to fix it.
- Every destructive operation has confirmation.
- Every async action shows progress or completion.
- Every form has validation.
- Empty states explain what to do next.
- Feature names match the API, CLI, docs, and roadmap.
- No legacy “site” terminology remains.
- No production secret is exposed in the browser.
- All UX flows are covered by Playwright tests where practical.

---

## D2. GitHub Pages Documentation Site

Prepare the full documentation set for GitHub Pages.

The documentation must be complete enough for users, operators, and contributors to understand, install, run, operate, troubleshoot, and extend CDNLite without reading implementation code.

### GitHub Pages Goal

Create a polished static documentation site under `docs/` that works directly with GitHub Pages.

The docs should be navigable from `docs/README.md` and `docs/index.md`.

If the repository already uses plain Markdown, keep it simple and GitHub Pages-compatible. Do not introduce a heavy documentation framework unless it is clearly justified.

### Required Documentation Structure

Ensure the following docs exist, are accurate, and link to each other:

- `docs/index.md`
- `docs/README.md`
- `docs/getting-started.md`
- `docs/quick-start.md`
- `docs/installation.md`
- `docs/local-development.md`
- `docs/production-deployment.md`
- `docs/configuration.md`
- `docs/environment-variables.md`
- `docs/architecture.md`
- `docs/project-overview.md`
- `docs/user-journey.md`
- `docs/dashboard-guide.md`
- `docs/domain-onboarding.md`
- `docs/dns-and-powerdns.md`
- `docs/dns-records.md`
- `docs/origin-configuration.md`
- `docs/ssl-certificates.md`
- `docs/cache.md`
- `docs/routing-and-geo.md`
- `docs/edge-runtime.md`
- `docs/edge-agent.md`
- `docs/edge-network.md`
- `docs/custom-headers.md`
- `docs/ip-access-control.md`
- `docs/waf-and-security-rules.md`
- `docs/rate-limits.md`
- `docs/redirects-and-page-rules.md`
- `docs/usage-and-metrics.md`
- `docs/analytics.md`
- `docs/security-events.md`
- `docs/audit-log.md`
- `docs/config-snapshots.md`
- `docs/api-reference.md`
- `docs/cli-reference.md`
- `docs/testing-and-ci.md`
- `docs/operations-runbook.md`
- `docs/troubleshooting.md`
- `docs/security.md`
- `docs/production-readiness.md`
- `docs/release-process.md`
- `docs/contributing.md`
- `docs/glossary.md`
- `docs/ROADMAP.md`

### Documentation Content Requirements

Each documentation page must include:

- Clear purpose
- Who should read it
- Prerequisites, if any
- Step-by-step usage
- Commands or API examples where relevant
- Expected output where useful
- Troubleshooting notes
- Links to related pages

### API Documentation Requirements

`docs/api-reference.md` must document every public API endpoint that remains in `v1.0.0`.

For each endpoint include:

- Method
- Path
- Auth requirement
- Request body
- Query parameters
- Response shape
- Error responses
- Example curl command
- Notes about side effects such as config snapshot regeneration or audit logging

Removed compatibility routes must not appear.

### CLI Documentation Requirements

`docs/cli-reference.md` must document every CLI command.

For each command include:

- Purpose
- Usage
- Required flags
- Optional flags
- JSON output example
- Table output example where supported
- Exit code behavior
- Destructive action warnings

### Operations Documentation Requirements

`docs/operations-runbook.md` must cover:

- Clean startup
- Clean reset
- Backup and restore
- Domain onboarding
- DNS delegation verification
- Edge registration
- Config snapshot rollback
- SSL renewal
- Cache purge
- Origin failure handling
- Security event investigation
- Audit review
- Readiness failure response
- Production release checklist

### GitHub Pages Requirements

Make the docs ready for GitHub Pages:

- `docs/index.md` acts as the homepage
- All internal links are relative and valid
- Images are stored under `docs/assets/` or another documented docs path
- Screenshots are optimized and referenced correctly
- No broken links
- No references to deleted routes, old fields, or obsolete terms
- The docs can be browsed directly on GitHub and through GitHub Pages
- Add or update `.nojekyll` if needed
- Add a simple docs navigation index
- Add a docs validation script if practical

### Documentation Validation

Add a docs verification step to CI or a local script.

The script should check:

- Broken internal Markdown links
- Missing referenced images
- Stale references to `/api/v1/sites`
- Stale references to `site_id`
- Stale references to `origin_port`
- Missing docs for public API routes
- Missing docs for CLI commands

### Documentation Acceptance Criteria

Documentation is ready for GitHub Pages only when:

- `docs/index.md` is a useful landing page.
- Every major feature has a dedicated documentation page.
- API docs match actual routes.
- CLI docs match actual commands.
- Screenshots and diagrams load correctly.
- Internal links work.
- Removed compatibility terms do not appear.
- A new user can go from zero to a working local CDNLite stack using only the docs.
- An operator can prepare a production deployment using only the docs.

---

## E. CLI

Complete CLI coverage for every major admin operation.

All commands must output JSON by default and support `--format=table` where useful.

Required command groups:

- Domain CRUD, activate, verify nameservers, delete
- DNS record CRUD
- Settings get/set/test
- Edge list/show/disable/token operations
- Cache purge/settings
- SSL list/request/renew/renew-due
- Analytics summary
- Custom headers CRUD
- IP rules CRUD
- Origin status/check if retained
- Readiness check
- DB fresh/reset with explicit `--force`
- Bootstrap fresh/dev seed

CLI quality rules:

- Exit `0` on success
- Exit non-zero on errors
- Output valid JSON by default
- Use a consistent error format
- Do not run destructive commands without explicit `--force`

---

## F. Documentation

Update all docs for `v1.0.0`:

- `README.md`
- `docs/README.md`
- `docs/ROADMAP.md`
- `docs/api-reference.md`
- `docs/cli-reference.md`
- `docs/configuration.md`
- `docs/production-readiness.md`
- `docs/security.md`
- `docs/operations-runbook.md`
- `docs/testing-and-ci.md`
- Env examples
- Docker/Compose docs

Documentation must not mention removed compatibility routes or removed fields.

Every documented command and endpoint must exist.

Every existing public endpoint and command must be documented.

---

## G. Tests and CI

Add and update tests for every changed behavior.

Required validation commands:

```bash
docker compose config
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
./ci/smoke.sh
./ci/e2e.sh
```

Add or update tests for:

- No `/api/v1/sites` routes
- No active `site_id` vocabulary
- Custom response header rules
- IP allow/block CIDR rules
- Origin autodetection:
  - HTTPS/443 success uses HTTPS
  - HTTPS/443 failure falls back to HTTP/80
  - Invalid/self-signed HTTPS origin accepted when verification mode is `ignore`
  - HTTPS validation enforced when verification mode is `verify`
  - `origin_port` rejected everywhere
- DNS record proxy on/off behavior
- Per-DNS-record geo origin options
- Audit log coverage for all mutations
- Config snapshot regeneration and rollback
- CLI JSON output for every command
- Clean Compose rebuild

Clean rebuild command:

```bash
docker compose down -v
docker compose up --build
```

---

## H. Security and Production Hardening

Production examples must disable:

- Bootstrap admin
- Bootstrap edge token

Development tokens and default admin credentials must be clearly local-only.

Validate CORS configuration.

Ensure secrets are not committed or compiled into browser assets.

Ensure edge HMAC auth remains required for:

- Edge registration
- Heartbeat
- Config fetch
- Usage ingest
- Security event ingest

Ensure origin TLS verification `ignore` mode is scoped only to origin-to-proxy connections.

Ensure dashboard auth/session behavior is documented honestly.

---

## Implementation Strategy

1. Inspect the full repository before editing.
2. Build an inventory:
   - Active routes
   - CLI commands
   - Database tables/columns
   - Vue routes/views/components
   - Lua modules
   - Tests
   - Docs
   - Env variables
3. Identify:
   - Unused files
   - Duplicated code paths
   - Backward compatibility routes/fields
   - Roadmap gaps
   - Failing or missing tests
4. Make changes in small coherent commits or patches:
   - Cleanup/removal
   - Schema/API canonicalization
   - Roadmap feature completion
   - Dashboard completion
   - CLI completion
   - Tests
   - Docs
   - Release metadata
5. Prefer deleting obsolete code over layering abstractions.
6. Keep implementation simple, explicit, and consistent with current project structure.
7. Avoid unrelated rewrites that do not support `v1.0.0` readiness.

---

## Release Acceptance Criteria

The repository is release-ready only when all of the following are true:

- No active code path contains `site_id`, `SiteService`, `/api/v1/sites`, or `SitesView`.
- No backward compatibility routes or payload aliases remain.
- No `origin_port` support remains.
- Every roadmap item required for `v1.0.0` is implemented or deliberately removed from the `v1.0.0` scope with docs updated.
- Dashboard exposes all final domain features with working CRUD.
- Edge enforces final config snapshot behavior.
- CLI covers all major admin operations.
- Docs match code exactly.
- All tests pass.
- Clean Compose rebuild works:

```bash
docker compose down -v
docker compose up --build
```

---

## Required Deliverables

Return the following:

1. Summary of all major changes.
2. List of files deleted.
3. List of files changed.
4. List of new files added.
5. Route inventory before/after.
6. CLI inventory before/after.
7. Schema diff summary.
8. Roadmap completion summary.
9. Full user journey / UX redesign summary.
10. Dashboard navigation and screen inventory.
11. GitHub Pages documentation map.
12. Documentation validation results.
13. Test results with exact commands and pass/fail output.
14. Remaining risks or intentionally deferred items, if any.
15. Final recommended release commit message.
16. Final recommended tag command:

```bash
git tag -a v1.0.0 -m "CDNLite v1.0.0"
git push origin v1.0.0
```

---

## Hard Prohibitions

Do not stop after only documentation changes.

Do not produce a partial roadmap-only patch.

Do not preserve backward compatibility.

Do not claim production readiness unless all validation commands pass.

Do not leave compatibility shims, duplicate routes, dead UI, or obsolete schema fields behind.
