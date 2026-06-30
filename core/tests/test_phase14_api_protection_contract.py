from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase14_api_protection_discovery_route_and_dashboard_client_are_wired():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/routes/api.php")
    openapi = read("docs/public/api/openapi.yaml")
    dashboard_api = read("dash/src/lib/api/protection.ts")
    types = read("dash/src/types.ts")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")

    assert "discoverApiPaths" in service
    assert "recommended_header_key" in service
    assert "discoverApiPaths" in controller
    assert "/domains/{domainId}/protection/api-paths" in routes
    assert "/domains/{domainId}/protection/api-paths:" in openapi
    assert "discoverApiPaths" in dashboard_api
    assert "ApiProtectionDiscovery" in types
    assert "/protection/api-paths" in smoke
    assert "api-protection-path-discovery" in e2e


def test_phase14_api_shield_generates_real_advanced_method_and_token_rules():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    dashboard = read("dash/src/views/domain-tabs/DomainWafTab.vue")
    docs = read("docs/api/api.md")
    e2e = read("ci/e2e.sh")

    assert "'protect_api' => [" in service
    assert "'rate_api_authorization_header'" in service
    assert "'key_type' => 'header_path'" in service
    assert "'key_header_name' => 'Authorization'" in service
    assert "'waf_api_allowed_methods'" in service
    assert "'type' => 'path_method_not_allowed'" in service
    assert "'pattern' => '/api/:GET,POST,PUT,PATCH,DELETE,OPTIONS'" in service
    assert "path_method_not_allowed" in controller
    assert "path_method_not_allowed" in dashboard
    assert "API Shield (`protect_api`) generates real advanced rules" in docs
    assert "api-protection-preview" in e2e


def test_phase14_edge_enforces_path_scoped_method_restrictions_and_events():
    router = read("edge/openresty/lua/router.lua")
    docs = read("docs/api/api.md")
    smoke = read("ci/smoke.sh")

    assert "if t == 'path_method_not_allowed' then" in router
    assert "split_once(pattern, ':')" in router
    assert "string.sub(path, 1, #prefix) ~= prefix" in router
    assert "current_method == string.upper(trim(allowed))" in router
    assert "append_security_event(nil)" in router
    assert "path_method_not_allowed" in docs
    assert "schema-api-protection-route" in smoke
