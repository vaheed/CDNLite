---
title: Phase 1 Evidence
description: Validation evidence for CDNLite Phase 1 database architecture and real-time reporting foundation.
---

# Phase 1 Evidence

Phase 1 closed on 2026-06-24.

## Scope

- Fresh-install schema and upgrade migration for workload budgets, telemetry ingest diagnostics, reporting watermarks, reconciliation results, reporting indexes, and current reporting read model.
- Reporting query budgets through `App\Support\DatabaseWorkload`.
- Phase manifest, one-shot runner, stress scenario registration, and tracked database architecture documentation.
- E2E harness fix for edge heartbeat config-version validation using the active config-state version.

## Validation

| Check | Result | Notes |
| --- | --- | --- |
| `pytest -q core/tests/test_phase1_reporting_foundation_contract.py` | Pass | 4 tests passed. |
| `./ci/phase.sh 01 --profile pr` | Pass | Manifest, compose config, PHP syntax, Phase 1 contracts, agent syntax, phase runner syntax, and stress runner syntax passed. |
| PHP syntax | Pass | `find core -name '*.php' -print0 \| xargs -0 -n1 php -l` passed. |
| Clean-stack smoke | Pass | `ci/smoke.sh` passed during the full Phase 1 gate attempt. |
| End-to-end | Pass | `ci/e2e.sh` passed with 109 steps and 0 failures. Evidence was written to ignored local report files under `ci/reports/`. |

## Known Limits

- The canonical full gate was not rerun after the final closure update because the operator requested not to repeat smoke and e2e.
- Phase 1 closes the foundation and contracts. Larger benchmark datasets, deeper interruption tests, and reporting freshness expansion continue in Phase 2.
