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

    assert "client_ip TEXT NULL" in schema
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
    assert "countTimelineRequests" in collector
    assert "countTimelineAudit" in collector
    assert "$type === '' || $type === 'audit' || $type === 'security'" in collector
    assert "$type === 'error'" in collector
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
    assert "pruneOperationalRetention" in collector
    assert "WITH doomed AS" in collector
    assert "CDNLITE_SECURITY_EVENT_RETENTION_DAYS" in collector
    assert "CDNLITE_RETENTION_BATCH_SIZE" in collector
    assert "event IN ('waf_match','rate_limited','bot_match','geo_block')" in collector
    assert "status IN ('success','verified')" in collector
    assert "status IN ('issued','failed','cancelled')" in collector
    assert "pruneOperationalRetention([" in prune_command
    assert "isset($opts['all'])" in prune_command


def test_dashboard_activity_shows_request_origin_and_router_details():
    usage_api = read("dash/src/lib/api/usage.ts")
    types = read("dash/src/types.ts")
    activity = read("dash/src/views/domain-tabs/DomainActivityTab.vue")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "recentRequests" in usage_api
    assert "export interface RequestActivity" in types
    assert "Recent edge requests" in activity
    assert "Activity timeline" in activity
    assert "setTimelineOffset" in activity
    assert "setRequestsOffset" in activity
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
    assert "request.client_ip" in activity
    assert "request.client_country" in activity
    assert "client_ip ILIKE :search" in collector
    assert "client_country ILIKE :search" in collector
    assert "/api/v1/domains/{domainId}/activity" in docs
    assert "/api/v1/domains/{domainId}/activity/summary" in docs
    assert "/api/v1/domains/{domainId}/activity/requests" in docs
    assert "/api/v1/domains/{domainId}/activity/requests/{requestId}" in docs
    assert "cdn:usage:prune --dry-run" in docs
    assert "cdn:usage:prune --all --dry-run" in docs
    assert "CDNLITE_STORE_FULL_CLIENT_IP" in docs
    assert "/api/v1/domains/{domainId}/activity/requests:" in openapi
    assert "/api/v1/domains/{domainId}/activity:" in openapi
    assert "/api/v1/domains/{domainId}/activity/summary:" in openapi


def test_e2e_covers_real_edge_activity_ingest_and_502_diagnostics():
    e2e = read("ci/e2e.sh")
    agent_flow = read("ci/agent_flow_checks.sh")

    assert "activity-edge-request-ingest" in e2e
    assert "activity-edge-502-diagnostics" in e2e
    assert "/agent/push_metrics.sh" in e2e
    assert "agent_push_metrics()" in e2e
    assert 'agent_push_metrics >/dev/null' in e2e
    assert '.push.lock' in e2e
    assert "activity_request_lookup_ok" in e2e
    assert 'retry 20 1 activity_request_lookup_ok "$activity_ok_request_id"' in e2e
    assert 'retry 20 1 activity_request_lookup_ok "$activity_502_request_id"' in e2e
    assert "edge metrics file missing activity" not in e2e
    assert "X-CDNLITE-Request-Id" in e2e
    assert "phase6-activity-ok-${RUN_KEY}" in e2e
    assert "phase6-activity-502-${RUN_KEY}" in e2e
    assert "/activity/requests/${activity_ok_request_id}" in e2e
    assert "/activity/requests/${activity_502_request_id}" in e2e
    assert "activity?type=error&search=${activity_502_request_id}" in e2e
    assert "recent origin errors missing 502 request" in e2e
    assert "phase6-secret" in e2e
    assert "activity lookup leaked sensitive query parameter value" in e2e
    assert '"client_ip":"203.0.113.10"' in agent_flow
    assert '"client_country":"IR"' in agent_flow
    assert 'items[0].get("client_ip") == "203.0.113.10"' in agent_flow
    assert 'items[0].get("client_country") == "IR"' in agent_flow


def test_terminal_502_paths_emit_activity_metrics():
    nginx = read("edge/openresty/nginx.conf")
    error_page = read("edge/openresty/lua/error_page.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")

    assert "location @cdnlite_backup" not in nginx
    assert "location @cdnlite_tls_backup" not in nginx

    for location in (
        "location / {",
        "location @cdnlite_noverify {",
        "location @cdnlite_tls_noverify {",
    ):
        start = nginx.index(location)
        block = nginx[start:nginx.index("proxy_set_header Host", start)]
        assert "log_by_lua_block" in block
        assert "metrics.on_log()" in block

    error_page_start = nginx.index("location = /__cdnlite_error_page {")
    error_page_block = nginx[error_page_start:nginx.index("location / {", error_page_start)]
    assert "log_by_lua_block" in error_page_block
    assert "metrics.on_log()" in error_page_block
    assert "restore_activity_context()" in error_page
    assert "ngx.ctx.domain_id" in error_page
    assert "ngx.ctx.origin = {" in error_page
    assert "X-CDNLite-Domain-Id" in proxy
    assert "X-CDNLite-Origin-Id" in proxy
    assert "X-CDNLite-Origin-Host" in proxy
    assert "X-CDNLite-Origin-Role" in proxy
    assert "X-CDNLite-Origin-Tls-Verify" in proxy
    assert "cdnlite_request_context" in nginx
    assert "dict:set" in proxy
    assert "dict:get" in error_page
    assert 'headers["X-CDNLite-Domain-Id"]' in error_page
    assert 'headers["X-CDNLite-Origin-Id"]' in error_page
    assert 'headers["X-CDNLite-Origin-Host"]' in error_page
    assert "domainIdFromItem" in collector
    assert "SELECT id FROM domains WHERE domain = :host OR name = :host LIMIT 1" in collector
