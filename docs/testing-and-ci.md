# Testing And CI

[Back to docs index](index.md)

## Test Layout

| Path | Purpose |
|---|---|
| `core/tests/test_contract.py` | Basic response-shape contract example. |
| `core/tests/test_edge_auth_contract.py` | Edge auth missing-token and replay behavior. |
| `core/tests/test_hardening_contract.py` | Idempotency, config version reuse, usage aggregate rebuilds. |
| `dash/src/**/*.test.ts` | Dashboard env parsing, URL building, HMAC signing, formatting, diagnostics, field tooltip UX, and key Vue form behavior. |
| `ci/smoke.sh` | Stack health, DB connectivity, schema (including stage-9 security tables/columns), edge container, config path, and dashboard container/SPA health. |
| `ci/e2e.sh` | Full API, admin bootstrap/user creation/login, Vue dashboard SPA runtime/fallback/cache checks, backend dashboard removal check, DNS, PowerDNS, edge proxy, edge auth, usage, cleanup workflow, API auth coverage, stage-9 security pack checks (WAF v2/rate-limit v2/origin shield/security events), and stage-10 SSL manual-import + TLS proxy checks. |
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

## GitHub Actions

`.github/workflows/ci.yml` runs jobs in this order:

1. `test`: PHP lint, shell syntax checks, marker scan, dashboard `npm ci`, `npm run typecheck`, `npm test`, `npm run build`, and `pytest -q core/tests` with PostgreSQL service.
2. `smoke`: starts Compose and runs `ci/smoke.sh`, including dashboard container health and SPA index checks.
3. `e2e`: starts Compose and runs `ci/e2e.sh`, including dashboard SPA fallback and static asset cache checks.
4. `e2e-powerdns`: starts Compose with `--profile powerdns`, enables strict PowerDNS sync, and runs e2e against the mock server.
5. `release_check`: starts Compose and runs release checks.
6. `build_and_push`: on push, publishes core, edge, edge-agent, and dashboard images to GHCR.

CI uses only the root `docker-compose.yml`. The plain e2e job sets
`EDGE_AGENT_IDLE=1`, `CDNLITE_CACHE_DEFAULT_TTL=1s`, and PowerDNS off. The
PowerDNS e2e job uses the same Compose file with `--profile powerdns`,
`POWERDNS_ENABLED=1`, and `POWERDNS_STRICT=1`. In both jobs, `ci/e2e.sh`
provisions the edge token before running the agent registration and heartbeat
scripts explicitly.

Dashboard checks use the root `dashboard` Compose service. The SPA is served by
Nginx at `DASHBOARD_PORT` (default `8082`), but CI validates it from inside the
container as well so host port timing does not hide runtime failures.

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
