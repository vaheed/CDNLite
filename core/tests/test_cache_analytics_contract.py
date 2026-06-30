from pathlib import Path


def test_cache_analytics_route_contract():
    repo_root = Path(__file__).resolve().parents[2]
    routes = (repo_root / "core" / "routes" / "api.php").read_text()
    collector_controller = (repo_root / "core" / "app" / "Modules" / "Collector" / "Http" / "Controllers" / "CollectorController.php").read_text()
    collector_service = (repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    schema = (repo_root / "core" / "database" / "schema.sql").read_text()

    assert "/analytics/cache" in routes
    assert "/domains/{domainId}/analytics/cache" in routes
    assert "cacheAnalytics" in collector_controller
    assert "cacheAnalytics" in collector_service
    assert "cache_status TEXT NOT NULL DEFAULT 'UNKNOWN'" in schema
