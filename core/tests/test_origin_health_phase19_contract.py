from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_origin_schema_contract():
    schema = read("core/database/schema.sql")

    assert "CREATE TABLE IF NOT EXISTS domain_origins" in schema
    assert "health_check_interval_seconds INTEGER NOT NULL DEFAULT 30" in schema
    assert "health_check_enabled BOOLEAN NOT NULL DEFAULT false" in schema
    assert "health_status TEXT NOT NULL DEFAULT 'unknown'" in schema
    assert "role TEXT NOT NULL DEFAULT 'primary'" in schema


def test_origin_api_and_cli_contract():
    routes = read("core/routes/api.php")
    controller = read("core/app/Http/Controllers/Api/DomainController.php")
    scheduler = read("core/app/Console/Commands/ScheduleRunCommand.php")

    assert "Route::get('/domains/{domainId}/origins'" in routes
    assert "Route::post('/domains/{domainId}/origins/{originId}/check'" in routes
    assert "Route::post('/domains/{domainId}/origins/{originId}/test'" in routes
    assert "Route::get('/domains/{domainId}/origins/health'" in routes
    assert "OriginLifecycleService" in controller
    assert "checkOrigin" in controller
    assert "originHealth" in controller
    assert "cdn:origins:health-check" in scheduler


def test_origin_health_service_and_readiness_contract():
    service = read("core/app/Services/ControlPlane/OriginLifecycleService.php")
    readiness = read("core/app/Services/ControlPlane/ReadinessService.php")
    compose = read("docker-compose.yml")

    assert "function healthReport" in service
    assert "function diagnose" in service
    assert "core_active_checks' => false" in service
    assert "Origin routing health is updated from edge metrics" in service
    assert "health_status" in service
    assert "origin_health" in readiness
    assert "health_check_enabled=true AND health_status='unhealthy'" in readiness
    assert "Check the origin or review the origin pool" in readiness
    assert "php /app/artisan cdn:scheduler:run" in compose
    assert "CDNLITE_ORIGIN_HEALTH_INTERVAL_SECONDS" in compose
    assert "CDNLITE_SCHEDULER_IDLE" in compose
    assert "CDNLITE_SCHEDULER_TICK_SECONDS" in compose


def test_config_snapshot_and_edge_failover_contract():
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")
    selector = read("edge/openresty/lua/origin_selector.lua")
    router = read("edge/openresty/lua/router.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    nginx = read("edge/openresty/nginx.conf")

    assert "'origin' => $origins[0]" in config or "'origin' => $origin" in config
    assert "selectOriginFromPool" in config
    assert "'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore')" in config
    assert "health_check_enabled' => (bool) ($origin['health_check_enabled'] ?? false)" in config
    assert "empty($origin['health_check_enabled'])" in config
    assert "$pool = $healthy !== [] ? $healthy : $unknown" in config
    assert "INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING" in config
    assert "candidate_origins" in selector
    assert "choose_origin" in selector
    assert "ngx.ctx.backup_upstream" not in router
    assert "target_backup_upstream" not in proxy
    assert "X-CDNLITE-Origin" in proxy
    assert "ngx.header['X-CDNLITE-Origin'] = 'origin'" in proxy


def test_dashboard_and_docs_expose_origins():
    detail = read("dash/src/views/DomainDetailView.vue")
    tab = read("dash/src/views/domain-tabs/DomainOriginsTab.vue")
    api = read("dash/src/lib/api/origins.ts")
    docs = read("docs/api/api.md")

    assert "DomainOriginsTab" in detail
    assert "label: 'Origins'" in detail
    assert "Enable edge health routing" in tab
    assert "Origin health from edge nodes" in tab
    assert "Health pass-through" in tab
    assert "schema-origin-shared-hosting-defaults" in read("ci/smoke.sh")
    assert "origin-ip-shared-hosting-default" in read("ci/e2e.sh")
    assert "origin-health-disabled-still-routes" in read("ci/e2e.sh")
    assert "origin-health-edge-observations" in read("ci/e2e.sh")
    assert "Add origin" in tab
    assert '<span class="field-label">Protocol</span>' in tab
    assert '<span class="field-label">Port</span>' not in tab
    assert "const originProtocol = computed" in tab
    assert "origins/${originId}/check" in api
    assert "origins/health" in api
    assert "/api/v1/domains/{domainId}/origins/health" in docs
    assert "X-CDNLITE-Origin" in docs
