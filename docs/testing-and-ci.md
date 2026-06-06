# Testing And CI

[Back to docs index](index.md)

## Test Layout

| Path | Purpose |
|---|---|
| `core/tests/test_contract.py` | Basic response-shape contract example. |
| `core/tests/test_edge_auth_contract.py` | Edge auth missing-token and replay behavior. |
| `core/tests/test_hardening_contract.py` | Idempotency, config version reuse, usage aggregate rebuilds. |
| `dash/src/**/*.test.ts` | Dashboard env parsing, session restoration, URL building, HMAC signing, formatting, diagnostics, and Vue component behavior. |
| `dash/tests/e2e/*.spec.ts` | Playwright browser workflows, including login, password visibility, and session restoration after refresh. |
| `ci/smoke.sh` | Stack health, DB connectivity, schema (including stage-9 security tables/columns), edge container, config path, and dashboard container/SPA health. |
| `ci/e2e.sh` | Full API, admin bootstrap/user creation/login, Vue dashboard SPA runtime/fallback/cache checks, backend dashboard removal check, DNS, PowerDNS, edge proxy, edge auth, usage, cleanup workflow, API auth coverage, stage-9 security pack checks (WAF v2/rate-limit v2/origin shield/security events), and stage-10 SSL manual-import + TLS proxy checks. Rate-limit bursts exceed twice the configured per-minute limit so assertions remain stable when requests cross a minute boundary. |
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
2. `smoke`, `e2e`, `e2e-powerdns`, and `frontend-e2e` start independently after `test`.
3. `frontend-e2e` installs Chromium and runs the Playwright dashboard suite against Compose.
4. `release_gate` succeeds only after all four stack jobs pass.
5. `build_and_push`: on push, publishes core, edge, edge-agent, and dashboard multi-platform images for `linux/amd64` and `linux/arm64` to GHCR.

The publish steps use GitHub Actions build caches scoped per image. Independent
stack jobs intentionally do not depend on one another, reducing the critical
path while keeping each job isolated on its own runner and Compose project.

CI uses only the root `docker-compose.yml`. The plain e2e job sets
`EDGE_AGENT_IDLE=1`, `CDNLITE_CACHE_DEFAULT_TTL=1s`, and PowerDNS off. The
PowerDNS e2e job uses the same Compose file with `--profile powerdns`,
`POWERDNS_ENABLED=1`, and `POWERDNS_STRICT=1`. These CI variables are inputs to
`ci/e2e.sh`, which writes the effective PowerDNS values through the authenticated
Platform Settings API; the core container does not consume them as operational
environment variables. `PDNS_API_KEY` configures the mock service, while
`POWERDNS_API_KEY` is the value the E2E script saves as the platform credential.
In both jobs, `ci/e2e.sh`
provisions the edge token before running the agent registration and heartbeat
scripts explicitly.

Dashboard checks use the root `dashboard` Compose service. The SPA is served by
Nginx at `DASHBOARD_PORT` (default `8082`), but CI validates it from inside the
container as well so host port timing does not hide runtime failures.

Playwright login helpers wait for the authenticated OPS Dashboard before
navigating to a protected page. Tests must not click Sign in and immediately
issue a second `page.goto()`, because that can cancel the in-flight login request.

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
