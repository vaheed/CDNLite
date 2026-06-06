from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_domain_analytics_routes_and_dashboard_filter_contract():
    public_index = (REPO_ROOT / "core" / "public_index.php").read_text()
    analytics = (REPO_ROOT / "dash" / "src" / "components" / "analytics" / "AnalyticsDashboard.vue").read_text()
    router = (REPO_ROOT / "dash" / "src" / "router" / "index.ts").read_text()
    domains = (REPO_ROOT / "dash" / "src" / "views" / "DomainsView.vue").read_text()

    assert "'/api/v1/domains/{domainId}/analytics/summary'" in public_index
    assert "'/api/v1/domains/{domainId}/analytics/cache'" in public_index
    assert "All domains" in analytics
    assert "usageApi.domainSummary(domainId" in analytics
    assert "cacheApi.analytics(domainId || undefined)" in analytics
    assert "usageApi.recalculate(selectedDomainId.value || undefined)" in analytics
    assert "path: '/domains/:domainId/analytics'" in router
    assert "`/domains/${row.id}/analytics`" in domains
