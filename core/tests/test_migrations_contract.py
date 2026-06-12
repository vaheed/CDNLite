from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_fresh_install_has_one_canonical_schema_and_no_numbered_migrations():
    assert (ROOT / "core/database/schema.sql").is_file()
    assert not list((ROOT / "core/database/migrations").glob("*.sql"))


def test_schema_application_is_serialized_across_services():
    database = (ROOT / "core/app/Support/Database.php").read_text()
    assert "pg_advisory_lock" in database
    assert "pg_advisory_unlock" in database
    assert "finally" in database


def test_migrate_command_documents_fresh_install_only_contract():
    command = (ROOT / "core/app/Console/Commands/CdnMigrateCommand.php").read_text()
    assert "fresh_install_only" in command
    assert "historical migrations are unsupported" in command
