# CDNLite Repository Guide

## Scope

These instructions apply to the entire repository unless a narrower
`AGENTS.md` exists in a subdirectory.

CDNLite is pre-1.0 and fresh-install-only. Backward compatibility is not
required unless a task explicitly requests it. Do not introduce historical
migrations, upgrade shims, deprecated environment aliases, or old API and CLI
aliases. `core/database/schema.sql` is the authoritative PostgreSQL schema.

## Product Surfaces

Treat the repository as one product:

- `core/`: PHP API, control plane, CLI, schedulers, and PostgreSQL access.
- `core/database/schema.sql`: complete fresh-install database schema.
- `dash/`: Vue dashboard for operators and users.
- `edge/openresty/`: OpenResty/Lua proxy runtime.
- `edge/agent/`: POSIX shell registration, heartbeat, config, and telemetry agent.
- `infra/dnsgeo/`: PowerDNS, DNSGeo/Lua, Recursor, PostgreSQL, MMDB, and Poweradmin.
- `docker-compose.yml` and `deploy/`: normal and split deployment topologies.
- `ci/` and `.github/workflows/`: validation, smoke, e2e, and stress automation.
- `docs/`: VitePress operational and API documentation.

Behavior changes must update matching code, tests, docs, examples, Compose, and
CI checks. Keep public behavior stable only when the task requires it. Runtime
agent scripts use POSIX `sh`; CI scripts use Bash.

## DNS And PowerDNS

- Reconcile desired state idempotently with bounded retries and a sync guard.
- Write and verify records through the real/local PowerDNS API.
- Publish proxied apex records as PowerDNS `ALIAS` to a stable site target.
- Publish proxied subdomains as CNAMEs to a stable CDN hostname.
- Update shared CDN/DNSGeo records when edge pools change; do not rewrite every
  customer zone for one edge IP or health change.
- Keep sync status, last success, and errors visible through health APIs and UI.
- Keep `expand-alias=yes`, a separate resolver, and documented DNSSEC behavior.
- Use DNSGeo as the project GeoDNS implementation.
- The root `docker-compose.yml` is the normal topology. Do not require Compose
  profiles or CI-only override files.

Tests for DNS changes must cover real zone writes, ALIAS/CNAME/Lua records,
failure visibility, health-driven answer changes, ALIAS answer equivalence, and
shared-record updates.

## Change Discipline

- Prefer deterministic desired-state reconciliation over one-off writes.
- Do not remove current security, dashboard, edge, DNS, or observability features
  merely to simplify the code.
- Keep API clients, OpenAPI, dashboard types, and backend contracts aligned.
- Document every user-visible endpoint, command, environment variable, setup,
  and operational change in the same change.
- Do not add placeholder code or TODO-only documentation.
- Do not claim production readiness without relevant smoke, e2e, and stress
  results.

## Validation

Run the checks relevant to the change:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests

sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh

bash -n ci/agent_flow_checks.sh
bash -n ci/smoke.sh
bash -n ci/e2e.sh
bash -n ci/dns_e2e.sh
bash -n ci/stress-dns.sh
bash -n ci/powerdns_dns_checks.sh

(cd dash && npm ci && npm run typecheck && npm test && npm run build)
(cd docs && npm ci && npm run docs:build)
```

For practical runtime changes, start the root stack and run smoke/e2e. Run the
destructive DNS stress test only against an explicitly disposable environment.

## Final Handoff

Include files changed, code removed, docs regenerated, tests run, tests skipped
with reasons, environment variables removed or changed, breaking changes,
fresh-install/schema notes, and known risks.
