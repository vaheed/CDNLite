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
    assert "return $this->result('ALIAS', $canonical" in planner
    assert "return $this->result('CNAME'" in planner
    assert "canonicalHostname" in planner
    assert "routing_policy" in planner


def test_routing_api_and_republish_contract():
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    controller = read("core/app/Modules/Dns/Http/Controllers/DnsController.php")

    assert "/api/v1/domains/{domainId}/routing" in routes
    assert "/dns/records/{recordId}/preview-routing" in routes
    assert "$this->rebuildDomain($domainId);" in service
    assert "rebuildGeoDomains" in service
    assert "SELECT DISTINCT domain_id FROM dns_records" in service
    assert "['geo', 'anycast', 'dns_only']" in controller
    assert "apex_cname_not_allowed" in controller
    assert "Validator::originHost($content, 'content')" in controller
    assert "['A', 'AAAA']" in controller

    edge = read("core/app/Modules/Edge/Services/EdgeService.php")
    assert "(new DnsService())->rebuildGeoDomains();" in edge


def test_dashboard_routing_controls_contract():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    api = read("dash/src/lib/api/dns.ts")

    assert "Geo origin routing" in view
    assert "Proxy through CDNLite" in view
    assert "geo_origins: geoOriginPayload()" in view
    assert "Visitor country" in view
    assert "Origin IP or hostname" in view
    assert "Geo + Anycast CDN" not in view
    assert "Anycast IPv4" not in view
    assert "previewRouting" in api
