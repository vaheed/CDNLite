---
title: Database Migrations
description: Database migration guidance for CDNLite PostgreSQL schema changes, fresh installs, in-place upgrades, validation, and rollback planning.
---

# Database Migrations

CDNLite uses ordered PostgreSQL migrations in `core/database/migrations/` for
in-place upgrades. `core/database/schema.sql` is kept as a development snapshot
and reference for fresh local rebuilds.

## Commands

Preview pending work:

```bash
docker compose exec core php artisan cdn:db:migrate --dry-run
```

Apply migrations:

```bash
docker compose exec core php artisan cdn:db:migrate
```

Check migration and compatibility status:

```bash
docker compose exec core php artisan cdn:db:status
```

## Back Up Before Upgrading

Take a PostgreSQL backup before enabling automatic migrations or applying a new
release:

```bash
docker compose exec -T postgres pg_dump -U cdnlite -d cdnlite > cdnlite-backup.sql
```

Restore only into a maintenance window after stopping services that write to the
database:

```bash
docker compose exec -T postgres psql -U cdnlite -d cdnlite < cdnlite-backup.sql
```

## Automatic Migrations

Local/dev containers run migrations at startup by default:

```text
CDNLITE_AUTO_MIGRATE=true
```

For production-controlled rollouts, set:

```text
CDNLITE_AUTO_MIGRATE=false
```

Then run `cdn:db:migrate --dry-run`, take a backup, and run `cdn:db:migrate`
manually.

## Legacy Schema Adoption

Deployments created from the old `schema.sql` model can be adopted without
wiping data. When the migrator sees the baseline migration is not recorded but
the expected CDNLite tables already exist, it validates required tables and marks
`000001_baseline_schema.sql` as applied. If required tables are missing, the
command fails with `legacy_schema_incompatible` and does not mark the baseline.

If the baseline row already exists but its checksum no longer matches the
current bootstrap snapshot, the migrator refreshes the stored checksum after it
verifies the legacy schema is still present. This keeps existing installs booting
cleanly when the baseline file changes for fresh installs, while later
migrations continue to fail on checksum drift.

If a migration row exists with `success = false`, the migrator treats it as a
retryable failed attempt. On the next run it clears that row and executes the
migration again, which is safe because the previous attempt was rolled back
before the failure row was recorded.

## Safety Rules

- Migrations run under a PostgreSQL advisory lock.
- Applied migration checksums are validated on every run.
- Re-running migrations is safe; already-applied migrations are skipped.
- Destructive schema changes must not be added without an explicit manual flag,
  backup note, and rollback or restore instructions.

## Phase 1 Reporting Foundation

Migration `000021_phase1_reporting_foundation.sql` is additive. It creates workload budget metadata, telemetry batch diagnostics, rejected-event diagnostics, reporting rollup watermarks, reconciliation results, reporting indexes, and the `reporting_current_platform_summary` materialized view.

Rollback is a forward-fix or restore-from-backup operation for existing installs because later reporting code may depend on these objects. Fresh installs receive the same objects from `core/database/schema.sql`.

## Operations Report Range Indexes

Migration `000025_operations_report_range_indexes.sql` is additive. It adds
range-first indexes for the operations dashboard report over `audit_log`,
`ssl_jobs`, and `dns_sync_events` so upgraded production databases with larger
history can satisfy the default 24-hour operations view inside the reporting
statement timeout. Fresh installs receive the same indexes from
`core/database/schema.sql`.

## Shared-Hosting Origin Defaults

Migration `000023_origin_shared_hosting_defaults.sql` is additive and intended
for in-place upgrades from 1.6.0-era deployments. It adds
`domain_origins.health_check_enabled` with default `false`, changes new origin
defaults to `preserve_host=true` and `tls_verify='ignore'`, and upgrades old
DNS-linked origin rows only when their host-header/SNI fields still match the
old generated defaults.

The migration does not rewrite explicit custom origin settings. DNS-linked rows
that still have `host_header` or `sni` equal to the backend IP/host are moved to
the requested DNS hostname so cPanel/shared-hosting origins receive the real site
name in Host and SNI while the edge still connects to the configured origin IP.

For low-risk upgrades under load:

```bash
docker compose exec core php artisan cdn:db:migrate --dry-run
docker compose exec core php artisan cdn:db:migrate
docker compose exec core php artisan cdn:edge:sync-config
docker compose exec edge-agent /agent/pull_config.sh
```

After the rollout, verify a proxied domain whose origin is an IP returns the
site correctly and that the origin row shows `health_check_enabled=false` unless
you intentionally enabled active monitoring.
