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
