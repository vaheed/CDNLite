from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_record_routing_and_geo_schema():
    schema = read("core/database/schema.sql")
    assert "routing_policy TEXT NOT NULL DEFAULT 'standard'" in schema
    assert "canonical_edge_hostname TEXT NULL" in schema
    assert "CREATE TABLE IF NOT EXISTS dns_record_geo_routes" in schema
    assert "('standard', 'geo', 'anycast', 'geo_anycast')" in schema


def test_anycast_and_geo_api_contract():
    routes = read("core/public_index.php")
    assert "/api/v1/admin/edge-network/anycast" in routes
    assert "/api/v1/edge-countries" in routes
    assert "/api/v1/sites/{domainId}/dns-records/{recordId}/geo-routes" in routes


def test_canonical_hostname_and_no_cname_to_ip_contract():
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")
    edge_dns = read("core/app/Modules/Dns/Services/EdgeDnsService.php")
    assert "canonicalHostname" in planner
    assert "return $this->result('CNAME', $canonical" in planner
    assert "'global.' . $prefix" in edge_dns
    assert "'CNAME', $anycast ? $global : $geo" in edge_dns


def test_proxied_default_does_not_require_anycast():
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")
    assert "$input['routing_policy'] ?? 'standard'" in service
    assert "$record['routing_policy'] ?? 'standard'" in planner


def test_dashboard_record_level_routing_contract():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    assert "Origin target" in view
    assert "Standard DNS" in view
    assert "Geo + Anycast CDN" in view
    assert "Default fallback" in view
    assert "Anycast IPv4" not in view
    assert "Edge node ID" not in view
    assert "Route to edge country" in view
