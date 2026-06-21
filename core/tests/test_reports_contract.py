from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_report_routes_are_authenticated_and_complete():
    public_index = (ROOT / "core/public_index.php").read_text()
    for report in ("summary", "traffic", "cache", "edge", "security", "reliability", "operations"):
        assert f"'/api/v1/reports/{report}'" in public_index
    assert public_index.count("ReportService") >= 2
    assert "auth: true" in public_index


def test_report_service_uses_real_tables_and_validates_query_shape():
    service = (ROOT / "core/app/Modules/Reports/Services/ReportService.php").read_text()
    for table in (
        "usage_rollups",
        "audit_log",
        "edge_nodes",
        "dns_sync_state",
        "dns_sync_events",
        "ssl_certificates",
        "ssl_jobs",
        "cache_purge_requests",
        "config_snapshots",
    ):
        assert table in service
    for token in ("invalid_bucket", "domain_not_found", "time_range_too_large", "CACHE_STATUSES", "SECURITY_EVENTS"):
        assert token in service
    assert "cache_rule_match_counts' => null" in service
    assert "does not currently include" in service
    assert "pg_column_size(s) AS size" in service
    assert "content_hash,size FROM config_snapshots" not in service
    assert "$limit = min($limit, 5);" in service


def test_reporting_indexes_are_in_schema_and_migration():
    schema = (ROOT / "core/database/schema.sql").read_text()
    migration = (ROOT / "core/database/migrations/000016_reporting_indexes.sql").read_text()
    for index in (
        "idx_usage_rollups_ts",
        "idx_usage_rollups_edge_ts",
        "idx_usage_rollups_cache_ts",
        "idx_audit_log_event_created",
        "idx_ssl_jobs_status_created",
        "idx_cache_purge_requests_domain_created",
    ):
        assert index in schema
        assert index in migration


def test_dashboard_reports_client_and_overview_use_real_report_endpoints():
    api = (ROOT / "dash/src/lib/api/reports.ts").read_text()
    overview = (ROOT / "dash/src/views/OverviewView.vue").read_text()
    types = (ROOT / "dash/src/types.ts").read_text()
    for report in ("summary", "traffic", "cache", "edge", "security", "reliability", "operations"):
        assert f"/api/v1/reports/{report}" in api
        assert f"reportsApi.{report}" in overview
    assert "ReportSummary" in types and "ReportTraffic" in types and "ReportOperations" in types
    assert "Top Visitor Countries" in overview
    assert "request.client_ip" in overview
    assert "request.client_country" in overview
    assert "mock" not in overview.lower()
