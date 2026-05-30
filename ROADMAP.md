# Roadmap

This roadmap is organized as implementation phases with clear start criteria, delivery targets, and exit gates.

## Phase 0: Foundation and Baseline
Status: In progress

Goals:
- Keep architecture modular and API/CLI-first
- Maintain documentation and runtime stage discipline

Implementation items:
- Baseline architecture docs and agent governance
- Runtime stack bootstrapping and local compose flow
- DB schema baseline and migration bootstrap

Exit gate:
- Repo can be booted, linted, and tested by a new contributor using documented steps.

## Phase 1: Core v1 Functional Platform
Status: In progress

Goals:
- Complete operational core modules for site onboarding and traffic control

Implementation items:
- `Sites`: create/list/update/delete + proxy toggle
- `Dns`: add/list/delete records (adapter-ready)
- `Edge`: register, heartbeat, node list, token management
- `Proxy`: config snapshot generation + versioning
- `Collector`: usage ingest + summary query
- CLI parity for critical operations

Exit gate:
- All core operations available through both API and CLI.

## Phase 2: Edge Runtime v1
Status: In progress

Goals:
- Run edge data plane and agent control loop reliably in one deployment

Implementation items:
- OpenResty + Lua routing pipeline
- Host-based routing and proxy handoff
- Metrics capture and forwarding
- Agent loop: register, heartbeat, pull config, push metrics
- Last-known-good config behavior

Exit gate:
- E2E flow proves edge can serve enabled sites and fail safely when disabled/origin fails.

## Phase 3: Hardening and Production Safety
Status: In progress

Goals:
- Improve reliability, replay safety, and deterministic behavior

Implementation items:
- Idempotency for usage ingest and config polling
- Edge token auth with replay-protection headers
- Token rotation command flow
- Deterministic config snapshots and no-op version reuse
- Usage aggregation rebuild/query support (`minute|hour|day`)
- Branded edge error/status page for upstream failures

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
