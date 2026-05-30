# Roadmap

## Phase 0: Repository bootstrap
- Add architecture/docs baseline
- Define modules, API contract, and DB model
- Define agent execution guidelines

## Phase 1: CDN Core v1 (MVP)
- Implement `Sites` module (CRUD + proxy toggle)
- Implement `Dns` module with PowerDNS adapter
- Implement `Edge` module (token issuance, register, heartbeat, list)
- Implement `Proxy` module (config snapshot generation and versioning)
- Implement `Collector` module (usage ingest + usage query)
- Add matching Artisan commands for all critical operations

## Phase 2: Edge v1 runtime
- Implement edge `docker-compose.yml`
- Implement OpenResty Lua flow:
  - config loader
  - host router
  - proxy forwarding
  - metrics counter
- Implement agent flow:
  - register
  - heartbeat
  - pull config
  - push metrics

## Phase 3: Hardening
- Idempotency for ingest and config sync
- Edge auth rotation and replay protection
- Better rollback and snapshot verification
- Usage aggregation jobs (minute/hour/day)

## Phase 4: Extensibility scaffolding
- Add interfaces and empty extension points for:
  - Redirects
  - Cache rules
  - WAF hooks
  - Rate limiting hooks
- Keep modules inactive by default

## Phase 5: Future AI-agent development
- Add task specs per module for autonomous execution
- Add test-generation and migration-review checklists
- Add architecture compliance checks (lint for module boundaries)
- Add release agent workflow (changelog + migration safety checks)
