from pathlib import Path


def test_backend_dashboard_removed_and_admin_auth_contract():
    repo_root = Path(__file__).resolve().parents[2]
    routes = (repo_root / "core" / "routes" / "api.php").read_text()
    laravel_public_index = (repo_root / "core" / "public" / "index.php").read_text()

    assert "text/html; charset=utf-8" not in laravel_public_index
    assert "/v1/admin/login" in routes
    assert "/admin/me" in routes
    assert "/admin/logout" in routes
    assert not (repo_root / "core" / "app" / "Modules" / "Dashboard" / "Http" / "Controllers" / "DashboardController.php").exists()
