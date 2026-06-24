# Changelog

All notable changes to CDNLite will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Release entries below are summarized from the published GitHub releases and repository tags.

## [Unreleased]

### Added

- Phase 1 database architecture foundation with workload budgets, telemetry ingest batch diagnostics, reporting watermarks, reconciliation tables, current summary read model, reporting query budgets, one-shot phase runner, manifest, stress scenario, and database architecture docs.
- Phase 2 analytics scalability with asynchronous recalculation jobs, job status API, idempotent aggregate upserts, bounded summary metadata, analytics cache schema, dashboard queued-job feedback, and contract documentation.
- Single canonical roadmap at `docs/ROADMAP.md`; removed duplicate root, lower-case, and legacy roadmap files.
- Repository-level project presentation, contribution, security, roadmap, changelog, issue template, and pull request template documentation.

### Changed

- Closed Phase 1 after PR gate, clean-stack smoke, and end-to-end validation; updated the e2e config-version check to compare against the active config-state version.
- Documentation information architecture is being organized around new users, operators, developers, and private deployment teams.

### Security

- Security guidance documents local-only defaults, secret handling, edge token rotation, TLS, external dashboard authentication, and PowerDNS API exposure.

## [1.4.0] - 2026-06-22

Tag-only release. No GitHub release notes are currently published for this tag.

### Added

- Client IP tracking in usage metrics and reports.
- Raw GeoDNS record support for A/AAAA records.
- Reporting endpoints and related database indexes.
- Client country details across metrics and reporting flows.
- PowerDNS SOA management, validation, and repair behavior.

### Changed

- Replaced proxied apex ALIAS handling with PowerDNS LUA records in DNS acceptance paths.
- Improved Edge DNS service edge selection for DNS records.
- Enhanced PowerDNS doctor diagnostics and error reporting.
- Expanded DNS e2e scripts with force-sync retries, failure reports, and validation after unproxied record deletion.
- Updated documentation screenshot.

## [1.3.0] - 2026-06-21

### Added

- Guided domain onboarding that recommends starter protection profiles from simple site questions.
- Proactive recommendation engine for traffic, cache, SSL, origin, and security signals.
- Smart Rate Limiting templates, API Shield rules, bot protection, verified bot handling, WAF metadata, challenge actions, and header-aware API rate limits.
- Simple and Advanced domain Activity views.
- Edge config visibility for applied config version, last config pull time, and config apply errors.
- Admin CLI commands for listing admins, changing passwords, and deleting users while revoking sessions.
- Migrations for rate-limit header keys, edge config visibility, performance starter settings, bot protection, verified bot sources, recommendations, domain onboarding, and admin-session indexes.

### Changed

- Improved country-aware routing and WAF decisions with GeoIP/MMDB fallback when trusted country headers are unavailable.
- Updated deployment docs around backups, migration dry-runs, immutable image tags, and smoke/e2e/DNS validation.

## [1.2.0] - 2026-06-19

### Added

- Security Center dashboard tab.
- Protection intents, grouped protection profiles, preview/apply/disable/undo workflows, and managed rule metadata.
- Detach support for managed WAF and rate-limit rules.
- Automatic managed SSL handling for apex and wildcard hostnames.
- DNS visibility checks before ACME validation.
- Stale SSL job retry configuration.
- Per-record DNS reconciliation retry support from the dashboard/API.
- Authority-only PowerDNS zones for pending domains.

### Changed

- Refactored origin handling from primary/backup terminology to a pool of enabled origins.
- Updated edge routing to select from the healthy origin pool.
- Improved SSL job scheduling, status reporting, validation errors, dashboard feedback, and CLI workflow.
- Improved PowerDNS reconciliation behavior and failure diagnostics.
- Improved Security Center layout and profile preview screens.
- Improved edge-agent metrics push reliability, queue locking, preserved collector responses, invalid-line quarantine, and recovery for invalid preserved metrics payloads.
- Updated API docs, OpenAPI, setup, runbook, DNS, troubleshooting, and dashboard usage docs.

### Removed

- Primary/backup origin terminology from the active routing model.

## [1.1.0] - 2026-06-16

### Added

- Static Anycast IP support for Edge DNS configuration.
- Support for handling Anycast IPs as lists.

### Changed

- Improved related update logic for Anycast IP handling and Edge DNS setup.

## [1.0.0] - 2026-06-16

### Added

- First stable `v1.0.0` release marker.

### Changed

- Rolled up stability, reliability, cleanup, and refinements from the `0.x` release series.

## [0.1.9] - 2026-06-08

### Added

- Enhanced domain management dashboard with horizontal scrollable tabs, contextual help, improved status handling, and refined domain detail views.
- Full audit logging for domain and settings changes.
- Expanded security events UI and filtering.
- Better config snapshot handling, timestamp refresh on no-op syncs, visual diff views, PowerDNS checks, and core/edge health integration.
- CDN In A Minute quickstart guide.

### Changed

- Improved rate-limit and traffic-rule propagation through snapshot invalidation.
- Refined dashboard components, accessibility, session handling, login, and troubleshooting flows.
- Improved test reliability with deadlock retry utilities, migration retry logic, CI fixes, and focused contract coverage.
- Updated setup, operations, admin, user, use-case, and architecture docs.

## [0.1.5] - 2026-06-07

### Added

- Domain-first CDN control plane replacing the earlier site-based model.
- Cloudflare-style domain onboarding with nameserver verification and activation flow.
- Domain routing modes for `geo`, `anycast`, and `dns_only`.
- Database-backed platform settings with dashboard editing, secret masking, validation, and PowerDNS connection testing.
- Domain feature tabs for Overview, DNS, SSL, Cache, Redirects, Page Rules, WAF, Rate Limits, and Analytics.
- Operational overview dashboard with aggregate metrics, warnings, and Markdown report export.
- Cache analytics with real cache status tracking and `X-CDNLITE-Cache` visibility.
- Full CRUD support for rate-limit rules.
- Structured readiness API for PostgreSQL, PowerDNS, edge heartbeat, edge identity, and config freshness.

### Changed

- Renamed Site API and related models/UI concepts to Domain API.
- Improved proxied DNS record publishing for apex and non-apex records.
- Improved domain analytics filtering.
- Improved edge identity handling for runtime responses, metrics, security events, and dashboard display.
- Updated CI, tests, documentation, README badges, and local development workflows.

### Breaking Changes

- Replaced `/api/v1/sites` and `site_id` terminology with `/api/v1/domains` and domain-first resources.
- Recommended clean database rebuild for local development because of the domain-first schema reset.
- Domain creation no longer requires full origin configuration at creation time.

## [0.1.1] - 2026-06-06

Tag-only release. No GitHub release notes are currently published for this tag.

### Added

- Domain onboarding, nameserver verification, and activation work.
- Domain routing controls for geo, anycast, and DNS-only modes.
- Overview dashboard with aggregate metrics and report export.
- Database-backed settings dashboard.
- Domain-specific analytics and cache analytics improvements.
- Readiness API and edge identity management.
- Rate-limit CRUD and supporting dashboard/API flows.

### Changed

- Refactored proxied DNS publishing toward ALIAS/CNAME behavior for apex and non-apex records.
- Improved migration command behavior, E2E seeding, CI setup, and dashboard session handling.

## [0.0.9] - 2026-06-04

### Added

- Initial pre-1.0 CDNLite control-plane and edge-runtime release.
- PHP/PostgreSQL control plane with APIs and CLI commands.
- Site lifecycle APIs and CLI commands.
- Site-scoped DNS record management with optional PowerDNS sync.
- OpenResty/Lua edge runtime with host-based routing, config snapshots, upstream selection, security/cache logic, proxying, and metrics.
- Signed shell edge agent for registration, heartbeat, config pull, metrics push, and security-event push.
- Edge authentication using bearer token, edge ID, timestamp, nonce, and HMAC SHA-256 signature.
- Vue 3 dashboard for sites, DNS, redirects, page rules, cache, WAF, rate limits, SSL, edges, usage, and security events.
- Admin login, dashboard session tokens, and admin CLI creation.
- Edge caching, cache settings, cache rules, purge requests, and cache analytics.
- Redirects, page rules, WAF rules, rate limiting, SSL metadata, manual certificate import, and ACME DNS-01 support.
- Usage summaries, aggregate buckets, security-event ingest, CLI utilities, base schema, Docker Compose stack, and CI validation.

### Breaking Changes

- Server-rendered backend `/dashboard/*` routes were removed; the official dashboard is the static SPA served by the dashboard Compose service.
- Edge config updates are pull-based.
- Control-plane API auth remains optional for local development and should be enabled before exposure.

[Unreleased]: https://github.com/vaheed/CDNLite/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/vaheed/CDNLite/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/vaheed/CDNLite/releases/tag/v1.3.0
[1.2.0]: https://github.com/vaheed/CDNLite/releases/tag/v1.2.0
[1.1.0]: https://github.com/vaheed/CDNLite/releases/tag/v1.1.0
[1.0.0]: https://github.com/vaheed/CDNLite/releases/tag/v1.0.0
[0.1.9]: https://github.com/vaheed/CDNLite/releases/tag/v0.1.9
[0.1.5]: https://github.com/vaheed/CDNLite/releases/tag/v0.1.5
[0.1.1]: https://github.com/vaheed/CDNLite/compare/v0.0.9...v0.1.1
[0.0.9]: https://github.com/vaheed/CDNLite/releases/tag/v0.0.9
