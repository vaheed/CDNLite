from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_origin_schema_and_migration_contract():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/029_domain_origins.sql")

    assert "CREATE TABLE IF NOT EXISTS domain_origins" in schema
    assert "health_check_interval_seconds INTEGER NOT NULL DEFAULT 30" in schema
    assert "health_status TEXT NOT NULL DEFAULT 'unknown'" in schema
    assert "domain_origins_one_primary_idx" in schema
    assert "INSERT INTO domain_origins" in schema
    assert "CREATE TABLE IF NOT EXISTS domain_origins" in migration
    assert "INSERT INTO domain_origins" in migration


def test_origin_api_and_cli_contract():
    public_index = read("core/public_index.php")
    artisan = read("core/artisan")

    assert "/api/v1/domains/{domainId}/origins" in public_index
    assert "/api/v1/domains/{domainId}/origins/{originId}" in public_index
    assert "/api/v1/domains/{domainId}/origins/{originId}/check" in public_index
    assert "OriginController" in public_index
    assert "cdn:origins:health-check" in artisan
    assert "cdn:origins:list" in artisan


def test_origin_health_service_and_readiness_contract():
    service = read("core/app/Modules/Proxy/Services/OriginHealthService.php")
    readiness = read("core/app/Modules/Health/Services/ReadinessService.php")
    compose = read("docker-compose.yml")

    assert "function checkDue" in service
    assert "file_get_contents($url" in service
    assert "health_status" in service
    assert "origin_health" in readiness
    assert "Check the origin or configure a backup origin" in readiness
    assert "origin-health-scheduler" in compose
    assert "cdn:origins:health-check" in compose
    assert "CDNLITE_SCHEDULER_IDLE" in compose
    assert "origin health scheduler idle" in compose
    assert "sleep 30" in compose


def test_config_snapshot_and_edge_failover_contract():
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")
    selector = read("edge/openresty/lua/origin_selector.lua")
    router = read("edge/openresty/lua/router.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    nginx = read("edge/openresty/nginx.conf")

    assert "'primary_origin' => $primaryOrigin" in config
    assert "'backup_origin' => $backupOrigin" in config
    assert "primaryAndBackupForDomain" in config
    assert "'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'verify')" in config
    assert "INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING" in config
    assert "role == 'backup'" in selector
    assert "ngx.ctx.backup_upstream" in router
    assert "target_backup_upstream" in proxy
    assert "X-CDNLITE-Origin" in proxy
    assert "@cdnlite_backup" in nginx
    assert "ngx.header['X-CDNLITE-Origin'] = 'backup'" in nginx


def test_dashboard_and_docs_expose_origins():
    detail = read("dash/src/views/DomainDetailView.vue")
    tab = read("dash/src/views/domain-tabs/DomainOriginsTab.vue")
    api = read("dash/src/lib/api/origins.ts")
    docs = read("docs/api-reference.md")

    assert "DomainOriginsTab" in detail
    assert "label: 'Origins'" in detail
    assert "Origin health" in tab
    assert "Add backup" in tab
    assert "origins/${originId}/check" in api
    assert "X-CDNLITE-Origin: primary|backup" in docs
