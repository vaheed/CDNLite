# Roadmap

This roadmap is organized as implementation phases with clear start criteria, delivery targets, and exit gates.

## Phase 0: Foundation and Baseline
Status: Done

Goals:
- Keep architecture modular and API/CLI-first
- Maintain documentation and runtime stage discipline

Implementation items:
- [x] Baseline architecture docs and agent governance
- [x] Runtime stack bootstrapping and local compose flow
- [x] DB schema baseline and migration bootstrap

Exit gate:
- Repo can be booted, linted, and tested by a new contributor using documented steps.

## Phase 1: Core v1 Functional Platform
Status: Done

Goals:
- Complete operational core modules for site onboarding and traffic control

Implementation items:
- [x] `Sites`: create/list/update/delete + proxy toggle
- [x] `Dns`: add/list/delete records (adapter-ready)
- [x] `Edge`: register, heartbeat, node list, token management
- [x] `Proxy`: config snapshot generation + versioning
- [x] `Collector`: usage ingest + summary query
- [x] CLI parity for critical operations

Exit gate:
- All core operations available through both API and CLI.

## Phase 2: Edge Runtime v1
Status: Done

Goals:
- Run edge data plane and agent control loop reliably in one deployment

Implementation items:
- [x] OpenResty + Lua routing pipeline
- [x] Host-based routing and proxy handoff
- [x] Metrics capture and forwarding
- [x] Agent loop: register, heartbeat, pull config, push metrics
- [x] Last-known-good config behavior

Exit gate:
- E2E flow proves edge can serve enabled sites and fail safely when disabled/origin fails.

## Phase 3: Hardening and Production Safety
Status: Done (baseline)

Goals:
- Improve reliability, replay safety, and deterministic behavior

Implementation items:
- [x] Idempotency for usage ingest and config polling
- [x] Edge token auth with replay-protection headers
- [x] Token rotation command flow
- [x] Deterministic config snapshots and no-op version reuse
- [x] Usage aggregation rebuild/query support (`minute|hour|day`)
- [x] Branded edge error/status page for upstream failures

Exit gate:
- Contract tests and E2E checks cover failure and retry paths.

## Phase 4: Extensibility Interfaces (Start Implementation)
Status: Planned (next active phase)

Goals:
- Prepare extension points without adding heavyweight runtime complexity

Implementation items:
- Add inactive module scaffolds and interfaces for:
  - Redirects
  - Cache Rules
  - WAF hooks
  - Rate Limiting hooks
- Add API/CLI surface for rule lifecycle (create/list/enable/disable/delete)
- Keep all extensions feature-flagged and disabled by default
- Add validation contracts per extension type

Start now checklist:
- Create module folders and service interfaces in `app/Modules/*`
- Add no-op implementations with explicit "not enabled" responses
- Add route + controller + CLI command stubs for each extension
- Add tests confirming disabled-by-default behavior

Exit gate:
- Extensions are pluggable and test-covered without impacting core traffic path.

## Phase 5: Operational Maturity and Automation (Start Implementation)
Status: Planned

Goals:
- Make releases safer and operations more repeatable

Implementation items:
- Architecture compliance checks (module-boundary lint rules)
- Migration safety checks (preflight + rollback notes)
- Task spec templates per module for autonomous execution
- Release workflow automation:
  - change-log verification
  - runtime-stage verification
  - image/tag integrity checks
- Expand observability:
  - structured logs
  - error counters by edge/origin status class

Start now checklist:
- Add CI job for architecture/boundary checks
- Add release checklist script under `ci/`
- Add migration review template in `docs/`
- Add basic metrics SLO report command in CLI

Exit gate:
- Every release candidate passes automated safety gates before publish.

## Phase 6: Scale-on-Demand Improvements
Status: Future

Goals:
- Scale only when traffic and operational evidence require it

Implementation items:
- Pooling/tuning for DB and upstream connections
- Async jobs for heavy aggregation and reporting
- Storage/index tuning driven by observed bottlenecks
- Optional sharding strategy design (deferred)

Exit gate:
- Throughput and latency targets are met under load tests tied to real usage patterns.

## Production Start Gate (Current Step)
Status: Ready to start production hardening work

What is already ready:
- [x] Core + Edge + Agent runtime architecture is in place
- [x] PostgreSQL default runtime path exists
- [x] CI pipeline includes lint, unit test, smoke, e2e, and image build/push
- [x] Security baseline exists for edge control-plane auth and replay protection

Next immediate work (recommended order):
1. Start Phase 4 scaffolding (Redirects, Cache Rules, WAF hooks, Rate Limiting hooks) behind feature flags.
2. Start Phase 5 safety automation (architecture lint, release checklist, migration review template).
3. Add stronger production checks:
   - health/readiness for Postgres dependency
   - backup/restore runbook for Postgres
   - alerting and log retention policy
