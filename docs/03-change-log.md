# Change Log

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
- Added persistent DB path `core/storage/cdnt.sqlite`.

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
