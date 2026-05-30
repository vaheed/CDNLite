# AGENTS

## Purpose
This repository is run by human + AI agents to build a lightweight modular CDN.

## Product target
- Build a simple CDN core, not a Cloudflare clone.
- Keep core + edge modular and easy to operate.
- Prioritize API-first and CLI-first execution.

## Agent operating rules
- Keep implementations minimal, explicit, and readable.
- Do not introduce Kubernetes, Kafka, microservices, or heavy distributed patterns in v1.
- Use module boundaries:
  - `app/Modules/Sites`
  - `app/Modules/Dns`
  - `app/Modules/Edge`
  - `app/Modules/Collector`
  - `app/Modules/Proxy`
  - `app/Modules/Core`
- Controllers must stay thin; business logic goes to services.
- Every new feature must expose API and CLI entry points.
- Edge must keep last-known-good config and fail safe.

## Runtime stage files
- Runtime stages are defined in `docs/02-runtime-stages.md`.
- Change history is tracked in `docs/03-change-log.md`.

## Documentation policy
- Every behavior change must update the relevant documentation in the same change.
- Runtime-flow changes must update `docs/02-runtime-stages.md`.
- Significant implementation changes must be appended to `docs/03-change-log.md`.
- Core behavior changes must also update `core/AGENTS.md` requirements.
- Edge behavior changes must also update `edge/AGENTS.md` requirements.

## Definition of done (task level)
- Code implemented in the correct module.
- API route + request validation + service path present.
- CLI command present for key operation.
- Tests added or updated for behavior and failure path.
- Runtime and change-log docs updated in the same change.

## Delivery phases
1. Core v1 foundation (sites, DNS, edge registration, config sync, usage ingest)
2. Operational hardening (auth tightening, retries, idempotency, observability)
3. Extensible modules (redirects, cache rules, WAF interfaces)
4. Scale improvements only when needed (pooling, async jobs, aggregation tuning)
