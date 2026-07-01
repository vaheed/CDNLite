from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_edge_pool_schema_and_api_contract():
    schema = read("core/database/schema.sql")
    routes = read("core/routes/api.php")
    controller = read("core/app/Http/Controllers/Api/EdgeController.php")

    assert "CREATE TABLE IF NOT EXISTS edge_pools" in schema
    assert "CREATE TABLE IF NOT EXISTS edge_pool_members" in schema
    assert "geo_enabled BOOLEAN NOT NULL DEFAULT true" in schema
    assert "anycast_enabled BOOLEAN NOT NULL DEFAULT false" in schema
    assert "Route::get('/edges/pools'" in routes
    assert "public function pools(): JsonResponse" in controller


def test_compose_runs_two_test_edges_with_public_ip_metadata():
    compose = read("docker-compose.yml")

    assert "edge-2:" in compose
    assert "edge-agent-2:" in compose
    assert "EDGE_TOKEN: ${EDGE_TOKEN:-edge-dev-token}" in compose
    assert "EDGE_TOKEN: ${EDGE_2_TOKEN:-edge-dev-token-2}" in compose
    assert "EDGE_PUBLIC_IP: ${EDGE_PUBLIC_IP:-203.0.113.10}" in compose
    assert "EDGE_COUNTRY: ${EDGE_COUNTRY:-US}" in compose
    assert "EDGE_PUBLIC_IP: ${EDGE_2_PUBLIC_IP:-198.51.100.20}" in compose
    assert "EDGE_COUNTRY: ${EDGE_2_COUNTRY:-DE}" in compose


def test_platform_dns_plan_contract():
    dns = read("core/app/Modules/Dns/Services/EdgeDnsService.php")
    renderer = read("core/app/Modules/Dns/Services/EdgeDnsPoolRenderer.php")
    routes = read("core/routes/api.php")
    settings = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")

    assert "SELECT * FROM edge_state" in renderer
    assert "'shared_proxy:' . $type" in dns
    assert "ORDER BY anycast DESC" in renderer
    assert "luaRecord(string $type)" in renderer
    assert "edge_state_generations" in dns
    assert "desiredRrsets(bool $persistGeneration = false)" in dns
    assert "Route::get('/edges/dns'" in routes
    assert "'cdn_zone'" in settings
    assert "'proxy_host'" in settings


def test_edge_agent_writes_do_not_block_on_powerdns():
    controller = read("core/app/Http/Controllers/Api/EdgeController.php")
    edge = read("core/app/Modules/Edge/Services/EdgeService.php")
    builder = read("core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php")

    assert "DnsReconciler" not in controller
    assert "syncEdgeDnsRecords" not in controller
    assert "rebuildGeoDomains" not in edge
    assert "desiredRrsets(true)" in builder


def test_edge_network_dashboard_contract():
    view = read("dash/src/views/EdgeNetworkView.vue")
    api = read("dash/src/lib/api/edges.ts")
    types = read("dash/src/types.ts")
    router = read("dash/src/router/index.ts")

    assert 'title="Nodes"' in view
    assert ">Pools<" in view
    assert ">Platform DNS<" in view
    assert "Static proxy anycast" in view
    assert "staticAnycastSummary" in view
    assert "has no public IP" in view
    assert "Config snapshot status" in view
    assert "applied_config_version" in view
    assert "config_apply_error" in view
    assert "configStatusLabel" in view
    assert "/api/v1/edges/pools" in api
    assert "/api/v1/edges/dns" in api
    assert "applied_config_version" in types
    assert "EdgeNetworkView" in router
