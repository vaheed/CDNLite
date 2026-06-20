from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_performance_starter_persists_safe_controls_and_enforces_them_at_edge():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000010_performance_starter.sql")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    proxy = read("edge/openresty/lua/proxy.lua")

    for field in (
        "static_asset_cache_enabled",
        "ignore_query_strings_for_static",
        "bypass_logged_in_users",
    ):
        assert field in schema
        assert field in migration
        assert field in service
        assert field in controller

    assert "local function is_static_asset(path)" in proxy
    assert "local function has_logged_in_cookie(value)" in proxy
    assert "cache_settings.static_asset_cache_enabled == true" in proxy
    assert "cache_settings.ignore_query_strings_for_static == true" in proxy
    assert "cache_settings.bypass_logged_in_users ~= false" in proxy
    assert "ngx.var.cdnlite_cache_key = table.concat" in proxy


def test_performance_starter_has_dashboard_docs_smoke_and_e2e_coverage():
    dashboard = read("dash/src/views/domain-tabs/DomainCacheTab.vue")
    types = read("dash/src/types.ts")
    docs = read("docs/api/api.md")
    roadmap = read("docs/ROADMAP.md")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")

    for field in (
        "Cache static assets",
        "Ignore query strings for static assets",
        "Bypass cache for logged-in users",
    ):
        assert field in dashboard
    assert "static_asset_cache_enabled" in types
    assert "Cache settings additionally support" in docs
    assert "Implemented (2026-06-20)" in roadmap
    assert "schema-performance-starter" in smoke
    assert "performance-starter-edge" in e2e
    assert "static query-string normalization should reuse the cached asset" in e2e
    assert "logged-in cookie should bypass static cache" in e2e
