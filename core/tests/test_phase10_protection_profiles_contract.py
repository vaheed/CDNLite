from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase10_backend_exposes_profile_templates_and_routes():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/public_index.php")
    openapi = read("docs/public/api/openapi.yaml")

    for method in (
        "listProtectionProfiles",
        "previewProtectionProfile",
        "applyProtectionProfile",
        "disableProtectionProfile",
        "protectionProfileTemplates",
        "upsertProtectionProfile",
    ):
        assert method in service
        assert method in controller or method == "protectionProfileTemplates" or method == "upsertProtectionProfile"

    for profile_key in ("basic_website", "wordpress", "api", "saas_app", "ecommerce", "emergency"):
        assert f"'{profile_key}' => [" in service

    for route in (
        "/api/v1/domains/{domainId}/protection/profiles",
        "/api/v1/domains/{domainId}/protection/profiles/{profileKey}/preview",
        "/api/v1/domains/{domainId}/protection/profiles/{profileKey}/apply",
        "/api/v1/domains/{domainId}/protection/profiles/{profileId}/disable",
    ):
        assert route in routes
        assert route in openapi


def test_phase10_profiles_compose_real_intents_with_profile_ownership_and_safety():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    docs = read("docs/api/api.md")
    roadmap = read("docs/ROADMAP.md")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")
    dashboard_api = read("dash/src/lib/api/protection.ts")
    dashboard_view = read("dash/src/views/domain-tabs/DomainSecurityCenterTab.vue")

    assert "enableProtectionIntentForProfile" in service
    assert "disableProtectionIntentForProfile" in service
    assert "profile_id=:profile_id" in service
    assert "managed_by' => $profileTemplate['name']" in service
    assert "protection_profile.apply" in service
    assert "protection_profile.disable" in service
    assert "user_modified_rule_conflict" in service
    assert "confirm_overwrite" in service
    assert "profile_change_history" in service
    assert "profile_rollback_points" in service
    assert "invalidateConfigSnapshot" in service

    assert "Protection profiles" in docs
    assert "One-click profiles compose protection intents" in docs
    assert "Phase 10 — One-Click Protection Profiles" in roadmap
    assert "Progress Notes" in roadmap
    assert "schema-protection-contract" in smoke
    assert "protection-profile-list" in e2e
    assert "protection-profile-preview" in e2e
    assert "protection-profile-apply" in e2e
    assert "protection-profile-disable" in e2e
    assert "listProfiles" in dashboard_api
    assert "/protection/profiles" in dashboard_api
    assert "applyProfile" in dashboard_view
    assert "disableProfile" in dashboard_view
