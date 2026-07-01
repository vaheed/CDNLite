from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_global_security_and_audit_routes_are_registered():
    routes = (ROOT / "core/routes/api.php").read_text()
    controller = (ROOT / "core/app/Http/Controllers/Api/OperationsController.php").read_text()
    assert "Route::get('/security/events'" in routes
    assert "Route::get('/security/summary'" in routes
    assert "Route::get('/audit'" in routes
    assert "Route::get('/events'" in routes
    assert "Route::get('/jobs'" in routes
    assert "public function events" in controller
    assert "public function jobs" in controller


def test_operations_log_service_supports_required_filters_and_pagination():
    controller = (ROOT / "core/app/Http/Controllers/Api/OperationsController.php").read_text()
    for field in ["domain_id", "type", "search", "from", "to", "status", "active"]:
        assert f"'{field}'" in controller
    assert "offset($offset)" in controller
    assert "limit($limit)" in controller
    assert "audit_log as a" in controller
    assert "ssl_jobs as j" in controller
    assert "leftJoin('domains as d'" in controller
    assert "details_json" in controller


def test_domain_security_events_exclude_administrative_audit_rows():
    service = (ROOT / "core/app/Services/ControlPlane/TrafficRulesService.php").read_text()
    assert "event IN ('waf_match','rate_limited','geo_block')" in service


def test_dashboard_exposes_security_events_and_audit_log_pages():
    router = (ROOT / "dash/src/router/index.ts").read_text()
    nav = (ROOT / "dash/src/components/layout/nav.ts").read_text()
    assert "/security-events" in router and "/security-events" in nav
    assert "/audit-log" in router and "/audit-log" in nav
    assert "/jobs" in router and "/jobs" in nav
    assert (ROOT / "dash/src/views/SecurityEventsView.vue").exists()
    assert (ROOT / "dash/src/views/AuditLogView.vue").exists()
    assert (ROOT / "dash/src/views/JobQueueView.vue").exists()


def test_domain_activity_view_is_paginated_and_domain_scoped():
    detail = (ROOT / "dash/src/views/DomainDetailView.vue").read_text()
    activity = (ROOT / "dash/src/views/domain-tabs/DomainActivityTab.vue").read_text()
    pagination = (ROOT / "dash/src/components/ui/PaginationControls.vue").read_text()

    assert "DomainActivityTab" in detail
    assert "key: 'activity'" in detail
    assert "domain_id: props.domainId" in activity
    assert activity.count("<PaginationControls") == 4
    assert "timelineOffset" in activity
    assert "requestsOffset" in activity
    assert "Search details" in activity
    assert "Rows" in pagination
