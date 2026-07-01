from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_routing_schema_and_planner_contract():
    schema = read("core/database/schema.sql")
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")

    assert "CREATE TABLE IF NOT EXISTS domain_routing_settings" in schema
    assert "CHECK (routing_mode IN ('geo', 'anycast', 'dns_only'))" in schema
    assert "activeEdgeIps" not in planner
    assert "no_healthy_edge_ips_for_apex" not in planner
    assert "return $this->result('LUA', 'managed edge pool'" in planner
    assert "return $this->result('CNAME'" in planner
    assert "canonicalHostname" in planner
    assert "routing_policy" in planner


def test_routing_api_and_republish_contract():
    routes = read("core/routes/api.php")
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    controller = read("core/app/Http/Controllers/Api/DomainController.php")
    request = read("core/app/Http/Requests/StoreDnsRecordRequest.php")
    laravel_service = read("core/app/Services/ControlPlane/DnsRecordService.php")

    assert "/domains/{domainId}/dns/records/{recordId}/geo-routes" in routes
    assert "/domains/{domainId}/routing" not in routes
    assert "/dns/records/{recordId}/preview-routing" not in routes
    assert "$this->rebuildDomain($domainId);" in service
    assert "rebuildGeoDomains" in service
    assert "SELECT DISTINCT domain_id FROM dns_records" in service
    assert "geo_routes.*.route_scope" in request
    assert "geo_routes_require_dns_only_record" in laravel_service
    assert "geo_routes_require_address_record" in laravel_service
    assert "Rule::in(['A', 'AAAA'])" in request

    edge = read("core/app/Modules/Edge/Services/EdgeService.php")
    assert "(new DnsService())->rebuildGeoDomains();" not in edge


def test_dashboard_routing_controls_contract():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    api = read("dash/src/lib/api/dns.ts")

    assert "GeoDNS answers" in view
    assert "Proxy through CDNLite" in view
    assert "geo_routes: geoRoutePayload()" in view
    assert "Select country" in view
    assert "Answer" in view
    assert "Geo + Anycast CDN" not in view
    assert "Anycast IPv4" not in view
    assert "geoRoutes" in api
    assert "previewRouting" not in api
