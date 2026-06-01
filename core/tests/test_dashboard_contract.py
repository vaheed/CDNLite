from pathlib import Path


def test_dashboard_routes_contract():
    repo_root = Path(__file__).resolve().parents[2]
    public_index = (repo_root / "core" / "public_index.php").read_text()
    dashboard_controller = (repo_root / "core" / "app" / "Modules" / "Dashboard" / "Http" / "Controllers" / "DashboardController.php").read_text()

    assert "/dashboard/sites" in public_index
    assert "/dashboard/sites/{siteId}" in public_index
    assert "/dashboard/console" in public_index
    assert "text/html; charset=utf-8" in public_index
    assert "CDNLite Control Deck" in dashboard_controller
    assert "API Action Console" in dashboard_controller
    assert "curl_init" in dashboard_controller
    assert "Only /api/v1/* paths are allowed." in dashboard_controller
