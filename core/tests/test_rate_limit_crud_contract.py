from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_rate_limit_crud_routes_and_snapshot_collection_contract():
    routes = (REPO_ROOT / "core" / "routes" / "api.php").read_text()
    service = (REPO_ROOT / "core" / "app" / "Services" / "ControlPlane" / "TrafficRulesService.php").read_text()
    config = (REPO_ROOT / "core" / "app" / "Modules" / "Proxy" / "Services" / "ConfigService.php").read_text()

    assert "/domains/{domainId}/rate-limits" in routes
    assert "/domains/{domainId}/rate-limits/{ruleId}" in routes
    assert "createRateLimit" in service
    assert "updateRateLimit" in service
    assert "deleteRateLimit" in service
    assert "invalidateConfigSnapshot" in service
    assert "ConfigService::markDirty('traffic_rules.changed')" in service
    assert "listRateLimits($domainId)" in config


def test_dashboard_rate_limit_crud_contract():
    view = (REPO_ROOT / "dash" / "src" / "views" / "domain-tabs" / "DomainRateLimitsTab.vue").read_text()
    api = (REPO_ROOT / "dash" / "src" / "lib" / "api" / "rateLimit.ts").read_text()

    assert "rateLimitApi.list" in view
    assert "rateLimitApi.create" in view
    assert "rateLimitApi.update" in view
    assert "rateLimitApi.delete" in view
    assert "Rate Limits" in view
    assert "/rate-limits/${ruleId}" in api
