# CDNLite Full Project Migration Roadmap

This roadmap tracks the fresh-install Laravel rebuild from 0 to 100 percent.
CDNLite is pre-1.0 and fresh-install-only: old database migrations, deprecated
environment aliases, legacy request fields, legacy API aliases, and fallback
paths to the old core are intentionally not preserved.

The goal is not to wrap the old project. The goal is to finish a Laravel-native
control plane, keep the dashboard, edge, DNSGeo, deployment, CI, and docs aligned,
then remove the old PHP runtime once it has no remaining production ownership.

## Progress Summary

Current estimated migration progress: **25% complete**.

| Percent | Status | Milestone |
| --- | --- | --- |
| 0% | Complete | Baseline and fresh-install direction agreed. |
| 5% | Complete | Laravel project foundation, Docker build path, PostgreSQL test path. |
| 10% | Complete | Laravel HTTP entrypoint, auth middleware, health/readiness, admin auth. |
| 15% | Complete | Domain lifecycle, nameserver verification, activation, audit/config dirty side effects. |
| 20% | Complete | Domain-scoped origin CRUD, diagnostics, and edge-observed health report. |
| 25% | Complete | Laravel DNS record CRUD, status, retry queue, audit/config dirty side effects. |
| 30% | In progress | PowerDNS desired-state, dry-run, force-sync, and DNSGeo reconciliation migration. |
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

Status: **In progress**.

Completed in the first DNS migration slice:

- Laravel-native domain DNS record list/show/create/update/delete.
- Laravel-native domain DNS status endpoint for dashboard publication state.
- Laravel-native per-record reconcile queue endpoint.
- DNS record writes normalize fresh-install fields, reject duplicate/conflicting
  records with stable `409` errors, compute public ALIAS/CNAME projection, write
  audit rows, mark config dirty, and queue PowerDNS reconciliation when enabled.
- PostgreSQL-backed Laravel feature coverage for DNS record lifecycle, status,
  retry queue, duplicate errors, and name conflicts.
- API docs updated for the Laravel DNS record contract.

Scope:

- Continue desired-state and PowerDNS/DNSGeo reconciliation migration.
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

## Active Execution Notes

These notes turn the phase plan into concrete work slices. Update this section
as each slice lands so the roadmap remains an execution document, not a stale
status page.

### Current Laravel Surface Audit

Already Laravel-native or partially Laravel-native:

- Health, readiness, admin login/session, overview, settings, audit list.
- Domain lifecycle, nameserver verification, activation, and domain deletion.
- Domain-scoped origin CRUD, diagnostic checks, and edge-observed origin health.
- DNS record CRUD, domain DNS status, and per-record reconcile queue under
  domain routes.
- DNS operations read endpoints for sync state, zones, and desired records.
- Edge registration, heartbeat, edge config, edge DNS list, and collector usage
  routes are present behind edge HMAC middleware.
- Edge HMAC signature, timestamp, nonce replay protection, and nonce pruning are
  present in Laravel.

Known gaps to close before advancing milestone percentages:

- DNS `force-sync` must perform a real verified sync, not share the dry-run
  implementation.
- Desired DNS generation must be a Laravel service with tests for customer
  zones, shared CDN records, stale ownership cleanup, SOA/NS invariants, and
  ACME challenge exclusion.
- Edge config currently returns a direct database projection; the migrated
  contract needs the same published snapshot behavior, versioning, last-known
  good semantics, size limits, and OpenResty fields used by the edge runtime.
- Collector usage ingest is present, but security-event ingest, rollup rebuilds,
  activity timelines, summaries, retention, and recommendations still need
  Laravel ownership.
- Dashboard clients already reference many rule, edge, DNS, SSL, analytics, and
  security endpoints; each client must be reconciled against the Laravel route
  list instead of preserved through legacy fallbacks.
- Legacy migration adoption behavior exists in current tests and docs; remove or
  rewrite it when the fresh-install Laravel schema flow owns setup completely.

### DNS Slice Breakdown

Work this phase in narrow vertical slices:

1. **Record contract**: implement Laravel list/show/create/update/delete for DNS
   records with fresh-install request/response fields only.
2. **Validation**: enforce RR type, name normalization, TTL bounds, proxied
   record rules, CNAME/ALIAS exclusivity, duplicate multi-value rules, and clear
   `409` errors.
3. **Side effects**: write audit rows, mark edge config dirty when applicable,
   enqueue DNS reconcile, and expose saved-but-not-published failures clearly.
4. **Desired state**: build rrsets for customer zones, apex ALIAS, proxied
   subdomain CNAMEs, direct records, SOA/NS records, and shared CDN/DNSGeo edge
   pool records from Laravel services.
5. **PowerDNS client**: implement bounded retry, PATCH, verify-after-write,
   actual-state reads, error capture, and idempotent stale-record cleanup.
6. **Sync operations**: split dry-run from force-sync, persist `dns_sync_state`,
   expose last success/error, and guard concurrent syncs.
7. **DNSGeo behavior**: keep shared Lua records and health/country/continent
   answer selection tied to edge pool changes, not customer-zone rewrites.
8. **Docs and dashboard**: update DNS Operations, domain DNS tab, API docs, and
   setup notes for the Laravel-only contract.

DNS phase exit evidence must include:

- Laravel feature tests for record CRUD, validation conflicts, audit, config
  dirty state, and reconcile enqueueing.
- Product-level tests for desired rrsets and stale rrset cleanup.
- Local PowerDNS integration coverage for zone writes, dry run, force sync,
  ALIAS/CNAME/Lua records, health-driven answers, and shared-record updates.
- `ci/dns_e2e.sh` and `ci/powerdns_dns_checks.sh` results, or a documented
  reason they were intentionally deferred.

### Edge Slice Breakdown

After DNS reaches the 35% milestone, move edge control-plane ownership from
"routes exist" to "runtime contract proven":

1. Make registration and heartbeat payloads match the POSIX edge agent exactly.
2. Persist edge region, IP families, health, DNS eligibility, software version,
   and last heartbeat with bounded validation.
3. Publish edge config snapshots through Laravel services/jobs, including
   version, ETag/conditional fetch behavior, last-known-good metadata, and size
   guardrails.
4. Return all OpenResty fields required for origins, cache, WAF, rate limits,
   IP rules, redirects, response headers, waiting room, SSL material references,
   debug flags, and telemetry limits.
5. Keep edge HMAC auth and replay protection required for registration,
   heartbeat, config, usage, security events, and origin-health ingest.
6. Update edge agent scripts only when the forward API contract changes, keeping
   them POSIX `sh`.
7. Remove any legacy edge route/controller once the agent, OpenResty, dashboard,
   docs, and CI use the Laravel path.

Edge phase exit evidence must include signed-request feature tests, shell syntax
checks, config snapshot contract tests, and a root-stack registration/config-pull
smoke path.

### Collector And Analytics Slice Breakdown

Collector migration should preserve ingestion semantics while making reporting
bounded and observable:

1. Accept bounded telemetry batches with edge auth, replay protection, payload
   size limits, idempotency, and clear partial-failure reporting.
2. Store edge request metrics, cache status, origin timing, routing decision,
   country, device, and error fields needed by dashboard activity views.
3. Accept security events for WAF, rate limit, bot/challenge, Geo/IP, waiting
   room, and managed-profile decisions.
4. Preserve `minute|hour|day` aggregate rebuild/query behavior in Laravel API
   and Artisan commands.
5. Move activity summaries, recommendations, operational reports, retention, and
   prune behavior behind Laravel services/commands.
6. Keep origin-health observation ingest edge-sourced and visible in readiness
   and domain health APIs.

Collector exit evidence must cover successful ingest, malformed batch handling,
replay rejection, aggregate rebuilds, dashboard summary queries, retention
pruning, and report generation.

### Rule And Delivery Feature Slice Breakdown

Do these after the edge config contract can carry full policy snapshots:

1. Cache settings, cache rules, and purge requests.
2. WAF rules, managed profile detach, challenge configuration, and clearance
   behavior.
3. Rate limits, dry runs, managed intent detach, key selection, and challenge
   actions.
4. IP allow/block rules and precedence relative to WAF/rate/clearance.
5. Redirects, response headers, and route-debug output.
6. Waiting room activation, admission, emergency mode, and telemetry.

Each feature is complete only when the Laravel API, dashboard tab, config
snapshot field, OpenResty behavior, activity/security telemetry, docs, and tests
all agree.

### SSL, Jobs, Scheduler, And CLI Slice Breakdown

Use the fresh-install contract for all operator commands:

1. Move SSL settings, certificate import/list, managed issuance request, ACME
   DNS-01 challenge publication, renewal, and job history to Laravel services.
2. Use Laravel jobs/queues for expensive DNS, SSL, config, usage, and reporting
   work where synchronous requests would be unsafe.
3. Convert recurring work to Laravel scheduler commands with documented
   intervals and failure visibility.
4. Convert old command-runner tasks to Artisan commands or delete them when they
   are obsolete.
5. Make `php artisan list` and docs the supported CLI source of truth.

Do not keep old command names as aliases unless they are deliberately chosen as
the new product contract.

### Removal Readiness Gates

Before deleting a legacy file or route family, record:

- The Laravel route or command that owns the workflow now.
- The dashboard/API/docs/CI references that were updated.
- The product-level tests that replaced old implementation tests.
- `rg` output showing no runtime dependency remains.
- Any intentionally quarantined reference file and the phase that still needs it.

Deletion is safe only when the workflow has no remaining production ownership in
the old runtime.

## Immediate Implementation Queue

Use this queue when opening the next coding session. Finish one item fully before
starting the next unless the work is purely preparatory and does not change
runtime behavior.

### Queue 1: DNS Record Ownership

Status: **Complete**.

Completed evidence:

- Runtime owner: `DnsRecordService` plus Laravel `DomainController` DNS routes.
- Removed owner: direct DNS record insertion in `DomainController`; old DNS
  module remains reference-only for later routing/PowerDNS slices.
- Schema: no schema change; `core/database/schema.sql` already had the required
  fresh-install DNS columns.
- Dashboard/API/docs: Laravel routes now cover dashboard list, status, create,
  update, delete, and retry-sync calls; API docs were updated for accepted fields
  and publication semantics.
- Validation: `docker compose run --rm -v /root/cdnlite/core/app:/app/app -v
  /root/cdnlite/core/routes:/app/routes -v /root/cdnlite/core/tests:/app/tests
  core-test sh -lc 'php artisan migrate:fresh --seed --force && php artisan test
  --filter=FreshInstallApiTest'` passed with 10 tests and 109 assertions.

Files likely to change:

- `core/routes/api.php`
- `core/app/Http/Controllers/Api/DomainController.php`
- `core/app/Services/ControlPlane/*Dns*`
- `core/database/schema.sql`
- `core/tests/Feature/*`
- `core/tests/test_dns*_contract.py`
- `dash/src/lib/api/dns.ts`
- `dash/src/views/domain-tabs/DomainDnsTab.vue`
- `docs/api/api.md`
- `docs/dns.md`
- `docs/setup.md`

Required result:

- Domain DNS records have complete Laravel CRUD.
- Creates, updates, and deletes queue reconciliation and mark affected runtime
  state dirty.
- API responses expose publication state without implying that a local database
  write has already reached PowerDNS.
- Legacy DNS aliases or old request fields are removed from clients, docs, and
  tests instead of accepted silently.

Do not advance past this queue if:

- DNS record deletes leave stale desired rrsets without an ownership cleanup
  path.
- Dashboard DNS actions require an endpoint that exists only in the old runtime.

### Queue 2: Desired DNS State And PowerDNS Sync

Target milestone: advance from **25%** toward the **30%** checkpoint by moving
PowerDNS desired-state and sync ownership into Laravel.

Files likely to change:

- `core/app/Services/ControlPlane/DnsDesiredStateBuilder.php`
- `core/app/Services/ControlPlane/PowerDnsClient.php`
- `core/app/Services/ControlPlane/DnsReconciler.php`
- Laravel command classes for DNS reconcile/doctor/dry-run/force-sync
- `ci/dns_e2e.sh`
- `ci/powerdns_dns_checks.sh`
- `infra/dnsgeo/**` only when DNSGeo behavior or bootstrap data changes
- `docs/dns.md`
- `docs/runbooks/index.md`

Required result:

- Dry-run builds and reports the desired plan without writes.
- Force-sync writes, verifies, persists status, and reports bounded failures.
- Customer-zone records and shared CDN/DNSGeo edge-pool records are reconciled
  independently.
- Proxied apex records publish as ALIAS.
- Proxied subdomain records publish as CNAME to the stable CDN hostname.
- Edge pool health or IP changes update shared records without rewriting every
  customer zone.

Do not advance past this queue if:

- PowerDNS errors are visible only in logs.
- DNS health/readiness cannot show last success, last failure, and stale sync
  state.
- Tests inspect only desired database rows and never verify actual PowerDNS
  state.

### Queue 3: Edge Config Publication

Target milestone: move from **35% pending** to active edge control-plane
completion.

Files likely to change:

- `core/app/Http/Controllers/Api/EdgeController.php`
- `core/app/Services/ControlPlane/Config*`
- `core/routes/api.php`
- `edge/agent/*.sh`
- `edge/openresty/lua/**`
- `core/tests/Feature/EdgeAuthTest.php`
- config snapshot contract tests under `core/tests`
- `docs/architecture.md`
- `docs/setup.md`

Required result:

- Edge config is served from the Laravel-published snapshot contract, not an ad
  hoc direct database projection.
- Conditional fetch/version behavior is deterministic.
- Oversized, malformed, stale, or unpublished config states are visible in
  readiness and do not break an already healthy edge unnecessarily.
- OpenResty receives every field needed for currently supported delivery,
  security, SSL, telemetry, and origin routing features.

Do not advance past this queue if:

- Edge config omits a field that the current Lua runtime expects.
- The agent must call a legacy endpoint for registration, heartbeat, config,
  metrics, security events, or origin health.
- Replay protection is bypassed for any edge write endpoint.

### Queue 4: Collector And Activity Ownership

Target milestone: move from **45% pending** toward analytics and security ingest
completion.

Files likely to change:

- `core/app/Http/Controllers/Api/CollectorController.php`
- Laravel services for usage ingest, security ingest, rollups, activity, reports
- Laravel Artisan commands for rollup rebuilds and retention pruning
- `edge/agent/push_metrics.sh`
- `edge/agent/push_security_events.sh` if present or added
- `dash/src/views/domain-tabs/DomainActivityTab.vue`
- `docs/api/api.md`
- `docs/setup.md`

Required result:

- Usage and security ingest are bounded, signed, replay-protected, and visible.
- Dashboard activity, summaries, and recommendations use Laravel endpoints.
- Rollup rebuild/query support remains available for `minute|hour|day`.
- Retention pruning is explicit, bounded, and documented.

Do not advance past this queue if:

- The dashboard can show activity only by reading old report endpoints.
- Failed or partial telemetry batches cannot be diagnosed by an operator.
- Retention behavior depends on old command-runner tasks.

## Migration Evidence Pack

Every migration slice should leave a small evidence pack in the final handoff or
phase report. Keep it short, but make it enough for the next person to trust the
state of the work.

Required fields:

- **Slice**: one of DNS record ownership, DNS sync, edge config, collector,
  delivery rules, SSL/jobs, dashboard alignment, CLI conversion, or deletion.
- **Runtime owner**: Laravel route, service, command, job, or scheduler that now
  owns the workflow.
- **Removed owner**: old route, controller, service, command, test, or doc path
  removed or quarantined.
- **Schema**: whether `core/database/schema.sql` changed, and why.
- **Dashboard/OpenAPI/docs**: exact user-visible contracts updated.
- **Validation**: commands run, commands skipped, and why skipped.
- **Risk**: remaining dependency, missing environment, heavy test not run, or
  unresolved legacy reference.

Use this compact format:

```text
Slice:
Runtime owner:
Removed owner:
Schema:
Dashboard/OpenAPI/docs:
Validation:
Risk:
```

## Phase Manifest Alignment

The large product roadmap already has one-shot phase infrastructure. The
Laravel migration should reuse it when a migration slice is large enough to need
runtime proof.

Rules:

- Do not create a second phase-runner system for this migration roadmap.
- Add or update `ci/phases/phase-XX.yml` only when the implementation changes
  runtime behavior that needs smoke, e2e, stress, recovery, or docs evidence.
- Keep migration percentages in this file synchronized with the evidence linked
  from `docs/ROADMAP.md` when a product phase is also advanced.
- If a migration slice is docs-only or test-contract-only, record the focused
  validation in the handoff instead of creating a new manifest.
- Full-profile evidence is required before marking a migration phase Complete,
  but not before landing an honest in-progress slice.

Suggested manifest coverage for migration work:

| Migration slice | Existing or future gate |
| --- | --- |
| DNS record CRUD | Laravel feature tests plus focused DNS pytest contracts |
| Desired DNS and PowerDNS sync | `ci/dns_e2e.sh`, `ci/powerdns_dns_checks.sh`, DNS phase manifest |
| Edge config publication | Edge auth/config feature tests, smoke, e2e, edge hot-path stress |
| Collector ingest and activity | Collector feature tests, telemetry e2e, reporting stress |
| Cache/WAF/rate/IP/redirect/header/waiting room | Feature-specific manifests and edge enforcement tests |
| SSL/ACME/jobs/scheduler | SSL job tests, ACME local/staging checks, scheduler command tests |
| CLI conversion | Artisan command tests and docs examples |
| Legacy runtime deletion | `rg` dependency proof, full syntax/tests/docs build, smoke/e2e |

## API Contract Cleanup Rules

Apply these cleanup rules during every slice:

- A migrated endpoint should return one fresh-install response shape.
- Request fields from old controllers must be deleted from dashboard clients and
  docs rather than accepted as aliases.
- Route names should describe product behavior, not old implementation names.
- Error codes should be stable, short, and documented when user-visible.
- Long-running or dependency-backed operations must distinguish local save,
  queued work, dry-run, attempted sync, verified success, and failed sync.
- Dashboard types should be regenerated or hand-updated in the same change as
  API shape changes.
- OpenAPI examples should use the Laravel route and current field names only.

## Schema And Fresh-Install Rules

`core/database/schema.sql` is the contract for a new install. During migration:

- Add columns only when the new Laravel workflow needs them.
- Remove obsolete columns only when every runtime and test reference is gone.
- Do not add historical migrations or legacy adoption paths for the rebuild.
- Do not document upgrade procedures from the old core unless a task explicitly
  asks for that compatibility work.
- Keep seed/bootstrap data aligned with Compose, CI, dashboard defaults, and
  docs examples.
- If a queue, lock, sync state, nonce, or event table is added, define retention
  and cleanup ownership in the same slice.

## Dashboard Alignment Rules

The dashboard should follow the Laravel contract, not hide backend migration
gaps.

- If a dashboard action points to a missing Laravel endpoint, either migrate the
  endpoint in the same slice or remove/disable the action with honest state.
- Keep operator language product-focused: DNS publishing, edge config, cache,
  protection, certificates, activity, and health. Avoid exposing old module
  names or implementation internals.
- Error messages should say whether the issue is validation, auth, queued sync,
  PowerDNS, edge config, ACME, or telemetry.
- Every migrated domain tab should have matching API client tests or component
  tests when the request shape changes.

## Stop Conditions

Pause a slice and record the blocker instead of pushing through when:

- The only way to make a test pass is to route Laravel through old runtime code.
- The change requires accepting old aliases that the fresh-install contract does
  not want.
- A DNS or SSL test would mutate a non-disposable environment.
- A dashboard action would claim success before the backing sync is attempted or
  verified.
- A deletion candidate still has a runtime reference from routes, commands,
  scheduler, dashboard, edge agent, CI, or docs.

These are not reasons to abandon the migration; they are reasons to narrow the
slice, make the dependency visible, and continue from the next safe boundary.
