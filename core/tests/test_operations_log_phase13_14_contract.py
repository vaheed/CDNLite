from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_global_security_and_audit_routes_are_registered():
    public_index = (ROOT / "core/public_index.php").read_text()
    assert "'/api/v1/security/events'" in public_index
    assert "'/api/v1/security/summary'" in public_index
    assert "'/api/v1/audit'" in public_index
    assert "auth: true" in public_index


def test_operations_log_service_supports_required_filters_and_pagination():
    service = (ROOT / "core/app/Modules/Operations/Services/OperationsLogService.php").read_text()
    for field in ["domain_id", "edge_id", "type", "ip", "from", "to", "actor", "action", "resource_type"]:
        assert f"'{field}'" in service
    assert "LIMIT :limit OFFSET :offset" in service
    assert "top_ips" in service
    assert "top_domains" in service
    assert "FROM audit_log a" in service
    assert "d.zone_name" not in service
    assert "SELECT a.event" in service


def test_domain_security_events_exclude_administrative_audit_rows():
    service = (ROOT / "core/app/Modules/Proxy/Services/TrafficRulesService.php").read_text()
    assert "event IN ('waf_match','rate_limited','geo_block')" in service


def test_dashboard_exposes_security_events_and_audit_log_pages():
    router = (ROOT / "dash/src/router/index.ts").read_text()
    nav = (ROOT / "dash/src/components/layout/nav.ts").read_text()
    assert "/security-events" in router and "/security-events" in nav
    assert "/audit-log" in router and "/audit-log" in nav
    assert (ROOT / "dash/src/views/SecurityEventsView.vue").exists()
    assert (ROOT / "dash/src/views/AuditLogView.vue").exists()
