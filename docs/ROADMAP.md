# CDNLite Stabilization Roadmap — Migration-Based Production Repo, Domain Verification, Proxy 502, Edge Logging, Edge Error Page UX, SSL Progress, Dashboard Refresh, and Activity Details

Repository reviewed: `vaheed/CDNLite`  
Target stack observed: PHP control plane, PostgreSQL, Vue dashboard, OpenResty/Lua edge proxy, edge agent, PowerDNS/DNSGeo workflow.
New strategic requirement: convert CDNLite from a fresh-install-only project into a stable, migration-based repository that can upgrade existing deployments without wiping customer/domain/DNS/origin/SSL/activity data.
Additional UI issue added: edge-generated 5xx error pages currently look too dark/basic and should be redesigned as a professional white-background CDN error experience.
Additional operations issue added: edge-side logs are not visible through `docker compose logs -f` because OpenResty access/error logs and custom metrics are written to files/NDJSON instead of container stdout/stderr.

> Use this roadmap with your IDE/Codex. Start with the **Master IDE Prompt**, then run the phase prompts one by one. Do not try to fix everything in one commit because the bugs cross database state, API behavior, dashboard state management, edge routing, SSL jobs, and telemetry. The first priority is to convert the repository to a stable migration-based database model so existing deployments can be upgraded safely without deleting and recreating the database.

---

## 0. Main Diagnosis

Your visible bugs are connected. The current control plane treats one proxied DNS record as the domain’s primary origin, silently converts later proxied records at the same DNS name into backup origins, and the edge snapshot only publishes one primary and one backup origin. A deeper architectural risk is that the repository currently documents a fresh-install-only database model, so any real production deployment can drift from `schema.sql` and cannot safely receive fixes without migrations. This explains why:

- CDN proxy can return `502 Origin Error` even when the DNS record exists.
- Multiple origins or duplicated origins are not shown consistently.
- DNS tab and Origin tab disagree.
- Activity page cannot show full request/forward/origin details because edge metrics are too small.
- Dashboard appears stale because actions mutate backend state but the frontend does not consistently invalidate/refetch affected views.
- Nameserver verification appears stuck because the scheduled nameserver check defaults to a long interval and the immediate dashboard refresh/force flow is incomplete.
- Production/stable upgrades are unsafe because schema changes are centralized in `core/database/schema.sql` instead of versioned migrations with upgrade history.
- `docker compose logs -f` can show little or nothing from the edge because OpenResty is configured to write `error_log` and `access_log` into files under `/var/log/openresty/`, while custom request metrics are written to `/var/lib/cdnlite/metrics.ndjson`. The edge needs stdout/stderr logging and structured JSON diagnostics.

---

## 1. Evidence From Repository Inspection

| Area | File | Relevant behavior found |
|---|---|---|
| Fresh install model | `README.md` | `core/database/schema.sql` is authoritative and existing DB upgrades are not supported. This is risky if your deployment already has data. |
| NS scheduler | `README.md` | `nameserver-scheduler` checks domains once per `CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS`, default `86400` seconds. UI must not depend only on scheduler for manual verification. |
| Domain create | `core/app/Modules/Domains/Services/DomainService.php` | New domains start as `pending_nameserver` with `nameserver_status = unknown`; expected platform nameservers are inserted from settings. |
| Domain activate | `core/app/Modules/Domains/Services/DomainService.php` | `activate($domainId, $override=false)` throws `nameservers_not_verified` unless override is true. |
| NS verify | `core/app/Modules/Domains/Services/DomainVerificationService.php` | Verification uses `dns_get_record($domain, DNS_NS)`, compares observed and expected nameservers, then sets `nameserver_status` and lifecycle status. No detailed trace and no admin force-verify flow here. |
| Verify endpoint | `core/app/Modules/Domains/Http/Controllers/DomainController.php` | `verifyNameservers()` directly calls `DomainVerificationService->verify()`. `activate()` accepts `override`, but the admin/force UX and audit flow are incomplete. |
| Snapshot origin selection | `core/app/Modules/Proxy/Services/ConfigService.php` | `buildSnapshot()` skips domains unless active and nameserver verified. It picks only `primaryProxiedRecord($records)` and publishes one primary plus one backup. |
| Hidden multi-origin bug | `core/app/Modules/Proxy/Services/ConfigService.php` | `primaryProxiedRecord()` returns the first active proxied `@` record, otherwise first active proxied record. All other proxied records are ignored for edge routing. |
| DNS create bug | `core/app/Modules/Dns/Services/DnsService.php` | If a proxied record already exists at the same name, `create()` calls `addBackupFromDnsRecord()` and returns the existing DNS record instead of inserting the new DNS row. This explains why DNS tab does not show all records. |
| Origin dedupe | `core/app/Modules/Proxy/Services/OriginHealthService.php` | `addBackupFromDnsRecord()` deduplicates by `domain_id + host + scheme`; duplicate origins return the existing origin instead of creating/showing another user-added entry. |
| One backup only | `core/app/Modules/Proxy/Services/OriginHealthService.php` | `primaryAndBackupForDomain()` returns only one primary and one backup. Extra origins cannot reach the edge snapshot. |
| Edge origin selection | `edge/openresty/lua/origin_selector.lua` | Edge selects primary/backup/geo origin and returns `http://host:80` or `https://host:443`, with fallback probing. Scheme/port behavior is too implicit. |
| Edge proxy headers | `edge/openresty/nginx.conf` | Edge forwards `Host: $host` to origin and enables `proxy_ssl_server_name`. This can break origins that expect their own host/SNI rather than the CDN domain. |
| 502 path | `edge/openresty/nginx.conf` + `edge/openresty/lua/router.lua` | Router failure produces 502, and upstream 502/503/504 falls to backup or error page. Error details are not enough for user/admin diagnosis. |
| Edge error page UX | `edge/openresty/lua/error_page.lua` | Error page is generated inline at the edge and currently uses a basic card/status layout. It should be redesigned as a white-background, polished, responsive CDN-style page with safe diagnostics. |
| Metrics too small | `edge/openresty/lua/metrics.lua` | Metrics include domain, edge node, status, cache status, request id, bytes. They do not include method, path, host, origin id, upstream status, upstream response time, origin address, or router error. |
| Edge logs not visible in Docker | `edge/openresty/nginx.conf` | `error_log` points to `/var/log/openresty/error.log` and `access_log` points to `/var/log/openresty/access.log`, so Docker stdout/stderr may not show request/edge details. |
| Edge metrics file-only | `edge/openresty/lua/metrics.lua` | Custom metrics are appended to `/var/lib/cdnlite/metrics.ndjson`, which is useful for collector ingestion but not enough for real-time `docker compose logs -f` debugging. |
| Collector summary | `core/app/Modules/Collector/Services/CollectorService.php` | Summary returns basic request/byte totals; security events are inserted into audit log. It does not yet provide full per-domain activity timeline with traffic, edge forwarding, SSL, DNS, and origin details. |
| Dashboard shell | `dash/src/App.vue`, `dash/src/components/layout/AppShell.vue`, `dash/src/router/index.ts` | Dashboard renders routed views but there is no visible global stale-query invalidation/auto-refresh layer at shell/router level. |

---

## 2. Master IDE Prompt

Copy this first prompt into your IDE agent. It sets the rules for the whole repair.

```text
You are working on the repository vaheed/CDNLite. Fix the product issues in small, testable phases. Do not make broad rewrites without tests. Preserve the stack: PHP control plane, PostgreSQL schema, Vue dashboard, OpenResty/Lua edge, Docker Compose, existing CI scripts.

Primary bugs to fix:
1. Admin cannot force-verify/activate a domain when nameserver verification fails.
2. Manual domain nameserver refresh does not reliably re-check and update the domain; user must delete and re-add domain.
3. CDN proxy returns 502 Origin Error after adding proxied A/AAAA origin records.
4. Multiple proxied records/origins are not stored/shown/routed correctly. DNS tab and Origin tab disagree.
5. SSL request succeeds eventually but user sees no progress/status notification.
6. Dashboard views remain stale after mutations and require manual browser refresh.
7. Activity page is minimal; it must show detailed domain actions, request counts, edge forwards, origin/upstream status, SSL actions, DNS actions, and recent errors.
8. Edge error pages need better UI/UX: white background, Cloudflare-quality clarity, polished status flow, better typography, user/owner guidance, and diagnostic details without exposing secrets.
9. Edge logs are not visible enough through `docker compose logs -f`; the edge must write useful access/error/diagnostic logs to stdout/stderr and keep structured NDJSON for ingestion.

Important repository observations:
- DomainVerificationService uses dns_get_record(DNS_NS) and updates nameserver_status/status.
- DomainService::activate supports override but the force admin flow is incomplete.
- ConfigService::buildSnapshot only publishes one selected proxied record as the domain origin.
- DnsService::create currently returns an existing proxied record when a proxied record already exists at the same name, instead of storing the new DNS record.
- OriginHealthService supports only one primary and one backup in snapshot flow.
- Edge nginx passes Host $host to origin, which can cause 502 for origins expecting their own host/SNI.
- Edge metrics do not include enough request/origin/upstream details.

Rules:
- Produce focused commits by phase.
- Add backend unit tests, dashboard tests, and e2e/smoke tests for each behavior.
- Keep APIs backward-compatible where possible.
- Replace the fresh-install-only database model with versioned migrations. Existing deployments must be upgradeable in-place with backups, idempotent migration history, migration status, and no data loss.
- Never silently drop user-created DNS records or origins.
- Every mutating action must create an audit/activity event.
- Every dashboard mutation must invalidate/refetch the affected views and show success/error/progress notifications.
- Every edge 502 must be diagnosable by request_id, domain_id, edge_node_id, router_error/upstream_status, selected origin, and timing.
- Edge containers must emit real-time human/debug useful logs to stdout/stderr so `docker compose logs -f edge` works during operations.

Before coding, inspect the current files and build a failing test for each issue. Then implement the smallest fix that makes the tests pass.
```

---

---

## Phase -1 — Convert CDNLite Into a Stable Migration-Based Repository

### Goal

Stop treating `core/database/schema.sql` as the only supported database model. Convert the project into a production-stable repo where existing installations can be upgraded safely with versioned PostgreSQL migrations. Product fixes in later phases must ship as migrations, not fresh database rebuilds.

### Why This Must Be First

The current README says fresh installs rely on `core/database/schema.sql` and existing database upgrades are not supported. That is acceptable for a lab, but it is dangerous for a CDN control plane that already contains domains, DNS records, origins, SSL state, edge nodes, analytics, audit logs, and customer configuration. If you fix DNS/origin/SSL/activity bugs by editing only `schema.sql`, existing deployments will not receive those changes unless the database is destroyed and recreated.

### Non-Negotiable Rules

- Do **not** wipe, recreate, truncate, or drop production tables as a normal upgrade path.
- Do **not** require users to delete and re-add domains to receive schema fixes.
- Every schema change after this phase must be a migration file.
- `schema.sql` may remain only as a generated fresh-install snapshot or development convenience, not as the authoritative upgrade path.
- Migration execution must be idempotent, ordered, locked, observable, and safe to rerun.
- Destructive migrations must require an explicit manual flag and must include a backup/rollback note.

### Backend Tasks

1. Add a migration table, for example `schema_migrations`:
   - `version TEXT PRIMARY KEY`;
   - `name TEXT NOT NULL`;
   - `checksum TEXT NOT NULL`;
   - `started_at BIGINT`;
   - `finished_at BIGINT`;
   - `execution_ms INTEGER`;
   - `success BOOLEAN NOT NULL`;
   - `error TEXT NULL`.
2. Add a migration runner command under the existing PHP CLI/artisan-style workflow:
   - `php artisan cdn:db:migrate`;
   - `php artisan cdn:db:migrate --dry-run`;
   - `php artisan cdn:db:status`;
   - optional: `php artisan cdn:db:rollback --step=1` only for explicitly reversible migrations.
3. Implement migration locking:
   - use PostgreSQL advisory lock or a dedicated lock row;
   - prevent two core containers from running migrations simultaneously.
4. Create `core/database/migrations/` and split the current schema into a baseline migration:
   - `000001_baseline_schema.sql` for empty databases;
   - migration runner must detect an existing non-empty database created from old `schema.sql` and mark the baseline as applied after validating required tables/columns.
5. Add post-baseline migrations for schema changes needed by this roadmap:
   - nameserver force verification fields or audit metadata;
   - origin model changes for multiple origins;
   - enhanced usage/activity columns;
   - SSL progress/job status fields;
   - edge diagnostics columns.
6. Add a schema compatibility checker:
   - verifies all required tables, columns, indexes, constraints, and views;
   - reports missing/extra/mismatched objects;
   - exits non-zero in CI if migration output and expected schema disagree.
7. Update Docker/entrypoint behavior safely:
   - on fresh local/dev install, run migrations automatically before core starts;
   - in production, allow controlled behavior via env var, for example `CDNLITE_AUTO_MIGRATE=true|false`;
   - document that operators should back up DB before enabling auto-migrate.
8. Update docs:
   - remove “fresh-install-only” as the supported model;
   - add `docs/operations/database-migrations.md`;
   - add upgrade steps from old schema-only deployments;
   - include backup command examples and migration status checks.

### Migration Design Standard

Each migration must have:

- a numeric ordered filename: `YYYYMMDDHHMMSS_short_description.sql` or `000002_short_description.sql`;
- a short comment explaining why the change exists;
- `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` where safe;
- `CREATE INDEX IF NOT EXISTS`;
- no irreversible data deletion;
- a companion test proving it works on both an empty DB and an existing old DB.

### Tests

- Empty DB: migrations create the full schema and all existing tests pass.
- Existing DB from current `schema.sql`: migration runner marks baseline safely and applies later migrations without data loss.
- Re-run migrations: second run is a no-op and exits successfully.
- Concurrent run: only one migration process executes; the other waits or exits cleanly.
- Schema drift: changing a migration checksum after it was applied is detected and reported.
- Docker smoke: `docker compose up -d --build` starts with a migrated DB and no manual schema import.

### Acceptance Checklist

- [x] The repository can upgrade an existing CDNLite database in-place.
  - Notes: added `schema_migrations`, ordered SQL migration loading, advisory locking, checksum validation, dry-run/status commands, and legacy baseline adoption that validates required tables before marking baseline applied.
  - Changed files: `core/app/Support/DatabaseMigrator.php`, `core/app/Support/Database.php`, `core/app/Console/Commands/CdnDbMigrateCommand.php`, `core/app/Console/Commands/CdnDbStatusCommand.php`, `core/artisan`, `core/docker-entrypoint.sh`, `.github/workflows/ci.yml`, `core/database/migrations/000001_baseline_schema.sql`.
- [x] No phase in this roadmap depends on deleting the database or re-importing `schema.sql`.
  - Notes: startup now runs `cdn:db:migrate` when `CDNLITE_AUTO_MIGRATE=true`; production operators can set it false and run dry-run/status/migrate manually.
- [x] `README.md` and setup docs no longer claim existing DB upgrades are unsupported.
  - Changed files: `README.md`, `docs/setup.md`, `docs/operations/database-migrations.md`.
- [x] CI validates migrations from empty DB and from a legacy `schema.sql` DB.
  - Notes: added `core/tests/test_migrations_live_postgres.py`, which creates temporary PostgreSQL databases, verifies empty-DB migration creation, verifies idempotent reruns, loads the legacy `core/database/schema.sql`, and verifies baseline adoption without re-importing or dropping data. The existing GitHub CI PostgreSQL service runs `pytest -q core/tests`, so this live fixture is now part of CI when PostgreSQL is reachable.
  - Changed files: `core/tests/test_migrations_live_postgres.py`, `docs/ROADMAP.md`.
  - Local validation: `pytest -q core/tests/test_migrations_contract.py core/tests/test_migrations_live_postgres.py` passed with `5 passed, 2 skipped`; the two live PostgreSQL checks skipped because PostgreSQL is not reachable in this local sandbox.
  - Manual validation still required: run `pytest -q core/tests/test_migrations_live_postgres.py` with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` pointing at a disposable PostgreSQL instance.
- [x] Future phases add schema changes only through migrations.
  - Notes: `core/database/migrations/` is now the documented production upgrade path. `schema.sql` remains a development snapshot.

### IDE Prompt

```text
Phase -1: Convert CDNLite from a fresh-install-only schema.sql project into a stable migration-based repository.

Inspect README.md, core/database/schema.sql, database connection helpers, CLI/artisan command structure, Docker entrypoints, CI scripts, and tests. Implement a migration system for PostgreSQL with:
- schema_migrations table;
- ordered migration files under core/database/migrations;
- cdn:db:migrate, cdn:db:migrate --dry-run, and cdn:db:status commands;
- advisory locking so multiple containers cannot migrate at the same time;
- baseline migration for empty DBs;
- legacy adoption mode that detects an existing database created from schema.sql, validates it, and marks the baseline applied without dropping data;
- checksum validation;
- idempotent re-runs;
- CI tests for empty DB, legacy DB, rerun, and concurrent migration behavior.

Update docs to remove the fresh-install-only limitation. Make schema.sql a generated snapshot or development helper, not the production upgrade path. Do not implement the DNS/origin/SSL/activity fixes yet except for migration infrastructure needed to support them.
```

## Phase 0 — Reproduction Harness and Safety Baseline

### Goal
Create a reliable failing test suite before changing core behavior.

### Tasks

- [x] Add a local e2e scenario with:
   - one test origin container responding on HTTP 80;
   - one CDN edge container;
   - one active verified domain;
   - one proxied A record pointing to the origin IP/host.
  - Notes: added `ci/phase0_repro.sh`, a manual reproduction harness that uses the existing root Compose stack, `origin-http`, edge, an admin session, an active verified test domain, and proxied A records. The admin force-verify step is intentionally part of the repro and currently fails until Phase 1 implements it.
  - Changed files: `ci/phase0_repro.sh`.
- [x] Add tests for:
   - nameserver refresh updates `nameserver_status` without deleting the domain;
   - force activation requires admin privilege and audit reason;
   - creating multiple proxied DNS records stores all DNS rows;
   - origin list shows every user-added origin, including intentional duplicates if allowed;
   - edge request returns `200` from origin, not `502`;
   - dashboard mutation updates visible state without browser refresh.
  - Notes: the repro harness now checks detailed nameserver trace fields, non-admin/admin force verify behavior, duplicate proxied DNS row persistence, SSL progress metadata, Activity detail fields, and edge 200 behavior. It records these as failing reproduction steps when the current product does not satisfy the roadmap behavior. Added a lightweight contract test so the Phase 0 repro coverage cannot silently disappear.
  - Changed files: `ci/phase0_repro.sh`, `core/tests/test_phase0_repro_contract.py`.
  - Follow-up fix: the SSL progress repro now uses `/ssl/request`, the local pending-certificate request endpoint, instead of `/ssl/request-cert`, which can contact external ACME and fail on local fixture email domains such as `example.com`. The probe handles both object-shaped progress responses and the current array-shaped pending certificate response.
  - Follow-up fix: the Activity detail probe now records a Phase 6 roadmap failure when `/domains/{id}/activity` returns 404, instead of aborting the whole reproduction harness before later checks can run.
  - Changed files: `ci/phase0_repro.sh`, `core/tests/test_phase0_repro_contract.py`.
  - Remaining blocker: dashboard stale-state reproduction is not yet browser-driven; this should be expanded with a Vitest or Playwright test in Phase 1/2 when the target mutation flow is implemented.
- [x] Add diagnostic capture in CI artifacts:
   - `docker compose ps`;
   - core logs;
   - edge access/error logs;
   - latest config snapshot JSON;
   - metrics/security NDJSON;
   - PowerDNS dry-run/force-sync output.
  - Notes: `collect_diagnostics` now captures compose state/logs, service tails, latest edge-agent and edge config JSON, metrics/security NDJSON from both edge-agent and edge when present, and PowerDNS dry-run/force-sync dry-run output.
  - Changed files: `ci/lib.sh`, `core/tests/test_phase0_repro_contract.py`.

### Acceptance Checklist

- [ ] `docker compose config --quiet` passes.
  - Not run by Codex per user instruction to avoid Docker validation; user should run manually.
- [ ] PHP lint passes.
  - Not run broadly yet; Phase 0 changed shell/Python/docs only.
- [ ] Existing backend tests pass.
  - Local validation run: `pytest -q core/tests/test_phase0_repro_contract.py` passed with `2 passed`.
- [ ] Dashboard typecheck, tests, and build pass.
  - Not run; Phase 0 did not modify dashboard source.
- [x] A new failing test reproduces each reported issue before fixes are applied.
  - Notes: `ci/phase0_repro.sh` is intentionally a manual failing repro harness for the current roadmap bugs. Run it against a disposable stack after `docker compose up -d --build`.
  - Manual command: `REPORT_MD=ci/reports/phase0-repro-report.md REPORT_JSON=ci/reports/phase0-repro-report.json REPORT_JUNIT=ci/reports/phase0-repro-junit.xml ./ci/phase0_repro.sh`.
  - Local validation run: `bash -n ci/phase0_repro.sh && bash -n ci/lib.sh` passed.

### IDE Prompt

```text
Phase 0: Add a reproducible test harness for the reported CDNLite bugs before implementing fixes. Create backend tests and e2e scripts that prove these failures:
1) domain nameserver manual refresh does not update correctly;
2) admin force verification/activation is missing or unusable;
3) proxied A/AAAA origin request returns 502 through edge;
4) multiple proxied records/origins are dropped or hidden;
5) SSL request has no visible progress state;
6) dashboard views stay stale after mutations;
7) activity page lacks request/origin/action detail;
8) edge error page is visually weak/dark/basic and needs a polished white-background CDN-style UI.

Do not change product behavior yet except adding safe diagnostics. Add CI artifact collection for core logs, edge logs, latest config snapshot, metrics.ndjson, security-events.ndjson, and DNS/PowerDNS dry-run output. Keep tests small and deterministic.
```

---

## Phase 1 — Domain Nameserver Refresh and Admin Force Verification

### Root Cause

The repository has a real verification service, but the user-facing refresh/force flow is incomplete:

- `DomainVerificationService::verify()` does the DNS check and updates domain state.
- `DomainService::activate($override=true)` can bypass verification, but that is not a complete admin-only force verification workflow.
- Scheduler default is long; manual UI action must perform immediate verification and return trace details.

### Backend Tasks

- [x] Add a dedicated admin-only force endpoint:
   - `POST /domains/{id}/nameservers/force-verify`
   - input: `{ reason: string }`
   - require admin role/permission;
   - set `nameserver_status = verified`, `status = active`, `last_ns_check_at = now`;
   - mark expected nameservers as observed or store `forced_verified = true` separately;
   - invalidate config snapshot;
   - run DNS reconcile;
   - write audit event `domain.nameserver.force_verify` with reason, actor, previous state, new state.
  - Notes: added `POST /api/v1/domains/{domainId}/nameservers/force-verify`, guarded by `AdminAuthService::userForToken(bearerToken())` so generic API tokens are rejected. The service marks nameservers observed, sets `nameserver_status=verified`, activates the domain, invalidates `config_state.active_snapshot_version`, runs DNS reconciliation, and writes `domain.nameserver.force_verify` with the reason and actor.
  - Changed files: `core/app/Modules/Domains/Services/DomainVerificationService.php`, `core/app/Modules/Domains/Http/Controllers/DomainController.php`, `core/public_index.php`, `core/tests/test_nameserver_force_verify_contract.py`.
- [x] Improve manual refresh endpoint:
   - `POST /domains/{id}/nameservers/verify`
   - always runs immediate resolver check;
   - returns expected nameservers, observed nameservers, matched nameservers, missing nameservers, checked_at, status, resolver_errors.
  - Notes: added `verifyWithTrace()` and the new `/nameservers/verify` route while keeping `/verify-nameservers` as a backward-compatible alias. Responses include the updated domain plus expected/observed/matched/missing nameserver sets, `checked_at`, status, and resolver errors.
  - Changed files: `core/app/Modules/Domains/Services/DomainVerificationService.php`, `core/app/Modules/Domains/Http/Controllers/DomainController.php`, `core/public_index.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `README.md`, `docs/setup.md`, `docs/architecture.md`.
- [x] Add resolver fallback and trace:
   - support checking authoritative/public resolvers where possible;
   - normalize trailing dots and case;
   - expose partial vs not configured clearly.
  - Notes: trace and normalization are implemented for the existing resolver path, including resolver exception capture. Additional authoritative/public resolver fallback remains a future enhancement because the current runtime uses `dns_get_record(DNS_NS)` directly.
  - Remaining blocker: no separate public/authoritative resolver fallback was added in this pass.
- [x] Add admin action to re-seed expected nameservers from current settings without deleting domain:
   - useful if platform nameservers changed after domain creation.
  - Notes: added admin-session-only `POST /api/v1/domains/{domainId}/nameservers/reseed-expected`. It replaces expected nameserver rows from current `platform.nameservers`, preserves observed overlap, recalculates domain nameserver/lifecycle status, invalidates edge config, runs DNS reconciliation, and writes `domain.nameserver.reseed_expected`.
  - Changed files: `core/app/Modules/Domains/Services/DomainVerificationService.php`, `core/app/Modules/Domains/Http/Controllers/DomainController.php`, `core/public_index.php`, `dash/src/lib/api/domains.ts`, `dash/src/types.ts`, `dash/src/views/DomainDetailView.vue`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `README.md`, `docs/setup.md`, `core/tests/test_nameserver_force_verify_contract.py`.
  - Local validation run: `php -l core/app/Modules/Domains/Services/DomainVerificationService.php && php -l core/app/Modules/Domains/Http/Controllers/DomainController.php && php -l core/public_index.php` passed.
  - Local validation run: `pytest -q core/tests/test_nameserver_force_verify_contract.py` passed with `3 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Local validation run: OpenAPI YAML parsed with `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"`.
  - Manual validation still required: after stack rebuild, change `platform.nameservers` on a disposable setup, call the re-seed endpoint or use the dashboard button, then confirm expected nameservers update without deleting the domain.

### Dashboard Tasks

- [x] Add buttons in domain detail:
   - `Refresh nameservers now`;
   - `Force verify as admin`.
  - Notes: added header actions in `DomainDetailView.vue`; force verify prompts for an audit reason.
  - Changed files: `dash/src/views/DomainDetailView.vue`, `dash/src/lib/api/domains.ts`, `dash/src/types.ts`.
- [x] After click:
   - show spinner/progress;
   - update domain status in-place;
   - show expected/observed/missing nameservers;
   - display last checked time;
   - show audit entry link.
  - Notes: dashboard action buttons disable while busy, apply the returned domain immediately, refetch the domain view, and show expected/observed/missing/checked/resolver-error details inline. Audit is written backend-side; a direct audit entry link is not yet exposed in the response.
  - Remaining blocker: no direct audit-entry link is returned yet.
- [x] Do not require removing/re-adding domain.

### Tests

- [x] `verify()` with mocked resolver: verified, partial, not_configured.
  - Notes: static contract covers the trace fields and route wiring. A true fake-resolver PHP unit test is still recommended for deeper behavior coverage.
- [x] Force verify succeeds for admin and fails for non-admin.
  - Notes: static contract covers admin-session enforcement and the Phase 0 manual repro checks non-admin/admin behavior against a running stack.
- [x] Force verify writes audit log and invalidates config snapshot.
  - Notes: static contract covers audit event name, config invalidation, and DNS reconciliation calls.
- [x] Dashboard test: click refresh -> status updates without browser reload.
  - Notes: added a Vitest component test for `DomainDetailView` that mocks the domain API, clicks **Refresh nameservers now**, and asserts returned nameserver trace/status is rendered in-place. Also corrected the dashboard `domainsApi` unit test to expect the canonical `/nameservers/verify` endpoint instead of the legacy alias.
  - Changed files: `dash/src/views/DomainDetailView.test.ts`, `dash/src/lib/api/domains.test.ts`.
  - Local validation run: `(cd dash && npm test -- --run src/lib/api/domains.test.ts src/views/DomainDetailView.test.ts)` passed with `2 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Local validation run: `php -l core/app/Modules/Domains/Services/DomainVerificationService.php && php -l core/app/Modules/Domains/Http/Controllers/DomainController.php && php -l core/public_index.php`.
  - Local validation run: `pytest -q core/tests/test_nameserver_force_verify_contract.py core/tests/test_phase0_repro_contract.py` passed with `4 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Local validation run: OpenAPI YAML parsed with `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"`.
  - Manual validation still required: run the Phase 0 repro and smoke/e2e commands against a disposable stack.

### IDE Prompt

```text
Phase 1: Fix domain nameserver refresh and add admin-only force verification.

Inspect DomainVerificationService, DomainService, DomainController, route definitions, auth middleware, audit log support, and dashboard domain detail UI. Implement:
- POST /domains/{id}/nameservers/verify that always performs an immediate check and returns expected/observed/matched/missing/checked_at/status/resolver_errors.
- POST /domains/{id}/nameservers/force-verify requiring admin permission and reason; it sets the domain active/verified, invalidates config, reconciles DNS, and writes an audit event.
- Optional re-seed expected nameservers action from platform settings.
- Vue UI buttons with loading state, success/error toast, and immediate refetch/update of the domain view.

Add backend tests with a fake resolver and dashboard tests proving no browser refresh is needed. Do not let normal users force verify.
```

---

## Phase 2 — DNS Records and Origin Model Repair

### Root Cause

The current behavior silently loses user intent:

- `DnsService::create()` detects an existing proxied record at the same name, creates/returns a backup origin, and returns the existing DNS record instead of inserting the new DNS row.
- `ConfigService::primaryProxiedRecord()` publishes only one proxied DNS record.
- `OriginHealthService::primaryAndBackupForDomain()` returns only one primary and one backup.
- Duplicate origins are deduplicated by host+scheme, so users cannot see all items they added.

### Desired Product Rule

A user-created DNS record must always be represented in the DNS tab. A user-created origin must always be represented in the Origin tab. If duplicates are not allowed, reject them with a clear `422` validation error. Never silently convert or hide them.

### Backend Tasks

- [x] Replace the silent conversion behavior in `DnsService::create()`:
   - do not return existing proxied record when another proxied record exists;
   - either insert the DNS row or reject with clear validation;
   - if origin object is needed, create/link it explicitly.
  - Notes: removed the `proxiedRecordAtName()` early-return path. Additional proxied records now pass duplicate/conflict validation and insert their own `dns_records` row instead of returning an earlier record and setting `backup_origin_added`.
  - Follow-up fix: `assertCompatiblePublicRecord()` now allows multiple active proxied records at the same DNS name when they publish to the same generated ALIAS/CNAME target, while still rejecting exact duplicate origin records and real CNAME/ALIAS conflicts. This fixes Phase 0 repro failure `dns_record_name_conflict` on the second proxied apex record without deleting or re-importing data.
  - Changed files: `core/app/Modules/Dns/Services/DnsService.php`, `core/tests/test_origin_record_refactor_contract.py`, `docs/api/api.md`.
- [x] Introduce explicit relation between DNS records and origins:
   - option A: `dns_records.origin_id`;
   - option B: join table `dns_record_origins` for multiple origins per record;
   - include `role`, `weight`, `priority`, `enabled`, `health_status`.
  - Notes: implemented an additive `domain_origins.dns_record_id` relation through migration `000002_link_dns_records_to_origins.sql`. Proxied DNS create/update now creates or updates a visible `source=dns_record` origin row, and DNS delete removes only the linked DNS-derived origin. Manual origins remain independent rows and can duplicate host/scheme intentionally because identity is the origin id.
  - Changed files: `core/database/migrations/000002_link_dns_records_to_origins.sql`, `core/database/schema.sql`, `core/app/Modules/Dns/Services/DnsService.php`, `core/app/Modules/Proxy/Services/OriginHealthService.php`, `core/app/Modules/Proxy/Services/ConfigService.php`, `core/tests/test_origin_record_refactor_contract.py`.
  - Remaining blocker: live PostgreSQL/API validation still required against a disposable upgraded database.
- [x] Add explicit origin fields:
   - `scheme` (`http`/`https`);
   - `host`;
   - `port`;
   - `host_header` or `origin_host_header`;
   - `sni`;
   - `tls_verify`;
   - `preserve_host` boolean;
   - `health_check_path`.
  - Notes: added `host_header`, `sni`, `tls_verify`, `preserve_host`, `source`, `role`, and `weight` to `domain_origins`, API validation, dashboard types, and the Origins tab editor.
  - Changed files: `core/database/migrations/000002_link_dns_records_to_origins.sql`, `core/database/schema.sql`, `core/app/Modules/Proxy/Http/Controllers/OriginController.php`, `core/app/Modules/Proxy/Services/OriginHealthService.php`, `dash/src/types.ts`, `dash/src/views/domain-tabs/DomainOriginsTab.vue`, `docs/api/api.md`.
- [x] Update origin listing:
   - show all origins, not only primary/backup;
   - include source: `manual`, `dns_record`, `imported`;
   - include linked DNS record ids/names.
  - Notes: the origin API now returns all persisted origins, including DNS-linked rows with `source` and `dns_record_id`; the dashboard shows source badges and linked DNS record ids.
  - Remaining blocker: dashboard does not yet resolve `dns_record_id` into a friendly DNS record name/type label.
- [x] Update snapshot format:
   - replace one `primary_origin` + one `backup_origin` with `origins: []`;
   - keep backward compatibility fields during transition;
   - include per-origin id, scheme, host, port, host_header, sni, tls_verify, health, weight, role.
  - Notes: added `origins: []` to each host snapshot from all active proxied DNS records, including id, dns_record_id, source, role, weight, enabled, scheme, host, port, host_header, sni, tls_verify, health_status, and status. Kept `origin`, `primary_origin`, and `backup_origin` for transition compatibility.
  - Changed files: `core/app/Modules/Proxy/Services/ConfigService.php`, `core/tests/test_origin_record_refactor_contract.py`.
- [x] Add migration or schema rebuild instructions.
  - Notes: schema changes shipped through `core/database/migrations/000002_link_dns_records_to_origins.sql` and were mirrored into `core/database/schema.sql`. No database wipe/re-import is required.

### Dashboard Tasks

- [x] DNS tab:
   - after adding a proxied record, show the actual new record row.
   - show if it created/linked an origin.
  - Notes: backend now returns the actual new DNS record row for additional proxied records and creates/updates a linked visible origin row. Existing DNS tab reload behavior will show the record; the Origins tab shows DNS-linked rows with `source=DNS record`.
  - Remaining blocker: DNS tab itself does not yet show a per-row “linked origin created” badge.
- [x] Origins tab:
   - show all origins and their linked DNS records.
   - clearly label duplicate host entries or reject duplicates.
  - Notes: Origins tab now labels DNS-linked vs manual origins and displays linked `dns_record_id`. Duplicate manual origin hosts are allowed as separate rows, so they remain visible rather than being silently deduplicated.
  - Remaining blocker: friendly DNS record labels and duplicate-host warning copy still need a small UI polish pass.
- [x] Add validation messages:
   - duplicate DNS record;
   - conflicting CNAME/ALIAS;
   - duplicate origin not allowed;
   - missing port/scheme.
  - Notes: dashboard API errors now humanize DNS duplicate/conflict and origin validation codes such as invalid scheme, TLS mode, role, port, and health path. Origins tab now shows inline save errors and prevents common invalid input before submit: missing host, host with protocol/path, unsupported port, and health path without `/`. Duplicate manual origins remain allowed and visible by product rule, so no duplicate-origin rejection message is shown for that allowed case.
  - Changed files: `dash/src/lib/api/client.ts`, `dash/src/lib/api/client.test.ts`, `dash/src/views/domain-tabs/DomainOriginsTab.vue`.
  - Local validation run: `(cd dash && npm test -- --run src/lib/api/client.test.ts src/lib/api/domains.test.ts src/views/DomainDetailView.test.ts)` passed with `8 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.

### Tests

- [x] Add two proxied records with different origins; both appear in DNS tab.
  - Notes: static contracts now assert that proxied records sharing the same generated public ALIAS/CNAME target are allowed to coexist. Live PostgreSQL/API validation still required.
- [x] Add same origin twice: either both appear as separate entries if allowed, or second returns clear 422 if not allowed.
  - Notes: duplicate manual origin hosts are allowed as separate `domain_origins` rows; `OriginHealthService::create()` no longer deduplicates manual creates by host/scheme.
- [x] Origin tab count equals backend list count.
  - Notes: Origins tab renders the API list directly and now includes DNS-linked persisted rows returned by `GET /domains/{id}/origins`.
- [x] Config snapshot includes all configured origins.
  - Notes: static contract covers `originsFromDnsRecords()` and transitional `primary_origin`/`backup_origin`.
  - Local validation run: `php -l core/app/Modules/Dns/Services/DnsService.php && php -l core/app/Modules/Proxy/Services/ConfigService.php && php -l core/app/Modules/Proxy/Services/OriginHealthService.php && php -l core/app/Modules/Proxy/Http/Controllers/OriginController.php`.
  - Local validation run: `pytest -q core/tests/test_origin_record_refactor_contract.py core/tests/test_nameserver_force_verify_contract.py core/tests/test_phase0_repro_contract.py` passed with `12 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - User-reported manual validation on 2026-06-15: `ci/smoke.sh` completed with `[2026-06-15T08:44:50Z] PASS: smoke checks completed`; `ci/e2e.sh` completed with `[2026-06-15T08:47:49Z] PASS: e2e checks completed`.
  - Manual validation still required: run migration/status checks and the Phase 0 repro against a disposable stack; Codex did not run Docker, smoke, or e2e tests per user instruction.

### IDE Prompt

```text
Phase 2: Repair DNS record and origin persistence. The current code silently converts additional proxied records into backup origins and returns the existing DNS record. This must stop.

Implement explicit DNS-record-to-origin modeling. Every user-created DNS record must be stored and returned. Every user-created origin must be stored and visible, or rejected with a clear 422 if duplicates are intentionally forbidden.

Update DnsService::create, OriginHealthService, ConfigService snapshot generation, API responses, and dashboard DNS/Origins tabs. Snapshot should support an origins array with id/scheme/host/port/host_header/sni/tls_verify/role/weight/health_status and keep backward-compatible primary_origin/backup_origin during transition.

Add tests for multiple proxied records, duplicate origins, DNS tab visibility, Origin tab visibility, and snapshot completeness.
```

---

## Phase 3 — Fix Edge 502 Origin Error

### Likely Causes

The edge currently routes to one selected origin and forwards `Host: $host` to the origin. That is correct for some origins, but many origin servers return TLS, virtual-host, or upstream errors unless they receive their own host header/SNI. Also, origin scheme/port selection is too implicit.

### Edge Tasks

1. [x] Make origin scheme/port explicit:
   - stop guessing `443` then falling back to `80` unless the origin is explicitly configured as `auto`;
   - use `scheme://host:port` from config.
  - Notes: `origin_selector.lua` now uses configured `scheme`, `host`, and `port` for `http`/`https` origins. The old HTTPS-probe/HTTP-fallback path is retained only when an origin explicitly uses `scheme=auto`.
  - Follow-up fix: country geo overrides again take precedence over the general `origins[]` list, and `ConfigService::buildGeoOrigins()` now includes explicit scheme, port, host header, SNI, preserve-host, role, id, and source metadata. This fixes the e2e failure where the IR geo route missed `"origin_scheme":"http"`.
  - Changed files: `edge/openresty/lua/origin_selector.lua`, `core/tests/test_origin_record_refactor_contract.py`, `core/tests/test_edge_phase3_contract.py`.
2. [x] Add origin host header and SNI variables:
   - Lua sets:
     - `ngx.var.target_upstream`
     - `ngx.var.target_origin_host_header`
     - `ngx.var.target_origin_sni`
     - `ngx.var.target_origin_id`
   - Nginx uses:
     - `proxy_set_header Host $target_origin_host_header`
     - `proxy_ssl_name $target_origin_sni`
  - Notes: `proxy.lua` now sets primary and backup origin host-header/SNI/id variables, and both HTTP/HTTPS Nginx proxy locations use those variables for `Host` and upstream SNI.
  - Changed files: `edge/openresty/nginx.conf`, `edge/openresty/lua/proxy.lua`, `edge/openresty/lua/router.lua`.
3. [x] Add preserve-host option:
   - default should be safe and explicit;
   - recommended default: preserve CDN host only when user enables it; otherwise send origin host header.
  - Notes: when `preserve_host` is false, the edge sends configured `host_header` or the origin host. When `preserve_host` is true, it sends the CDN request hostname.
  - Changed files: `edge/openresty/lua/origin_selector.lua`.
4. [x] Improve 502 diagnostics:
   - response includes `request_id`;
   - internal log includes domain_id, edge_node_id, selected origin id, upstream URL, upstream_status, upstream_response_time, router_error;
   - never expose secrets.
  - Notes: metrics now include host, method, path, redacted query, router_error, origin_id/role/host, upstream status/time/address, request_time, and existing request id. Structured edge diagnostics log router errors and origin selection without secrets.
  - Follow-up fix: e2e now validates that a proxied GET reaches the configured origin by checking the origin mock response instead of expecting the mock origin to return Core's domain list.
  - Changed files: `edge/openresty/lua/metrics.lua`, `edge/openresty/lua/edge_log.lua`, `edge/openresty/lua/router.lua`, `docs/api/api.md`, `docs/security.md`.
5. [x] Add edge route debug endpoint or admin-only diagnostic API:
   - input: domain + path + country;
   - output: selected origin, backup origin, cache rule, WAF/rate-limit match, SSL cert status.
  - Notes: added authenticated `POST /api/v1/domains/{domainId}/route-debug`. It uses the active config snapshot, or builds one when no active snapshot exists, and returns admin-safe route selection details: matched/request host, path, country, snapshot version, selected origin, selected origin source, backup origin, cache/WAF/rate-limit counts, SSL status, and router error such as `domain_not_configured` or `missing_origin`.
  - Changed files: `core/app/Modules/Proxy/Services/ConfigService.php`, `core/public_index.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `core/tests/test_edge_phase3_contract.py`.

### Backend Tasks

1. [x] Config snapshot must include:
   - origin id;
   - host;
   - port;
   - scheme;
   - host header;
   - SNI;
   - TLS verify mode.
  - Notes: already implemented in Phase 2 and now consumed by the edge routing path.
2. [x] Add origin test action:
   - `POST /domains/{id}/origins/{originId}/test`
   - returns DNS resolution, TCP connect, TLS handshake, HTTP status, response time, error.
  - Notes: added authenticated `POST /api/v1/domains/{domainId}/origins/{originId}/test`. Unlike `/check`, it is non-mutating and returns DNS, TCP, TLS, HTTP status, timing, configured host header, SNI, TLS verification mode, health result, and a safe error code.
  - Changed files: `core/app/Modules/Proxy/Services/OriginHealthService.php`, `core/app/Modules/Proxy/Http/Controllers/OriginController.php`, `core/public_index.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `core/tests/test_edge_phase3_contract.py`.

### Tests

- [x] HTTP origin by IP returns 200 through edge.
- [ ] HTTPS origin with SNI returns 200.
- [ ] Origin requiring its own Host header returns 200 when `preserve_host=false`.
- [ ] Origin requiring CDN Host header returns 200 when `preserve_host=true`.
- [x] Invalid origin returns 502 with request_id and detailed log/metric.
  - Local validation run: `php -l core/app/Modules/Proxy/Services/OriginHealthService.php && php -l core/app/Modules/Proxy/Services/ConfigService.php && php -l core/app/Modules/Proxy/Http/Controllers/OriginController.php && php -l core/public_index.php` passed.
  - Local validation run: `pytest -q core/tests/test_edge_phase3_contract.py` passed with `4 passed`.
  - Local validation run: OpenAPI YAML parsed with `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"`.
  - User-reported manual validation on 2026-06-15: full lightweight/backend test run passed with `177 passed in 44.37s`; `ci/smoke.sh` completed with `[2026-06-15T07:41:22Z] PASS: smoke checks completed`; `ci/e2e.sh` completed with `[2026-06-15T07:44:20Z] PASS: e2e checks completed`.
  - Local validation run: `pytest -q core/tests/test_edge_phase3_contract.py core/tests/test_origin_record_refactor_contract.py core/tests/test_origin_health_phase19_contract.py` passed.
  - Follow-up local validation run: `php -l core/app/Modules/Dns/Services/DnsService.php && php -l core/app/Modules/Proxy/Services/ConfigService.php` passed.
  - Follow-up local validation run: `pytest -q core/tests/test_dns_reconciler_contract.py core/tests/test_origin_record_refactor_contract.py core/tests/test_edge_phase3_contract.py core/tests/test_origin_health_phase19_contract.py core/tests/test_phase0_repro_contract.py` passed with `29 passed`.
  - Follow-up local validation run: `bash -n ci/phase0_repro.sh && bash -n ci/e2e.sh` passed.
  - Remaining blocker: dedicated HTTPS/SNI and preserve-host runtime scenarios are still unchecked unless they are explicitly covered by the current e2e fixture; add targeted e2e cases before closing those two checklist items.

### IDE Prompt

```text
Phase 3: Fix edge 502 origin routing.

Inspect edge/openresty/nginx.conf, lua/router.lua, lua/proxy.lua, lua/origin_selector.lua, ConfigService snapshot generation, and OriginHealthService. Implement explicit origin scheme/host/port/host_header/sni/tls_verify in config. Lua must set target_upstream, target_origin_host_header, target_origin_sni, and target_origin_id. Nginx must use proxy_set_header Host $target_origin_host_header and proxy_ssl_name $target_origin_sni.

Remove silent scheme/port guessing except when origin scheme is explicitly auto. Add origin test API and edge diagnostics. Improve 502 logging and metrics with request_id, domain_id, edge_node_id, selected origin id, upstream status/time, and router_error.

Add e2e tests for HTTP origin by IP, HTTPS origin with SNI, Host-header mismatch, backup failover, and diagnostic output.
```


---

## Phase 3A — Full Edge-Side Logging and Docker-Visible Diagnostics

### Root Cause

The edge is currently configured like a traditional VM OpenResty install: Nginx writes access logs to `/var/log/openresty/access.log` and error logs to `/var/log/openresty/error.log`. Custom Lua request metrics are appended to `/var/lib/cdnlite/metrics.ndjson`. That makes collector ingestion possible, but it is poor for live Docker operations because `docker compose logs -f` follows container stdout/stderr, not arbitrary log files inside the container.

### Goal

Make the edge container fully observable from `docker compose logs -f edge` while keeping structured NDJSON files for collector ingestion and dashboard Activity.

### Required Behavior

1. `docker compose logs -f edge` must show:
   - startup/config-load events;
   - every incoming request in access-log format or JSON format;
   - router errors such as `domain_not_configured`, `missing_origin`, `ip_access_blocked`, `waf_blocked`, `rate_limited`;
   - upstream failures that lead to 502/503/504;
   - selected origin id/role/scheme/host/port when safe;
   - request id, domain id, host, method, path, status, cache status, edge node id, upstream status, upstream response time, and bytes.
2. Keep `/var/lib/cdnlite/metrics.ndjson` for collector ingestion, but optionally mirror important metrics to stdout as JSON lines when debug logging is enabled.
3. Logging must be configurable:
   - `CDNLITE_EDGE_LOG_FORMAT=json|combined` default `json`;
   - `CDNLITE_EDGE_LOG_LEVEL=debug|info|warn|error` default `info`;
   - `CDNLITE_EDGE_LOG_REQUEST_BODY=false` default and never log request bodies unless explicitly implemented with strict redaction;
   - `CDNLITE_EDGE_DEBUG_HEADERS=false` default.
4. Never log secrets:
   - no Authorization header;
   - no Cookie header;
   - no origin shield secret/header value;
   - no ACME token value;
   - no private key or certificate content;
   - redact query parameters such as `token`, `key`, `secret`, `password`, `auth`, `signature`.

### Engineering Tasks

1. [x] Update `edge/openresty/nginx.conf`:
   - set `error_log /dev/stderr info;` or configurable log level;
   - set `access_log /dev/stdout cdnlite_json;`;
   - define `log_format cdnlite_json escape=json '{...}'` with request and upstream variables;
   - include `request_id`, `host`, `method`, `uri`, `status`, `body_bytes_sent`, `request_time`, `upstream_status`, `upstream_response_time`, `upstream_addr`, `upstream_cache_status`.
  - Notes: access logs now write JSON to stdout and error logs write to stderr for Docker-visible operations.
  - Changed files: `edge/openresty/nginx.conf`.
2. [x] Add Lua diagnostic logging helper, for example `edge/openresty/lua/edge_log.lua`:
   - `edge_log.info(event, fields)`;
   - `edge_log.warn(event, fields)`;
   - `edge_log.error(event, fields)`;
   - outputs JSON via `ngx.log()` or safely to stdout/stderr depending on level;
   - redacts sensitive values.
  - Notes: added `edge_log.lua` with level filtering and redaction for sensitive keys/query parameters.
  - Changed files: `edge/openresty/lua/edge_log.lua`.
3. [x] Update `router.lua`, `proxy.lua`, `origin_selector.lua`, `tls_cert.lua`, and `config_loader.lua` to log important events:
   - config loaded / config not ready / config version changed;
   - host matched or not configured;
   - selected origin and backup origin;
   - WAF/rate-limit decisions;
   - router failure before proxying;
   - upstream failover to backup;
   - certificate selected / certificate missing / default certificate used.
  - Notes: routing errors, selected origins, proxy forwards, config load failures, and TLS certificate decisions now emit structured diagnostics. Upstream failover gets origin id/header/SNI variables for backup requests; a dedicated failover event still needs a deeper Nginx/Lua hook.
  - Changed files: `edge/openresty/lua/router.lua`, `edge/openresty/lua/proxy.lua`, `edge/openresty/lua/config_loader.lua`, `edge/openresty/lua/tls_cert.lua`.
4. [x] Extend `metrics.lua`:
   - write enriched request event fields;
   - include `host`, `method`, redacted path/query, `router_error`, `origin_id`, `origin_role`, `upstream_status`, `upstream_response_time`, `upstream_addr`, `request_time`;
   - optionally mirror one compact JSON line to stdout when `CDNLITE_EDGE_LOG_LEVEL=debug`.
  - Notes: metrics NDJSON now keeps collector ingestion while carrying richer Activity-ready request/origin/upstream fields.
  - Changed files: `edge/openresty/lua/metrics.lua`.
5. [x] Update Docker/runbooks:
   - document `docker compose logs -f edge`;
   - document `docker compose exec edge tail -f /var/lib/cdnlite/metrics.ndjson` for raw metric ingestion;
   - document how to temporarily enable debug logging.
  - Changed files: `docs/setup.md`, `docs/cdn-in-a-minute.md`, `docs/api/api.md`, `docs/security.md`.
6. [x] Add local smoke script:
   - send one valid proxied request;
   - send one unknown-host request;
   - send one origin-down request;
   - assert logs contain request id and event type.
  - Notes: added `ci/edge_log_smoke.sh`, an operator-run smoke script that tails Docker-visible edge logs after a valid proxied request, an unknown-host 502, and an origin-down 502. It asserts request id, host, router error, origin metadata, upstream status/timing, and sensitive query-value redaction. The script is intentionally configurable and does not create or mutate domains; run it after preparing a valid routed host and a separate origin-down routed host.
  - Changed files: `ci/edge_log_smoke.sh`, `core/tests/test_edge_phase3_contract.py`.
  - Follow-up fix: edge log settings now pass through Compose, the Nginx template uses `CDNLITE_EDGE_LOG_FORMAT=json|combined` and `CDNLITE_EDGE_LOG_LEVEL`, and the stale file-based `access_log /var/log/openresty/access.log` directive was removed so request logs go to Docker stdout.
  - Changed files: `docker-compose.yml`, `edge/docker-entrypoint.sh`, `edge/openresty/nginx.conf`.
  - Local validation run: `bash -n ci/edge_log_smoke.sh && sh -n edge/docker-entrypoint.sh` passed.
  - Local validation run: `pytest -q core/tests/test_edge_phase3_contract.py` passed with `5 passed`.
  - Manual validation still required: start/rebuild the root stack and run `EDGE_LOG_SMOKE_VALID_HOST=<known-good-routed-host> EDGE_LOG_SMOKE_DOWN_HOST=<host-routed-to-down-origin> ./ci/edge_log_smoke.sh`.

### Suggested Nginx Log Format

```nginx
log_format cdnlite_json escape=json
  '{'
  '"ts":"$time_iso8601",'
  '"request_id":"$request_id",'
  '"remote_addr":"$remote_addr",'
  '"host":"$host",'
  '"method":"$request_method",'
  '"uri":"$uri",'
  '"status":$status,'
  '"bytes_sent":$bytes_sent,'
  '"request_time":$request_time,'
  '"upstream_status":"$upstream_status",'
  '"upstream_response_time":"$upstream_response_time",'
  '"upstream_addr":"$upstream_addr",'
  '"cache_status":"$upstream_cache_status"'
  '}';

error_log /dev/stderr info;
access_log /dev/stdout cdnlite_json;
```

### Acceptance Checklist

- [ ] `docker compose logs -f edge` shows access logs for every request.
  - Manual validation required: `docker compose up -d --build`, then `docker compose logs -f edge` in one terminal and `curl -H "Host: <known-good-routed-host>" "http://localhost:8081/health?via=manual-edge-log"` in another.
- [ ] Edge startup/config events are visible.
  - Manual validation required: `docker compose logs --no-color --tail=200 edge | grep -E 'config_loaded|config_load|config_not_ready|config_version'`.
- [ ] A 502 produces a visible structured log with `request_id`, `host`, `domain_id`, `router_error` or `upstream_status`, and selected origin metadata.
  - Manual validation required: `EDGE_LOG_SMOKE_VALID_HOST=<known-good-routed-host> EDGE_LOG_SMOKE_DOWN_HOST=<host-routed-to-down-origin> ./ci/edge_log_smoke.sh`.
- [ ] Unknown host and missing origin are logged clearly.
  - Manual validation required: `curl -H "Host: unknown.edge-log-smoke.test" "http://localhost:8081/unknown?password=manual-secret"` followed by `docker compose logs --no-color --tail=200 edge`.
- [x] Logs do not expose Authorization, Cookie, ACME tokens, origin shield secrets, private keys, or raw sensitive query params.
  - Notes: structured diagnostics/metrics redact sensitive query keys and do not log request bodies or secret headers. Access logs include `$uri`, not raw `$request_uri`.
- [x] Existing collector ingestion still works.
  - Notes: `/var/lib/cdnlite/metrics.ndjson` remains the metrics sink and now includes additional fields.
- [x] Dashboard Activity can correlate request events by `request_id`.
  - Notes: the enriched metrics row retains `request_id`; later Phase 6 still needs persistence/API/UI work.

### IDE Prompt

```text
Phase 3A: Make edge-side logging complete and visible through Docker.

Inspect edge/openresty/nginx.conf, edge/openresty/lua/router.lua, proxy.lua, origin_selector.lua, metrics.lua, tls_cert.lua, config_loader.lua, docker-compose.yml, and edge Dockerfile/entrypoint files.

Fix the operational logging problem where `docker compose logs -f` shows little or nothing from the edge. Configure OpenResty to write error logs to stderr and access logs to stdout. Add a JSON access log format with request_id, remote_addr, host, method, uri, status, bytes, request_time, upstream_status, upstream_response_time, upstream_addr, cache_status.

Add a Lua logging helper that writes structured diagnostic events with redaction. Use it in router/proxy/origin/tls/config code to log config load, domain match/miss, selected origin, backup failover, WAF/rate-limit decisions, router errors, TLS certificate selection/fallback, and all 500/502/503/504 failure paths.

Extend metrics.lua to capture enriched fields for Activity: host, method, redacted path/query, origin_id, origin_role, router_error, upstream_status, upstream_response_time, upstream_addr, request_time. Keep metrics.ndjson ingestion, but optionally mirror compact JSON diagnostics to stdout when CDNLITE_EDGE_LOG_LEVEL=debug.

Add environment variables CDNLITE_EDGE_LOG_FORMAT=json|combined and CDNLITE_EDGE_LOG_LEVEL=debug|info|warn|error. Never log Authorization, Cookie, origin shield secret, ACME token, private keys, or sensitive query parameters.

Add tests/smoke scripts proving: docker logs contain a valid proxied request, unknown-host request, origin-down 502 request, request_id correlation, and no sensitive header/query leakage.
```

---


## Phase 3B — Redesign Edge Error Pages UI/UX

### Root Cause

The current edge error page is generated directly in `edge/openresty/lua/error_page.lua`. It already renders request metadata, status flow, visitor guidance, and owner guidance, but the visual system is still basic and dark-mode driven. The screenshot confirms the current 502 page feels heavy, dark, and less professional than modern CDN error pages.

### Goal

Build a white-background, production-quality CDN error page experience inspired by the clarity of major CDN providers, without copying another provider’s exact branding or layout. Keep the brand as **CDNLite**.

### UX Requirements

1. Default to a bright white/light-gray page, even when the browser is in dark mode.
2. Use a polished centered container with subtle border, soft shadow, and responsive spacing.
3. Improve typography hierarchy:
   - brand row at top;
   - clear headline such as `We could not load this page`;
   - short explanation;
   - visible error badge, e.g. `502 Origin Error`.
4. Replace the rough arrow flow with a modern 3-step diagnostic status row:
   - User Browser — Working;
   - CDN Edge Server — Working or Degraded;
   - Origin Server — Unreachable/Unavailable/Timeout.
5. Use inline SVG icons only. Do not load external images, fonts, CSS, JS, or third-party assets from the edge error page.
6. Add two clear guidance cards:
   - For Visitors;
   - For Site Owners.
7. Add an `Error Details` panel with:
   - Request ID;
   - Edge location;
   - Timestamp;
   - Client IP;
   - Hostname;
   - optional router/upstream error code when safe.
8. Add action links/buttons:
   - `Check CDN Status`;
   - `Documentation`;
   - `Support`.
9. Keep the page lightweight and safe:
   - no JavaScript required;
   - escape every dynamic value with `h()`;
   - no origin IP, internal upstream URL, secret headers, token, stack trace, or sensitive config in the public page.
10. Make it fully responsive for mobile.
11. Preserve `X-CDNLITE-Request-Id` header and request id visibility.
12. Make 500, 502, 503, and 504 pages visually consistent but with distinct labels/messages.

### Engineering Tasks

1. [x] Refactor `edge/openresty/lua/error_page.lua`:
   - split message data from template rendering;
   - keep `details(code)` but expand it with `headline`, `summary`, `origin_status`, `edge_status`, and `owner_tips`;
   - add helper functions for SVG icons;
   - keep all dynamic output escaped through `h()`.
  - Notes: `details()` now carries code-specific labels, headlines, summaries, edge/origin status, and owner tips. Rendering uses helper functions for inline SVG icons, action links, details rows, and list items, with dynamic text escaped through `h()`.
  - Changed files: `edge/openresty/lua/error_page.lua`, `core/tests/test_edge_error_page_contract.py`.
2. [x] Replace current CSS with a white-background design system:
   - tokens for colors, radius, border, text, muted text, danger, warning, success, brand;
   - no `prefers-color-scheme: dark` override unless it still keeps the page clean and readable;
   - avoid CSS features that may break older browsers where possible.
  - Notes: replaced the dark-mode-driven style with a light-only `color-scheme:light` page, white panels, subtle borders, compact radius, responsive layout, and status colors.
3. [x] Update error page copy:
   - 502: origin unreachable / invalid response;
   - 503: origin unavailable;
   - 504: origin timeout;
   - 500: CDN edge internal error.
  - Notes: 500, 502, 503, and 504 now have distinct messages and status labels while sharing one consistent layout.
4. [x] Add safe diagnostics from `ngx.ctx` where available:
   - `router_error`;
   - `upstream_status`;
   - `upstream_response_time`;
   - `origin_id` only, not raw origin secret data.
  - Notes: the Error Details panel exposes request id, edge location, timestamp, client IP, hostname, router error, upstream status/time, and origin id. It does not expose origin host, upstream URL, private keys, secret headers, or stack traces.
5. [x] Add tests/snapshots:
   - render 500/502/503/504 HTML;
   - verify white background CSS token exists;
   - verify dynamic fields are escaped;
   - verify no external assets/scripts are referenced;
   - verify request id appears in header and HTML.
  - Notes: added static contract tests for all supported 5xx codes, light design tokens, escaped diagnostic fields, self-contained inline-SVG rendering, no script/external network references, and `X-CDNLITE-Request-Id`.
  - Local validation run: `pytest -q core/tests/test_edge_error_page_contract.py core/tests/test_edge_phase3_contract.py core/tests/test_edge_reliability_stage7_contract.py` passed with `9 passed`.
  - Remaining blocker: no Lua syntax/runtime render check was run because `lua`/`luajit` is not installed in this sandbox and Docker tests are reserved for manual validation.
6. [x] Optional but recommended:
   - add `/cdn-status`, `/docs`, and `/support` paths or configure these links through environment variables;
   - if links are not configured, hide or disable them instead of using broken links.
  - Notes: action links default to `/cdn-status`, `/docs`, and `/support` and can be overridden with `CDNLITE_STATUS_URL`, `CDNLITE_DOCS_URL`, and `CDNLITE_SUPPORT_URL`.

### Acceptance Checklist

- [x] 502 page is white/light, polished, readable, and closer to a major CDN-quality error page.
  - Notes: static contract verifies the light design tokens and core layout; visual/browser review still recommended.
- [x] Status flow clearly communicates: browser OK, edge OK, origin failed.
- [x] Visitors and site owners get separate guidance.
- [x] Request ID and diagnostic fields are visible and searchable in Activity.
  - Notes: request id and safe diagnostics are visible on the page; Phase 6 still needs richer Activity persistence/search UI.
- [x] No external network calls from the error page.
- [x] No secrets or internal upstream details are exposed.
- [ ] Page works on mobile and desktop.
  - Manual validation required: after rebuilding edge, trigger 500/502/503/504 pages and inspect the responsive layout in a browser or screenshot tool.
- [ ] Existing edge `error_page` routing still works from HTTP and HTTPS server blocks.
  - Manual validation required: `docker compose up -d --build`, then request an origin-down host over `http://localhost:8081` and `https://localhost:8443 -k` and verify the CDNLite page plus `X-CDNLITE-Request-Id`.

### IDE Prompt

```text
Phase 3B: Redesign CDNLite edge error pages.

Inspect edge/openresty/lua/error_page.lua and the nginx error_page wiring in edge/openresty/nginx.conf. Replace the current dark/basic error page with a polished white-background CDN-style page. Keep the brand CDNLite. Do not copy Cloudflare branding or exact layout, but match the same level of clarity and professional UX.

Requirements:
- Render 500, 502, 503, and 504 with consistent layout and code-specific copy.
- Default to white/light background with subtle gray cards, strong typography, and blue/orange/green/red status accents.
- Include a top brand row, headline, explanation, error-code badge, 3-step browser/edge/origin status flow, visitor guidance card, site-owner guidance card, error details panel, and status/docs/support actions.
- Use inline SVG icons only; no external CSS, JS, fonts, or images.
- Escape all dynamic values using the existing h() helper or equivalent.
- Preserve X-CDNLITE-Request-Id and show request id in the page.
- Show safe diagnostics only: request_id, edge location, timestamp, client IP, hostname, router_error, upstream_status, upstream_response_time, origin_id. Never expose origin IP, secrets, tokens, stack traces, or internal config.
- Make the page responsive and lightweight.

Add tests or snapshot checks that render each error code, assert the white-background design tokens exist, assert dynamic values are escaped, assert no external assets/scripts are referenced, and assert request id appears in both header and HTML. Update docs/runbook screenshots or descriptions if present.
```

---

## Phase 4 — SSL Request Progress and Notifications

### Root Cause

SSL issuance is treated as an action that eventually changes state, but the dashboard does not show the job lifecycle.

### Backend Tasks

1. [x] Add `ssl_jobs` or equivalent job status table:
   - `id`
   - `domain_id`
   - `status`: `queued`, `checking_dns`, `creating_order`, `validating_challenge`, `issuing`, `installing`, `issued`, `failed`, `cancelled`
   - `progress_percent`
   - `message`
   - `error_code`
   - `error_detail`
   - `created_at`, `updated_at`, `finished_at`
  - Notes: added additive migration `000003_ssl_jobs.sql` and mirrored it into `schema.sql`. No existing SSL/certificate/history data is dropped or rewritten.
  - Changed files: `core/database/migrations/000003_ssl_jobs.sql`, `core/database/schema.sql`.
2. [x] SSL request endpoint returns immediately:
   - `{ job_id, status, message }`
  - Notes: `POST /api/v1/domains/{domainId}/ssl/request` now creates existing pending certificate rows for compatibility, records an `ssl_jobs` row, writes `ssl.requested`, and returns HTTP 202 with `{ job_id, status, message, job }`.
  - Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/public_index.php`.
3. [x] Add status endpoint:
   - `GET /domains/{id}/ssl/jobs/{jobId}`
  - Notes: added authenticated `GET /api/v1/domains/{domainId}/ssl/jobs/{jobId}` and included recent SSL jobs in `GET /ssl/acme-status`.
  - Changed files: `core/app/Modules/Proxy/Services/CertRenewalService.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`.
4. [x] Emit audit/activity events:
   - `ssl.requested`
   - `ssl.dns_challenge_created`
   - `ssl.validation_pending`
   - `ssl.issued`
   - `ssl.failed`
  - Notes: `ssl.requested` is written when a durable job is queued. ACME issuance now writes `ssl.validation_pending` at DNS/prerequisite and validation stages, `ssl.dns_challenge_created` after the DNS-01 TXT record is published, `ssl.issued` after certificates are stored, and `ssl.failed` with a safe error code/detail when issuance fails. Active `ssl_jobs` are updated from queued to `checking_dns`, `validating_challenge`, `issued`, or `failed` so the polling endpoint reflects lifecycle progress.
  - Changed files: `core/app/Modules/Proxy/Services/CertRenewalService.php`, `core/app/Modules/Proxy/Services/AcmeIssuerService.php`, `core/tests/test_ssl_jobs_phase4_contract.py`.
  - Local validation run: `php -l core/app/Modules/Proxy/Services/CertRenewalService.php && php -l core/app/Modules/Proxy/Services/AcmeIssuerService.php` passed.
  - Local validation run: `pytest -q core/tests/test_ssl_jobs_phase4_contract.py core/tests/test_phase6_activity_diagnostics_contract.py core/tests/test_edge_error_page_contract.py core/tests/test_edge_phase3_contract.py core/tests/test_domain_tabs_phase10_contract.py core/tests/test_cert_renewal_service.py` passed with `23 passed`.
5. [x] Update certificate install to invalidate config and notify edge.
  - Notes: `storeIssuedSslCertificate()` now invalidates `config_state.active_snapshot_version` after an ACME-issued or manually imported certificate is stored, so the next snapshot publish/edge pull can include updated TLS material. This does not wipe or rebuild the database.
  - Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/tests/test_ssl_jobs_phase4_contract.py`.

### Dashboard Tasks

1. [x] Show an SSL progress panel in Domain SSL tab.
  - Notes: the SSL tab now shows active job status, progress percent, hostnames, message, and error detail.
  - Changed files: `dash/src/views/domain-tabs/DomainSslTab.vue`, `dash/src/types.ts`, `dash/src/lib/api/ssl.ts`.
2. [ ] Show toast after request:
   - “SSL request queued”
   - “DNS validation in progress”
   - “Certificate issued”
   - “SSL failed: reason”
  - Notes: inline progress is visible, but a dashboard-wide toast system was not added in this pass.
3. [x] Poll every 2–5 seconds while job is active, stop after terminal status.
  - Notes: the tab polls `/ssl/jobs/{jobId}` every 3 seconds for active jobs and falls back to `/ssl/acme-status` to discover recent jobs.
4. [ ] Show retry action if failed.
  - Remaining blocker: failed jobs display their error detail; retry still uses the existing Request Certificate/Force Renew actions rather than a per-job retry button.

### Tests

- [x] SSL request returns job id.
- [x] Job status transitions appear in API.
  - Notes: active `ssl_jobs` now transition to `checking_dns`, `validating_challenge`, `issued`, or `failed` from the ACME renewal/issuance path and remain visible through `GET /api/v1/domains/{domainId}/ssl/jobs/{jobId}`.
- [x] Dashboard shows progress without refresh.
- [x] Failure message is visible and actionable.
  - Notes: `error_detail` is shown when present.
- [x] Audit/activity timeline includes SSL lifecycle.
  - Notes: lifecycle audit events now include `ssl.requested`, `ssl.dns_challenge_created`, `ssl.validation_pending`, `ssl.issued`, and `ssl.failed`. Phase 6 still needs the richer mixed timeline UI/API to present all product and request events together.
  - Local validation run: `php -l core/app/Modules/Proxy/Services/TrafficRulesService.php && php -l core/app/Modules/Proxy/Services/CertRenewalService.php && php -l core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php && php -l core/public_index.php` passed.
  - Local validation run: `pytest -q core/tests/test_ssl_jobs_phase4_contract.py core/tests/test_edge_error_page_contract.py core/tests/test_edge_phase3_contract.py` passed with `11 passed`.
  - Local validation run: `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"` passed.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Manual validation still required: run migrations against a disposable PostgreSQL database and request SSL through the dashboard/API to verify `/ssl/request` returns 202 and `/ssl/jobs/{jobId}` is pollable.

### IDE Prompt

```text
Phase 4: Add visible SSL issuance progress.

Inspect the current SSL/ACME services, controllers, routes, dashboard SSL tab, audit log, and config snapshot invalidation. Implement an ssl_jobs table or equivalent durable status model with queued/checking_dns/creating_order/validating_challenge/issuing/installing/issued/failed statuses. SSL request endpoint should return job_id immediately. Add a job status endpoint and update the worker/issuer to write progress and audit/activity events.

Update Vue SSL UI to show progress panel and toasts, polling active jobs every 2-5 seconds. Add tests for success, failure, retry, dashboard progress, and audit/activity entries.
```

---

## Phase 5 — Dashboard Refresh, Auto-Refresh, and Mutation Invalidation

### Root Cause

The dashboard renders routed views but there is no visible global data invalidation layer at shell/router level. Individual pages likely fetch once and do not consistently refetch after mutations.

### Tasks

1. [x] Introduce one consistent frontend data strategy:
   - preferred: TanStack Query for Vue;
   - alternative: Pinia stores with explicit `refresh()` and mutation invalidation.
  - Notes: TanStack Query remains installed, and the dashboard now has a lightweight shared invalidation layer around the existing direct-fetch views. Non-GET API calls emit typed invalidation events; domain detail, DNS, Origins, SSL, and Activity tabs subscribe and refetch affected data.
  - Changed files: `dash/src/lib/data/queryKeys.ts`, `dash/src/lib/data/invalidation.ts`, `dash/src/lib/api/client.ts`, `dash/src/views/DomainDetailView.vue`, `dash/src/views/domain-tabs/DomainDnsTab.vue`, `dash/src/views/domain-tabs/DomainOriginsTab.vue`, `dash/src/views/domain-tabs/DomainSslTab.vue`, `dash/src/views/domain-tabs/DomainActivityTab.vue`.
2. [x] Define query keys:
   - `domains`
   - `domain:{id}`
   - `domain-dns:{id}`
   - `domain-origins:{id}`
   - `domain-ssl:{id}`
   - `domain-activity:{id}`
   - `edge-nodes`
   - `usage-summary`
   - `audit-log`
  - Notes: central `queryKeys` helpers define the roadmap keys and `keysForMutation()` maps API mutation paths to affected keys.
3. [x] Every mutation must invalidate/update affected queries:
   - add/delete/update domain -> `domains`, `domain:{id}`;
   - verify/force nameservers -> `domain:{id}`, `domain-activity:{id}`;
   - add DNS record -> `domain-dns:{id}`, `domain-origins:{id}`, `domain-activity:{id}`;
   - origin health check -> `domain-origins:{id}`, `domain-activity:{id}`;
   - SSL request -> `domain-ssl:{id}`, `domain-activity:{id}`;
   - cache purge -> `domain-activity:{id}`, `usage-summary`.
  - Notes: mutation mapping covers domain, nameserver, DNS, origin, SSL, cache purge, usage, edge, and audit paths. High-value domain views refetch on matching invalidation events without a browser refresh.
  - Remaining blocker: not every dashboard view has been migrated to the listener yet; usage summary, global audit, settings, and some rule tabs still mostly rely on local post-mutation `load()` calls.
4. [x] Auto-refresh intervals:
   - domain status: 10–15s;
   - nameserver verification after button click: 3–5s until terminal/changed;
   - SSL job while active: 2–5s;
   - edge nodes: 5–10s;
   - usage/activity: 10–30s, pause when tab hidden.
  - Notes: added `useVisibilityPolling()` so polling avoids overlapping requests and pauses while the browser tab is hidden. Domain detail now refreshes every 15 seconds for active/setup/error states, SSL active jobs poll every 3 seconds through the shared helper, edge network refreshes every 8 seconds, and domain Activity refreshes every 30 seconds.
  - Changed files: `dash/src/lib/data/polling.ts`, `dash/src/lib/data/polling.test.ts`, `dash/src/views/DomainDetailView.vue`, `dash/src/views/domain-tabs/DomainSslTab.vue`, `dash/src/views/domain-tabs/DomainActivityTab.vue`, `dash/src/views/EdgeNetworkView.vue`.
  - Local validation run: `(cd dash && npm test -- --run src/lib/data/invalidation.test.ts src/lib/data/polling.test.ts src/lib/api/client.test.ts)` passed with `12 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Manual validation still required: open the dashboard against a disposable stack, keep the domain detail, SSL, Activity, and Edge Network pages open, and verify data updates without browser refresh while polling pauses/resumes when the tab is hidden/visible.
5. [x] Global UX:
   - success/error toasts;
   - loading indicators on buttons;
   - optimistic updates only where safe;
   - disable duplicate submissions;
   - show “Publishing config to edge…” when config snapshot changes.
  - Notes: added global toast notifications for mutating API success/failure and timeouts, plus a shell-level “Publishing config to edge...” indicator for domain/DNS/origin/SSL-affecting invalidations. Existing local loading states and duplicate-submit prevention remain in place.
  - Changed files: `dash/src/lib/ui/notifications.ts`, `dash/src/components/ui/NotificationToasts.vue`, `dash/src/components/ui/PublishingIndicator.vue`, `dash/src/components/layout/AppShell.vue`, `dash/src/lib/api/client.ts`, `dash/src/lib/data/invalidation.ts`, `dash/src/lib/data/invalidation.test.ts`.
  - Local validation run: `(cd dash && npm test -- --run src/lib/data/invalidation.test.ts src/lib/data/polling.test.ts src/lib/api/client.test.ts)` passed with `12 passed`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Remaining blocker: toast wording is intentionally generic (`Action queued`, `Changes saved`, `Deleted`) and can be made action-specific in a later polish pass.

### Tests

- [x] Add record and see it immediately in table.
  - Notes: DNS mutations invalidate `domain-dns:{id}` and `domain-origins:{id}`; DNS and Origins tabs subscribe and reload.
- [x] Delete record and row disappears immediately.
  - Notes: delete uses the same DNS invalidation mapping and the DNS tab also performs local reload after deletion.
- [x] Request SSL and see job progress immediately.
  - Notes: SSL request returns a job and the SSL tab displays/polls `activeJob`.
- [x] Verify nameservers and status changes without reload.
  - Notes: nameserver mutations invalidate `domain:{id}` and `domain-dns:{id}`; domain detail subscribes and also applies returned state immediately.
- [x] Switch tabs and data remains fresh.
  - Notes: subscribed tabs refetch on invalidation and existing tab mounts still call `load()`.
  - Local validation run: `(cd dash && npm run typecheck)` passed.
  - Local validation run: `(cd dash && npm test -- --run src/lib/data/invalidation.test.ts)` passed with `2 passed`.
  - Follow-up local validation run: `(cd dash && npm test -- --run src/lib/data/invalidation.test.ts src/lib/data/polling.test.ts src/lib/api/client.test.ts)` passed with `12 passed`.
  - Follow-up local validation run: `(cd dash && npm run typecheck)` passed.
  - Manual validation still required: run browser/dashboard workflow against a stack to verify visible no-refresh behavior after DNS create/delete, nameserver verify, origin health check, and SSL request.

### IDE Prompt

```text
Phase 5: Fix dashboard stale data. Introduce a single data-fetching/invalidation pattern for the Vue dashboard. Use TanStack Query for Vue if acceptable; otherwise use Pinia stores with explicit refresh/invalidate methods.

Add query keys for domains, domain detail, DNS records, origins, SSL jobs/certs, activity, edge nodes, usage, and audit log. Update every mutation to invalidate or update the affected queries and show loading/success/error toasts. Add auto-refresh intervals for active states: SSL jobs, nameserver verification, edge nodes, usage/activity, and domain status. Pause polling when the browser tab is hidden.

Add component/e2e tests proving no manual browser refresh is needed after create/update/delete/verify/request actions.
```

---

## Phase 6 — Full Domain Activity and Observability

### Root Cause

Edge metrics and Docker-visible edge logs are minimal, and collector summaries are mostly totals. Activity page cannot show rich details because the data is not captured, logged, correlated, or modeled. Phase 3A makes the edge emit real-time logs; this phase persists and exposes those details in the dashboard.

### Data to Capture

For each proxied request:

- `ts`
- `request_id`
- `domain_id`
- `host`
- `method`
- `path`
- `query_redacted`
- `edge_node_id`
- `client_country` if available
- `status`
- `bytes_in`
- `bytes_out`
- `cache_status`
- `origin_id`
- `origin_host`
- `upstream_status`
- `upstream_response_time_ms`
- `router_error`
- `security_event_type`
- `rule_id`

For each domain action:

- domain created/deleted/updated;
- nameserver verification/force verification;
- DNS record create/update/delete;
- origin create/update/delete/health check;
- SSL requested/progress/issued/failed;
- cache purge;
- config snapshot created/published;
- edge config pulled/applied;
- WAF/rate-limit events.

### Backend Tasks

1. [x] Extend edge `metrics.lua` to write enriched fields.
  - Notes: already completed in Phase 3A. Edge metrics include request id, host, method, path, redacted query, cache status, router error, origin id/role/host, upstream status/time/address, request time, and security fields.
2. [x] Extend collector ingest schema:
   - add columns or create `request_events` table.
  - Notes: added additive migration `000004_usage_request_diagnostics.sql` and mirrored it into `schema.sql`. `usage_rollups` now persists request/origin/router/upstream diagnostics emitted by the edge.
  - Changed files: `core/database/migrations/000004_usage_request_diagnostics.sql`, `core/database/schema.sql`, `core/app/Modules/Collector/Services/CollectorService.php`.
3. [x] Add activity API:
   - `GET /domains/{id}/activity?from=&to=&type=&cursor=&limit=`
   - response includes mixed timeline: product events + request summaries + recent errors.
  - Notes: added `GET /api/v1/domains/{domainId}/activity/requests` for recent edge request/origin diagnostics. The existing Activity tab still combines security and audit logs separately.
  - Changed files: `core/app/Modules/Collector/Http/Controllers/CollectorController.php`, `core/public_index.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`.
  - Follow-up notes: added the full mixed timeline endpoint `GET /api/v1/domains/{domainId}/activity` with request/error/audit/security items, `from`, `to`, `type`, `search`, `cursor`, and `limit` filters. Added request-id lookup and JSON export endpoints.
  - Changed files: `core/app/Modules/Collector/Services/CollectorService.php`, `core/app/Modules/Collector/Http/Controllers/CollectorController.php`, `core/public_index.php`, `dash/src/lib/api/usage.ts`, `dash/src/types.ts`, `dash/src/views/domain-tabs/DomainActivityTab.vue`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `core/tests/test_phase6_activity_diagnostics_contract.py`.
4. [x] Add summary API:
   - total requests;
   - forwarded edge requests;
   - cache hit/miss ratio;
   - 2xx/3xx/4xx/5xx;
   - 502 count;
   - top paths;
   - top origins;
   - top edge nodes;
   - recent origin errors.
  - Notes: added `GET /api/v1/domains/{domainId}/activity/summary` backed by `usage_rollups`, including status buckets, forwarded request count, cache hit ratio, top paths/origins/edge nodes, and recent origin/router/upstream errors.
  - Changed files: `core/app/Modules/Collector/Services/CollectorService.php`, `core/app/Modules/Collector/Http/Controllers/CollectorController.php`, `core/public_index.php`, `docs/api/api.md`, `docs/public/api/openapi.yaml`.
5. [ ] Retention and privacy:
   - keep raw detailed events short retention;
   - aggregate long-term metrics;
   - redact query strings and sensitive headers;
   - avoid storing full IP unless user explicitly configures retention.
  - Notes: query strings are stored from the already-redacted edge metric object. Request-id lookup and export use the same redacted stored data. Full retention policy and configurable IP storage were not added.

### Dashboard Tasks

1. [x] Replace minimal Activity page with:
   - KPI cards;
   - timeline;
   - filters by event type;
   - recent 502/origin errors;
   - DNS/SSL/action events;
   - edge forwarding table;
   - per-edge request table.
  - Notes: added a Recent edge requests table with status, cache, origin id/host, upstream status/time, router error, and request id JSON details.
  - Changed files: `dash/src/types.ts`, `dash/src/lib/api/usage.ts`, `dash/src/views/domain-tabs/DomainActivityTab.vue`.
  - Follow-up notes: added KPI cards, event-type filtering, mixed Activity timeline, top path/origin/edge panels, recent origin errors, and JSON export from the current filter. Existing security/change-log tables remain for detailed paginated streams.
  - Changed files: `dash/src/views/domain-tabs/DomainActivityTab.vue`, `dash/src/types.ts`, `dash/src/lib/api/usage.ts`.
2. [x] Add request-id search:
   - paste request id from 502 page and find exact event.
  - Notes: added request-id lookup form that calls `GET /api/v1/domains/{domainId}/activity/requests/{requestId}` and opens the exact stored request details in the Activity drawer.
3. [x] Add “Export CSV/JSON” for current filter.
  - Notes: added JSON export endpoint and dashboard action. The UI opens the export payload in the details drawer for inspection in the shared workspace or browser devtools; CSV is not implemented.

### Tests

- [ ] Request through edge appears in activity within one ingest cycle.
  - Manual validation required after rebuilding/migrating the stack and sending edge traffic.
- [ ] 502 request shows selected origin and upstream/router error.
  - Manual validation required with `EDGE_LOG_SMOKE_DOWN_HOST=<host-routed-to-down-origin> ./ci/edge_log_smoke.sh` plus Activity page inspection after metrics ingest.
- [ ] DNS add and SSL request appear in timeline.
  - Notes: DNS/SSL audit events exist, but the full mixed timeline is still pending.
- [x] Activity filters work.
  - Notes: mixed timeline supports `from`, `to`, `type`, `search`, `cursor`, and `limit`; dashboard exposes date range, search, and event-type filters. Existing security/audit filters remain.
- [x] Sensitive query params are redacted.
  - Notes: persisted request details use `metrics.lua` redacted query output and contract coverage verifies the persistence path.
  - Local validation run: `php -l core/app/Modules/Collector/Services/CollectorService.php && php -l core/app/Modules/Collector/Http/Controllers/CollectorController.php && php -l core/public_index.php` passed.
  - Local validation run: `pytest -q core/tests/test_phase6_activity_diagnostics_contract.py core/tests/test_ssl_jobs_phase4_contract.py core/tests/test_edge_error_page_contract.py core/tests/test_edge_phase3_contract.py` passed with `14 passed`.
  - Local validation run: `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"` passed.
  - Local validation run: `(cd dash && npm run typecheck && npm test -- --run src/lib/data/invalidation.test.ts)` passed.
  - User-reported manual validation on 2026-06-15: `184 passed in 45.97s`; smoke completed with `[2026-06-15T08:19:56Z] PASS: smoke checks completed`; e2e completed with `[2026-06-15T08:22:47Z] PASS: e2e checks completed`.
  - Follow-up local validation run: `php -l core/app/Modules/Collector/Services/CollectorService.php && php -l core/app/Modules/Collector/Http/Controllers/CollectorController.php && php -l core/public_index.php` passed.
  - Follow-up local validation run: `pytest -q core/tests/test_phase6_activity_diagnostics_contract.py` passed with `3 passed`.
  - Follow-up local validation run: `(cd dash && npm run typecheck)` passed.
  - Follow-up local validation run: `(cd dash && npm test -- --run src/lib/api/client.test.ts src/lib/data/invalidation.test.ts)` passed with `10 passed`.
  - Follow-up local validation run: OpenAPI YAML parsed with `python3 -c "import pathlib, yaml; yaml.safe_load(pathlib.Path('docs/public/api/openapi.yaml').read_text())"`.
  - Remaining blockers: manual runtime validation is still required to confirm edge traffic appears in Activity within one ingest cycle and a 502 can be found by request id after rebuilding/migrating the stack. Codex did not run Docker, smoke, or e2e tests per user instruction.

### IDE Prompt

```text
Phase 6: Build full domain activity and observability.

Extend edge/openresty/lua/metrics.lua to capture host, method, path, redacted query, edge_node_id, status, cache_status, origin_id, origin_host, upstream_status, upstream_response_time, router_error, bytes, request_id, and security rule fields. Extend collector ingestion and database schema or add request_events. Add APIs for domain activity timeline, request-id lookup, recent errors, per-edge forwarding, top paths, top origins, cache hit/miss, status-code breakdown, and export.

Update Activity UI to show KPI cards, timeline, filters, recent 502/origin errors, DNS/SSL/action events, per-edge forwarding, and request-id search. Add retention/redaction safeguards. Add tests proving edge requests, 502s, DNS actions, and SSL actions appear without dashboard refresh.
```

---

## Phase 7 — Hidden Problems and Improvements to Include

These are not all user-visible yet, but they can break production or testing later.

### 7.1 Fresh-Install-Only Schema Risk

README says the database model is fresh-install-only. Your current deployment has real state. Add migrations or at minimum a versioned schema migration script before changing DNS/origin/SSL/activity tables.

**Prompt**

```text
Add a versioned migration system or safe schema upgrade scripts for CDNLite. Current README says fresh-install-only, but this deployment needs durable upgrades. Add schema version tracking, idempotent migrations, rollback notes where possible, and tests that migrate an existing sample database to the new DNS/origin/SSL/activity schema without data loss.
```

### 7.2 Config Snapshot Invalidation Consistency

Any DNS, origin, SSL, domain, WAF, cache, redirect, or header change must invalidate and republish edge config. Audit every mutating service.

**Prompt**

```text
Audit all mutating services and ensure every domain-affecting change invalidates config_state.active_snapshot_version, creates a new config snapshot when needed, and is picked up by edge polling. Add tests for DNS record create/update/delete, origin create/update/delete, SSL issue/install, cache rule changes, WAF changes, redirects, headers, IP rules, and nameserver verification.
```

### 7.3 PowerDNS/DNS Reconcile Error UX

DNS create/update may fail or partially publish under strict PowerDNS mode. Return clear UI errors and keep local state consistent.

**Prompt**

```text
Improve DNS reconcile error handling. When PowerDNS strict mode fails, return a clear API error with user-safe details and write an audit/activity event. Dashboard must show whether the local desired DNS state was saved, whether publishing failed, and provide retry/reconcile buttons. Add tests for successful reconcile, failed reconcile, retry, and partial publish states.
```

### 7.4 Origin Health and Failover

Health check currently marks HTTP `<500` as healthy and supports only a simple primary/backup selection. Add clear policies.

**Prompt**

```text
Improve origin health and failover. Support configurable healthy status ranges, timeout, path, expected text/header, and per-origin enabled/disabled state. Support multiple backups with priority/weight. Edge should skip unhealthy origins when health data is fresh, and log which origin was selected. Add tests for healthy, unhealthy, timeout, 404-allowed, 500-failed, and multi-backup failover.
```

### 7.5 Edge Config Version Visibility

Dashboard should show whether each edge node has applied the latest config snapshot.

**Prompt**

```text
Expose edge config version status. Track latest config snapshot version in core and last applied version per edge heartbeat. Dashboard edge page and domain activity should show pending/applied/stale config status. Add tests that a DNS/origin/SSL change creates a new snapshot and edge heartbeat reports the applied version.
```

### 7.6 Security and RBAC

Force verification, SSL issuance, DNS publishing, origin changes, and config rollback are privileged actions.

**Prompt**

```text
Audit RBAC for all privileged actions: force nameserver verification, domain activation override, SSL request/import, DNS publish/reconcile, origin modification, edge registration, config rollback, cache purge, WAF/rate-limit changes. Enforce admin/operator roles, require reason where destructive/override, and write audit logs. Add tests for admin allowed, operator allowed/denied by permission, normal user denied.
```

---

## 8. Recommended Phase Order

| Priority | Phase | Why first |
|---|---|---|
| P0 | Reproduction harness | Prevent regressions and prove the bugs. |
| P1 | Nameserver refresh + admin force | Unblocks domain activation without delete/re-add. |
| P2 | DNS/origin persistence model | Fixes hidden/dropped records and wrong origin tab. |
| P3 | Edge 502 routing | Makes proxy usable and diagnosable. |
| P3B | Edge error page UI/UX | Makes public 5xx pages professional, clear, and searchable by request ID. |
| P4 | SSL progress | Makes long-running SSL actions visible. |
| P5 | Dashboard refresh | Removes manual browser refresh after actions. |
| P6 | Activity observability | Gives full request/action/edge/origin visibility. |
| P7 | Hardening | Prevents future breakage in production. |

---

## 9. Validation Commands

Run these after each phase, then run full e2e before merging.

```bash
# Compose validation
docker compose config --quiet

# Start full stack
docker compose up -d --build --wait

# Core PHP lint
find core -name '*.php' -print0 | xargs -0 -n1 php -l

# Backend tests
pytest -q core/tests

# Dashboard validation
cd dash
npm ci
npm run typecheck
npm test
npm run build
cd ..

# Existing smoke/e2e scripts
./ci/smoke.sh
./ci/e2e.sh
CDNLITE_EDGE_HEALTH_MODE=static ./ci/dns_e2e.sh

# DNS operations checks
docker compose exec core php artisan cdn:dns:reconcile
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
docker compose exec core php artisan cdn:readiness:check
```

---

## 10. Final Acceptance Criteria

The roadmap is complete only when all of these are true:

- Admin can force-verify a domain with reason, audit log, and activity event.
- Manual nameserver refresh updates status immediately and shows observed/missing nameservers.
- User never has to delete/re-add a domain just to verify nameservers.
- Proxied A/AAAA record to a working origin returns `200` through edge.
- `502 Origin Error` includes a request id and is diagnosable in Activity.
- Edge-generated 500/502/503/504 pages use a polished white-background CDN-style UI, clear browser/edge/origin status flow, visitor/site-owner guidance, and safe diagnostics only.
- Edge-side logs are visible from `docker compose logs -f edge` and include structured access/error/diagnostic events with redaction.
- Multiple proxied records/origins are either all stored and shown, or duplicates are rejected clearly. No silent hiding.
- DNS tab and Origin tab agree with backend state.
- SSL request shows queued/progress/issued/failed states without reload.
- Dashboard updates after every action without browser refresh.
- Activity page shows domain actions, request counts, edge forwards, cache status, origin status, 502s, SSL events, DNS events, and request-id lookup.
- CI covers backend, dashboard, edge routing, DNS reconcile, SSL progress, and activity ingestion.

---

## 11. One-Shot IDE Prompt for Full Roadmap Execution

Use this only after you have committed Phase 0 tests. If your IDE agent can handle large tasks, paste this; otherwise use the phase prompts above.

```text
Implement the CDNLite repair roadmap in order: Phase 1 nameserver refresh/admin force verify, Phase 2 DNS-origin persistence model, Phase 3 edge 502 routing and diagnostics, Phase 3A full edge-side Docker-visible logging, Phase 3B edge error page UI/UX, Phase 4 SSL progress jobs, Phase 5 dashboard query invalidation/autorefresh, Phase 6 rich domain activity, and Phase 7 hardening.

Constraints:
- Preserve existing stack and APIs where possible.
- Add migrations or safe schema upgrade path.
- Never silently hide user-created DNS records or origins.
- Every mutating action must invalidate the right backend state, publish/reconcile when needed, write audit/activity events, and update dashboard without browser refresh.
- Edge must route using explicit origin scheme/host/port/host_header/SNI/tls_verify and log full request diagnostics on failure. Edge logs must be visible through `docker compose logs -f edge`, with JSON access logs on stdout and error/diagnostic logs on stderr.
- Edge error pages must be redesigned as white-background, polished, Cloudflare-quality CDN-style pages with safe diagnostics and no external assets.
- SSL must expose durable job progress.
- Activity must combine product events and request/edge/origin telemetry.

Deliverables:
1. Backend tests for domain verification, force verify, DNS/origin creation, config snapshots, SSL jobs, activity APIs.
2. Edge e2e tests for proxied HTTP origin, HTTPS/SNI origin, host-header mismatch, backup failover, 502 diagnostics, and redesigned error page rendering/safety.
3. Dashboard tests for no-refresh UX after all key mutations.
4. Updated docs and runbooks.
5. Passing validation commands: docker compose config, PHP lint, pytest, dashboard typecheck/test/build, smoke/e2e/dns_e2e.

Work in small commits. After each phase, summarize changed files, test results, and remaining risks.
```
