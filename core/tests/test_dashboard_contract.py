from pathlib import Path


def test_backend_dashboard_removed_and_admin_auth_contract():
    repo_root = Path(__file__).resolve().parents[2]
    public_index = (repo_root / "core" / "public_index.php").read_text()

    assert "/dashboard/domains" not in public_index
    assert "/dashboard/console" not in public_index
    assert "text/html; charset=utf-8" not in public_index
    assert "/api/v1/admin/login" in public_index
    assert "/api/v1/admin/me" in public_index
    assert "/api/v1/admin/logout" in public_index
    assert not (repo_root / "core" / "app" / "Modules" / "Dashboard" / "Http" / "Controllers" / "DashboardController.php").exists()
