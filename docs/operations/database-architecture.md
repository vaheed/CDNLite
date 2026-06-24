---
title: Database Architecture
description: CDNLite database workload classes, reporting read models, telemetry ingestion diagnostics, async analytics aggregation, and retention.
---

# Database Architecture

CDNLite uses PostgreSQL as the authoritative data store. Phase 1 separates database work by budget before adding any separate analytics database. Phase 2 keeps analytics in PostgreSQL and makes aggregate refresh asynchronous and idempotent.

## Workload Classes

`database_workload_budgets` records the initial operating contract:

| Workload | Purpose |
| --- | --- |
| `control` | Transactional API and configuration writes. |
| `telemetry_ingest` | Bounded edge metrics and security-event ingestion. |
| `reporting` | Read-only reporting queries with mandatory range and row limits. |
| `jobs` | Rollups, reconciliation, DNS, TLS, and background workers. |
| `maintenance` | Retention, backfill, partition maintenance, benchmark, and repair work. |

`App\Support\DatabaseWorkload` applies statement and lock timeout budgets for reporting queries. Reporting APIs also enforce maximum time range and result-row limits from the same budget table.

## Telemetry Ingestion

Existing usage ingestion keeps idempotency through `usage_ingest_keys`. Phase 1 adds diagnostics for the next ingestion iteration:

- `telemetry_ingest_batches` records source edge, idempotency key, event counts, payload size, status, and ingest time.
- `telemetry_rejected_events` records bounded rejected-event details with strict retention expectations.
- Batch size is capped at 1,000 events and payload size at 1 MiB in the schema contract.

## Reporting And Read Models

Historical request data remains in `usage_rollups`, with additional BRIN timestamp coverage and a domain/bucket index for bounded reports.

`reporting_current_platform_summary` is the first current-state reporting read model. It is a materialized view intended for dashboard cards and status pages that need recent counters without scanning all history.

`reporting_rollup_watermarks` tracks per-stream, per-bucket, per-domain aggregation progress. `reporting_reconciliation_results` stores raw-vs-aggregate checks, duplicate counts, missing counts, and status.

## Async Analytics Aggregation

`analytics_rollup_jobs` records queued, running, succeeded, failed, and cancelled aggregate refresh work. Dashboard and API recalculation requests return `202 Accepted` with a job ID instead of blocking the HTTP request while aggregates are rebuilt.

The worker path upserts into `usage_aggregates` using the unique aggregate identity `(bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)`. This keeps duplicate processing from corrupting totals and removes the old delete-and-rebuild behavior.

`analytics_query_cache` records normalized analytics response payloads, ETags, fresh windows, and stale windows for short-lived API result caching. Cache keys must include domain scope so future tenant boundaries cannot leak analytics across domains.

Usage summary responses are bounded to the last 24 hours by default, cap returned time-series points at 500, and include freshness, watermark, partial-data, query identifier, effective range, and cache-status metadata.

## Retention And Recovery

Retention remains driven through:

```bash
docker compose exec core php artisan cdn:usage:prune --dry-run
```

The Phase 1 stress scenario verifies the dry-run path and materialized read-model refresh. Deleting old telemetry should remain bounded and observable; large archive or legal-hold workflows are future work.

## Phase Gate

Run the PR-sized gate:

```bash
./ci/phase.sh 01 --profile pr
```

Run the Phase 2 contract gate:

```bash
pytest -q core/tests/test_phase2_analytics_async_contract.py
```

Run the full disposable-stack gate:

```bash
./ci/phase.sh 01 --profile full --clean
```

The full gate runs smoke, e2e, the Phase 1 stress scenario, then smoke and e2e again before building docs and writing evidence under `ci/reports/`.
