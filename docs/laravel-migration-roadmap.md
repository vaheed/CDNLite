You are an expert Laravel migration engineer. Convert the repository `vaheed/CDNLite` into a production-grade Laravel application while preserving all existing product behavior, APIs, Docker workflows, dashboard functionality, edge sync behavior, database state, tests, documentation, and CI validation.

Repository context:
- CDNLite is a self-hosted private CDN control plane and edge platform.
- Current architecture includes:
  - PHP 8.3 custom Core API.
  - PostgreSQL 16 state store.
  - Vue 3 + TypeScript + Vite dashboard.
  - OpenResty/Nginx/Lua edge proxy.
  - POSIX shell edge agent.
  - PowerDNS/DNSGeo publishing.
  - Docker Compose root deployment.
  - Custom SQL migration runner.
  - Custom `core/artisan` command runner.
  - Custom `public_index.php` HTTP router.
- The goal is to replace the custom PHP framework pieces with real Laravel, not merely rename files.
- Preserve the OpenResty/Lua edge runtime as the data plane. Do not rewrite the hot-path edge proxy into Laravel. Laravel should become the control plane, API, scheduler, CLI, auth, queue, database, event, and dashboard host layer.

Main objective:
Rebuild `core/` as a standard Laravel project and integrate the existing CDNLite control-plane functionality into Laravel idioms:
- Laravel routing.
- Controllers.
- Form requests or validators.
- Services.
- Eloquent models or query-builder repositories.
- Laravel migrations.
- Laravel console commands.
- Scheduler.
- Queues/jobs where appropriate.
- Middleware.
- Config files.
- Service providers.
- Policies/authorization structure.
- Tests using PHPUnit/Pest-compatible Laravel testing.
- Vite integration for the dashboard.

Hard requirements:
1. Preserve all existing public API endpoints and response contracts.
2. Preserve dashboard behavior.
3. Preserve edge-agent behavior:
   - Signed config polling.
   - Heartbeats.
   - Metrics ingestion.
   - Security-event ingestion.
   - Edge token validation.
   - Timestamp/nonce/HMAC replay protection.
4. Preserve DNS/PowerDNS behavior:
   - Desired-state generation.
   - Reconciliation.
   - Force sync.
   - Dry run.
   - Health/readiness endpoints.
   - DNSGeo routing behavior.
5. Preserve SSL/ACME behavior:
   - DNS-01 flows.
   - Queued/terminal job states.
   - Renewal scheduling.
   - Manual certificate import if present.
6. Preserve PostgreSQL as the only supported database backend.
7. Preserve root Docker Compose workflow:
   - `docker compose up -d --build`
   - Core health on port 8080.
   - Edge health on port 8081.
   - Dashboard access on port 8082 unless intentionally merged into Laravel and documented.
8. Preserve CI commands or update them with equivalent Laravel commands.
9. Preserve docs, OpenAPI, quickstart, deployment, security, and architecture docs.
10. Do not remove features silently. If a feature cannot be migrated in one pass, create a clear tracked TODO with the exact old file/function and expected replacement.

Target architecture:
Use a standard Laravel application under `core/`:

core/
  app/
    Console/Commands/
    Http/Controllers/
    Http/Middleware/
    Http/Requests/
    Models/
    Policies/
    Providers/
    Services/
    Actions/
    Repositories/
    Support/
  bootstrap/
  config/
  database/
    migrations/
    seeders/
    factories/
  public/
    index.php
  routes/
    api.php
    web.php
    console.php if needed
  storage/
  tests/
    Feature/
    Unit/
  composer.json
  artisan

Recommended Laravel structure:
- Move current module controllers from `core/app/Modules/*/Http/Controllers` into Laravel controllers, preserving namespaces or creating a clean domain namespace.
- Move current services into `app/Services` or `app/Domain/<Module>`.
- Keep domain boundaries:
  - Admin/Auth
  - Domains
  - DNS
  - Edge
  - Collector
  - Proxy/Origins
  - Traffic Rules
  - Cache
  - SSL
  - Settings
  - Overview
  - Operations
  - Reports
  - Recommendations
  - Health/Readiness
- Use `routes/api.php` for `/api/v1/...` endpoints.
- Use `routes/web.php` only for dashboard fallback/static serving if needed.
- Use Laravel middleware for:
  - API bearer token auth.
  - Admin session bearer token auth.
  - Edge HMAC signature validation.
  - CORS.
  - Request logging.
  - JSON error responses.
- Use Laravel exception handler to preserve current JSON error contract.
- Use Laravel config files for all `CDNLITE_*`, `DB_*`, PowerDNS, ACME, edge, CORS, retention, and scheduler environment variables.

Migration plan:
Perform the work in safe, reviewable phases.

Phase 1: Inventory and baseline
- Inspect all current files under:
  - `core/`
  - `dash/`
  - `edge/`
  - `ci/`
  - `docs/`
  - `.github/workflows/`
  - root `docker-compose.yml`
- Produce a migration map:
  - old file path
  - old responsibility
  - new Laravel file path
  - migration status
- Identify all routes currently registered in `core/public_index.php`.
- Identify all commands currently registered in `core/artisan`.
- Identify all SQL migrations under `core/database/migrations`.
- Identify all tests under `core/tests`.
- Do not start deleting old files until the migration map is complete.

Phase 2: Create Laravel foundation
- Replace the custom PHP core skeleton with a standard Laravel application.
- Add a real `core/composer.json`.
- Add Laravel’s standard `artisan`.
- Add `public/index.php`.
- Configure PostgreSQL.
- Configure Laravel logging to stdout/stderr for containers.
- Configure health/readiness endpoints.
- Configure CORS equivalent to the current behavior.
- Ensure `php artisan` works inside the core container.

Phase 3: Database migration conversion
- Convert existing SQL migrations into Laravel migrations.
- Preserve table names, columns, indexes, constraints, defaults, JSON fields, timestamps, and PostgreSQL-specific behavior.
- Preserve the existing `schema_migrations` compatibility story if existing deployments need it.
- If Laravel’s `migrations` table replaces the custom table, provide a safe adoption path:
  - Fresh installs must work.
  - Existing CDNLite databases must be upgradeable without data loss.
  - Legacy custom migration state must be detected and reconciled.
- Add migration tests for a fresh database.
- Add compatibility tests for existing schema where feasible.

Phase 4: Models and repositories
- Add Eloquent models or query-builder repositories for all main tables:
  - domains
  - domain_nameservers
  - dns_records
  - domain_origins
  - edge_nodes
  - config_snapshots
  - audit_log
  - admin users/sessions
  - settings
  - SSL jobs/certificates
  - WAF rules
  - rate limit rules
  - cache rules
  - redirect rules
  - response/header rules
  - IP access rules
  - security events
  - usage/analytics/reporting tables
  - DNS sync state/events/desired records
- Prefer Eloquent for normal domain entities.
- Use query builder/raw SQL where PostgreSQL-specific bulk operations or performance-critical reporting require it.
- Preserve existing JSON response shapes.

Phase 5: HTTP API migration
- Recreate every current route in Laravel.
- Preserve methods, paths, parameters, status codes, and payload formats.
- At minimum preserve these route groups:
  - `/health`
  - `/ready`
  - `/cdn-health`
  - `/api/v1/admin/*`
  - `/api/v1/readiness`
  - `/api/v1/overview`
  - `/api/v1/recommendations`
  - `/api/v1/reports/*`
  - `/api/v1/security/*`
  - `/api/v1/audit`
  - `/api/v1/events`
  - `/api/v1/jobs`
  - `/api/v1/config/snapshots/*`
  - `/api/v1/settings/*`
  - `/api/v1/dns/*`
  - `/api/v1/edge-countries`
  - `/api/v1/domains/*`
  - all domain subresources
  - all DNS record endpoints
  - all edge-agent endpoints
  - all collector/metrics/security-event ingestion endpoints
  - all SSL endpoints
  - all cache/rules/origin endpoints
- Implement route model binding only if it does not change error behavior.
- Add feature tests for all critical endpoints.

Phase 6: Auth and security
- Implement admin login/session behavior using Laravel services/middleware.
- Preserve current bootstrap admin variables:
  - `CDNLITE_BOOTSTRAP_ADMIN_USER`
  - `CDNLITE_BOOTSTRAP_ADMIN_USERNAME`
  - `CDNLITE_BOOTSTRAP_ADMIN_PASSWORD`
  - `CDNLITE_BOOTSTRAP_ADMIN_DISPLAY_NAME`
  - `CDNLITE_ADMIN_SESSION_TTL_SECONDS`
- Preserve API token behavior:
  - `CDNLITE_API_TOKEN`
  - production missing-token failure behavior.
- Preserve edge auth:
  - edge ID
  - bearer token
  - HMAC signature
  - timestamp
  - nonce/replay protection.
- Never log secrets, tokens, private keys, ACME account material, cert private keys, or request Authorization headers.
- Add tests for invalid token, missing token, invalid HMAC, expired timestamp, replayed nonce, and valid signed edge request.

Phase 7: Console commands
- Convert every custom command from the existing `core/artisan` runner into Laravel `Command` classes.
- Preserve command names exactly, including:
  - `cdn:admin:create`
  - `cdn:admin:list`
  - `cdn:admin:password`
  - `cdn:admin:delete`
  - `cdn:domain:create`
  - `cdn:domain:list`
  - `cdn:domain:show`
  - `cdn:domain:activate`
  - `cdn:domain:verify-ns`
  - `cdn:domains:verify-all`
  - `cdn:domain:update`
  - `cdn:domain:delete`
  - `cdn:dns:add-record`
  - `cdn:dns:list-records`
  - `cdn:dns:update-record`
  - `cdn:dns:delete-record`
  - `cdn:dns:bootstrap-edge-domain`
  - `cdn:dns:sync-edge-domain`
  - `cdn:dns:rebuild-customer-zones`
  - `cdn:dns:validate-routing`
  - `cdn:dns:reconcile`
  - `cdn:edge:list`
  - `cdn:edge:show`
  - `cdn:edge:disable`
  - `cdn:edge:register-token`
  - `cdn:edge:rotate-token`
  - `cdn:edge:sync-config`
  - `cdn:usage:summary`
  - `cdn:usage:recalculate`
  - `cdn:usage:ingest`
  - `cdn:usage:prune`
  - `cdn:settings:get`
  - `cdn:settings:set`
  - `cdn:settings:test-powerdns`
  - `cdn:powerdns:doctor`
  - `cdn:powerdns:dry-run`
  - `cdn:powerdns:force-sync`
  - `cdn:readiness:check`
  - `cdn:redirect:create`
  - `cdn:redirect:list`
  - `cdn:redirect:update`
  - `cdn:redirect:delete`
  - `cdn:waf:create`
  - `cdn:waf:list`
  - `cdn:waf:update`
  - `cdn:waf:delete`
  - `cdn:cache-rule:create`
  - `cdn:cache-rule:list`
  - `cdn:cache-rule:update`
  - `cdn:cache-rule:delete`
  - `cdn:cache:purge`
  - `cdn:cache:settings`
  - `cdn:header:create`
  - `cdn:header:list`
  - `cdn:header:update`
  - `cdn:header:delete`
  - `cdn:ip-rule:create`
  - `cdn:ip-rule:list`
  - `cdn:ip-rule:update`
  - `cdn:ip-rule:delete`
  - `cdn:origins:health-check`
  - `cdn:origins:list`
  - `cdn:recommendations:generate`
  - `cdn:ssl:renew-due`
  - `cdn:ssl:list`
  - `cdn:ssl:request`
  - `cdn:db:migrate`
  - `cdn:db:status`
  - `cdn:db:fresh`
  - `cdn:bootstrap:fresh`
- Keep aliases if needed for backward compatibility.
- Replace custom DB commands with Laravel migration wrappers only when behavior stays compatible.

Phase 8: Scheduler and jobs
- Replace long-running shell loops where appropriate with Laravel Scheduler.
- Preserve Docker Compose service behavior for:
  - SSL scheduler.
  - origin health scheduler.
  - nameserver scheduler.
  - retention scheduler.
  - DNS reconciler.
- Use `php artisan schedule:work` or explicit command loops only after documenting the chosen approach.
- Use Laravel jobs/queues for expensive or asynchronous work where appropriate, but keep local Compose simple and reliable.
- Ensure no scheduler silently exits in Docker.

Phase 9: Dashboard integration
Choose one of the following and implement consistently:

Preferred option:
- Keep the existing Vue 3 dashboard but integrate it into Laravel Vite.
- Move or link dashboard source into Laravel’s frontend structure if practical:
  - `resources/js`
  - `resources/css`
  - Laravel Vite config.
- Preserve TypeScript, Pinia, TanStack Query, Tailwind, ECharts, tests, and build behavior.
- Preserve dashboard API base URL behavior.
- Either:
  - serve dashboard from Laravel, or
  - keep separate dashboard container but document why.
- If serving through Laravel, add SPA fallback route and ensure API routes are not swallowed by the fallback.

Alternative option:
- If a full Laravel-rendered dashboard is required, rebuild using Blade/Livewire/Inertia while preserving all existing user workflows. Do not choose this unless explicitly requested, because it is much larger and higher risk.

Phase 10: Docker and deployment
- Update `core/Dockerfile` for Laravel:
  - install Composer dependencies.
  - install required PHP extensions: pdo_pgsql and any needed extensions.
  - cache config/routes/views safely for production where appropriate.
  - use proper entrypoint.
  - run migrations only when explicitly configured, not unconditionally on every start unless existing behavior requires it.
- Update `docker-compose.yml`:
  - preserve service names and ports where possible.
  - preserve environment variable names.
  - preserve healthchecks.
  - preserve dashboard and edge dependencies.
- Ensure root quickstart still works:
  - `cp .env.example .env`
  - `docker compose up -d --build`
  - `curl -fsS http://localhost:8080/health`
  - `curl -fsS http://localhost:8081/health`

Phase 11: Tests and CI
- Convert PHP tests to Laravel tests.
- Preserve existing Python/pytest tests if they validate black-box API behavior.
- Add/keep tests for:
  - health/readiness
  - admin login
  - API auth
  - domain CRUD
  - DNS records
  - DNS reconcile dry-run/force-sync behavior
  - edge registration/heartbeat/config
  - metrics/security ingest
  - config snapshot generation
  - cache/rules/WAF/rate-limit/IP/header rules
  - SSL request/renew-due flows
  - settings validation
  - reports/analytics
  - CLI commands
- Update CI to run:
  - Composer install.
  - PHP lint/static analysis if configured.
  - Laravel tests.
  - dashboard typecheck/test/build.
  - docs build.
  - Docker Compose config validation.
  - smoke/e2e scripts.

Phase 12: Documentation
Update:
- README.md
- docs/quickstart.md
- docs/architecture.md
- docs/deployment.md
- docs/security.md
- docs/production-hardening.md
- OpenAPI docs if endpoint behavior or auth documentation changes.
- CONTRIBUTING.md
- CI docs.

Documentation must explain:
- Laravel core architecture.
- How to run migrations.
- How to run scheduler/workers.
- How to run tests.
- How dashboard is served.
- How old deployments upgrade safely.
- Which parts remain non-Laravel and why:
  - OpenResty/Lua edge proxy.
  - shell edge agent if retained.
  - PowerDNS/DNSGeo services.

Implementation rules:
- Make small, reviewable commits or clearly separated changes.
- Preserve behavior first; refactor second.
- Do not introduce breaking endpoint changes unless unavoidable and documented.
- Do not delete old custom implementation until equivalent Laravel code and tests exist.
- Avoid global helper sprawl. Use services, config, dependency injection, middleware, and commands.
- Use Laravel conventions where they do not break compatibility.
- Keep secrets out of logs and committed files.
- Ensure containers work without requiring a developer’s global PHP/Composer installation.
- Keep PostgreSQL-specific behavior explicit and tested.
- Do not use SQLite for tests if PostgreSQL-specific behavior matters; use PostgreSQL in CI where needed.

Acceptance criteria:
The migration is complete only when all of the following are true:

1. `core/` is a standard Laravel app.
2. The old custom `public_index.php` router is removed or replaced by Laravel routes.
3. The old custom autoloader is removed or no longer used.
4. The old custom command runner is replaced by Laravel console commands.
5. All previous API endpoints still work.
6. All previous command names still work.
7. Existing Docker Compose quickstart still works.
8. Dashboard still builds and functions.
9. Edge proxy still receives valid config snapshots.
10. Edge agent can register, heartbeat, fetch config, and push telemetry.
11. DNS reconcile/dry-run/force-sync works against PowerDNS.
12. SSL scheduler and ACME workflows still work.
13. Existing docs and CI are updated.
14. Fresh install succeeds from an empty database.
15. Upgrade path from existing CDNLite database is documented and tested where possible.
16. Tests pass.
17. Smoke/e2e scripts pass.
18. No secrets are logged.
19. OpenAPI remains accurate.
20. README accurately describes the Laravel-based architecture.

Start by producing the migration inventory and file mapping. Then implement the migration phase by phase. After each phase, run the relevant tests and report:
- files changed
- behavior preserved
- tests run
- remaining risks
- next phase