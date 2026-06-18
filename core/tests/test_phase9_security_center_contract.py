from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase9_dashboard_security_center_uses_protection_intent_contract():
    detail = read("dash/src/views/DomainDetailView.vue")
    tab = read("dash/src/views/domain-tabs/DomainSecurityCenterTab.vue")
    api = read("dash/src/lib/api/protection.ts")
    types = read("dash/src/types.ts")
    unit = read("dash/src/views/domain-tabs/DomainSecurityCenterTab.test.ts")

    assert "DomainSecurityCenterTab" in detail
    assert "Security Center" in detail
    assert "ShieldHalf" in detail

    for method in ("listIntents", "previewIntent", "enableIntent", "disableIntent", "undoIntent"):
        assert method in api
        assert method in tab

    for route_part in ("/protection/intents", "/preview", "/enable", "/disable", "/undo"):
        assert route_part in api

    for type_name in (
        "ProtectionIntentSummary",
        "ProtectionIntentPreview",
        "ProtectionIntentMutationResult",
        "ProtectionGeneratedRule",
    ):
        assert type_name in types
        assert type_name in tab or type_name in api

    assert "Choose protection outcomes here" in tab
    assert "Preview only shows the technical rules" in tab
    assert "Safe" in unit
    assert "Needs review" in unit
    assert "previewIntent" in unit
    assert "enableIntent" in unit
    assert "disableIntent" in unit
    assert "undoIntent" in unit


def test_phase9_backend_has_real_templates_for_all_security_center_cards():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")

    for intent_key in (
        "common_exploits",
        "login_shield",
        "protect_api",
        "smart_rate_limiting",
        "bot_shield",
        "emergency_protection",
        "static_asset_performance",
    ):
        assert f"'{intent_key}' => [" in service

    for template_key in (
        "rate_api_paths",
        "waf_api_method_probe",
        "rate_site_abuse",
        "waf_bot_user_agents",
        "waf_fake_search_bots",
        "rate_emergency_sitewide",
        "waf_emergency_scanners",
    ):
        assert f"'template_key' => '{template_key}'" in service


def test_phase9_smoke_e2e_roadmap_and_docs_track_security_center():
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")
    roadmap = read("docs/ROADMAP.md")
    api_docs = read("docs/api/api.md")
    use_cases = read("docs/use-cases/index.md")

    assert "dashboard-security-center-bundle" in smoke
    assert "Security Center" in smoke
    assert "dashboard-security-center-bundle" in e2e
    assert "Protection intent APIs" in e2e

    assert "Phase 9 — Security Center" in roadmap
    assert "Progress Notes" in roadmap
    assert "DomainSecurityCenterTab.vue" in roadmap
    assert "API shield, smart rate limiting, bot shield, and emergency protection" in roadmap

    assert "Security Center tab" in api_docs
    assert "simple-mode entry point" in api_docs
    assert "Security Center" in use_cases
    assert "advanced inspection" in use_cases
