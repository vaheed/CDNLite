from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_domain_analytics_routes_and_dashboard_filter_contract():
    routes = (REPO_ROOT / "core" / "routes" / "api.php").read_text()
    analytics = (REPO_ROOT / "dash" / "src" / "components" / "analytics" / "AnalyticsDashboard.vue").read_text()
    router = (REPO_ROOT / "dash" / "src" / "router" / "index.ts").read_text()
    domains = (REPO_ROOT / "dash" / "src" / "views" / "DomainsView.vue").read_text()

    assert "/domains/{domainId}/analytics/summary" in routes
    assert "/domains/{domainId}/analytics/cache" in routes
    assert "All domains" in analytics
    assert "usageApi.domainSummary(domainId" in analytics
    assert "cacheApi.analytics(domainId || undefined)" in analytics
    assert "usageApi.recalculate(selectedDomainId.value || undefined, bucket.value)" in analytics
    assert "'top_countries'" in (REPO_ROOT / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    assert "Top visitor countries" in (REPO_ROOT / "dash" / "src" / "views" / "domain-tabs" / "DomainActivityTab.vue").read_text()
    assert "top_countries" in (REPO_ROOT / "dash" / "src" / "types.ts").read_text()
    assert "path: '/domains/:domainId/:tab?'" in router
    assert "`/domains/${row.id}/overview`" in domains
