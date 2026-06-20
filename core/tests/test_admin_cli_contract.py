from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_admin_cli_commands_exist_for_user_maintenance():
    artisan = read("core/artisan")
    service = read("core/app/Modules/Admin/Services/AdminAuthService.php")
    admin_list = read("core/app/Console/Commands/CdnAdminListCommand.php")
    admin_password = read("core/app/Console/Commands/CdnAdminPasswordCommand.php")
    admin_delete = read("core/app/Console/Commands/CdnAdminDeleteCommand.php")
    docs = read("docs/usage/admin.md")
    setup = read("docs/setup.md")

    for command in (
        "cdn:admin:create",
        "cdn:admin:list",
        "cdn:admin:password",
        "cdn:admin:delete",
    ):
        assert command in artisan

    assert "public function listUsers()" in service
    assert "public function changePassword" in service
    assert "public function deleteUser" in service
    assert "active_sessions" in service
    assert "latest_session_expires_at" in service
    assert "active_now" in service
    assert "expiry_now" in service
    assert "cannot_delete_last_active_admin" in service
    assert "revokeSessionsForUser" in service
    assert "sessions_revoked" in admin_password
    assert "sessions_revoked" in admin_delete
    assert "CommandIO::printJson(['data' => (new AdminAuthService())->listUsers()])" in admin_list

    assert "cdn:admin:list" in docs
    assert "cdn:admin:password" in docs
    assert "cdn:admin:delete" in docs
    assert "--force" in docs
    assert "cdn:admin:password" in setup
