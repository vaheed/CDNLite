# CDNLite Full Project Migration Roadmap

This roadmap tracks the fresh-install Laravel rebuild from 0 to 100 percent.
CDNLite is pre-1.0 and fresh-install-only: old database migrations, deprecated
environment aliases, legacy request fields, legacy API aliases, and fallback
paths to the old core are intentionally not preserved.

The goal is not to wrap the old project. The goal is to finish a Laravel-native
control plane, keep the dashboard, edge, DNSGeo, deployment, CI, and docs aligned,
then remove the old PHP runtime once it has no remaining production ownership.

## Progress Summary

Current estimated migration progress: **24% complete**.

| Percent | Status | Milestone |
| --- | --- | --- |
| 0% | Complete | Baseline and fresh-install direction agreed. |
| 5% | Complete | Laravel project foundation, Docker build path, PostgreSQL test path. |
| 10% | Complete | Laravel HTTP entrypoint, auth middleware, health/readiness, admin auth. |
| 15% | Complete | Domain lifecycle, nameserver verification, activation, audit/config dirty side effects. |
| 20% | Complete | Domain-scoped origin CRUD, diagnostics, and edge-observed health report. |
| 25% | In progress | DNS records and PowerDNS/DNSGeo reconciliation migration. |
| 35% | Pending | Edge registration, heartbeat, config sync, and edge auth fully Laravel-native. |
| 45% | Pending | Collector, analytics, activity, security ingest, and reports migrated. |
| 55% | Pending | Cache, WAF, rate-limit, IP rules, redirects, headers, waiting room migrated. |
| 65% | Pending | SSL/ACME, certificates, renewal scheduler, jobs, and queues migrated. |
| 75% | Pending | Dashboard API contract alignment and remaining API/OpenAPI cleanup. |
| 85% | Pending | Laravel CLI command conversion and scheduler ownership complete. |
| 92% | Pending | Legacy route/module isolation complete; old runtime has no write path. |
| 97% | Pending | Old core files, custom router, old support layer, and obsolete tests removed. |
| 100% | Pending | Full smoke, e2e, DNS e2e, stress, docs, CI, and deployment validation green. |

Percentages are product milestones, not line-count estimates. A phase is only
complete when code, tests, dashboard contracts, docs, Compose, and CI are aligned.

## Non-Negotiable Rules

- `core/database/schema.sql` remains the authoritative fresh-install PostgreSQL schema.
- PostgreSQL is the only supported runtime and test database.
- Laravel-native code under `core/app/Http`, `core/app/Services`, `core/routes`,
  `core/database`, `core/tests/Feature`, and Laravel Artisan commands is the
  control-plane forward path.
- Old module controllers/services may be read as product/spec reference only.
- Do not add compatibility shims for old URLs, request fields, environment
  variables, CLI aliases, response shapes, or migration history.
- Do not route Laravel requests through `core/public_index.php` or old service
  layers to make tests pass.
- Remove old code only after its workflow is migrated and verified.
- Keep OpenResty/Lua edge runtime and POSIX edge agent as product surfaces; do
  not rewrite the hot-path data plane into Laravel.

## Phase Plan

### 0-10% Foundation

Status: **Complete**.

Deliverables:

- Standard Laravel application skeleton under `core/`.
- Laravel `public/index.php`, `routes/api.php`, `routes/web.php`, config, tests,
  and Docker build path.
- PostgreSQL-backed Laravel feature test path.
- Core health/readiness endpoints served by Laravel.
- Admin bearer/session auth served by Laravel middleware.
- Root Compose config remains valid.

Exit checks:

- `docker compose config --quiet`
- PHP syntax lint
- Laravel Docker test path

### 10-20% Domain And Origin Lifecycle

Status: **Complete**.

Completed:

- Domain list/show/create/update/delete.
- Nameserver verify, force-verify, reseed expected nameservers, activate.
- Origin list/create/update/delete.
- Origin diagnostic-only check/test endpoints.
- Edge-observed origin health report endpoint.
- Audit writes and config dirty side effects for migrated mutations.
- Dashboard create-domain payload aligned to `{ domain, name }`.
- Removed legacy aliases:
  - `/api/v1/domains/{domainId}/verify-nameservers`
  - domain create `zone_name`
  - domain create `display_name`

Remaining risks:

- DNS-linked origin sync still intersects DNS record migration.
- Route-debug still depends on old proxy config service behavior.

### 20-35% DNS, PowerDNS, And DNSGeo

Status: **Next active phase**.

Scope:

- Migrate DNS record CRUD to Laravel-native controllers/services.
- Migrate DNS routing settings and GeoDNS route management.
- Migrate desired-state generation for customer zones and shared CDN records.
- Migrate PowerDNS zone writes, dry-run, force-sync, actual-state reads, and
  sync status visibility.
- Keep proxied apex records as PowerDNS `ALIAS` and proxied subdomains as CNAME.
- Keep DNSGeo as the project GeoDNS implementation.
- Keep edge pool updates shared-record based; do not rewrite every customer zone
  for one edge IP or health change.
- Keep failure visibility through health APIs and dashboard.

Exit checks:

- Real/local PowerDNS API writes covered.
- ALIAS, CNAME, Lua records, health-driven answers, ALIAS answer equivalence,
  and shared-record updates tested.
- `ci/dns_e2e.sh` and `ci/powerdns_dns_checks.sh` remain aligned.

### 35-45% Edge Control Plane

Status: **Pending**.

Scope:

- Edge registration, heartbeat, node list, DNS edge view, config polling.
- HMAC edge auth and replay protection fully Laravel-native.
- Config snapshot build/publish path moved behind Laravel services/jobs.
- Edge agent scripts keep POSIX `sh` compatibility.
- Edge config response remains compatible with OpenResty selectors and health
  probes.

Exit checks:

- Edge auth feature tests.
- Agent shell syntax checks.
- Edge config black-box contract tests.
- Smoke/e2e edge registration and config pull.

### 45-55% Collector, Analytics, And Security Ingest

Status: **Pending**.

Scope:

- Usage ingest, idempotency, rollups, activity requests, activity summaries.
- Security event ingest and summaries.
- Reports for traffic, cache, edge, security, reliability, and operations.
- Origin health observation ingest remains edge-sourced.
- Replay protection and edge auth enforced on ingest endpoints.

Exit checks:

- Bucket rebuild/query behavior for `minute|hour|day`.
- Activity diagnostics and recommendation generation preserved.
- Collector-focused Laravel feature tests and existing pytest contracts green.

### 55-70% Traffic Rules And Delivery Features

Status: **Pending**.

Scope:

- Cache settings, cache rules, purge requests.
- WAF rules, managed profile detach, challenge configuration.
- Rate limits, dry runs, managed intent detach.
- IP allow/block rules.
- Redirects, response headers, page rules.
- Waiting room and emergency activation/deactivation.
- Route-debug and config snapshot impact for all rule families.

Exit checks:

- Dashboard tabs keep type-safe API contracts.
- Config snapshots carry all expected edge fields.
- Smoke/e2e coverage for cache, WAF, rate, redirects, headers, and waiting room.

### 70-80% SSL, ACME, Jobs, Queues, Scheduler

Status: **Pending**.

Scope:

- SSL settings, certificate list/import/request.
- ACME DNS-01 issuance and renewal.
- SSL job progress/history.
- Laravel jobs/queues for expensive workflows.
- Laravel scheduler owns recurring SSL, DNS, config, usage, and origin tasks.
- Remove old scheduler runners after Laravel commands own the workflow.

Exit checks:

- ACME staging/local checks where available.
- Scheduler command tests.
- Queue/job retry behavior documented.

### 80-88% Dashboard, OpenAPI, Docs, And Deployment Alignment

Status: **Pending**.

Scope:

- Align dashboard clients/types with Laravel contracts.
- Remove obsolete dashboard assumptions tied to old core routes or aliases.
- Update OpenAPI for every migrated endpoint.
- Update README, setup, deployment, architecture, troubleshooting, runbooks, and
  examples.
- Keep root `docker-compose.yml` as the normal topology without CI-only profiles
  as a requirement.

Exit checks:

- Dashboard typecheck, tests, and build.
- Docs build.
- OpenAPI reviewed against route list.

### 88-94% CLI Conversion

Status: **Pending**.

Scope:

- Convert custom command runner behavior to Laravel `Command` classes.
- Keep fresh-install command names only when they are still the forward product
  contract.
- Remove old CLI aliases and obsolete commands.
- Ensure CI, docs, and operator examples use Laravel commands.

Exit checks:

- Command feature/integration tests.
- `php artisan list` is the source of truth.
- Old command runner has no required production command left.

### 94-98% Legacy Runtime Removal

Status: **Pending**.

Removal candidates after ownership reaches zero:

- `core/public_index.php`
- `core/app/Support/Router.php`
- `core/app/Support/Request.php`
- `core/app/Support/Response.php`
- `core/app/Support/DatabaseMigrator.php` if Laravel migration/fresh schema flow
  fully replaces it
- Old module HTTP controllers whose workflows have Laravel controllers
- Old services whose workflows have Laravel services
- Obsolete SQL migration history if no longer used by fresh installs
- Pytest assertions that only protect old routing internals instead of product
  behavior

Rules for deletion:

- Do not delete old code while any route, command, scheduler, dashboard screen,
  edge agent flow, or CI script still depends on it.
- Before deletion, prove no runtime reference remains with `rg`.
- Replace old tests with Laravel feature tests or product-level black-box tests.
- Deletion commits must include docs and CI updates when behavior is visible.

Exit checks:

- `rg "public_index|App\\Support\\Router|App\\Support\\Database"` shows no
  runtime dependency.
- Laravel routes own all API endpoints.
- Laravel commands own all supported CLI tasks.
- Old modules are either deleted or explicitly quarantined as non-runtime
  reference for a still-pending phase.

### 98-100% Final Certification

Status: **Pending**.

Required validation:

- `docker compose config --quiet`
- PHP syntax lint for `core/`
- Full Laravel feature/unit test suite
- Focused and full pytest contracts after obsolete assertions are retired
- Agent shell syntax checks
- CI shell syntax checks
- Dashboard `npm ci`, typecheck, tests, and build
- Docs `npm ci` and build
- Root stack smoke and e2e
- DNS e2e and PowerDNS checks
- Stress tests only against explicitly disposable environments

100% completion means:

- The old custom PHP control-plane runtime is removed.
- Fresh installs use only Laravel for core HTTP, CLI, scheduler, queue, auth,
  config, database access, and control-plane workflows.
- Edge/OpenResty, edge agent, DNSGeo/PowerDNS, dashboard, Compose, deploy, CI,
  docs, and OpenAPI all match the Laravel contracts.
- No documented setup path depends on legacy core files.

## Current Backlog Order

1. DNS/PowerDNS/DNSGeo reconciliation.
2. Edge registration, heartbeat, and config sync.
3. Collector, analytics, and security ingest.
4. Cache, WAF, rate limit, IP rule, SSL, redirect, header, and waiting room workflows.
5. Dashboard API contract alignment.
6. Laravel jobs, scheduler, and queues.
7. CLI command conversion.
8. Legacy runtime deletion.
9. Final smoke, e2e, stress, docs, and CI certification.

## Tracking Template

Use this checklist for every migrated workflow:

- Laravel route/controller/service implemented.
- PostgreSQL-backed Laravel feature tests added.
- Old aliases and deprecated fields removed instead of shimmed.
- Dashboard client/types updated if the contract changes.
- OpenAPI and docs updated if behavior is visible.
- Compose, deploy, and CI updated if runtime behavior changes.
- Focused pytest contracts converted from old internals to product behavior.
- Old route/controller/service removed or isolated after ownership reaches zero.
- Relevant validation commands run and recorded.

