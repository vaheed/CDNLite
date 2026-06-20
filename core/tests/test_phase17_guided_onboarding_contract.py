from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase17_guided_onboarding_backend_schema_and_routes_exist():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000014_domain_onboarding.sql")
    service = read("core/app/Modules/Onboarding/Services/OnboardingService.php")
    controller = read("core/app/Modules/Onboarding/Http/Controllers/OnboardingController.php")
    routes = read("core/public_index.php")

    for field in (
        "domain_onboarding",
        "domain_id",
        "status",
        "answers_json",
        "recommended_profile_key",
        "skipped_at",
        "completed_at",
    ):
        assert field in schema
        assert field in migration

    for method in ("saveAnswers", "preview", "apply", "skip", "resume", "progress"):
        assert method in service

    for profile in ("emergency", "wordpress", "ecommerce", "api", "saas_app", "basic_website"):
        assert f"'{profile}'" in service

    assert "under_attack" in service
    assert "framework === 'wordpress'" in service
    assert "has_api" in service
    assert "sells_products" in service
    assert "TrafficRulesService" in service
    assert "onboarding.answers_saved" in service
    assert "onboarding.apply_profile" in service
    assert "public function answers" in controller

    for route in (
        "/api/v1/domains/{domainId}/onboarding",
        "/api/v1/domains/{domainId}/onboarding/answers",
        "/api/v1/domains/{domainId}/onboarding/preview",
        "/api/v1/domains/{domainId}/onboarding/apply",
        "/api/v1/domains/{domainId}/onboarding/skip",
        "/api/v1/domains/{domainId}/onboarding/resume",
    ):
        assert route in routes


def test_phase17_dashboard_docs_smoke_and_e2e_are_wired():
    component = read("dash/src/components/protection/GuidedOnboardingWizard.vue")
    security = read("dash/src/views/domain-tabs/DomainSecurityCenterTab.vue")
    api = read("dash/src/lib/api/protection.ts")
    types = read("dash/src/types.ts")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")
    roadmap = read("docs/ROADMAP.md")

    assert "Guided onboarding" in component
    assert "Recommend profile" in component
    assert "Apply recommended profile" in component
    assert 'v-if="!dismissed"' in component
    assert "state.value.status === 'skipped'" in component
    assert "dismissed.value = true" in component
    assert "countryOptions" in component
    assert "selectedCountries" in component
    assert "Select a country" in component
    assert "input v-model=\"countriesText\"" not in component
    assert "Full preview details" in component
    assert "JSON.stringify(rule.payload ?? {}, null, 2)" in component
    assert "GuidedOnboardingWizard" in security
    assert "Full generated payload" in security
    assert "previewDetailItems" in security
    assert "ruleSummaryFields" in security
    assert "getOnboarding" in api
    assert "/onboarding/answers" in api
    assert "OnboardingState" in types
    assert "Guided Onboarding" in docs
    assert "/api/v1/domains/{domainId}/onboarding/answers" in openapi
    assert "schema-onboarding" in smoke
    assert "dashboard-onboarding-bundle" in smoke
    assert "guided-onboarding-flow" in e2e
    assert "Phase 17 — Guided Onboarding Wizard" in roadmap
