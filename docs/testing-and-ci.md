# Testing And CI

[Back to docs index](index.md)

## Test Layout

| Path | Purpose |
|---|---|
| `core/tests/test_contract.py` | Basic response-shape contract example. |
| `core/tests/test_edge_auth_contract.py` | Edge auth missing-token and replay behavior. |
| `core/tests/test_hardening_contract.py` | Idempotency, config version reuse, usage aggregate rebuilds. |
| `ci/smoke.sh` | Stack health, DB connectivity, schema, edge container, config path. |
| `ci/e2e.sh` | Full API, DNS, PowerDNS, edge proxy, edge auth, usage, cleanup workflow. |
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

1. `test`: PHP lint, shell syntax checks, WIP marker scan, `pytest -q core/tests` with PostgreSQL service.
2. `smoke`: starts Compose and runs `ci/smoke.sh`.
3. `e2e`: starts Compose and runs `ci/e2e.sh`.
4. `e2e-powerdns`: starts Compose with `ci/docker-compose.ci.yml`, enables strict PowerDNS sync, and runs e2e against the mock server.
5. `build_and_push`: on push, publishes core, edge, and edge-agent images to GHCR.
