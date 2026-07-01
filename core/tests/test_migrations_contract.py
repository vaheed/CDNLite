from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_migrations_are_the_supported_upgrade_path():
    assert (ROOT / "core/database/schema.sql").is_file()
    baseline = ROOT / "core/database/migrations/000001_baseline_schema.sql"
    assert baseline.is_file()
    assert "CREATE TABLE IF NOT EXISTS domains" in baseline.read_text()
    assert (ROOT / "core/database/migrations/000005_origin_pool_defaults.sql").is_file()
    assert (ROOT / "core/database/migrations/000006_protection_contract.sql").is_file()
    assert (ROOT / "core/database/migrations/000008_rate_limit_header_keys.sql").is_file()
    assert (ROOT / "core/database/migrations/000011_bot_protection.sql").is_file()
    assert (ROOT / "core/database/migrations/000012_verified_bot_sources.sql").is_file()
    assert (ROOT / "core/database/migrations/000018_reconcile_runtime_schema.sql").is_file()


def test_runtime_schema_reconciliation_migration_restores_activity_and_dns_state():
    migration = (ROOT / "core/database/migrations/000018_reconcile_runtime_schema.sql").read_text()

    assert "CREATE TABLE IF NOT EXISTS powerdns_zone_serials" in migration
    assert "ALTER TABLE usage_rollups ADD COLUMN IF NOT EXISTS client_ip TEXT NULL" in migration
    assert "CREATE INDEX IF NOT EXISTS idx_usage_rollups_client_ip_ts" in migration


def test_schema_application_is_explicit_and_serialized_at_container_start():
    database = (ROOT / "core/app/Support/Database.php").read_text()
    entrypoint = (ROOT / "core/docker-entrypoint.sh").read_text()
    dockerfile = (ROOT / "core/Dockerfile").read_text()
    migrator = (ROOT / "core/app/Support/DatabaseMigrator.php").read_text()
    assert "pg_advisory_lock" in migrator
    assert "pg_advisory_unlock" in migrator
    assert "finally" in migrator
    assert "cdn:db:migrate" in entrypoint
    assert "CDNLITE_AUTO_MIGRATE" in entrypoint
    assert "WORKDIR /app" in dockerfile
    assert "self::installFreshSchema" not in database


def test_schema_upgrade_commands_are_shipped():
    console = (ROOT / "core/routes/console.php").read_text()
    assert "cdn:db:migrate" in console
    assert "cdn:db:status" in console
    assert (ROOT / "core/app/Console/Commands/CdnDbMigrateCommand.php").exists()
    assert (ROOT / "core/app/Console/Commands/CdnDbStatusCommand.php").exists()


def test_migrator_supports_legacy_baseline_adoption_and_checksum_validation():
    migrator = (ROOT / "core/app/Support/DatabaseMigrator.php").read_text()
    assert "schema_migrations" in migrator
    assert "checksum" in migrator
    assert "migration_checksum_mismatch" in migrator
    assert "adopted_legacy_baseline" in migrator
    assert "reconciled_baseline_checksum" in migrator
    assert "would_retry_failed_migration" in migrator
    assert "legacy_schema_incompatible" in migrator


def test_phase7_origin_resilience_constraints_are_idempotent_for_legacy_adoption():
    migrator = (ROOT / "core/app/Support/DatabaseMigrator.php").read_text()
    migration = (ROOT / "core/database/migrations/000031_phase7_origin_resilience.sql").read_text()

    assert "sqlForExecution" in migrator
    assert "($migration['version'] ?? '') !== '000031'" in migrator
    assert "domain_origins_load_balancing_algorithm_check" in migrator
    assert "IF NOT EXISTS (SELECT 1 FROM pg_constraint" in migrator
    assert "Preserve the historical migration checksum" in migrator
    assert "ADD CONSTRAINT domain_origins_load_balancing_algorithm_check" in migration


def test_ci_runs_migrations_before_core_tests():
    workflow = (ROOT / ".github/workflows/ci.yml").read_text()
    schema_step = "php core/artisan cdn:db:migrate"

    assert schema_step in workflow
    assert workflow.index(schema_step) < workflow.index("pytest -q core/tests")


def test_retention_scheduler_is_opt_in_and_uses_bounded_prune_command():
    root_compose = (ROOT / "docker-compose.yml").read_text()
    starter_compose = (ROOT / "deploy/starter/docker-compose.yml").read_text()
    core_compose = (ROOT / "deploy/core/docker-compose.yml").read_text()
    generator = (ROOT / "deploy/generate-deployment.sh").read_text()
    scheduler = (ROOT / "core/app/Console/Commands/ScheduleRunCommand.php").read_text()

    assert "cdn:scheduler:run" in root_compose
    assert "retention_prune" in scheduler
    assert "'cdn:usage:prune --all'" in scheduler
    assert "cdn:config-snapshots:prune --keep=" in scheduler
    for source in (root_compose, starter_compose, core_compose, generator):
        assert "CDNLITE_RETENTION_PRUNE_ENABLED" in source
        assert "CDNLITE_RETENTION_INTERVAL_SECONDS" in source
        assert "CDNLITE_RETENTION_BATCH_SIZE" in source

    assert "CDNLITE_RETENTION_PRUNE_ENABLED:-false" in root_compose
    assert "CDNLITE_RETENTION_PRUNE_ENABLED=true" in generator
