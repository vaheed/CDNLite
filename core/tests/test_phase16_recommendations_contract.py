from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_recommendation_engine_schema_cli_api_and_generator_are_present():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000013_recommendations.sql")
    service = read("core/app/Modules/Recommendations/Services/RecommendationService.php")
    controller = read("core/app/Modules/Recommendations/Http/Controllers/RecommendationController.php")
    routes = read("core/public_index.php")
    artisan = read("core/artisan")

    for field in (
        "recommendations",
        "domain_id",
        "confidence",
        "risk",
        "impact",
        "preview_payload",
        "one_click_action",
        "snoozed_until",
        "dismissed_at",
        "applied_at",
    ):
        assert field in schema
        assert field in migration

    for signal in ("login_shield", "protect_api", "origin_diagnostics", "static_asset_cache", "bot_shield", "common_exploits"):
        assert signal in service

    assert "DISMISS_SUPPRESSION_SECONDS" in service
    assert "enableProtectionIntent" in service
    assert "setDomainCacheSettings" in service
    assert "OriginHealthService" in service
    assert "WHERE id=:id AND domain_id=:domain_id AND type=:type" in service
    assert "':snooze_cutoff' => $now" in service
    assert "recommendation.dismiss" in service
    assert "recommendation.apply" in service
    assert "public function snooze" in controller
    assert "cdn:recommendations:generate" in artisan

    for route in (
        "/api/v1/recommendations",
        "/api/v1/recommendations/generate",
        "/api/v1/domains/{domainId}/recommendations",
        "/api/v1/domains/{domainId}/recommendations/generate",
        "/api/v1/domains/{domainId}/recommendations/{recommendationId}/apply",
        "/api/v1/domains/{domainId}/recommendations/{recommendationId}/dismiss",
        "/api/v1/domains/{domainId}/recommendations/{recommendationId}/snooze",
    ):
        assert route in routes


def test_recommendations_have_dashboard_docs_smoke_and_e2e_coverage():
    panel = read("dash/src/components/recommendations/RecommendationsPanel.vue")
    overview = read("dash/src/views/OverviewView.vue")
    security = read("dash/src/views/domain-tabs/DomainSecurityCenterTab.vue")
    api = read("dash/src/lib/api/recommendations.ts")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")

    assert "Why am I seeing this?" in panel
    assert "recommendationsApi.apply" in panel
    assert "RecommendationsPanel" in overview
    assert "RecommendationsPanel" in security
    assert "/api/v1/recommendations/generate" in api
    assert "/api/v1/domains/${domainId}/recommendations" in api
    assert "Recommendation Engine" in docs
    assert "/api/v1/domains/{domainId}/recommendations" in openapi
    assert "schema-recommendations" in smoke
    assert "dashboard-recommendations-bundle" in smoke
    assert "recommendations-generate" in e2e
    assert "recommendations-dismiss-suppression" in e2e
