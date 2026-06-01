# Testing And CI

[Back to docs index](index.md)

## Test Layout

| Path | Purpose |
|---|---|
| `core/tests/test_contract.py` | Basic response-shape contract example. |
| `core/tests/test_edge_auth_contract.py` | Edge auth missing-token and replay behavior. |
| `core/tests/test_hardening_contract.py` | Idempotency, config version reuse, usage aggregate rebuilds. |
| `ci/smoke.sh` | Stack health, DB connectivity, schema (including stage-9 security tables/columns), edge container, config path. |
| `ci/e2e.sh` | Full API, dashboard routes/console execution, DNS, PowerDNS, edge proxy, edge auth, usage, cleanup workflow, API auth coverage, stage-9 security pack checks (WAF v2/rate-limit v2/origin shield/security events), and stage-10 SSL manual-import + TLS proxy checks. |
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

1. `test`: PHP lint, shell syntax checks, marker scan, `pytest -q core/tests` with PostgreSQL service.
2. `smoke`: starts Compose and runs `ci/smoke.sh`.
3. `e2e`: starts Compose and runs `ci/e2e.sh`.
4. `e2e-powerdns`: starts Compose with `--profile powerdns`, enables strict PowerDNS sync, and runs e2e against the mock server.
5. `build_and_push`: on push, publishes core, edge, and edge-agent images to GHCR.

CI uses only the root `docker-compose.yml`. The plain e2e job sets
`EDGE_AGENT_IDLE=1`, `CDNLITE_CACHE_DEFAULT_TTL=1s`, and PowerDNS off. The
PowerDNS e2e job uses the same Compose file with `--profile powerdns`,
`POWERDNS_ENABLED=1`, and `POWERDNS_STRICT=1`. In both jobs, `ci/e2e.sh`
provisions the edge token before running the agent registration and heartbeat
scripts explicitly.

When `CDNLITE_API_TOKEN` is set in the job environment, `ci/smoke.sh` and
`ci/e2e.sh` validate unauthenticated control-plane API requests return `401`
and continue all positive-path API calls with `Authorization: Bearer <token>`.

The workflow defines `POWERDNS_HOST_PORT` and `PDNS_PORT` globally because
Compose interpolates profile service ports even when the `powerdns` profile is
not started.
