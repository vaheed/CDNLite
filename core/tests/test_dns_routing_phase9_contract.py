from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_routing_schema_and_planner_contract():
    schema = read("core/database/schema.sql")
    planner = read("core/app/Modules/Dns/Services/DnsPublishingPlanner.php")

    assert "CREATE TABLE IF NOT EXISTS domain_routing_settings" in schema
    assert "CHECK (routing_mode IN ('geo', 'anycast', 'dns_only'))" in schema
    assert "ifportup(%d, {%s}" in planner
    assert "selector='%s', backupSelector='random'" in planner
    assert "return $this->result($origin['type'], $origin['content'], $mode, 'no_eligible_edge_ips');" in planner
    assert "anycast_ipv4_required" in planner
    assert "return $this->result('CNAME'" in planner


def test_routing_api_and_republish_contract():
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Dns/Services/DnsService.php")
    controller = read("core/app/Modules/Dns/Http/Controllers/DnsController.php")

    assert "/api/v1/domains/{domainId}/routing" in routes
    assert "/dns/records/{recordId}/preview-routing" in routes
    assert "$this->rebuildDomain($domainId);" in service
    assert "rebuildGeoDomains" in service
    assert "['geo', 'anycast', 'dns_only']" in controller

    edge = read("core/app/Modules/Edge/Services/EdgeService.php")
    assert "(new DnsService())->rebuildGeoDomains();" in edge


def test_dashboard_routing_controls_contract():
    view = read("dash/src/views/domain-tabs/DomainDnsTab.vue")
    api = read("dash/src/lib/api/dns.ts")

    assert "Save routing" in view
    assert "saveRouting" in view
    assert "async function toggle" in view
    assert "previewRouting" in api
