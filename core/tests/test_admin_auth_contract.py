from pathlib import Path


def test_admin_auth_schema_and_secret_handling_contract():
    repo_root = Path(__file__).resolve().parents[2]
    schema = (repo_root / "core" / "database" / "schema.sql").read_text()
    service = (repo_root / "core" / "app" / "Modules" / "Admin" / "Services" / "AdminAuthService.php").read_text()
    command = (repo_root / "core" / "app" / "Console" / "Commands" / "CdnAdminCreateCommand.php").read_text()
    public_index = (repo_root / "core" / "public_index.php").read_text()
    env_example = (repo_root / ".env.example").read_text()
    env_dev_example = (repo_root / ".env.dev.example").read_text()
    env_production_example = (repo_root / ".env.production.example").read_text()
    dash_env_example = (repo_root / "dash" / ".env.example").read_text()
    compose = (repo_root / "docker-compose.yml").read_text()

    assert "CREATE TABLE IF NOT EXISTS admin_users" in schema
    assert "CREATE TABLE IF NOT EXISTS admin_sessions" in schema
    assert "idx_admin_sessions_user_id" in schema
    assert "idx_admin_sessions_active_lookup" in schema
    assert "idx_admin_sessions_expiry" in schema
    assert "password_hash" in schema
    assert "token_hash" in schema
    assert "password_hash($password" in service
    assert "password_verify($password" in service
    assert "hash('sha256', $token)" in service
    assert "$this->deleteExpiredSessions();" in service.split("public function login", 1)[1].split("public function userForToken", 1)[0]
    assert "deleteExpiredSessions" not in service.split("public function userForToken", 1)[1].split("public function revokeToken", 1)[0]
    assert "bootstrapUser(" in service
    assert "CDNLITE_BOOTSTRAP_ADMIN_USER" in public_index
    assert "CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=admin" in env_example
    assert "CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=admin" in env_dev_example
    assert "CDNLITE_BOOTSTRAP_ADMIN_USER=0" in env_production_example
    assert "CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=" in env_production_example
    assert "VITE_CDNLITE_CORE_URL=http://localhost:8080" in dash_env_example
    assert "CDNLITE_BOOTSTRAP_ADMIN_PASSWORD" not in dash_env_example
    assert "CDNLITE_BOOTSTRAP_ADMIN_PASSWORD" in compose
    assert "CDNLITE_CORS_ALLOWED_ORIGINS" in public_index
    assert "CDNLITE_CORS_ALLOWED_ORIGINS" in env_example
    assert "CDNLITE_CORS_ALLOWED_ORIGINS" in compose
    assert "cdn:admin:create" not in command
    assert "--username" in command
    assert "--password" in command


def test_e2e_refreshes_admin_session_after_core_recreation():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    assert "login_admin()" in e2e
    recreate = e2e.index("docker compose up -d --force-recreate core")
    relogin = e2e.index("login_admin", recreate)
    domain_create = e2e.index('# Domain lifecycle')
    assert recreate < relogin < domain_create
