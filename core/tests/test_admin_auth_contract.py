from pathlib import Path


def test_admin_auth_schema_and_secret_handling_contract():
    repo_root = Path(__file__).resolve().parents[2]
    schema = (repo_root / "core" / "database" / "schema.sql").read_text()
    service = (repo_root / "core" / "app" / "Modules" / "Admin" / "Services" / "AdminAuthService.php").read_text()
    command = (repo_root / "core" / "app" / "Console" / "Commands" / "CdnAdminCreateCommand.php").read_text()

    assert "CREATE TABLE IF NOT EXISTS admin_users" in schema
    assert "CREATE TABLE IF NOT EXISTS admin_sessions" in schema
    assert "password_hash" in schema
    assert "token_hash" in schema
    assert "password_hash($password" in service
    assert "password_verify($password" in service
    assert "hash('sha256', $token)" in service
    assert "cdn:admin:create" not in command
    assert "--username" in command
    assert "--password" in command
