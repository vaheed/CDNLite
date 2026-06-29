from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_dns_operations_api_exposes_setup_sync_and_operator_actions():
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Dns/Services/DnsOperationsService.php")

    for endpoint in [
        "/api/v1/dns/operations",
        "/api/v1/dns/zones",
        "/api/v1/dns/desired",
        "/api/v1/dns/dry-run",
        "/api/v1/dns/force-sync",
        "/api/v1/domains/{domainId}/dns/status",
    ]:
        assert endpoint in routes
    for capability in [
        "'apex_proxy_mode' => 'LUA'",
        "'alias_expansion' => false",
        "'lua_records' => true",
        "'edns_subnet_processing' => true",
        "last_success_at",
        "last_error",
        "pending_changes",
    ]:
        assert capability in service


def test_dashboard_has_dns_operations_and_no_snapshot_navigation():
    router = read("dash/src/router/index.ts")
    nav = read("dash/src/components/layout/nav.ts")
    operations = read("dash/src/views/DnsOperationsView.vue")
    records = read("dash/src/views/domain-tabs/DomainDnsTab.vue")

    assert "/dns-operations" in router
    assert "/dns-operations" in nav
    assert "/config-snapshots" not in router
    assert "/config-snapshots" not in nav
    assert "Force sync now" in operations
    assert "Dry run" in operations
    assert "No apex ALIAS" in operations
    assert "Proxied apex records publish as PowerDNS LUA" in records
    assert "proxied subdomains publish as CNAME" in records
