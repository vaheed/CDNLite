# Change Log

## 2026-05-31

### Core runtime database
- Switched core runtime default database driver to PostgreSQL via environment-based connection config.
- Added PostgreSQL schema bootstrap file (`core/database/schema.pgsql.sql`).
- Updated Docker runtime to include `pdo_pgsql` and wired `postgres` service into `docker-compose.yml`.
- Kept SQLite support for local test execution and updated tests to explicitly set SQLite driver.

### Edge runtime UX
- Added modern branded OpenResty error/status page renderer for 5xx proxy failures.
- Added dynamic diagnostics on edge error pages:
  - request ID
  - edge location
  - timestamp
  - client IP
  - hostname
- Added OpenResty build-time module check to ensure Lua cjson availability.

### Collector hardening
- Added materialized usage aggregates table (`usage_aggregates`) for `minute`, `hour`, and `day` buckets.
- Added deterministic aggregate rebuild flow in Collector service and exposed it via:
  - API: `POST /api/v1/usage/recalculate`
  - CLI: `cdn:usage:recalculate` (supports optional `--site_id`)
- Extended usage summary query to support aggregate buckets:
  - API: `GET /api/v1/usage/summary?bucket=minute|hour|day`
  - CLI: `cdn:usage:summary --bucket=<minute|hour|day>`
- Added contract test coverage validating aggregate rebuild and bucketed summaries.

## 2026-05-30

### Architecture and process
- Added root agent governance and delivery docs.
- Added AI-agent roadmap and future execution plan files.

### Runtime stack
- Added `docker-compose.yml` with `core`, `edge`, and `edge-agent` services.
- Added OpenResty runtime config and Lua pipeline:
  - config loader
  - host router
  - proxy handoff
  - metric logging
- Added edge agent scripts:
  - register
  - heartbeat
  - pull config
  - push metrics
  - runtime loop

### Core API implementation
- Replaced initial prototype with PHP core runtime (`core/public_index.php`).
- Implemented module services and controllers for:
  - Sites
  - DNS
  - Edge
  - Proxy config snapshot generation
  - Collector
- Added full v1 endpoints for sites, dns, edge, collector.

### Persistence
- Migrated core persistence to SQLite using PDO.
- Added `core/database/schema.sql` and auto-migration bootstrap.
- Added SQLite persistence for early baseline runtime (later replaced by PostgreSQL-default runtime).

### CLI implementation
- Added executable CLI runner `core/artisan`.
- Implemented all main CDN command signatures:
  - `cdn:site:create`
  - `cdn:site:list`
  - `cdn:site:update`
  - `cdn:site:delete`
  - `cdn:dns:add-record`
  - `cdn:dns:list-records`
  - `cdn:dns:delete-record`
  - `cdn:edge:list`
  - `cdn:edge:register-token`
  - `cdn:edge:sync-config`
  - `cdn:usage:summary`
  - `cdn:usage:recalculate`

### CI/CD
- Added single GitHub Actions pipeline with stages:
  - test
  - smoke
  - e2e
  - build and push
- Added GHCR image build/push for core, edge, edge-agent.
- Added CI checks for syntax and unfinished marker text.

### Test scripts
- Added `ci/smoke.sh` for health checks.
- Added `ci/e2e.sh` for integration flow checks.

### Documentation
- Updated root README with API and CLI usage.
- Added runtime stage documentation (`docs/02-runtime-stages.md`).

### Hardening
- Added usage ingest idempotency support via optional `idempotency_key` and persisted dedupe table (`usage_ingest_keys`).
- Added CLI command `cdn:usage:ingest` for direct usage ingestion from terminal automation.
- Added deterministic config snapshot storage (`config_snapshots`) so config version only increments when snapshot content changes.
- Added `if_version` support to edge config sync (`GET /api/v1/edge/config` and `cdn:edge:sync-config`) for no-change polling.
- Added contract tests for ingest dedupe and config snapshot version reuse.
- Added edge token authentication and replay protection on edge control and usage ingest endpoints.
- Added nonce storage table (`edge_request_nonces`) with duplicate nonce rejection per edge.
- Added edge token rotation CLI command (`cdn:edge:rotate-token`).
- Updated edge agent scripts to send auth and replay-protection headers on every control-plane call.
