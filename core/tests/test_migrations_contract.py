from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_migrations_are_the_supported_upgrade_path():
    assert (ROOT / "core/database/schema.sql").is_file()
    baseline = ROOT / "core/database/migrations/000001_baseline_schema.sql"
    assert baseline.is_file()
    assert "CREATE TABLE IF NOT EXISTS domains" in baseline.read_text()
    assert (ROOT / "core/database/migrations/000005_origin_pool_defaults.sql").is_file()


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
    artisan = (ROOT / "core/artisan").read_text()
    assert "cdn:db:migrate" in artisan
    assert "cdn:db:status" in artisan
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


def test_ci_runs_migrations_before_core_tests():
    workflow = (ROOT / ".github/workflows/ci.yml").read_text()
    schema_step = "php core/artisan cdn:db:migrate"

    assert schema_step in workflow
    assert workflow.index(schema_step) < workflow.index("pytest -q core/tests")
