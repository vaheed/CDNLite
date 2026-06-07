# Testing And CI

[Back to docs index](index.md)

## Test Layout

| Path | Purpose |
|---|---|
| `core/tests/test_contract.py` | Basic response-shape contract example. |
| `core/tests/test_edge_auth_contract.py` | Edge auth missing-token and replay behavior. |
| `core/tests/test_hardening_contract.py` | Idempotency, config version reuse, usage aggregate rebuilds. |
| `core/tests/test_security_events_contract.py` | PostgreSQL-backed domain and global security-event, security-summary, and audit query contracts. |
| `dash/src/**/*.test.ts` | Dashboard env parsing, session restoration, URL building, HMAC signing, formatting, diagnostics, and Vue component behavior. |
| `dash/tests/e2e/*.spec.ts` | Playwright browser workflows, including login, password visibility, and session restoration after refresh. |
| `ci/smoke.sh` | Stack health, DB connectivity, live operations SQL queries, record-level origin schema checks, origin fixture health, edge config path, and dashboard container/SPA health. |
| `ci/e2e.sh` | Full API, DNS-record origin configuration, HTTPS/443 selection, HTTP/80 fallback, origin certificate `verify`/`ignore`, per-record proxy and geo origins, PowerDNS, edge proxy/auth, domain and global security/audit queries, usage, cache (including stale delivery against a closed loopback origin), and SSL workflows. |

The root Compose stack also runs `ssl-scheduler`, which invokes `cdn:ssl:renew-due` hourly. Tests can run the command directly for deterministic renewal checks.
| `ci/pdns_mock_server.py` | Minimal PowerDNS-compatible mock for CI. |

## Local Commands

```bash
docker compose config
find core -name '*.php' -print0 | xargs -0 -n1 php -l
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh
sh -n edge/agent/doctor.sh
bash -n ci/smoke.sh
bash -n ci/e2e.sh
bash -n ci/lib.sh
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd dash && npx playwright install chromium && npm run test:e2e
```

Expected `pytest` output is similar to:

```text
4 passed in 1.23s
```

Smoke and e2e require a running Compose stack:

```bash
docker compose up -d --build
./ci/smoke.sh
./ci/e2e.sh
```

Both scripts write reports under `ci/reports/` by default.

The redirect e2e assertion verifies that the edge returns the redirect response
and that the redirected path does not appear in core origin logs. Background
core logs from agent polling are ignored.

## GitHub Actions

`.github/workflows/ci.yml` uses a fast test gate, then runs the stack suites in parallel:

1. `test`: PHP lint, shell syntax checks, marker scan, dashboard `npm ci`, `npm run typecheck`, `npm test`, `npm run build`, and `pytest -q core/tests` with PostgreSQL service.
2. `smoke` and `e2e` start independently after `test`. Each job validates the Compose model first, has an explicit timeout, and waits for the required HTTP health endpoints before starting assertions. The root Compose stack declares healthchecks for core, edge, and dashboard so `docker compose up --wait` tracks application readiness instead of only container startup.
3. Playwright dashboard E2E is currently manual-only and is not part of GitHub Actions.
4. `release_gate` succeeds only after the two stack jobs pass.
5. `build_and_push`: on push, publishes core, edge, edge-agent, and dashboard multi-platform images for `linux/amd64` and `linux/arm64` to GHCR.

Compose logs are collected into `ci/reports` before artifact upload, including
when a stack assertion fails. This keeps the uploaded report complete and avoids
artifact upload racing the diagnostic step.

The publish steps use GitHub Actions build caches scoped per image. Independent
stack jobs intentionally do not depend on one another, reducing the critical
path while keeping each job isolated on its own runner and Compose project.

CI uses only the root `docker-compose.yml`. The e2e job starts the root Compose
`powerdns` profile as part of the full stack and sets `EDGE_AGENT_IDLE=1`,
`CDNLITE_CACHE_DEFAULT_TTL=1s`, `POWERDNS_ENABLED=1`, and
`POWERDNS_STRICT=1`. `ci/e2e.sh` provisions the edge token before running the
agent registration and heartbeat scripts explicitly, refreshes its admin session
after any conditional core container recreation, and runs the PowerDNS sync
assertions in the same full e2e flow.

After the full e2e assertions, the same job runs `ci/powerdns_dns_checks.sh`.
That check logs DNS resolution for the core, edge, dashboard, postgres, origin,
and PowerDNS endpoints from inside the Compose network and validates the mock
PowerDNS API accepts the configured key and rejects a bad one. `PDNS_API_KEY`
configures the mock service, while `POWERDNS_API_KEY` is used by the e2e and DNS
check clients.

Dashboard checks use the root `dashboard` Compose service. The SPA is served by
Nginx at `DASHBOARD_PORT` (default `8082`), but CI validates it from inside the
container as well so host port timing does not hide runtime failures.

Playwright login helpers wait for the authenticated OPS Dashboard before
navigating to a protected page. Tests must not click Sign in and immediately
issue a second `page.goto()`, because that can cancel the in-flight login request.
The helper waits for the login API response and allows extra time for the
single-process development server when edge-agent polling is active.
For hosts with a system Chromium but no Playwright browser download, set
`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/to/chromium`.

`ci/seed_frontend_e2e.sh` creates `Analytics Alpha` and `Analytics Beta`, ingests
distinct HIT/MISS/BYPASS/UNKNOWN rows through `cdn:usage:ingest`, and rebuilds
minute/hour/day aggregates. Browser tests assert exact non-zero global totals,
domain isolation, cache ratios, and bucket changes; zero-only analytics must fail.
The seed waits for PostgreSQL and runs `cdn:migrate` first so schema initialization
cannot race the direct fixture insert after `docker compose up -d`. Migration and
ingest output remains visible in CI so PHP/database failures are diagnosable. It
also resets usage rollups, aggregates, and idempotency keys so exact global totals
do not depend on tests or traffic that ran earlier in the same stack.

The core image also runs `cdn:migrate` from its POSIX entrypoint before starting
the PHP server, keeping persisted databases aligned after image upgrades.

Environment examples are split by target: `.env.dev.example` for local Compose,
`.env.production.example` for production operators, and `dash/.env.example` for
dashboard-only Vite workflows. CI still injects job-specific values directly in
the workflow and uses the root `docker-compose.yml`.

When `CDNLITE_API_TOKEN` is set in the job environment, `ci/smoke.sh` and
`ci/e2e.sh` validate unauthenticated control-plane API requests return `401`
and continue all positive-path API calls with `Authorization: Bearer <token>`.

The workflow defines `POWERDNS_HOST_PORT` and `PDNS_PORT` globally because
Compose interpolates profile service ports even when the `powerdns` profile is
not started.
