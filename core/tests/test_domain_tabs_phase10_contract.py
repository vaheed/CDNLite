from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_ssl_settings_are_domain_scoped_and_published():
    schema = read("core/database/schema.sql")
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    snapshot = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "CREATE TABLE IF NOT EXISTS domain_ssl_settings" in schema
    assert "/api/v1/domains/{domainId}/ssl/settings" in routes
    assert "setSslSettings" in service
    assert "'ssl' => $this->rules->getSslSettings" in snapshot
    assert "valid_ssl_certificate_required" in service
    assert "managed_by='force_https'" in service
    assert "VALUES (:domain_id,false" in service
    assert ":min_tls_version,false" in service


def test_force_https_redirect_is_http_only_and_preserves_request_uri():
    router = read("edge/openresty/lua/router.lua")

    assert "rule.managed_by == 'force_https' and ngx.var.scheme == 'http'" in router
    assert "ngx.var.request_uri" in router


def test_ssl_tab_defaults_are_off_and_save_feedback_is_visible():
    tab = read("dash/src/views/domain-tabs/DomainSslTab.vue")
    schema = read("core/database/schema.sql")

    assert "auto_renew: false" in tab
    assert "force_https: false" in tab
    assert "SSL settings saved." in tab
    assert "Import manual certificate" in tab
    assert "sslApi.manualCertificate" in tab
    assert "auto_renew BOOLEAN NOT NULL DEFAULT false" in schema


def test_dashboard_uses_domain_detail_tabs():
    detail = read("dash/src/views/DomainDetailView.vue")
    router = read("dash/src/router/index.ts")
    nav = read("dash/src/components/layout/nav.ts")

    for label in ["Overview", "DNS", "SSL", "Cache", "Redirects", "Page Rules", "WAF", "Rate Limits", "Analytics"]:
        assert f"label: '{label}'" in detail
    assert "const wafTabs" in detail
    assert "label: 'WAF', tabs: wafTabs" in detail
    assert "HorizontalScrollFrame" in detail
    assert "domain-tabs-frame" in detail
    assert "Setup needed" in detail
    assert "Disabled" in detail
    assert "/domains/:domainId/:tab?" in router
    assert "DomainFeatureView" not in router
    assert "{ to: '/dns'" not in nav
    assert not (ROOT / "dash/src/views/DomainFeatureView.vue").exists()


def test_each_domain_tab_has_a_component():
    expected = [
        "DomainOverviewTab.vue",
        "DomainDnsTab.vue",
        "DomainSslTab.vue",
        "DomainCacheTab.vue",
        "DomainRedirectsTab.vue",
        "DomainPageRulesTab.vue",
        "DomainWafTab.vue",
        "DomainRateLimitsTab.vue",
        "DomainAnalyticsTab.vue",
    ]
    tab_dir = ROOT / "dash/src/views/domain-tabs"
    assert all((tab_dir / name).exists() for name in expected)
