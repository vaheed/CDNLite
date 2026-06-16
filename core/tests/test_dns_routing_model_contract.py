from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_record_routing_and_geo_schema():
    schema = read("core/database/schema.sql")
    assert "routing_policy TEXT NOT NULL DEFAULT 'standard'" in schema
    assert "canonical_edge_hostname" not in schema
    assert "edge_target" not in schema
    assert "CREATE TABLE IF NOT EXISTS dns_record_geo_routes" in schema
    assert "('standard', 'geo', 'anycast', 'geo_anycast')" in schema


def test_anycast_and_geo_api_contract():
    routes = read("core/public_index.php")
    assert "/api/v1/admin/edge-network/anycast" not in routes
    assert "/api/v1/edge-countries" in routes
    assert "/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes" in routes


def test_canonical_hostname_and_no_cname_to_ip_contract():
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")
    edge_dns = read("core/app/Modules/Dns/Services/EdgeDnsService.php")
    assert "canonicalHostname" in planner
    assert "return $this->result('CNAME', $canonical" in planner
    assert "'site-' . $this->label($domainId)" in planner
    assert "'shared_proxy:' . $type" in edge_dns
    assert "'site_proxy:'" in edge_dns
    assert "[$this->proxyHost() . '.']" in edge_dns
    assert "JOIN dns_records r ON r.domain_id = d.id" in edge_dns


def test_proxied_default_does_not_require_anycast():
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")
    assert "$input['routing_policy'] ?? 'standard'" in service
    assert "$record['routing_policy'] ?? 'standard'" in planner


def test_dashboard_record_level_routing_contract():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    assert "Default origin IP or hostname" in view
    assert "Proxy through CDNLite" in view
    assert "Default origin protocol" in view
    assert "origin_scheme: form.origin_scheme" in view
    assert "...originProtocolPayload(form.origin_scheme)" in view
    assert "Geo origin routing" in view
    assert "country-specific origins" in view
    assert "geoOriginPayload" in view
    assert "Anycast IPv4" not in view
    assert "Edge node ID" not in view
    assert "Route to edge country" not in view


def test_dashboard_geo_origins_are_independent_from_proxy_status():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    assert ':disabled="!form.proxied"' not in view
    assert 'v-if="form.geo_enabled && form.proxied"' not in view
    assert "if (form.proxied && form.geo_enabled)" not in view
    assert "if (form.geo_enabled)" in view
    assert "origin_host: form.content.trim()" in view
    assert "scheme: origin.scheme === 'https' ? 'https' : 'http'" in view


def test_local_development_edges_are_selectable():
    service = read("core/app/Modules/Dns/Services/GeoRoutingService.php")
    assert "'LOCAL'" in service
    assert "Local development" in service


def test_powerdns_lua_records_are_expressions_not_chunks():
    builder = read("core/app/Modules/Dns/Services/EdgeHealthRecordBuilder.php")
    assert "country(" in builder
    assert "ifportup" not in builder
    assert "ifurlup" not in builder
    assert "return ifportup" not in builder
    assert "return ifurlup" not in builder
