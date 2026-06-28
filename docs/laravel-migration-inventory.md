# Laravel Migration Inventory

This inventory is the Phase 1 baseline for rebuilding `core/` from the custom
PHP control-plane framework to a standard Laravel application. The rebuild is
fresh-install-only; old database contents, deprecated aliases, and custom
framework compatibility are intentionally not preserved.

## Current Runtime Summary

- Core HTTP entrypoint: `core/public/index.php`
- Core CLI entrypoint: `core/artisan`
- Custom bootstrap/autoload/config layer: `core/app/Support/bootstrap.php`
- Custom router/request/response layer: `core/app/Support/Router.php`,
  `core/app/Support/Request.php`, `core/app/Support/Response.php`
- Custom PostgreSQL access and migration runner: `core/app/Support/Database.php`,
  `core/app/Support/DatabaseMigrator.php`
- Authoritative fresh-install schema: `core/database/schema.sql`
- Incremental SQL migrations: `core/database/migrations/*.sql`
- Current tests: black-box and contract-oriented pytest files in `core/tests/`
- Dashboard: separate Vue 3/Vite app under `dash/`
- Edge data plane: OpenResty/Lua under `edge/openresty/`
- Edge agent: POSIX shell scripts under `edge/agent/`
- DNSGeo/PowerDNS topology: `infra/dnsgeo/`, root `docker-compose.yml`, and
  split deployment files under `deploy/`

## Migration Principles

- Prefer Laravel-native routes, requests, resources, middleware, policies,
  migrations, jobs, scheduler entries, and tests over preserving old internals.
- Keep only public behavior required by the current product surface.
- Keep PostgreSQL as the only supported database backend.
- Use `core/database/schema.sql` and the SQL migrations as the source of truth
  for Laravel migrations.
- Keep OpenResty/Lua and the POSIX edge agent as non-Laravel runtime surfaces.
- Keep the root Compose workflow and service ports unless a later phase
  intentionally changes and documents them.

## File Mapping

| Old path | Old responsibility | Laravel target | Status |
| --- | --- | --- | --- |
| `core/public_index.php` | Front controller, CORS, JSON parsing, exception handling, bootstrapping, route registration, API auth dispatch, edge auth dispatch | `core/public/index.php`, `routes/api.php`, `app/Http/Middleware/*`, `bootstrap/app.php`, `app/Exceptions` or Laravel exception bootstrap | Not started |
| `core/artisan` | Custom CLI bootstrap and command registry | Standard Laravel `core/artisan`, `routes/console.php`, `app/Console/Commands/*` | Not started |
| `core/app/Console/CommandRunner.php` | Custom command parser, help output, execution dispatch | Laravel console kernel/command signatures | Not started |
| `core/app/Console/Commands/*.php` | Existing command implementations | Laravel `Illuminate\Console\Command` classes in `app/Console/Commands` with same command names | Not started |
| `core/app/Modules/Admin/Http/Controllers/AdminAuthController.php` | Admin login, session lookup, logout controller | `app/Http/Controllers/Admin/AdminAuthController.php` | Not started |
| `core/app/Modules/Admin/Services/AdminAuthService.php` | Admin bootstrap users, password/session behavior | `app/Services/Admin/AdminAuthService.php`, auth config, middleware | Not started |
| `core/app/Modules/Collector/Http/Controllers/CollectorController.php` | Usage ingest, security-event ingest, analytics and activity endpoints | `app/Http/Controllers/Collector/CollectorController.php` | Not started |
| `core/app/Modules/Collector/Services/CollectorService.php` | Usage rollups, analytics aggregation, request diagnostics | `app/Services/Collector/CollectorService.php` plus jobs where needed | Not started |
| `core/app/Modules/Dns/Http/Controllers/DnsController.php` | Customer DNS record/routing/GeoDNS endpoints | `app/Http/Controllers/Dns/DnsController.php` | Not started |
| `core/app/Modules/Dns/Http/Controllers/DnsOperationsController.php` | DNS operation status, desired/actual state, dry-run, force-sync | `app/Http/Controllers/Dns/DnsOperationsController.php` | Not started |
| `core/app/Modules/Dns/Http/Controllers/EdgeNetworkController.php` | Edge country metadata endpoint | `app/Http/Controllers/Dns/EdgeNetworkController.php` | Not started |
| `core/app/Modules/Dns/Services/*.php` | Desired state generation, DNSGeo rendering, PowerDNS writes, reconciliation, sync state | `app/Services/Dns/*` and repositories/jobs for reconciliation | Not started |
| `core/app/Modules/Domains/Http/Controllers/DomainController.php` | Domain CRUD, activation, nameserver verification | `app/Http/Controllers/Domains/DomainController.php` | Not started |
| `core/app/Modules/Domains/Services/*.php` | Domain persistence and verification logic | `app/Services/Domains/*` | Not started |
| `core/app/Modules/Edge/Http/Controllers/EdgeController.php` | Edge node listing, pools, DNS view, registration, heartbeat | `app/Http/Controllers/Edge/EdgeController.php` | Not started |
| `core/app/Modules/Edge/Services/*.php` | Edge token/HMAC auth, edge node state, edge health | `app/Services/Edge/*`, `app/Http/Middleware/EdgeSignatureAuth.php` | Not started |
| `core/app/Modules/Health/Http/Controllers/ReadinessController.php` | Readiness and CDN health payloads | `app/Http/Controllers/Health/ReadinessController.php` | Not started |
| `core/app/Modules/Health/Services/ReadinessService.php` | Readiness checks | `app/Services/Health/ReadinessService.php` | Not started |
| `core/app/Modules/Onboarding/*` | Guided domain onboarding | `app/Http/Controllers/Onboarding`, `app/Services/Onboarding` | Not started |
| `core/app/Modules/Operations/*` | Audit, events, jobs, security event views | `app/Http/Controllers/Operations`, `app/Services/Operations` | Not started |
| `core/app/Modules/Overview/*` | Dashboard overview and warnings | `app/Http/Controllers/Overview`, `app/Services/Overview` | Not started |
| `core/app/Modules/Proxy/Http/Controllers/OriginController.php` | Origin CRUD and checks | `app/Http/Controllers/Proxy/OriginController.php` | Not started |
| `core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php` | Redirects, WAF, rate limits, cache, headers, IP rules, SSL, page rules, waiting room | `app/Http/Controllers/Proxy/TrafficRulesController.php` or split Laravel controllers by resource | Not started |
| `core/app/Modules/Proxy/Services/*.php` | Config snapshots, traffic rules, origin health, ACME, renewal | `app/Services/Proxy/*`, jobs/scheduler for expensive work | Not started |
| `core/app/Modules/Recommendations/*` | Recommendations list/generate/apply/dismiss/snooze | `app/Http/Controllers/Recommendations`, `app/Services/Recommendations` | Not started |
| `core/app/Modules/Reports/Services/ReportService.php` | Report summary/traffic/cache/edge/security/reliability/operations | `app/Services/Reports/ReportService.php` | Not started |
| `core/app/Modules/Settings/*` | Platform settings read/update/validation/test | `app/Http/Controllers/Settings`, `app/Repositories/Settings` | Not started |
| `core/app/Support/ApiAuth.php` | Bearer token auth and production missing-token behavior | `app/Http/Middleware/ApiTokenAuth.php`, `config/cdnlite.php` | Not started |
| `core/app/Support/AuditLog.php` | Audit writes | `app/Services/Audit/AuditLog.php` or model/repository | Not started |
| `core/app/Support/CommandIO.php` | Custom console I/O | Laravel command input/output helpers | Not started |
| `core/app/Support/Database.php` | PDO connection factory | Laravel database config/query builder | Not started |
| `core/app/Support/DatabaseMigrator.php` | SQL migration runner and schema state | Laravel migrations plus compatibility/adoption command if needed | Not started |
| `core/app/Support/DatabaseWorkload.php` | DB workload budgeting | `app/Support/DatabaseWorkload.php` or service using Laravel DB | Not started |
| `core/app/Support/Logger.php` | JSON-ish app logging and debug toggles | Laravel logging channels to stdout/stderr | Not started |
| `core/app/Support/Request.php` | Custom request DTO | Laravel `Illuminate\Http\Request` and form requests | Not started |
| `core/app/Support/Response.php` | JSON response helper/status envelope | Laravel responses/resources with compatibility helpers | Not started |
| `core/app/Support/Router.php` | Pattern router and auth/edgeAuth flags | Laravel routing and middleware groups | Not started |
| `core/app/Support/Secrets.php` | Secret masking/encryption support | Laravel config/encryption plus dedicated secret service | Not started |
| `core/app/Support/Uuid.php` | UUID generation | Laravel/ramsey UUID or retained support helper | Not started |
| `core/app/Support/Validator.php` | Custom validation | Laravel validators/form requests while preserving error keys | Not started |
| `core/docker-entrypoint.sh` | Container startup and runtime preparation | Laravel-aware entrypoint | Not started |
| `core/Dockerfile` | PHP-FPM/nginx/supervisor image without Composer install | Laravel Dockerfile with Composer deps, config cache policy, same health port | Not started |
| `core/docker/nginx/*` | Nginx serving `public_index.php` | Nginx serving Laravel `public/index.php` | Not started |
| `core/docker/php/*` | PHP-FPM/php/opcache config | Retain and adjust for Laravel | Not started |
| `core/docker/supervisor/supervisord.conf` | PHP-FPM, nginx, scheduler processes | Laravel service processes and scheduler/queue workers | Not started |
| `core/database/schema.sql` | Fresh-install authoritative schema | Preserve as reference; convert into Laravel migrations | Not started |
| `core/database/migrations/*.sql` | Custom SQL migrations | `core/database/migrations/*.php` Laravel migrations | Not started |
| `core/tests/*.py` | Contract tests for API/CLI/docs/dashboard/edge behavior | Preserve as black-box tests; add Laravel PHPUnit/Pest feature/unit tests | Not started |
| `dash/*` | Separate Vue dashboard container/build | Keep separate initially or migrate to Laravel Vite in Phase 9 | Not started |
| `edge/openresty/*` | Hot-path data plane | Remains non-Laravel; validate config snapshot compatibility | Not started |
| `edge/agent/*` | POSIX edge registration/heartbeat/config/telemetry agent | Remains non-Laravel; validate API compatibility | Not started |
| `infra/dnsgeo/*` | PowerDNS/DNSGeo/recursor/MMDB/Poweradmin infrastructure | Remains non-Laravel; validate DNS operations compatibility | Not started |
| `docker-compose.yml` | Normal root deployment topology | Preserve service names/ports; update core command/image only when Laravel core exists | Not started |
| `deploy/*` | Split deployment topologies and generator | Update after Laravel core service shape is known | Not started |
| `.github/workflows/ci.yml` | CI lint/test/dashboard/build/smoke/e2e/stress workflows | Add Composer/Laravel tests while preserving current checks | Not started |
| `docs/*`, `README.md`, `CONTRIBUTING.md` | Operator, API, deployment, security, architecture docs | Update phase-by-phase as behavior/runtime changes | Not started |

## Current HTTP Routes

These routes are registered in `core/public_index.php` and must be recreated in
Laravel with compatible auth behavior and response contracts.

```text
POST /api/v1/admin/login
GET /api/v1/admin/me
POST /api/v1/admin/logout
GET /health
GET /cdn-health
GET /ready
GET /api/v1/readiness
GET /api/v1/overview
GET /api/v1/overview/warnings
GET /api/v1/recommendations
POST /api/v1/recommendations/generate
GET /api/v1/reports/summary
GET /api/v1/reports/traffic
GET /api/v1/reports/cache
GET /api/v1/reports/edge
GET /api/v1/reports/security
GET /api/v1/reports/reliability
GET /api/v1/reports/operations
GET /api/v1/security/events
GET /api/v1/security/summary
GET /api/v1/audit
GET /api/v1/events
GET /api/v1/jobs
GET /api/v1/config/snapshots
GET /api/v1/config/snapshots/latest
GET /api/v1/config/snapshots/{version}
POST /api/v1/config/snapshots/diff
POST /api/v1/config/snapshots/{version}/rollback
POST /api/v1/config/snapshots/rebuild
GET /api/v1/settings
GET /api/v1/settings/{group}
PATCH /api/v1/settings/{group}
POST /api/v1/settings/validate
POST /api/v1/settings/test/powerdns
GET /api/v1/dns/operations
GET /api/v1/dns/zones
GET /api/v1/dns/desired
GET /api/v1/dns/zones/{zone}/actual
POST /api/v1/dns/dry-run
POST /api/v1/dns/force-sync
GET /api/v1/edge-countries
GET /api/v1/domains
POST /api/v1/domains
GET /api/v1/domains/{domainId}
PATCH /api/v1/domains/{domainId}
DELETE /api/v1/domains/{domainId}
POST /api/v1/domains/{domainId}/verify-nameservers
POST /api/v1/domains/{domainId}/nameservers/verify
POST /api/v1/domains/{domainId}/nameservers/force-verify
POST /api/v1/domains/{domainId}/nameservers/reseed-expected
POST /api/v1/domains/{domainId}/activate
POST /api/v1/domains/{domainId}/dns/records
GET /api/v1/domains/{domainId}/dns/records
GET /api/v1/domains/{domainId}/dns/status
PATCH /api/v1/domains/{domainId}/dns/records/{recordId}
DELETE /api/v1/domains/{domainId}/dns/records/{recordId}
POST /api/v1/domains/{domainId}/dns/records/{recordId}/reconcile
GET /api/v1/domains/{domainId}/routing
PATCH /api/v1/domains/{domainId}/routing
POST /api/v1/domains/{domainId}/dns/records/{recordId}/preview-routing
GET /api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes
PUT /api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes
GET /api/v1/domains/{domainId}/origins
POST /api/v1/domains/{domainId}/origins
PATCH /api/v1/domains/{domainId}/origins/{originId}
DELETE /api/v1/domains/{domainId}/origins/{originId}
POST /api/v1/domains/{domainId}/origins/{originId}/check
POST /api/v1/domains/{domainId}/origins/{originId}/test
POST /api/v1/domains/{domainId}/route-debug
POST /api/v1/domains/{domainId}/redirects
GET /api/v1/domains/{domainId}/redirects
PATCH /api/v1/domains/{domainId}/redirects/{ruleId}
DELETE /api/v1/domains/{domainId}/redirects/{ruleId}
POST /api/v1/domains/{domainId}/redirects/import
GET /api/v1/domains/{domainId}/redirects/export
POST /api/v1/domains/{domainId}/redirects/test
GET /api/v1/domains/{domainId}/protection/profiles
GET /api/v1/domains/{domainId}/protection/waf-presets
GET /api/v1/domains/{domainId}/protection/rate-limit-templates
GET /api/v1/domains/{domainId}/protection/api-paths
POST /api/v1/domains/{domainId}/protection/profiles/{profileKey}/preview
POST /api/v1/domains/{domainId}/protection/profiles/{profileKey}/apply
POST /api/v1/domains/{domainId}/protection/profiles/{profileId}/disable
GET /api/v1/domains/{domainId}/protection/intents
POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/preview
POST /api/v1/domains/{domainId}/protection/intents/{intentKey}/enable
POST /api/v1/domains/{domainId}/protection/intents/{intentId}/disable
POST /api/v1/domains/{domainId}/protection/intents/{intentId}/undo
GET /api/v1/domains/{domainId}/onboarding
POST /api/v1/domains/{domainId}/onboarding/answers
POST /api/v1/domains/{domainId}/onboarding/preview
POST /api/v1/domains/{domainId}/onboarding/apply
POST /api/v1/domains/{domainId}/onboarding/skip
POST /api/v1/domains/{domainId}/onboarding/resume
GET /api/v1/domains/{domainId}/recommendations
POST /api/v1/domains/{domainId}/recommendations/generate
POST /api/v1/domains/{domainId}/recommendations/{recommendationId}/apply
POST /api/v1/domains/{domainId}/recommendations/{recommendationId}/dismiss
POST /api/v1/domains/{domainId}/recommendations/{recommendationId}/snooze
POST /api/v1/domains/{domainId}/rate-limits
GET /api/v1/domains/{domainId}/rate-limits
POST /api/v1/domains/{domainId}/rate-limits/dry-run
PATCH /api/v1/domains/{domainId}/rate-limits/{ruleId}
POST /api/v1/domains/{domainId}/rate-limits/{ruleId}/detach-managed
DELETE /api/v1/domains/{domainId}/rate-limits/{ruleId}
GET /api/v1/domains/{domainId}/waiting-room
PATCH /api/v1/domains/{domainId}/waiting-room
POST /api/v1/domains/{domainId}/waiting-room/emergency/activate
POST /api/v1/domains/{domainId}/waiting-room/emergency/deactivate
POST /api/v1/domains/{domainId}/waf-rules
GET /api/v1/domains/{domainId}/waf-rules
PATCH /api/v1/domains/{domainId}/waf-rules/{wafId}
POST /api/v1/domains/{domainId}/waf-rules/{ruleId}/detach-managed
DELETE /api/v1/domains/{domainId}/waf-rules/{wafId}
POST /api/v1/domains/{domainId}/headers
GET /api/v1/domains/{domainId}/headers
PATCH /api/v1/domains/{domainId}/headers/{ruleId}
DELETE /api/v1/domains/{domainId}/headers/{ruleId}
POST /api/v1/domains/{domainId}/ip-rules
GET /api/v1/domains/{domainId}/ip-rules
PATCH /api/v1/domains/{domainId}/ip-rules/{ruleId}
DELETE /api/v1/domains/{domainId}/ip-rules/{ruleId}
POST /api/v1/domains/{domainId}/cache-rules
GET /api/v1/domains/{domainId}/cache-rules
PATCH /api/v1/domains/{domainId}/cache-rules/{ruleId}
DELETE /api/v1/domains/{domainId}/cache-rules/{ruleId}
GET /api/v1/domains/{domainId}/cache/settings
PUT /api/v1/domains/{domainId}/cache/settings
POST /api/v1/domains/{domainId}/cache/purge
GET /api/v1/domains/{domainId}/cache/purge-requests
GET /api/v1/domains/{domainId}/cache/purge-requests/{requestId}
POST /api/v1/domains/{domainId}/page-rules
GET /api/v1/domains/{domainId}/page-rules
PATCH /api/v1/domains/{domainId}/page-rules/{ruleId}
DELETE /api/v1/domains/{domainId}/page-rules/{ruleId}
POST /api/v1/domains/{domainId}/page-rules/test
GET /api/v1/domains/{domainId}/ssl/certificates
GET /api/v1/domains/{domainId}/ssl
PATCH /api/v1/domains/{domainId}/ssl/settings
POST /api/v1/domains/{domainId}/ssl/request
POST /api/v1/domains/{domainId}/ssl/acme/issue
POST /api/v1/domains/{domainId}/ssl/request-cert
POST /api/v1/domains/{domainId}/ssl/renew
GET /api/v1/domains/{domainId}/ssl/acme-status
GET /api/v1/domains/{domainId}/ssl/jobs/{jobId}
POST /api/v1/domains/{domainId}/ssl/check
POST /api/v1/domains/{domainId}/ssl/manual-certificate
GET /api/v1/domains/{domainId}/security/events
GET /api/v1/analytics/cache
GET /api/v1/domains/{domainId}/analytics/summary
GET /api/v1/domains/{domainId}/analytics/cache
GET /api/v1/domains/{domainId}/activity/requests
GET /api/v1/domains/{domainId}/activity
GET /api/v1/domains/{domainId}/activity/summary
GET /api/v1/domains/{domainId}/origins/health
GET /api/v1/domains/{domainId}/activity/requests/{requestId}
GET /api/v1/domains/{domainId}/activity/export
GET /api/v1/edge/nodes
GET /api/v1/edges/pools
GET /api/v1/edges/dns
POST /api/v1/edge/register
POST /api/v1/edge/heartbeat
GET /api/v1/edge/config
POST /api/v1/collector/usage
POST /api/v1/collector/security-events
GET /api/v1/usage/summary
POST /api/v1/usage/recalculate
GET /api/v1/usage/recalculate/{jobId}
```

## Current Command Names

These commands are registered in `core/artisan` and must keep the same names
when converted to Laravel command classes.

```text
cdn:admin:create
cdn:admin:list
cdn:admin:password
cdn:admin:delete
cdn:domain:create
cdn:domain:list
cdn:domain:show
cdn:domain:activate
cdn:domain:verify-ns
cdn:domains:verify-all
cdn:domain:update
cdn:domain:delete
cdn:dns:add-record
cdn:dns:list-records
cdn:dns:update-record
cdn:dns:delete-record
cdn:dns:bootstrap-edge-domain
cdn:dns:sync-edge-domain
cdn:dns:rebuild-customer-zones
cdn:dns:validate-routing
cdn:edge:list
cdn:edge:show
cdn:edge:disable
cdn:edge:register-token
cdn:edge:rotate-token
cdn:edge:sync-config
cdn:config-snapshots:prune
cdn:usage:summary
cdn:usage:recalculate
cdn:usage:ingest
cdn:usage:prune
cdn:settings:get
cdn:settings:set
cdn:settings:test-powerdns
cdn:powerdns:doctor
cdn:powerdns:dry-run
cdn:powerdns:force-sync
cdn:dns:reconcile
cdn:readiness:check
cdn:redirect:create
cdn:redirect:list
cdn:redirect:update
cdn:redirect:delete
cdn:waf:create
cdn:waf:list
cdn:waf:update
cdn:waf:delete
cdn:cache-rule:create
cdn:cache-rule:list
cdn:cache-rule:update
cdn:cache-rule:delete
cdn:cache:purge
cdn:cache:settings
cdn:header:create
cdn:header:list
cdn:header:update
cdn:header:delete
cdn:ip-rule:create
cdn:ip-rule:list
cdn:ip-rule:update
cdn:ip-rule:delete
cdn:origins:health-check
cdn:origins:list
cdn:recommendations:generate
cdn:ssl:renew-due
cdn:ssl:list
cdn:ssl:request
cdn:db:migrate
cdn:db:status
cdn:db:fresh
cdn:bootstrap:fresh
cdn:scheduler:run
```

## SQL Migration Inventory

`core/database/schema.sql` remains the authoritative fresh-install schema until
Phase 3 produces equivalent Laravel migrations. Existing SQL migration files:

```text
000001_baseline_schema.sql
000002_link_dns_records_to_origins.sql
000003_ssl_jobs.sql
000004_usage_request_diagnostics.sql
000005_origin_pool_defaults.sql
000006_protection_contract.sql
000007_managed_waf_metadata.sql
000008_rate_limit_header_keys.sql
000009_edge_config_version_visibility.sql
000010_performance_starter.sql
000011_bot_protection.sql
000012_verified_bot_sources.sql
000013_recommendations.sql
000014_domain_onboarding.sql
000015_admin_session_indexes.sql
000016_reporting_indexes.sql
000017_raw_geodns_routes.sql
000018_reconcile_runtime_schema.sql
000019_dns_record_ownership.sql
000020_config_snapshot_republish.sql
000021_phase1_reporting_foundation.sql
000022_phase2_analytics_async_aggregation.sql
000023_origin_shared_hosting_defaults.sql
000024_operations_report_audit_indexes.sql
000025_operations_report_range_indexes.sql
000026_config_snapshot_report_index.sql
000027_usage_aggregate_range_indexes.sql
000028_challenge_difficulty.sql
000029_waiting_room.sql
000030_phase6_cache_correctness.sql
000031_phase7_origin_resilience.sql
000032_config_snapshot_materialized_cache.sql
000033_runtime_retention_indexes.sql
```

## Schema Areas To Model

The fresh schema includes these major table groups:

- Domains, nameservers, routing settings, DNS records, GeoDNS routes, origins,
  and origin health observations.
- Edge nodes, pools, pool members, edge state generations, edge tokens, and
  replay-protection nonces.
- DNS desired state, desired RRsets, PowerDNS serials, sync state, and sync
  events.
- Admin users/sessions, audit log, platform settings/audit, and database
  workload budgets.
- Usage rollups, ingest keys, aggregates, telemetry batches, rejected events,
  reporting watermarks/results, analytics jobs, and analytics cache.
- Recommendations and domain onboarding state.
- Config state and config snapshots.
- Redirect, rate-limit, WAF, verified bot, header, IP, cache, protection
  profile/intent, managed rule, waiting room, page rule, and purge tables.
- SSL certificates, domain SSL settings, renewal history, jobs, and ACME
  account material.

## Test Inventory

The current core test suite is pytest-based and should stay as black-box
contract coverage during the migration. Laravel PHPUnit/Pest tests should be
added alongside it as implementation moves into Laravel.

```text
core/tests/test_admin_auth_contract.py
core/tests/test_admin_cli_contract.py
core/tests/test_analytics_api.py
core/tests/test_analytics_domain_filter.py
core/tests/test_analytics_service.py
core/tests/test_api_auth_contract.py
core/tests/test_api_auth_routes_contract.py
core/tests/test_cache_analytics_contract.py
core/tests/test_cache_purge_audit_contract.py
core/tests/test_cert_renewal_service.py
core/tests/test_cli_phase20_contract.py
core/tests/test_collector_unknown_domains_contract.py
core/tests/test_config_snapshot_materialized_cache_contract.py
core/tests/test_contract.py
core/tests/test_dashboard_contract.py
core/tests/test_dashboard_domains_design_contract.py
core/tests/test_dashboard_edge_network_design_contract.py
core/tests/test_dashboard_pagination_activity_contract.py
core/tests/test_dashboard_sidebar_design_contract.py
core/tests/test_dashboard_top_status_bar_design_contract.py
core/tests/test_db_test_utils.py
core/tests/test_dns_operations_phase5_contract.py
core/tests/test_dns_reconciler_contract.py
core/tests/test_dns_routing_model_contract.py
core/tests/test_dns_routing_phase9_contract.py
core/tests/test_dns_ssl_production_regressions.py
core/tests/test_dns_stress_phase7_contract.py
core/tests/test_dnsgeo_deployment_contract.py
core/tests/test_docs_pages_contract.py
core/tests/test_domain_onboarding_contract.py
core/tests/test_domain_tabs_phase10_contract.py
core/tests/test_edge_agent_stage6_contract.py
core/tests/test_edge_auth_contract.py
core/tests/test_edge_error_page_contract.py
core/tests/test_edge_health_record_builder.py
core/tests/test_edge_identity_contract.py
core/tests/test_edge_network_phase12_contract.py
core/tests/test_edge_phase3_contract.py
core/tests/test_edge_reliability_stage7_contract.py
core/tests/test_hardening_contract.py
core/tests/test_header_ip_rules_contract.py
core/tests/test_migrations_contract.py
core/tests/test_migrations_live_postgres.py
core/tests/test_nameserver_force_verify_contract.py
core/tests/test_operations_log_phase13_14_contract.py
core/tests/test_origin_health_phase19_contract.py
core/tests/test_origin_record_refactor_contract.py
core/tests/test_overview_service.py
core/tests/test_phase0_repro_contract.py
core/tests/test_phase10_protection_profiles_contract.py
core/tests/test_phase11_managed_waf_presets_contract.py
core/tests/test_phase12_smart_rate_limiting_contract.py
core/tests/test_phase13_bot_protection_contract.py
core/tests/test_phase14_15_contract.py
core/tests/test_phase14_api_protection_contract.py
core/tests/test_phase15_performance_starter_contract.py
core/tests/test_phase16_recommendations_contract.py
core/tests/test_phase17_guided_onboarding_contract.py
core/tests/test_phase18_beginner_activity_contract.py
core/tests/test_phase1_reporting_foundation_contract.py
core/tests/test_phase21_edge_config_visibility_contract.py
core/tests/test_phase2_analytics_async_contract.py
core/tests/test_phase3_edge_hot_path_contract.py
core/tests/test_phase4_challenge_clearance_contract.py
core/tests/test_phase5_waiting_room_contract.py
core/tests/test_phase6_activity_diagnostics_contract.py
core/tests/test_phase6_cache_correctness_contract.py
core/tests/test_phase7_config_invalidation_contract.py
core/tests/test_phase7_origin_resilience_contract.py
core/tests/test_phase8_protection_contract.py
core/tests/test_phase9_security_center_contract.py
core/tests/test_powerdns_client_contract.py
core/tests/test_production_deploy_contract.py
core/tests/test_rate_limit_crud_contract.py
core/tests/test_readiness_service.py
core/tests/test_reports_contract.py
core/tests/test_router_contract.py
core/tests/test_security_events_contract.py
core/tests/test_security_events_ingest_contract.py
core/tests/test_settings_contract.py
core/tests/test_ssl_jobs_phase4_contract.py
core/tests/test_traffic_rules_validation_contract.py
core/tests/test_usage_timeseries_contract.py
core/tests/test_validation_routes_contract.py
core/tests/test_validator_contract.py
```

## Dashboard Inventory

The dashboard is a Vue 3, TypeScript, Vite application under `dash/`. The
Laravel migration must preserve:

- API client modules in `dash/src/lib/api/*`.
- Auth/session stores and edge dev tooling.
- Views for overview, domains, domain tabs, DNS operations, edge network,
  security events, jobs, audit, config snapshots, settings, and analytics.
- Pinia/TanStack Query-style data invalidation and polling behavior currently
  tested in `dash/src/lib/data/*`.
- Existing dashboard tests, typecheck, and production build.

Phase 9 should decide whether to keep the dashboard container or integrate the
Vue app into Laravel Vite. Until that phase, the default assumption is to keep
the separate container and preserve `http://localhost:8082`.

## Docker, Deployment, And CI Inventory

- Root Compose exposes core on `8080`, edge on `8081`, dashboard on `8082`,
  PowerDNS API on the configured host port, and PostgreSQL on the configured
  host port.
- Core currently uses PHP-FPM, nginx, and supervisor from `core/Dockerfile`.
- Root Compose injects the existing `DB_*`, `CDNLITE_*`, edge, ACME, scheduler,
  retention, PowerDNS, telemetry, Redis, PHP-FPM, and CORS environment
  variables into `core`.
- CI currently runs PHP lint, shell syntax checks, custom `php core/artisan
  cdn:db:migrate`, dashboard install/typecheck/test/build, `pytest -q
  core/tests`, root Compose smoke/e2e, DNS e2e, and optional DNS stress.
- Split deployment files in `deploy/` must be updated when the Laravel core
  image, commands, scheduler, or dashboard serving model changes.

## Phase 2 Entry Criteria

Phase 2 has started with a reversible Laravel foundation. The current runtime
still serves the existing custom `core/public_index.php` router through nginx so
public API behavior is preserved while Laravel routes are migrated.

Completed foundation work:

- Added standard Laravel project files under `core/`, including
  `composer.json`, `composer.lock`, `bootstrap/`, `config/`, `public/`,
  `routes/`, `resources/`, and `phpunit.xml`.
- Added a Laravel `core/artisan` wrapper that delegates current `cdn:*`
  commands to `core/artisan-legacy` until each command is migrated.
- Added PostgreSQL as the default Laravel database connection.
- Added Laravel config for CDNLite environment groups in `config/cdnlite.php`.
- Added initial Laravel `/health`, `/ready`, `/cdn-health`, and
  `/api/v1/readiness` route definitions for focused Phase 2 testing.
- Updated `core/Dockerfile` to install Composer dependencies during image
  builds.

Remaining Phase 2 work:

- Build the core image to verify Composer install inside Docker.
- Decide when nginx should switch from `public_index.php` to Laravel
  `public/index.php`; do this only after equivalent Laravel routes preserve
  public API behavior.
- Add focused Laravel feature tests for the initial health/readiness routes.
- Keep `core/public_index.php`, `core/artisan-legacy`, support helpers, SQL
  migrations, and existing command classes until replacements pass contract
  tests.
