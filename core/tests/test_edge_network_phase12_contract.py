from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_edge_pool_schema_and_api_contract():
    schema = read("core/database/schema.sql")
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Edge/Services/EdgeService.php")

    assert "CREATE TABLE IF NOT EXISTS edge_pools" in schema
    assert "CREATE TABLE IF NOT EXISTS edge_pool_members" in schema
    assert "geo_enabled BOOLEAN NOT NULL DEFAULT true" in schema
    assert "anycast_enabled BOOLEAN NOT NULL DEFAULT false" in schema
    assert "/api/v1/edges/pools" in routes
    assert "public function pools(): array" in service


def test_platform_dns_plan_contract():
    dns = read("core/app/Modules/Dns/Services/EdgeDnsService.php")
    routes = read("core/public_index.php")
    settings = read("core/app/Modules/Settings/Repositories/SettingsRepository.php")

    assert "SELECT * FROM edge_state" in dns
    assert "'shared_proxy:' . $type" in dns
    assert "ORDER BY anycast DESC" in dns
    assert "edge_state_generations" in dns
    assert "/api/v1/edges/dns" in routes
    assert "'cdn_zone'" in settings
    assert "'proxy_host'" in settings


def test_edge_network_dashboard_contract():
    view = read("dash/src/views/EdgeNetworkView.vue")
    api = read("dash/src/lib/api/edges.ts")
    router = read("dash/src/router/index.ts")

    assert 'title="Nodes"' in view
    assert ">Pools<" in view
    assert ">Platform DNS<" in view
    assert "Static proxy anycast" in view
    assert "staticAnycastSummary" in view
    assert "has no public IP" in view
    assert "/api/v1/edges/pools" in api
    assert "/api/v1/edges/dns" in api
    assert "EdgeNetworkView" in router
