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
    artisan = read("core/artisan")
    prune_command = read("core/app/Console/Commands/CdnUsagePruneCommand.php")

    assert "host, method, path, query_redacted" in collector
    assert ":upstream_response_time_ms" in collector
    assert "$this->durationMs($item['upstream_response_time'] ?? null)" in collector
    assert "public function recentRequests(string $domainId" in collector
    assert "public function activityTimeline(string $domainId" in collector
    assert "public function activitySummary(string $domainId" in collector
    assert "public function findRequest(string $domainId, string $requestId)" in collector
    assert "castRequestActivity" in collector
    assert "public function recentRequests(string $domainId, array $query)" in controller
    assert "public function activityTimeline(string $domainId, array $query)" in controller
    assert "/api/v1/domains/{domainId}/activity/requests" in routes
    assert "/api/v1/domains/{domainId}/activity/summary" in routes
    assert "/api/v1/domains/{domainId}/activity/requests/{requestId}" in routes
    assert "/api/v1/domains/{domainId}/activity/export" in routes
    assert "public function pruneDetailedEvents" in collector
    assert "CDNLITE_ANALYTICS_RETENTION_DAYS" in collector
    assert "CDNLITE_STORE_FULL_CLIENT_IP" in collector
    assert "'sha256:' . hash('sha256', $ip)" in collector
    assert "cdn:usage:prune" in artisan
    assert "pruneDetailedEvents($days, $dryRun)" in prune_command


def test_dashboard_activity_shows_request_origin_and_router_details():
    usage_api = read("dash/src/lib/api/usage.ts")
    types = read("dash/src/types.ts")
    activity = read("dash/src/views/domain-tabs/DomainActivityTab.vue")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "recentRequests" in usage_api
    assert "export interface RequestActivity" in types
    assert "Recent edge requests" in activity
    assert "Activity timeline" in activity
    assert "Request-id lookup" in activity
    assert "Recent origin errors" in activity
    assert "Export JSON" in activity
    assert "usageApi.activitySummary" in activity
    assert "usageApi.activityTimeline" in activity
    assert "usageApi.findRequest" in activity
    assert "usageApi.exportActivity" in activity
    assert "usageApi.recentRequests" in activity
    assert "request.origin_id" in activity
    assert "request.upstream_status" in activity
    assert "request.router_error" in activity
    assert "/api/v1/domains/{domainId}/activity" in docs
    assert "/api/v1/domains/{domainId}/activity/summary" in docs
    assert "/api/v1/domains/{domainId}/activity/requests" in docs
    assert "/api/v1/domains/{domainId}/activity/requests/{requestId}" in docs
    assert "cdn:usage:prune --dry-run" in docs
    assert "CDNLITE_STORE_FULL_CLIENT_IP" in docs
    assert "/api/v1/domains/{domainId}/activity/requests:" in openapi
    assert "/api/v1/domains/{domainId}/activity:" in openapi
    assert "/api/v1/domains/{domainId}/activity/summary:" in openapi


def test_e2e_covers_real_edge_activity_ingest_and_502_diagnostics():
    e2e = read("ci/e2e.sh")

    assert "activity-edge-request-ingest" in e2e
    assert "activity-edge-502-diagnostics" in e2e
    assert "/agent/push_metrics.sh" in e2e
    assert "X-CDNLITE-Request-Id" in e2e
    assert "phase6-activity-ok-${RUN_KEY}" in e2e
    assert "phase6-activity-502-${RUN_KEY}" in e2e
    assert "/activity/requests/${activity_ok_request_id}" in e2e
    assert "/activity/requests/${activity_502_request_id}" in e2e
    assert "activity?type=error&search=${activity_502_request_id}" in e2e
    assert "recent origin errors missing 502 request" in e2e
    assert "phase6-secret" in e2e
    assert "activity lookup leaked sensitive query parameter value" in e2e
