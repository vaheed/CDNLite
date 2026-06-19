from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase12_exposes_read_only_smart_rate_limit_template_catalog():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/public_index.php")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")
    roadmap = read("docs/ROADMAP.md")

    assert "smartRateLimitTemplates" in service
    assert "smartRateLimitImpact" in service
    assert "listSmartRateLimitTemplates" in controller
    assert "/api/v1/domains/{domainId}/protection/rate-limit-templates" in routes
    assert "/api/v1/domains/{domainId}/protection/rate-limit-templates:" in openapi

    for intent_key in (
        "login_protection",
        "api_protection",
        "form_spam",
        "expensive_pages",
        "emergency_traffic_limit",
    ):
        assert f"'{intent_key}' => [" in service

    for template_key in (
        "rate_login_paths",
        "rate_api_paths",
        "rate_form_spam",
        "rate_expensive_pages",
        "rate_emergency_sitewide",
    ):
        assert f"'template_key' => '{template_key}'" in service

    assert "Smart Rate Limiting template catalog" in docs
    assert "would_have_matched_24h" in docs
    assert "Phase 12 — Smart Rate Limiting" in roadmap
    assert "Progress Notes" in roadmap


def test_phase12_rate_limit_events_are_enriched_for_activity():
    router = read("edge/openresty/lua/router.lua")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    docs = read("docs/api/api.md")

    for field in (
        "limit_key_type",
        "threshold",
        "current_count",
        "window_seconds",
        "retry_after",
    ):
        assert field in router
        assert field in collector
        assert field in docs

    assert "ngx.ctx.security_rate_limit_id" in router
    assert "'rate_limit_id' => (string) ($item['rate_limit_id'] ?? $item['rule_id'] ?? '')" in collector


def test_phase12_header_based_rate_limit_keys_flow_to_schema_api_and_edge():
    migration = read("core/database/migrations/000008_rate_limit_header_keys.sql")
    schema = read("core/database/schema.sql")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    router = read("edge/openresty/lua/router.lua")
    types = read("dash/src/types.ts")
    docs = read("docs/api/api.md")

    assert "ADD COLUMN IF NOT EXISTS key_header_name TEXT NULL" in migration
    assert "key_header_name TEXT NULL" in schema
    assert "['ip', 'ip_path', 'header', 'header_path']" in controller
    assert "key_header_name" in controller
    assert "'key_header_name' => null" in service
    assert "request_header_value" in router
    assert "key_type == 'header' or key_type == 'header_path'" in router
    assert "key_header_name" in types
    assert "header/header_path" in docs
