from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_usage_request_diagnostics_migration_and_schema_are_additive():
    migration = read("core/database/migrations/000004_usage_request_diagnostics.sql")
    schema = read("core/database/schema.sql")

    for field in (
        "host TEXT NULL",
        "method TEXT NULL",
        "path TEXT NULL",
        "query_redacted JSONB NULL",
        "origin_id TEXT NULL",
        "origin_host TEXT NULL",
        "upstream_status TEXT NULL",
        "upstream_response_time_ms INTEGER NULL",
        "router_error TEXT NULL",
        "security_event_type TEXT NULL",
    ):
        assert field in migration
        assert field in schema

    assert "DROP TABLE" not in migration
    assert "TRUNCATE" not in migration


def test_collector_persists_enriched_edge_metrics_and_exposes_recent_requests():
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    controller = read("core/app/Modules/Collector/Http/Controllers/CollectorController.php")
    routes = read("core/public_index.php")

    assert "host, method, path, query_redacted" in collector
    assert ":upstream_response_time_ms" in collector
    assert "$this->durationMs($item['upstream_response_time'] ?? null)" in collector
    assert "public function recentRequests(string $domainId" in collector
    assert "castRequestActivity" in collector
    assert "public function recentRequests(string $domainId, array $query)" in controller
    assert "/api/v1/domains/{domainId}/activity/requests" in routes


def test_dashboard_activity_shows_request_origin_and_router_details():
    usage_api = read("dash/src/lib/api/usage.ts")
    types = read("dash/src/types.ts")
    activity = read("dash/src/views/domain-tabs/DomainActivityTab.vue")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "recentRequests" in usage_api
    assert "export interface RequestActivity" in types
    assert "Recent edge requests" in activity
    assert "usageApi.recentRequests" in activity
    assert "request.origin_id" in activity
    assert "request.upstream_status" in activity
    assert "request.router_error" in activity
    assert "/api/v1/domains/{domainId}/activity/requests" in docs
    assert "/api/v1/domains/{domainId}/activity/requests:" in openapi
