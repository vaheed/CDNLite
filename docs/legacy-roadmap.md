---
title: Legacy Roadmap Notes
description: Legacy CDNLite roadmap notes retained for project history; use the current roadmap page for user-facing planning.
head:
  - - meta
    - name: robots
      content: noindex
---

# CDNLite Roadmap — Stable Edge Platform, Beginner-Friendly Protection, and Advanced Operations

**Repository:** `vaheed/CDNLite`  
**Recommended replacement file:** `docs/roadmap.md`  
**Generated:** 2026-06-18

---

## 0. Roadmap Purpose

This roadmap replaces the older stabilization-only roadmap with a product roadmap that keeps all completed stability work, keeps the engineering discipline of phased implementation, and adds the remaining phases needed to make CDNLite easier for non-expert users while preserving advanced operator control.

The product direction is:

> Simple mode for outcomes. Advanced mode for control.

CDNLite should let a beginner choose goals such as **Block exploits**, **Protect login**, **Protect API**, **Stop abusive traffic**, **Cache static assets**, or **Emergency protection** without requiring them to understand WAF rule syntax, rate-limit windows, edge config snapshots, origin SNI, bot scoring, or DNS reconciliation.

Advanced users must still be able to inspect, edit, override, export, and audit the generated technical rules.

---

## 1. Current Project Baseline

The current CDNLite stack already supports the right foundation:

- PHP control plane.
- PostgreSQL database.
- Vue 3 / TypeScript dashboard.
- OpenResty / Lua edge proxy.
- Shell-based edge agent.
- DNS / DNSGeo / PowerDNS publishing workflow.
- Domain lifecycle, nameserver verification, and activation.
- DNS records with proxy mode.
- Origin management and health checks.
- Cache settings, cache rules, and purge workflows.
- Redirects, page rules, WAF rules, rate limits, response headers, and IP access rules.
- SSL settings, ACME DNS-01 issuance, renewal scheduling, and manual certificate import.
- Edge node registration, heartbeat, config polling, metrics ingest, and security-event ingest.
- Dashboard views for domains, edge network, analytics, snapshots, events, audit log, settings, and activity.
- Docker Compose stack plus smoke/e2e validation.

The old roadmap focused on stabilizing the platform. This new roadmap keeps that work and adds the product layer above it.

---

## 2. Product Design Rules

### 2.1 Simple Controls Must Generate Real Technical Rules

Every simple option must map to real CDNLite objects.

Example:

```text
User chooses:
Protect login pages

System creates:
- WAF/login abuse rules
- Rate-limit rules for login paths
- Optional suspicious bot challenge
- Activity/audit events
- Edge config invalidation
- Dashboard status and undo state
```

### 2.2 Advanced Users Must Never Lose Control

Generated rules must be visible in the advanced rule views.

Each generated rule should show:

- Which profile created it.
- Which simple intent owns it.
- Whether it has been customized.
- What will happen if the simple intent is disabled.
- How to detach it from the managed profile.

### 2.3 Risky Settings Require Preview, Log-Only, or Confirmation

Safe settings can be one-click. Risky settings must use:

- Preview.
- Log-only first.
- Challenge before block.
- Confirmation dialog.
- Undo / rollback.
- Audit reason.

### 2.4 No Silent Overwrites

If an advanced user modifies a generated rule, CDNLite must mark it as user-modified and avoid overwriting it during profile updates unless the user confirms.

### 2.5 Every Protection Change Must Be Observable

Every protection action must create:

- Audit event.
- Domain activity item.
- Edge config invalidation when needed.
- Request/security events when triggered.

---

# Completed and Stabilized Phases

These phases summarize the completed or mostly completed work from the older roadmap. Keep these sections in the new roadmap so project history and acceptance criteria remain clear.

---

## Phase -1 — Migration-Based Production Repository

### Status

Mostly completed.

### Goal

Convert CDNLite from a fresh-install-only schema model into a stable migration-based project that can upgrade existing deployments without deleting customer/domain/DNS/origin/SSL/activity data.

### Completed Work

- Added migration infrastructure.
- Added `schema_migrations` history.
- Added ordered SQL migration loading.
- Added advisory locking.
- Added checksum validation.
- Added dry-run/status commands.
- Added legacy baseline adoption.
- Added Docker startup migration option.
- Added tests for empty database and legacy schema adoption.
- Updated docs to make migrations the production upgrade path.

### Remaining Work

- Continue enforcing: all future schema changes must ship as migrations.
- Keep `schema.sql` only as a generated/development snapshot.
- Add migration drift checks to every release process.

### Acceptance Checklist

- [x] Existing deployments can be upgraded in place.
- [x] New schema changes are migration-based.
- [x] Migration runner is idempotent.
- [x] Migration status is observable.
- [ ] Release checklist requires migration validation before tagging.

---

## Phase 0 — Reproduction Harness and Safety Baseline

### Status

Mostly completed.

### Goal

Create reliable failing/reproduction tests before changing behavior.

### Completed Work

- Added reproduction script for domain verification, proxied records, edge routing, SSL progress, activity details, and diagnostics.
- Added diagnostic capture for Compose state, core logs, edge logs, config snapshots, metrics, security events, and DNS dry-run output.
- Added contract tests so reproduction coverage cannot silently disappear.

### Remaining Work

- Keep repro reports as CI artifacts.

### Acceptance Checklist

- [x] Reproduction harness exists.
- [x] Diagnostics are captured.
- [x] Contract tests exist.

---

## Phase 1 — Nameserver Refresh and Admin Force Verification

### Status

Mostly completed.

### Goal

Allow users to refresh nameserver status immediately and allow admins to force-verify a domain with an audit reason when necessary.

### Completed Work

- Added immediate nameserver verification endpoint.
- Added expected/observed/matched/missing nameserver trace fields.
- Added admin-only force verification endpoint.
- Added expected nameserver re-seed action.
- Added audit events and config invalidation.
- Added dashboard controls with inline trace display.

### Remaining Work

- Add direct audit-entry link in the API response.
- Add deeper fake-resolver unit tests if not already present.
- Add authoritative/public resolver fallback if required for production reliability.

### Acceptance Checklist

- [x] Manual refresh updates status without deleting domain.
- [x] Force verify requires admin session and reason.
- [x] Force verify creates audit event and invalidates config.
- [x] Dashboard updates without reload.
- [ ] API response links directly to relevant audit/activity item.

---

## Phase 2 — DNS Records and Origin Model Repair

### Status

Mostly completed.

### Goal

Stop silently hiding or converting proxied DNS records and origins. Every user-created DNS record and origin must be stored, visible, and routable or clearly rejected.

### Completed Work

- Removed silent conversion of additional proxied records.
- Added explicit DNS-record-to-origin relation.
- Added visible DNS-linked origins.
- Added origin fields for scheme, host, port, host header, SNI, TLS verification, preserve-host, source, role, and weight.
- Updated snapshot format with `origins: []` while keeping transitional compatibility fields.
- Updated Origins tab to show all origins.
- Added validation and better error messages.

### Remaining Work

- Show friendly DNS record label instead of only `dns_record_id` in Origins tab.
- Add duplicate-host warning copy where duplicates are allowed.
- Continue live migration validation against disposable upgraded databases.

### Acceptance Checklist

- [x] Multiple proxied records can be stored.
- [x] DNS tab shows actual records.
- [x] Origins tab shows all origins.
- [x] Snapshot includes all configured origins.
- [ ] Origins tab resolves linked DNS record into friendly label.

---

## Phase 3 — Edge 502 Routing and Diagnostics

### Status

Mostly completed.

### Goal

Make edge origin routing explicit, reliable, and diagnosable.

### Completed Work

- Made origin scheme/host/port explicit.
- Added host header and SNI variables.
- Added preserve-host behavior.
- Added origin test API.
- Added route-debug API.
- Added enriched 502 diagnostics with request ID, origin metadata, upstream status, upstream timing, and router error.
- Added TLS verification behavior and no-verify paths.
- Added e2e coverage for HTTP origin, HTTPS origin, SNI, host header behavior, and diagnosable 502s.

### Remaining Work

- Keep Docker/e2e runtime validation in release checklist.
- Expand multi-origin failover policy in a later hardening phase.

### Acceptance Checklist

- [x] HTTP origin by IP can return 200 through edge.
- [x] HTTPS origin with SNI is supported.
- [x] Origin host header behavior is configurable.
- [x] Invalid origin returns diagnosable 502 with request ID.
- [ ] Multi-origin weighted failover is productized.

---

## Phase 3A — Docker-Visible Edge Logging

### Status

Mostly completed.

### Goal

Make edge access/error/diagnostic logs visible through `docker compose logs -f edge` while keeping structured metrics ingestion.

### Completed Work

- Changed OpenResty access logs to stdout.
- Changed error logs to stderr.
- Added JSON log format.
- Added Lua diagnostic logging helper.
- Added redaction for sensitive fields.
- Enriched metrics with request/origin/upstream/router fields.
- Added environment controls for log format and level.
- Added edge log smoke script.

### Remaining Work

- Run smoke script against rebuilt disposable stack for each release.
- Add explicit startup/config-applied log checks to release validation.

### Acceptance Checklist

- [x] Access logs are Docker-visible.
- [x] Error logs are Docker-visible.
- [x] Diagnostics are structured and redacted.
- [x] Metrics ingestion still works.
- [ ] Release validation proves unknown-host and origin-down logs are visible.

---

## Phase 3B — Edge Error Page UX

### Status

Mostly completed.

### Goal

Make edge-generated 500/502/503/504 pages professional, clear, light, responsive, self-contained, and safe.

### Completed Work

- Refactored error page rendering.
- Added code-specific messages.
- Added light design system.
- Added request ID and safe diagnostics.
- Added visitor and site-owner guidance.
- Added no-external-assets checks.
- Added tests for escaping and request ID visibility.

### Remaining Work


### Acceptance Checklist

- [x] Error pages are clear and polished.
- [x] Request ID is visible.
- [x] Safe diagnostics only.
- [x] No external assets/scripts.
---

## Phase 4 — SSL Progress and Notifications

### Status

Mostly completed.

### Goal

Make SSL issuance visible as a job lifecycle instead of an opaque action.

### Completed Work

- Added `ssl_jobs` migration.
- SSL request endpoint returns job ID and 202 status.
- Added SSL job status endpoint.
- Added lifecycle events for requested, validation, challenge created, issued, and failed.
- Added config invalidation when certificates are installed.
- Added SSL dashboard progress panel.
- Added job queue table, polling, retry, and notifications.

### Remaining Work

- Runtime validation with real or staged ACME flow.
- Stronger UI messaging for DNS validation delay states.

### Acceptance Checklist

- [x] SSL request returns job ID.
- [x] Job progress is pollable.
- [x] Dashboard shows progress without reload.
- [x] Failure is visible and actionable.
- [ ] Staged ACME runtime validation completed.

---

## Phase 5 — Dashboard Refresh and Mutation Invalidation

### Status

Mostly completed.

### Goal

Remove manual browser refresh after actions.

### Completed Work

- Added shared invalidation layer.
- Added query key definitions.
- Added mutation-to-key mapping.
- Added polling helper that pauses when tab is hidden.
- Added domain, SSL, Activity, and Edge Network auto-refresh.
- Added global toasts.
- Added publishing indicator.
- Improved nameserver and force-verify UX.

### Remaining Work

- Migrate remaining views fully to shared invalidation/listener behavior.
- Make toast wording more action-specific.
- Add full browser e2e coverage for major dashboard workflows.

### Acceptance Checklist

- [x] DNS create/delete updates visible tables.
- [x] SSL request shows progress immediately.
- [x] Nameserver status changes without reload.
- [x] Active states poll safely.
- [ ] All views use the shared invalidation pattern.

---

## Phase 6 — Domain Activity and Observability

### Status

Mostly completed.

### Goal

Provide a rich domain activity timeline with request, edge, origin, DNS, SSL, security, and audit details.

### Completed Work

- Enriched edge metrics.
- Added request/origin/router/upstream diagnostic persistence.
- Added mixed Activity timeline endpoint.
- Added request-ID lookup.
- Added summary endpoint.
- Added JSON export.
- Added retention and privacy safeguards.
- Added dashboard KPI cards, filters, timeline, recent errors, top paths/origins/edges, and request details.

### Remaining Work

- Add CSV export if required.
- Add more beginner-friendly event grouping in a later product phase.
- Keep runtime ingestion validation in release checklist.

### Acceptance Checklist

- [x] Edge request can appear in Activity.
- [x] 502 can be found by request ID.
- [x] DNS and SSL actions appear in timeline.
- [x] Filters work.
- [x] Sensitive query params are redacted.
- [ ] CSV export implemented if required.

---

## Phase 7 — Production Hardening

### Status

Partially completed.

### Goal

Fix hidden production issues that can cause stale edge config, unclear DNS errors, weak failover policy, missing edge config visibility, or insufficient permission checks.

### Completed Work

- Improved config snapshot invalidation consistency for domain/DNS/GeoDNS changes.
- Improved strict PowerDNS publish failure UX.
- Added `dns_publish_failed` API detail.
- Added `dns.reconcile.failed` audit/activity events.
- Improved dashboard error text for DNS reconcile failures.

### Remaining Work

- Origin health and failover policy.
- Edge config version visibility.
- Full RBAC audit for privileged actions.
- Broader tests for all mutating services.

### Acceptance Checklist

- [x] Domain/DNS/GeoDNS mutations invalidate config.
- [x] DNS publish failures are understandable.
- [x] DNS failures preserve local desired state.
- [x] DNS tab has per-row retry/reconcile actions.
- [ ] Edge config applied/pending/stale status is visible.
- [ ] RBAC is audited across all privileged actions.
- [ ] Origin health policy supports configurable failover.

### Progress Notes

- Date: 2026-06-18
- Changed files: `core/app/Modules/Dns/Services/DnsService.php`, `core/app/Modules/Dns/Http/Controllers/DnsController.php`, `core/public_index.php`, `dash/src/lib/api/dns.ts`, `dash/src/views/domain-tabs/DomainDnsTab.vue`, `dash/src/views/domain-tabs/DomainDnsTab.test.ts`, `core/tests/test_phase7_config_invalidation_contract.py`, `docs/api/api.md`, `docs/public/api/openapi.yaml`
- Behavior added: DNS records now expose a row-level retry/reconcile action in the dashboard, backed by a new record reconcile endpoint that invalidates config snapshots, writes an audit event, and reuses the existing PowerDNS reconciler.
- Tests added/updated: backend contract coverage for the new route; Vue test for the DNS tab row action.
- Validation commands run: `php -l core/app/Modules/Dns/Services/DnsService.php && php -l core/app/Modules/Dns/Http/Controllers/DnsController.php && php -l core/public_index.php`; `pytest -q core/tests/test_phase7_config_invalidation_contract.py`; `npm test -- --run src/views/domain-tabs/DomainDnsTab.test.ts` in `dash/`; `npm run typecheck` in `dash/`.
- Commands not run and why: full repository test matrix, Docker Compose smoke/e2e, and docs build were not run because this phase was kept to a small targeted hardening slice.
- Remaining blockers: origin failover policy, edge config version visibility, and privileged-action RBAC still need dedicated phase work.
- Manual validation still required: a live compose check of the retry action against a real PowerDNS-backed domain.

---

# Remaining Product Phases

The next phases build the simple product layer above the stabilized platform.

---

## Phase 8 — Simple/Advanced Protection Contract

### Goal

Create the internal model that links beginner-friendly protection choices to generated advanced rules.

### User Outcome

A user can enable a simple protection option, and advanced users can still inspect the exact WAF, rate-limit, bot, cache, IP, or origin rules it created.

### Backend Tasks

Add or adapt tables for:

```text
protection_profiles
protection_intents
managed_rule_links
profile_change_history
profile_rollback_points
```

Each generated technical rule must store or link to:

- `profile_id`
- `intent_id`
- `template_key`
- `managed_by`
- `user_modified`
- `last_generated_at`
- `last_applied_at`

### Dashboard Tasks

- Add labels on advanced rules:
  - `Managed by recommended protection`
  - `Managed by login shield`
  - `Customized by user`
- Add detach action. `[partial: implemented for WAF and rate-limit advanced views]`
- Add preview of generated rules before apply.
- Add undo state for simple protection changes.

### Edge Tasks

No major edge behavior change yet. Edge continues consuming generated rules through existing config snapshot.

### Tests

- [x] Generated WAF and rate-limit rules expose ownership in advanced views and API responses.
- [x] Editing generated WAF and rate-limit rules marks them as user-modified.
- [x] Detaching generated WAF and rate-limit rules preserves the rule and clears managed ownership.
- [x] Fresh-install smoke validates protection ownership tables and technical-rule columns.
- [x] E2E validates managed WAF/rate-limit metadata, managed_rule_links, user_modified, detach, and cleanup.
- [x] Fresh-install smoke validates protection intent, history, and rollback schema.
- [x] E2E validates intent preview, enable, user-modified conflict, confirmed overwrite, disable, undo, audit, history, and rollback.
- [x] Updating a profile/intent does not overwrite user-modified rules silently.
- [x] Disabling an intent disables generated rules.
- [x] Undo restores previous generated state.

### Progress Notes

- Date: 2026-06-18
- Changed files: `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/database/migrations/000006_protection_contract.sql`, `core/database/schema.sql`, `core/public_index.php`, `dash/src/lib/api/rateLimit.ts`, `dash/src/lib/api/waf.ts`, `dash/src/types.ts`, `dash/src/views/domain-tabs/DomainRateLimitsTab.vue`, `dash/src/views/domain-tabs/DomainRulesTab.vue`, `dash/src/views/domain-tabs/DomainWafTab.vue`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `ci/smoke.sh`, `ci/e2e.sh`, `core/tests/test_phase8_protection_contract.py`.
- Behavior added: technical WAF, rate-limit, IP, cache, and header rules now have managed ownership fields. WAF and rate-limit rules can be detached from managed protection without deleting the technical rule; ordinary edits to managed rules mark them as user-modified and sync the managed-rule link. Protection intent APIs now preview generated rules, enable real managed rules, reject silent overwrites of user-modified generated rules, support confirmed regeneration, disable generated rules, and undo from rollback points.
- Tests added/updated: backend Phase 8 contract coverage, smoke schema coverage for protection ownership, intent, history, and rollback tables/columns, and e2e API coverage for managed WAF/rate-limit create, edit, detach, managed_rule_links, protection intent preview/enable/conflict/disable/undo, audit events, profile_change_history, and profile_rollback_points.
- Remaining Phase 8 work: dashboard Security Center cards for beginner intent preview/apply/disable/undo flows.

### IDE Prompt

```text
Phase 8: Add the Simple/Advanced protection contract. Create models that link protection profiles and intents to generated technical rules. Generated WAF, rate-limit, IP, cache, bot, header, origin, or API rules must be traceable to profile_id, intent_id, and template_key. Advanced views must show managed labels and user_modified state. Profile updates must not overwrite user-modified rules silently. Add preview, detach, disable, undo, audit events, and tests.
```

---

## Phase 9 — Security Center

### Status

Partially completed.

### Goal

Create the main beginner-friendly domain security page.

### User Outcome

A non-expert user sees simple cards:

```text
Security Center

[ ] Block common exploits
[ ] Protect login pages
[ ] Protect API endpoints
[ ] Stop abusive traffic
[ ] Block suspicious bots
[ ] Emergency protection
```

Each card explains:

- What it protects.
- Recommended mode.
- Possible risk.
- What technical changes will be made.
- How to undo.

### Backend Tasks

Add APIs:

```text
GET  /api/v1/domains/{domainId}/protection/intents
POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/preview
POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/enable
POST /api/v1/domains/{domainId}/protection/intents/{intentId}/disable
POST /api/v1/domains/{domainId}/protection/intents/{intentId}/undo
```

### Dashboard Tasks

Add:

```text
dash/src/views/domain-tabs/DomainSecurityCenterTab.vue
```

Suggested cards:

- Common Exploit Protection
- Login Shield
- API Shield
- Smart Rate Limiting
- Bot Shield
- Emergency Protection
- Static Asset Performance

### Tests

- [x] Preview returns generated rule list.
- [x] Enable creates rules, audit events, and invalidates config.
- [x] Disable disables rules and invalidates config.
- [x] Undo restores previous state.
- [x] Dashboard shows safe/risky labels.
- [x] Dashboard exposes Security Center cards for existing protection intents.
- [x] Smoke/e2e verify the built dashboard contains Security Center and protection intent APIs.
- [x] Add backend templates for API shield, smart rate limiting, bot shield, and emergency protection.
- [ ] Add full browser e2e coverage for clicking Security Center cards in a running dashboard.

### Progress Notes

- Date: 2026-06-18
- Changed files: `dash/src/lib/api/protection.ts`, `dash/src/types.ts`, `dash/src/views/DomainDetailView.vue`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.vue`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.test.ts`, `core/tests/test_phase9_security_center_contract.py`, `ci/smoke.sh`, `ci/e2e.sh`, `docs/api/api.md`, `docs/use-cases/index.md`, `docs/ROADMAP.md`.
- Behavior added: each domain now has a Security Center tab that lists available simple protection intents, shows safe/review labels and generated-rule footprint, previews generated technical rules without mutating state, and can enable, disable, or undo existing intent-backed protections. Advanced WAF, Rate Limits, Cache, Headers, and IP Access views remain the technical inspection and override surfaces.
- Tests added/updated: focused Vue coverage for Security Center preview/enable/disable/undo, backend contract coverage for dashboard/API/docs/smoke/e2e wiring, smoke and e2e bundle checks for Security Center and protection intent API strings.
- Validation commands run: `npm test -- --run src/views/domain-tabs/DomainSecurityCenterTab.test.ts src/views/DomainDetailView.test.ts` in `dash/`; `npm test` in `dash/`; `npm run typecheck` in `dash/`; `npm run build` in `dash/`; `pytest -q core/tests/test_phase8_protection_contract.py`; `find core -name '*.php' -print0 | xargs -0 -n1 php -l`; `docker compose config --quiet`; `npm run docs:build` in `docs/`.
- Commands not run and why: live `ci/smoke.sh` and `ci/e2e.sh` were updated but not run against a live root stack in this pass.
- Remaining blockers: browser-driven Security Center e2e still needs a running UI automation layer.

### Progress Notes

- Date: 2026-06-18
- Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/tests/test_phase9_security_center_contract.py`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.vue`, `docs/api/api.md`, `docs/use-cases/index.md`, `docs/ROADMAP.md`.
- Behavior added: Security Center now has real backend templates for API Shield, Smart Rate Limiting, Bot Shield, and Emergency Protection, all generated through existing advanced WAF/rate-limit rule types so preview, enable, disable, undo, audit/history, and config invalidation use the established Phase 8 flow.
- Tests added/updated: Phase 9 backend contract now requires all seven Security Center intent keys and the new generated rule template keys.
- Validation commands run: `php -l core/app/Modules/Proxy/Services/TrafficRulesService.php`; `pytest -q core/tests/test_phase9_security_center_contract.py`; `npm test -- --run src/views/domain-tabs/DomainSecurityCenterTab.test.ts` in `dash/`; `npm run typecheck` in `dash/`; `npm run build` in `dash/`; `docker compose config --quiet`; `npm run docs:build` in `docs/`.
- Remaining blockers: browser-driven Security Center e2e still needs a running UI automation layer; richer bot/API engines remain future Phase 13/14 work.

### IDE Prompt

```text
Phase 9: Build the domain Security Center. Add beginner protection cards for common exploits, login protection, API protection, smart rate limiting, bot protection, emergency protection, and static asset performance. Each card needs plain-English copy, safe/risky label, preview, enable, disable, undo, and advanced details. Backend must generate real technical rules, write audit/activity events, and invalidate edge config. Add backend and dashboard tests.
```

---

## Phase 10 — One-Click Protection Profiles

### Goal

Let users apply a complete recommended setup for their site type.

### Profiles

- Basic Website
- WordPress
- API
- SaaS App
- E-commerce
- Emergency Protection

### Profile Mapping

```text
Basic Website:
- common exploit protection
- scanner protection
- static asset cache
- origin safety checks

WordPress:
- common exploit protection
- wp-login protection
- xmlrpc protection
- WordPress scanner protection
- static asset cache

API:
- API path discovery
- API rate limits
- method restrictions
- malformed request checks

SaaS App:
- login protection
- API protection
- dashboard/admin protection
- suspicious bot challenge

E-commerce:
- login protection
- checkout protection
- bot protection
- stricter POST limits

Emergency Protection:
- challenge suspicious traffic
- tighten rate limits
- block known scanner patterns
- protect origin from cache bypass abuse
```

### Backend Tasks

- Define profile templates.
- Add profile apply/update/disable APIs.
- Add conflict detection with user-modified rules.
- Add rollback point before apply.

### Dashboard Tasks

- Add profile cards to onboarding and Security Center.
- Show before/after preview.
- Show profile status and last applied time.

### Tests

- Applying WordPress profile creates expected intents.
- API profile detects `/api/*` or lets user define it.
- Emergency profile can be reverted.
- User-modified rule conflict shows confirmation.

### Progress Notes

- Backend profile templates and list/preview/apply/disable endpoints are wired through the traffic-rules controller and public router.
- Profile apply composes the existing protection intent engine, stores profile ownership on generated rules, writes profile/audit history, creates rollback points, and preserves user-modified rule conflict checks with `confirm_overwrite`.
- Smoke checks verify profile schema and dashboard profile API references. E2E now lists, previews, applies, and disables the Basic Website profile against live PostgreSQL-backed rules.

### Progress Notes

- Date: 2026-06-18
- Changed files: `dash/src/views/domain-tabs/DomainSecurityCenterTab.vue`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.test.ts`, `docs/ROADMAP.md`.
- Behavior added: Security Center profile cards now show the protection outcomes bundled into each one-click profile, display the last-applied timestamp for enabled profiles, and show a before/after summary in profile previews before the user applies a preset.
- Tests added/updated: focused Vue coverage for one-click profile bundle details, last-applied status, and before/after preview copy.
- Validation commands run: `npm test -- --run src/views/domain-tabs/DomainSecurityCenterTab.test.ts` in `dash/`; `npm run typecheck` in `dash/`; `npm run build` in `dash/`; `pytest -q core/tests/test_phase10_protection_profiles_contract.py`; `npm run docs:build` in `docs/`; `docker compose config --quiet`.
- Remaining blockers: full browser e2e coverage for clicking one-click profile cards in a running dashboard; broader profile tests for every preset beyond the existing API/e2e Basic Website flow.

### Progress Notes

- Date: 2026-06-18
- Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/tests/test_phase9_security_center_contract.py`, `core/tests/test_phase10_protection_profiles_contract.py`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.vue`, `dash/src/views/domain-tabs/DomainSecurityCenterTab.test.ts`, `docs/api/api.md`, `docs/use-cases/index.md`, `docs/ROADMAP.md`.
- Behavior added: WordPress profiles now include an explicit WordPress Hardening intent that generates XML-RPC and scanner WAF rules, and E-commerce profiles now include an explicit Checkout Protection intent that generates checkout rate-limit and method-probe rules. Both remain advanced-rule backed, previewable, auditable, and conflict-safe through the existing profile engine.
- Tests added/updated: Phase 10 contract coverage now pins the named WordPress and checkout outcomes, and Phase 9 Security Center coverage includes the new built-in intent templates.
- Validation commands run: `php -l core/app/Modules/Proxy/Services/TrafficRulesService.php`; `pytest -q core/tests/test_phase9_security_center_contract.py core/tests/test_phase10_protection_profiles_contract.py`; `npm test -- --run src/views/domain-tabs/DomainSecurityCenterTab.test.ts` in `dash/`; `npm run typecheck` in `dash/`; `npm run build` in `dash/`; `npm run docs:build` in `docs/`; `docker compose config --quiet`.
- Remaining blockers: live/browser e2e still needs to exercise profile card clicks for every preset in a running dashboard.

### IDE Prompt

```text
Phase 10: Add one-click protection profiles. Implement Basic Website, WordPress, API, SaaS App, E-commerce, and Emergency Protection profiles. Each profile should create protection intents that generate real WAF, rate-limit, bot/IP, cache, origin, and API rules. Add preview, apply, update, disable, rollback, conflict detection, audit events, and dashboard profile cards. Add tests for each profile and user-modified rule conflict behavior.
```

---

## Phase 11 — Managed WAF Presets

### Status

Partially completed.

### Goal

Make WAF usable through simple “Block exploits” controls while preserving detailed WAF rule control.

### Managed WAF Groups

- SQL Injection
- Cross-Site Scripting
- Path Traversal
- Local File Inclusion
- Remote File Inclusion
- Command Injection
- PHP Exploit Patterns
- WordPress Exploit Patterns
- Scanner / Recon Tools
- Suspicious Encodings
- Known Bad User Agents

### Modes

- Log only
- Challenge suspicious
- Block high-confidence
- Strict block

### Backend Tasks

- Add WAF managed group definitions.
- Add severity/confidence metadata.
- Add exceptions by path, IP, header, and rule ID.
- Add learning/log-only mode.
- Add event enrichment for WAF matches.

### Dashboard Tasks

Beginner view:

```text
Common Exploit Protection: On
Mode: Recommended
Blocked attacks today: 42
```

Advanced view:

```text
Rule group | Action | Matches | Last matched | Exceptions
```

### Edge Tasks

WAF events must include:

- `request_id`
- `rule_id`
- `group_id`
- `action`
- `confidence`
- `severity`
- `safe_reason`

### Tests

- SQL injection payload is blocked in block mode.
- XSS payload is logged in log-only mode.
- Path exception only bypasses intended path.
- WAF event appears in Activity.

### Progress Notes

- Date: 2026-06-18
- Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/app/Modules/Collector/Services/CollectorService.php`, `core/database/migrations/000007_managed_waf_metadata.sql`, `core/database/schema.sql`, `edge/openresty/lua/router.lua`, `core/tests/test_phase11_managed_waf_presets_contract.py`, `docs/api/api.md`, `docs/ROADMAP.md`.
- Behavior added: generated WAF rules now carry managed preset metadata for group, severity, confidence, and safe reason; edge `waf_match` events include the same context for Activity ingestion.
- Tests added/updated: Phase 11 contract coverage for managed WAF metadata, schema migration, edge event fields, collector persistence, and docs tracking.
- Validation commands run: `php -l core/app/Modules/Proxy/Services/TrafficRulesService.php`; `php -l core/app/Modules/Collector/Services/CollectorService.php`; `find core -name '*.php' -print0 | xargs -0 -n1 php -l`; `pytest -q core/tests/test_phase11_managed_waf_presets_contract.py core/tests/test_migrations_contract.py`; `pytest -q core/tests/test_phase8_protection_contract.py core/tests/test_phase9_security_center_contract.py core/tests/test_phase10_protection_profiles_contract.py core/tests/test_phase11_managed_waf_presets_contract.py`; `docker compose config --quiet`; `npm run docs:build` in `docs/`; `git diff --check`.
- Commands not run and why: dashboard typecheck/tests/build were not run because this slice changed no dashboard code or types; OpenAPI validation was not run because no route or schema contract changed; live smoke/e2e were not run because this was a small metadata/event enrichment slice and the root stack was not started.
- Remaining blockers: full managed WAF group catalog, exceptions, mode switching, learning/log-only workflows, dashboard group statistics, and payload-level edge tests still need dedicated slices.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/public_index.php`, `core/tests/test_phase11_managed_waf_presets_contract.py`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `docs/ROADMAP.md`.
- Behavior added: Phase 11 now exposes a read-only managed WAF preset catalog at `GET /api/v1/domains/{domainId}/protection/waf-presets`, returning available WAF modes, roadmap group definitions, and existing generated WAF rule templates grouped by `waf_group_id` without mutating rules or edge config.
- Tests added/updated: Phase 11 contract coverage now requires the catalog route, service/controller methods, OpenAPI entry, read-only docs, the managed WAF modes, and the roadmap WAF group list.
- Remaining blockers: mode application, exceptions, learning/log-only workflow, dashboard group statistics, and payload-level edge tests still need dedicated slices.

### IDE Prompt

```text
Phase 11: Add managed WAF presets. Build beginner Common Exploit Protection backed by WAF groups for SQLi, XSS, path traversal, LFI/RFI, command injection, PHP/WordPress patterns, scanners, suspicious encodings, and bad user agents. Support log-only, challenge, block high-confidence, and strict modes. Add severity, confidence, exceptions, learning mode, enriched WAF events, dashboard beginner/advanced views, and tests.
```

---

## Phase 12 — Smart Rate Limiting

### Goal

Allow users to stop abusive request volume without understanding thresholds.

### Simple Controls

- Protect login pages.
- Protect API endpoints.
- Stop form spam.
- Limit expensive pages.
- Emergency traffic limit.

### Recommended Defaults

| Intent | Default Paths | Threshold | Window | Action | Duration |
|---|---|---:|---:|---|---:|
| Login Protection | `/login`, `/admin`, `/wp-login.php` | 10 | 60s | Challenge | 10m |
| API Protection | `/api/*` | 120 | 60s | 429 | 5m |
| Form Spam | `/contact`, `/signup` | 5 POSTs | 60s | Challenge | 15m |
| Expensive Pages | configured | 30 | 60s | 429/challenge | 5m |
| Emergency | all paths | 300 | 60s | Challenge/block | 10m |

### Backend Tasks

- Add rate-limit templates.
- Add dry-run mode.
- Add preview impact based on recent Activity.
- Add per-IP and per-header/token limit keys.
- Add generated rule metadata.

### Dashboard Tasks

- Suggest login/API paths from Activity.
- Show preview:
  - “This would have challenged 18 requests in the last 24 hours.”
- Show activity summary after enable.

### Edge Tasks

Rate-limit events must include:

- `request_id`
- `rate_limit_id`
- `limit_key_type`
- `threshold`
- `current_count`
- `window_seconds`
- `action`
- `retry_after`

### Tests

- Login rate limit challenges after threshold.
- API rate limit returns 429.
- Dry-run logs but does not block.
- Activity shows rate-limit events.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/public_index.php`, `core/tests/test_phase12_smart_rate_limiting_contract.py`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `docs/ROADMAP.md`.
- Behavior added: Phase 12 now exposes a read-only Smart Rate Limiting template catalog at `GET /api/v1/domains/{domainId}/protection/rate-limit-templates`, covering login protection, API protection, form spam, expensive pages, and emergency traffic limiting. Each template returns the advanced `rate_limit_rules` shape, safe defaults, recommended mode, and a recent Activity impact estimate via `preview_impact.would_have_matched_24h`.
- Tests added/updated: Phase 12 contract coverage now requires the catalog route, service/controller methods, OpenAPI entry, docs, built-in templates, and preview-impact field.
- Remaining blockers: dry-run mode, per-header/token keys, challenge action support, richer dashboard path suggestions, enriched edge rate-limit event fields, and payload-level edge tests still need dedicated slices.

### Progress Notes

- Date: 2026-06-19
- Changed files: `edge/openresty/lua/router.lua`, `core/app/Modules/Collector/Services/CollectorService.php`, `core/tests/test_phase12_smart_rate_limiting_contract.py`, `docs/api/api.md`, `docs/ROADMAP.md`.
- Behavior added: edge `rate_limited` security events now include `rate_limit_id`, `limit_key_type`, `threshold`, `current_count`, `window_seconds`, and `retry_after`; collector ingestion persists those fields into Activity details while preserving the existing `decision`, `request_id`, path, method, and hashed client IP fields.
- Tests added/updated: Phase 12 contract coverage now pins the edge and collector event-enrichment fields.
- Remaining blockers: dry-run mode, per-header/token keys, challenge action support, richer dashboard path suggestions, and payload-level edge tests still need dedicated slices.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/database/migrations/000008_rate_limit_header_keys.sql`, `core/database/schema.sql`, `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `edge/openresty/lua/router.lua`, `dash/src/types.ts`, `dash/src/views/domain-tabs/DomainRateLimitsTab.vue`, `core/tests/test_phase12_smart_rate_limiting_contract.py`, `core/tests/test_migrations_contract.py`, `core/tests/test_traffic_rules_validation_contract.py`, `docs/api/api.md`, `docs/ROADMAP.md`.
- Behavior added: advanced rate-limit rules now support `header` and `header_path` key types with `key_header_name`, allowing API-token or Authorization-header limits to be represented in PostgreSQL, API responses, config snapshots, dashboard advanced editing, and edge counters. Missing headers fall back to client IP to avoid one shared unauthenticated bucket.
- Tests added/updated: Phase 12 contract coverage now pins migration/schema/API/edge/dashboard/docs support for header-based keys, migration coverage includes the new migration, and validation coverage rejects missing or invalid header names.
- Remaining blockers: dry-run mode, challenge action support, richer dashboard path suggestions, and payload-level edge tests still need dedicated slices.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php`, `core/app/Modules/Proxy/Services/TrafficRulesService.php`, `core/public_index.php`, `edge/openresty/lua/router.lua`, `dash/src/lib/api/rateLimit.ts`, `dash/src/views/domain-tabs/DomainRateLimitsTab.vue`, `core/tests/test_phase12_smart_rate_limiting_contract.py`, `docs/api/api.md`, `docs/public/api/openapi.yaml`, `docs/ROADMAP.md`.
- Behavior added: Smart Rate Limiting now supports dry-run previews, challenge action mode, and dashboard path suggestions. Dry-run returns a non-mutating `preview_impact` estimate, while `challenge` emits a distinct `challenge_required` edge response and still records security events. The Rate Limits tab now shows common beginner path suggestions and exposes the `challenge` action in the advanced editor.
- Tests added/updated: Phase 12 contract coverage now requires the dry-run route, API client, dashboard support, and challenge behavior.
- Remaining blockers: payload-level edge tests still need a dedicated slice.

### IDE Prompt

```text
Phase 12: Add Smart Rate Limiting. Implement beginner controls for login protection, API protection, form spam, expensive pages, and emergency traffic limiting. Add templates, safe defaults, dry-run mode, preview impact from Activity, per-IP and per-header/token limit keys, generated rule metadata, edge event enrichment, and dashboard path suggestions. Add tests for challenge, 429, dry-run, disable, and Activity visibility.
```

---

## Phase 13 — Bot Protection

### Status

Partially completed (2026-06-20).

### Progress Notes

- Bot Shield now generates explicit scraper and unverified-search-bot policies with class, score, and decision metadata.
- The edge emits `bot_match` security events with `bot_class`, `bot_score`, `bot_action`, and `request_id`; the collector, Operations view, and dashboard filter treat them as security events.
- Search-bot User-Agent claims are challenged rather than allowed, because User-Agent alone is not verification.
- Verified bot sources can now be published in edge config from `verified_bot_sources`; the edge allows a claimed search crawler only when the request matches both the configured CIDR and User-Agent pattern.

### Remaining Work

- Add automated reverse-DNS and forward-confirmation refresh for verified search-bot sources.
- Add configurable bot policies, richer behavioral signals, and Security Center match statistics.

### Goal

Protect against suspicious automation without requiring users to configure bot scores manually.

### Simple Controls

- Allow verified search bots.
- Block fake search bots.
- Challenge suspicious bots.
- Block obvious scrapers.
- Protect login from bots.
- Protect API from automated abuse.

### Bot Classes

```text
good_bot
verified_search_bot
monitoring_tool
unknown_automation
suspicious_bot
scraper
credential_attack_bot
ddos_bot
```

### Signals

- User-Agent.
- Reverse DNS validation for search bots where practical.
- Request rate.
- Path behavior.
- Header quality.
- Cookie/challenge behavior.
- ASN/country risk.
- Repeated 403/404/429 behavior.

### Backend Tasks

- Add bot policy templates.
- Add verified bot allowlist model.
- Add fake bot detection policy.
- Add bot event aggregation.

### Dashboard Tasks

Beginner view:

```text
Bot Protection: Recommended
Verified bots allowed: 2
Suspicious bots challenged today: 127
Scrapers blocked today: 34
```

Advanced view:

```text
Bot class | Action | Matches | Last seen | Rule source
```

### Edge Tasks

- Add `bot_class`, `bot_score`, and `bot_action` to request context and events.
- Do not trust User-Agent alone for verified search bots.

### Tests

- Verified search bot is allowed.
- Fake search bot is challenged or blocked.
- Suspicious high-rate client is challenged.
- Bot event appears in Activity.

### IDE Prompt

```text
Phase 13: Add Bot Protection. Build simple controls for verified search bots, fake search bot blocking, suspicious bot challenge, scraper blocking, login bot protection, and API bot protection. Add bot classes, bot scoring signals, verified bot validation where practical, bot policy templates, event aggregation, dashboard beginner/advanced views, and edge event fields bot_class, bot_score, bot_action, request_id. Add tests for verified bots, fake bots, scrapers, login bots, and Activity visibility.
```

---

## Phase 14 — API Protection

### Status

Implemented (2026-06-20).

### Progress Notes

- API Shield now generates scoped `/api/` method restrictions, IP/path limits, and `Authorization` header/path limits through the existing managed intent/profile engine.
- Added API path discovery at `GET /api/v1/domains/{domainId}/protection/api-paths`, returning Activity-derived API prefixes with safe `/api/` defaults when no traffic exists.
- Added the `path_method_not_allowed` WAF match type at the edge so unsupported methods are blocked only inside the configured API prefix and still emit normal WAF security events.
- Added dashboard API client/types, advanced WAF editor support, docs/OpenAPI, smoke checks, e2e API discovery/preview coverage, and focused Phase 14 contract tests.

### Goal

Provide one-click API security controls.

### Simple Controls

- Protect `/api/*`.
- Allow only selected methods.
- Rate limit by IP.
- Rate limit by token/header.
- Block malformed API requests where safe.
- Apply stricter limits to unauthenticated requests.

### Backend Tasks

- Add API path discovery from Activity.
- Add `protect_api` intent.
- Add method restriction rules.
- Add API-specific rate-limit templates.
- Add optional header/token limit keys.
- Add safe request validation options.

### Dashboard Tasks

API wizard:

```text
Which paths are APIs?
Which methods are allowed?
Do you use Authorization headers?
Do you want per-token limits?
Should unauthenticated requests have stricter limits?
```

### Edge Tasks

- Enforce method restrictions.
- Enforce API-specific rate limits.
- Emit API protection events.

### Tests

- Unsupported method is blocked.
- API limit applies only to API path.
- Header-based limit key works.
- Activity shows API protection events.

### IDE Prompt

```text
Phase 14: Add API Protection. Implement API path discovery, protect_api intents, method restrictions, API-specific rate limits, optional Authorization/header-based limit keys, stricter unauthenticated limits, and safe malformed request checks where edge supports them. Add an API Protection wizard, generated rule preview, config invalidation, API protection events, and tests.
```

---

## Phase 15 — Performance Starter

### Status

Implemented (2026-06-20).

### Progress Notes

- Added safe static-asset cache controls, query-string normalization limited to static extensions, and logged-in session-cookie bypass at the edge.
- Added API/dashboard configuration, schema migration, and smoke/e2e coverage for static cache hits, cookie bypasses, and query-string cache reuse.

### Goal

Make caching simple and safe for non-experts.

### Simple Controls

- Cache static assets.
- Respect origin cache headers.
- Ignore query strings for static files.
- Bypass cache for logged-in users.
- Cache everything on selected paths.
- Purge cache.

### Safe Static Extensions

```text
css js png jpg jpeg gif svg webp ico woff woff2 ttf mp4 pdf
```

### Common Bypass Cookies

```text
session
auth
wordpress_logged_in
laravel_session
```

### Backend Tasks

- Add performance profile templates.
- Map controls to existing cache settings/rules.
- Add generated cache rule metadata.
- Add cache preview and purge audit events.

### Dashboard Tasks

Beginner view:

```text
Performance
Static asset cache: On
Cache hit ratio: 72%
Bandwidth saved: 1.4 GB
Recommended: Enable longer static asset TTL
```

Advanced view keeps raw cache rules.

### Edge Tasks

- Ensure cache status is visible in Activity.
- Add rule source metadata to cache events where possible.

### Tests

- Static asset is cached.
- Authenticated cookie bypasses cache.
- Purge clears cache.
- Cache hit ratio appears in Activity summary.

### IDE Prompt

```text
Phase 15: Add Performance Starter controls. Build simple cache setup for static asset caching, respecting origin headers, ignoring query strings for static files, bypassing logged-in users, selected cache-everything paths, and purge workflows. Map to existing cache rules/settings, add generated metadata, preview, audit/activity events, and tests for static caching, auth-cookie bypass, purge, and Activity summaries.
```

---

## Phase 16 — Recommendation Engine

### Status

Implemented (2026-06-20).

### Progress Notes

- Added a deterministic recommendation engine backed by the `recommendations` table, fresh-install schema, migration, generator command, and authenticated API routes for list, generate, apply, dismiss, and snooze.
- Recommendations now use Activity/request diagnostics, cache hit ratio, origin 502s, SSL state, security events, and protection intent state to suggest Login Shield, API Protection, origin diagnostics, static asset caching, Bot Protection, common exploit protection, and SSL review.
- Added dashboard recommendation panels to Overview and domain Security Center with confidence, risk, impact, one-click actions, dismissal/snooze, and “Why am I seeing this?” explanations.
- Added docs, OpenAPI entries, smoke checks, and e2e coverage for recommendation generation and dismissed-recommendation suppression, including the regeneration parameter fix.

### Goal

Make CDNLite proactive by suggesting safe improvements based on traffic, errors, security events, and configuration.

### Recommendation Examples

- Login page has repeated requests. Enable Login Shield.
- API path has high volume. Enable API Protection.
- Origin returned many 502s. Run Origin Test.
- Cache hit ratio is low. Enable Static Asset Cache.
- Suspicious bots are hitting sensitive paths. Enable Bot Protection.
- Domain has no exploit protection. Enable Common Exploit Protection.

### Backend Tasks

Add recommendation model:

```text
recommendations
- id
- domain_id
- type
- title
- message
- confidence
- risk
- impact
- preview_payload
- one_click_action
- status
- dismissed_at
- applied_at
- created_at
- updated_at
```

Add generator command:

```bash
php artisan cdn:recommendations:generate
```

### Dashboard Tasks

- Recommendations panel on Overview.
- Recommendations panel in Security Center.
- One-click preview/apply.
- Dismiss/snooze.
- “Why am I seeing this?” explanation.

### Tests

- Login traffic creates login recommendation.
- 502 spike creates origin diagnostic recommendation.
- Low cache hit ratio creates cache recommendation.
- Dismissed recommendation does not reappear immediately.

### IDE Prompt

```text
Phase 16: Add a recommendations engine. Use Activity, request diagnostics, cache metrics, SSL state, origin errors, security events, and current configuration to generate recommendations with confidence, risk, impact, preview payload, one-click action, dismiss, and snooze. Add generator command, API, dashboard panels, and tests for login protection, API protection, 502 diagnostics, cache improvement, bot protection, and dismissed recommendation behavior.
```

---

## Phase 17 — Guided Onboarding Wizard

### Status

Implemented (2026-06-20).

### Progress Notes

- Added persistent per-domain onboarding state with answers, recommended profile, skip/resume lifecycle, completion tracking, and audit events. Dashboard skip now acts as a durable per-domain dismissal while preserving API-level resume state.
- Added deterministic recommendation logic for emergency, WordPress, e-commerce, API, SaaS app, and Basic Website profiles.
- Added onboarding preview/apply endpoints that reuse the existing Protection profile engine so generated rules, audit/history, rollback, and config invalidation stay aligned with Security Center.
- Added setup progress for domain added, nameservers, origin, SSL, protection profile, and edge readiness.
- Added a Security Center guided onboarding panel, themed country selection, full generated-rule preview details, API client/types, docs/OpenAPI entries, smoke schema/bundle checks, e2e skip/resume/apply coverage, and focused Phase 17 contract tests.

### Goal

Help new users choose safe protection and performance defaults during first setup.

### Wizard Questions

```text
What type of site are you adding?
Do users log in?
Do you have an API?
Do you sell products?
Which countries do you serve?
Are you currently under attack?
Do you use WordPress or another known framework?
Do you want recommended protection enabled now?
```

### Backend Tasks

- Store onboarding answers.
- Generate profile recommendation from answers.
- Preview recommended changes.
- Apply selected profile.
- Allow skip/resume.

### Dashboard Tasks

Setup progress:

```text
1. Domain added
2. Nameservers pending/verified
3. Origin configured
4. SSL queued/active
5. Protection profile selected
6. Edge ready
```

### Tests

- WordPress answers recommend WordPress profile.
- API answers recommend API profile.
- Under-attack answer recommends Emergency Protection.
- User can skip and resume later.

### IDE Prompt

```text
Phase 17: Add a guided onboarding wizard. Ask simple questions about site type, login, API, e-commerce, countries served, framework, and attack status. Generate a recommended protection profile, preview changes, apply profile, and allow skip/resume. Integrate with domain lifecycle, nameserver verification, origin setup, SSL setup, and Security Center. Add tests for recommendation logic and skip/resume behavior.
```

---

## Phase 18 — Beginner Activity UX

### Status

Implemented on 2026-06-20.

### Goal

Make Activity useful to non-experts while preserving advanced diagnostics.

### User Outcome

Beginner Activity summary:

```text
Today CDNLite protected your site from:

- 42 exploit attempts
- 18 suspicious bots
- 6 login abuse attempts
- 3 origin errors
- 1 SSL action

Recommended action:
Enable Login Shield
```

### Backend Tasks

- Add friendly event labels.
- Group events by protection intent/profile.
- Add beginner summary endpoint if needed.
- Keep raw advanced fields available.

### Dashboard Tasks

Add toggle:

```text
Simple view | Advanced view
```

Simple event cards:

- Blocked exploit attempt.
- Challenged suspicious bot.
- Stopped too many login requests.
- Origin error detected.
- SSL certificate issued.
- DNS change published.

### Tests

- WAF event maps to friendly exploit message.
- Rate-limit event maps to login abuse message.
- Bot event maps to suspicious bot message.
- Advanced detail still shows raw fields.

### IDE Prompt

```text
Phase 18: Add beginner Activity UX. Create friendly labels and grouped summaries for WAF, rate-limit, bot, origin, SSL, DNS, cache, and audit events. Add Simple/Advanced Activity toggle. Simple view should show readable cards and recommendations; advanced view must preserve request ID lookup, raw detail, filters, and export. Add tests mapping raw events to friendly labels.
```

---

## Phase 19 — Plans, RBAC, and Privileged Actions

### Goal

Make production/team usage safer and package features cleanly.

### Suggested Plans

| Plan | Target | Features |
|---|---|---|
| Free | labs / small sites | DNS, proxy, basic cache, basic log-mode security, basic analytics |
| Pro | production small sites | managed WAF, smart rate limits, SSL jobs, recommendations |
| Business | serious production | bot protection, API protection, advanced activity, exports, profiles |
| Enterprise | teams / private deployments | RBAC, extended audit retention, custom managed policies, advanced support controls |

### Roles

- Owner
- Admin
- Security Manager
- Developer
- Analyst
- Billing Only
- Read Only

### Backend Tasks

Audit and enforce permissions for:

- Force nameserver verification.
- Domain activation override.
- SSL request/import.
- DNS publish/reconcile.
- Origin modification.
- Edge registration.
- Config rollback.
- Cache purge.
- WAF/rate-limit changes.
- Profile apply/disable.
- Emergency Protection.

### Dashboard Tasks

- Hide/disable unavailable controls.
- Explain required role/plan.
- Require reason for risky override actions.
- Write audit event for denied privileged attempts if appropriate.

### Tests

- Read-only user cannot apply protection profile.
- Security Manager can modify WAF/rate-limit policies.
- Developer can manage origins but not billing.
- Plan-gated features return clear API error.

### IDE Prompt

```text
Phase 19: Add plan gates and RBAC. Define Free, Pro, Business, and Enterprise feature gates. Add roles Owner, Admin, Security Manager, Developer, Analyst, Billing Only, and Read Only. Enforce permissions for force verification, activation override, SSL, DNS publishing, origin changes, edge registration, config rollback, cache purge, WAF/rate-limit changes, profile apply/disable, and emergency protection. Dashboard should hide or disable unavailable controls with clear explanations. Add tests for allowed/denied flows.
```

---

## Phase 20 — Origin Health and Advanced Failover

### Goal

Finish production-grade origin health and failover policy.

### Required Behavior

- Configurable healthy status ranges.
- Timeout.
- Health check path.
- Expected text/header.
- Per-origin enabled/disabled state.
- Multiple backups.
- Priority and weight.
- Freshness of health data.
- Edge should skip unhealthy origins when health is fresh.
- Edge should log selected origin and failover reason.

### Backend Tasks

- Extend origin health model.
- Add failover policy model.
- Add health freshness rules.
- Add manual health test and scheduled checks.
- Add Activity events for health transitions.

### Dashboard Tasks

- Health policy editor.
- Origin priority/weight UI.
- Show current health and last check.
- Show failover events in Activity.

### Edge Tasks

- Respect health state when fresh.
- Select weighted/priority origins.
- Emit failover diagnostics.

### Tests

- Healthy origin selected.
- Unhealthy origin skipped.
- 404 can be allowed when configured.
- 500 fails health.
- Timeout fails health.
- Multi-backup failover works.

### IDE Prompt

```text
Phase 20: Improve origin health and failover. Support configurable healthy status ranges, timeout, health path, expected text/header, per-origin enabled/disabled state, multiple backups, priority, weight, and health freshness. Edge should skip unhealthy origins when health data is fresh and log selected origin/failover reason. Dashboard should show health policy, origin priority/weight, current health, and Activity failover events. Add tests for healthy, unhealthy, timeout, 404-allowed, 500-failed, and multi-backup failover.
```

---

## Phase 21 — Edge Config Version Visibility

### Goal

Show whether each edge node has applied the latest config snapshot.

### User Outcome

Edge Network page shows:

```text
Edge Node | Health | Applied Config | Latest Config | Status
edge-1    | healthy | 128 | 128 | up to date
edge-2    | healthy | 126 | 128 | pending
edge-3    | stale   | 120 | 128 | stale
```

### Backend Tasks

- Track latest config snapshot version.
- Track last applied config version per edge heartbeat.
- Track last config pull time.
- Track config apply errors.

### Dashboard Tasks

- Add applied/pending/stale status to Edge Network page.
- Add config version status to domain Activity.
- Add warning when active domains depend on stale edge nodes.

### Edge Agent Tasks

- Report applied config version in heartbeat.
- Report config apply failure reason safely.

### Tests

- DNS/origin/SSL change creates new snapshot.
- Edge heartbeat reports applied version.
- Dashboard shows pending then up to date.
- Stale edge is detected.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/database/migrations/000009_edge_config_version_visibility.sql`, `core/database/schema.sql`, `core/app/Modules/Edge/Services/EdgeService.php`, `dash/src/views/EdgeNetworkView.vue`, `dash/src/types.ts`, `core/tests/test_edge_network_phase12_contract.py`, `core/tests/test_dashboard_edge_network_design_contract.py`.
- Behavior added: edge nodes now persist `applied_config_version`, `last_config_pull_at`, and `config_apply_error` from heartbeat/register payloads. The Edge Network dashboard now compares each node against the latest config snapshot, shows applied/pending/stale/apply-error status, and surfaces the latest snapshot version alongside per-node pull timing.
- Tests added/updated: contract coverage now pins the new edge config visibility fields in the service, dashboard, and TypeScript types.
- Validation commands run: `php -l core/app/Modules/Edge/Services/EdgeService.php && php -l core/app/Modules/Edge/Http/Controllers/EdgeController.php && php -l core/public_index.php`; `pytest -q core/tests/test_edge_network_phase12_contract.py core/tests/test_dashboard_edge_network_design_contract.py core/tests/test_edge_agent_stage6_contract.py`; `npm run typecheck` in `dash/`.
- Commands not run and why: smoke/e2e and other long-running Docker validations were intentionally skipped per instruction.
- Remaining blockers: Activity/event records for config publish/apply transitions still need a dedicated slice, and the edge agent can still be extended to report a safe apply-error reason from the config pull loop.

### Progress Notes

- Date: 2026-06-19
- Changed files: `core/app/Modules/Proxy/Services/ConfigService.php`, `core/tests/test_phase14_15_contract.py`, `docs/ROADMAP.md`.
- Behavior added: config snapshot rebuild and rollback now write audit events for `config.publish`, `config.publish.reused`, and `config.rollback`, preserving the previous active version in audit details so Activity can show publish/apply transitions.
- Tests added/updated: Phase 14/15 contract coverage now pins the new audit-event methods and audit event names in `ConfigService`.
- Validation commands run: `php -l core/app/Modules/Proxy/Services/ConfigService.php`; `pytest -q core/tests/test_phase14_15_contract.py core/tests/test_edge_network_phase12_contract.py core/tests/test_dashboard_edge_network_design_contract.py`; `npm run typecheck` in `dash/`.
- Commands not run and why: smoke/e2e and other long-running Docker validations were intentionally skipped per instruction.
- Remaining blockers: the edge agent can still be extended to report a safe apply-error reason from the config pull loop.

### Progress Notes

- Date: 2026-06-19
- Changed files: `edge/agent/lib.sh`, `edge/agent/heartbeat.sh`, `core/tests/test_edge_agent_stage6_contract.py`, `docs/ROADMAP.md`.
- Behavior added: the edge agent heartbeat now reads the local sync-status file and reports `config_apply_error` alongside `config_version`, allowing Core and the dashboard to show a safe human-readable apply failure reason after a config pull or validation problem.
- Tests added/updated: stage 6 agent contract now pins the new heartbeat/error helper contract.
- Validation commands run: `sh -n edge/agent/lib.sh && sh -n edge/agent/heartbeat.sh && sh -n edge/agent/pull_config.sh`; `pytest -q core/tests/test_edge_agent_stage6_contract.py core/tests/test_edge_network_phase12_contract.py core/tests/test_dashboard_edge_network_design_contract.py`; `npm run typecheck` in `dash/`.
- Commands not run and why: smoke/e2e and other long-running Docker validations were intentionally skipped per instruction.
- Remaining blockers: none for the current Phase 21 slice.

### Progress Notes

- Date: 2026-06-19
- Changed files: `ci/smoke.sh`, `ci/e2e.sh`, `core/tests/test_phase21_edge_config_visibility_contract.py`, `core/tests/test_hardening_contract.py`, `docs/ROADMAP.md`.
- Behavior added: smoke checks now verify the edge-node config visibility columns, the config publish audit hooks, and the dashboard bundle strings for the new config-status UI. E2E now exercises `cdn:edge:sync-config` after domain activation, confirms publish audit entries, and verifies the edge heartbeat stores the applied config version after a pull.
- Tests added/updated: a dedicated Phase 21 contract now pins the new smoke/e2e wiring plus the config publish, rollback, and agent error-reporting hooks.
- Validation commands run: `bash -n ci/smoke.sh && bash -n ci/e2e.sh && sh -n edge/agent/lib.sh && sh -n edge/agent/heartbeat.sh && sh -n edge/agent/pull_config.sh`; `pytest -q core/tests/test_phase21_edge_config_visibility_contract.py`; `npm run typecheck` in `dash/`.
- Commands not run and why: live smoke/e2e stack execution was intentionally skipped per instruction.
- Remaining blockers: none for the current Phase 21 smoke/e2e wiring slice.

### IDE Prompt

```text
Phase 21: Add edge config version visibility. Track latest config snapshot version and last applied version per edge heartbeat. Edge agent must report applied config version and safe apply errors. Dashboard Edge Network page must show applied/pending/stale state, and Activity should record config publish/apply events. Add tests that DNS/origin/SSL changes create a new snapshot and edge heartbeat updates applied status.
```

---

## Phase 22 — Product Polish and Trust Layer

### Goal

Make CDNLite feel safe, predictable, and professional.

### UX Requirements

Every simple control should include:

- What this does.
- When to use it.
- Possible risk.
- What will change.
- How to undo.
- View technical rules.

### Safety Badges

- Recommended.
- Safe default.
- Advanced.
- Risky.
- Log-only.
- Requires confirmation.

### Backend Tasks

- Ensure every managed action has preview and audit.
- Ensure risky actions require reason or confirmation.
- Ensure undo/rollback points exist for profile changes.

### Dashboard Tasks

- Standardize cards, badges, warnings, previews, and confirmation dialogs.
- Improve empty states.
- Improve copy for zero-knowledge users.
- Add links from simple controls to advanced generated rules.

### Tests

- Every simple control has preview text.
- Risky controls require confirmation.
- Undo restores previous state.
- Activity shows who changed what and when.

### IDE Prompt

```text
Phase 22: Add product polish and trust UX. Standardize plain-English copy, safety badges, recommended labels, risk warnings, preview screens, undo flows, and View Technical Rules links for all simple protection controls. Ensure risky actions require confirmation and reason. Ensure every managed action creates audit/activity events. Improve empty states and beginner copy. Add tests for preview text, confirmation requirements, undo behavior, and Activity visibility.
```

---

## Phase 23 — Release Readiness and Documentation Replacement

### Goal

Prepare this roadmap to fully replace the old roadmap and make the product phases executable by contributors or IDE agents.

### Tasks

- Replace old `docs/ROADMAP.md` with this file.
- Move historical deep-dive notes from the old roadmap into `docs/operations/stabilization-history.md` if still useful.
- Update docs navigation to reference the new roadmap.
- Add a release checklist for each phase.
- Add a “How to work on a phase” contributor guide.
- Add screenshots or wireframes for new Security Center and profile flows.
- Keep old validation commands and add product-specific tests.

### Required Docs

```text
docs/ROADMAP.md
docs/operations/stabilization-history.md
docs/product/security-center.md
docs/product/protection-profiles.md
docs/product/simple-vs-advanced.md
docs/product/recommendations.md
docs/product/onboarding.md
```

### Acceptance Checklist

- [ ] New roadmap replaces old roadmap.
- [ ] Old detailed stabilization notes are archived, not lost.
- [ ] Docs navigation links to new product roadmap.
- [ ] Contributors can pick a phase and run validation commands.
- [ ] Product docs explain simple/advanced model.

### IDE Prompt

```text
Phase 23: Replace the old roadmap with the new product roadmap. Move historical stabilization detail into docs/operations/stabilization-history.md if useful. Update docs navigation and add product docs for Security Center, Protection Profiles, Simple vs Advanced Mode, Recommendations, and Onboarding. Add a release checklist and contributor guide for working phase-by-phase. Ensure no old roadmap content needed for maintenance is lost.
```

---

# Recommended Phase Order

| Priority | Phase | Why |
|---:|---|---|
| P0 | Phase -1 to 7 cleanup | Keep production foundation stable |
| P1 | Phase 8 — Simple/Advanced Contract | Prevent generated-rule conflicts |
| P2 | Phase 9 — Security Center | Main beginner product surface |
| P3 | Phase 10 — Protection Profiles | One-click setup |
| P4 | Phase 11 — Managed WAF Presets | Enables “Block exploits” safely |
| P5 | Phase 12 — Smart Rate Limiting | Enables “Stop abusive traffic” |
| P6 | Phase 13 — Bot Protection | Handles automation and scraping |
| P7 | Phase 14 — API Protection | Protects API workloads |
| P8 | Phase 15 — Performance Starter | Makes caching simple |
| P9 | Phase 16 — Recommendation Engine | Makes product proactive |
| P10 | Phase 17 — Guided Onboarding | Helps new users choose correctly |
| P11 | Phase 18 — Beginner Activity UX | Makes protection understandable |
| P12 | Phase 19 — Plans/RBAC | Production/team safety |
| P13 | Phase 20 — Origin Health/Failover | Production resilience |
| P14 | Phase 21 — Edge Config Visibility | Operational confidence |
| P15 | Phase 22 — Polish/Trust | Product quality |
| P16 | Phase 23 — Docs Replacement | Finish roadmap transition |

---

# Safe Defaults Matrix

| Feature | Beginner Default | Reason |
|---|---|---|
| High-confidence exploit WAF | Block | Usually low false-positive risk |
| Medium-confidence WAF | Log or challenge | Avoid breaking legitimate requests |
| Login rate limit | Challenge | Safer than hard block |
| API rate limit | 429 | Standard API behavior |
| Bot protection | Challenge suspicious | Avoid blocking real users |
| Verified search bots | Allow | Search visibility |
| Fake search bots | Challenge/block | Common abuse pattern |
| Static asset cache | Enable | Low breakage risk |
| HTML cache | Off | Can break dynamic sites |
| Emergency Protection | Manual enable | Can affect real users |
| Country blocking | Suggest only | Business-specific risk |
| Origin TLS verify | On | Secure default |
| Preserve host header | Off unless needed | Origin-specific behavior |

---

# Global Acceptance Criteria

This roadmap is complete when:

- Existing deployments upgrade safely through migrations.
- Domain verification can be refreshed without delete/re-add.
- Admin force verification is audited and reasoned.
- DNS records and origins are never silently hidden.
- Edge routing is explicit, diagnosable, and reliable.
- Edge logs are visible from Docker.
- Edge error pages are professional and safe.
- SSL issuance is visible as a job lifecycle.
- Dashboard updates without manual refresh.
- Activity supports request ID lookup and detailed diagnostics.
- Simple users can enable protection by choosing outcomes.
- Advanced users can inspect and customize generated rules.
- Generated rules are linked to profiles/intents.
- User-modified rules are not overwritten silently.
- Risky changes require preview, confirmation, undo, or log-only mode.
- Every protection action creates audit/activity events.
- Every edge-affecting change invalidates/publishes config.
- Recommendations suggest safe improvements.
- Onboarding recommends a profile based on simple questions.
- RBAC and plan gates protect privileged actions.
- Edge config applied/pending/stale status is visible.
- CI covers backend, dashboard, edge, DNS, SSL, activity, protection profiles, WAF, rate limiting, bot protection, API protection, and onboarding.

---

# Validation Commands

Run after each phase and before merging.

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

# Database checks
docker compose exec core php artisan cdn:db:status
docker compose exec core php artisan cdn:db:migrate --dry-run

# Usage retention check
docker compose exec core php artisan cdn:usage:prune --dry-run
```

---

# One-Shot IDE Prompt

Use this only after the completed stabilization phases are committed and the Phase 8 Simple/Advanced contract is agreed.

```text
You are working on vaheed/CDNLite. Replace the old stabilization-only roadmap with the new product roadmap and implement it phase by phase.

Current stack: PHP control plane, PostgreSQL, Vue 3/TypeScript dashboard, OpenResty/Lua edge proxy, shell edge agent, Docker Compose, DNS/PowerDNS/DNSGeo, config snapshots, WAF rules, rate limits, IP access rules, cache rules, SSL, edge metrics, activity, and audit logs.

Core product principle:
Simple mode for outcomes. Advanced mode for control.

Rules:
- Preserve existing production stabilization work.
- Do not remove advanced controls.
- Every simple action must preview generated technical changes.
- Every generated technical rule must link to profile_id, intent_id, and template_key.
- Do not overwrite user-modified advanced rules silently.
- Every mutating action must write audit/activity events.
- Every edge-affecting action must invalidate config.
- Risky settings should default to log-only, challenge, preview, undo, or explicit confirmation.
- Add backend tests, dashboard tests, edge tests, and e2e coverage for each phase.

Implement phases in order:
1. Complete remaining Phase 7 hardening gaps.
2. Phase 8 Simple/Advanced Protection Contract.
3. Phase 9 Security Center.
4. Phase 10 One-Click Protection Profiles.
5. Phase 11 Managed WAF Presets.
6. Phase 12 Smart Rate Limiting.
7. Phase 13 Bot Protection.
8. Phase 14 API Protection.
9. Phase 15 Performance Starter.
10. Phase 16 Recommendation Engine.
11. Phase 17 Guided Onboarding.
12. Phase 18 Beginner Activity UX.
13. Phase 19 Plans/RBAC.
14. Phase 20 Origin Health and Advanced Failover.
15. Phase 21 Edge Config Version Visibility.
16. Phase 22 Product Polish and Trust Layer.
17. Phase 23 Documentation Replacement.
```
