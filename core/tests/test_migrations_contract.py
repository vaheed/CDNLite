from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_fresh_install_has_one_canonical_schema_and_no_numbered_migrations():
    assert (ROOT / "core/database/schema.sql").is_file()
    assert not list((ROOT / "core/database/migrations").glob("*.sql"))


def test_schema_application_is_explicit_and_serialized_at_container_start():
    database = (ROOT / "core/app/Support/Database.php").read_text()
    entrypoint = (ROOT / "core/docker-entrypoint.sh").read_text()
    assert "pg_advisory_lock" in database
    assert "pg_advisory_unlock" in database
    assert "finally" in database
    assert "installFreshSchema" in entrypoint
    assert "self::installFreshSchema" not in database


def test_no_schema_upgrade_command_is_shipped():
    artisan = (ROOT / "core/artisan").read_text()
    assert "cdn:migrate" not in artisan
    assert not (ROOT / "core/app/Console/Commands/CdnMigrateCommand.php").exists()
