from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]

def test_overview_routes_and_aggregate_contract():
    routes = (ROOT / "core/routes/api.php").read_text()
    service = (ROOT / "core/app/Modules/Overview/Services/OverviewService.php").read_text()
    assert "Route::get('/overview'" in routes
    for field in ("domains_count","active_domains","total_requests_24h","bandwidth_24h_bytes","cache_hit_ratio_24h","edge_online","edge_offline","security_events_24h","ssl_expiring_count","top_domains","recent_snapshots"):
        assert f"'{field}'" in service
    assert "LEFT JOIN usage_rollups" in service and "LIMIT 5" in service

def test_overview_warnings_include_ssl_expiry_rule():
    service = (ROOT / "core/app/Modules/Overview/Services/OverviewService.php").read_text()
    assert "not_after<:expiry" in service
    assert "expire within 30 days" in service

def test_dashboard_uses_aggregate_report_calls_without_domain_loop():
    view = (ROOT / "dash/src/views/OverviewView.vue").read_text()
    api = (ROOT / "dash/src/lib/api/overview.ts").read_text()
    assert "reportsApi.summary" in view and "reportsApi.traffic" in view
    assert "reportsApi.security" in view and "reportsApi.operations" in view
    assert "Promise.all" in view and "overviewApi" not in view
    assert "/overview/warnings" not in api
