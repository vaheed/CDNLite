from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]

def test_overview_routes_and_aggregate_contract():
    routes = (ROOT / "core/routes/api.php").read_text()
    controller = (ROOT / "core/app/Http/Controllers/Api/OperationsController.php").read_text()
    assert "Route::get('/overview'" in routes
    for field in ("domains","active_domains","edge_nodes","online_edges","dns_records","open_jobs"):
        assert f"'{field}'" in controller
    assert "DB::table('domains')" in controller
    assert "DB::table('edge_nodes')" in controller

def test_overview_warnings_include_ssl_expiry_rule():
    report = (ROOT / "core/app/Http/Controllers/Api/ReportController.php").read_text()
    assert "ssl_expiring_count" in report
    assert "certificates_expiring_soon" in report

def test_dashboard_uses_aggregate_report_calls_without_domain_loop():
    view = (ROOT / "dash/src/views/OverviewView.vue").read_text()
    api = (ROOT / "dash/src/lib/api/overview.ts").read_text()
    assert "reportsApi.summary" in view and "reportsApi.traffic" in view
    assert "reportsApi.security" in view and "reportsApi.operations" in view
    assert "Promise.all" in view and "overviewApi" not in view
    assert "/overview/warnings" not in api
