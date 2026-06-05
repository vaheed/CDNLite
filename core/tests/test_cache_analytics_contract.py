from pathlib import Path


def test_cache_analytics_route_contract():
    repo_root = Path(__file__).resolve().parents[2]
    public_index = (repo_root / "core" / "public_index.php").read_text()
    collector_controller = (repo_root / "core" / "app" / "Modules" / "Collector" / "Http" / "Controllers" / "CollectorController.php").read_text()
    collector_service = (repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    schema = (repo_root / "core" / "database" / "schema.sql").read_text()

    assert "/api/v1/domains/{domainId}/analytics/cache" in public_index
    assert "cacheAnalytics" in collector_controller
    assert "cacheAnalytics" in collector_service
    assert "cache_status" in schema
