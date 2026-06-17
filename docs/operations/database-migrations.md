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
