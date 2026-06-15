from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_error_page_uses_light_professional_template_for_5xx_codes():
    page = read("edge/openresty/lua/error_page.lua")

    for code in ("[500]", "[502]", "[503]", "[504]"):
        assert code in page

    assert "--page-bg:#f7f9fc" in page
    assert "color-scheme:light" in page
    assert "prefers-color-scheme" not in page
    assert "We could not load this page" in page
    assert "status-flow" in page
    assert "User Browser" in page
    assert "CDN Edge Server" in page
    assert "Origin Server" in page
    assert "For Visitors" in page
    assert "For Site Owners" in page
    assert "Error Details" in page
    assert "Check CDN Status" in page
    assert "Documentation" in page
    assert "Support" in page


def test_error_page_escapes_dynamic_values_and_exposes_safe_diagnostics_only():
    page = read("edge/openresty/lua/error_page.lua")

    assert "local function h(v)" in page
    for dynamic in (
        'detail_row("Request ID", reqid)',
        'detail_row("Edge location", edge_loc)',
        'detail_row("Timestamp", ts)',
        'detail_row("Client IP", client_ip)',
        'detail_row("Hostname", host)',
        'detail_row("Router error", router_error)',
        'detail_row("Upstream status", upstream_status)',
        'detail_row("Upstream response time", upstream_response_time)',
        'detail_row("Origin ID", origin_id)',
    ):
        assert dynamic in page

    assert "ngx.header['X-CDNLITE-Request-Id'] = reqid" in page
    assert "identity.apply()" in page
    assert "ngx.ctx.origin" in page
    assert "origin.host" not in page
    assert "target_upstream" not in page
    assert "private_key" not in page
    assert "Authorization" not in page
    assert "Cookie" not in page


def test_error_page_is_self_contained_without_external_assets_or_scripts():
    page = read("edge/openresty/lua/error_page.lua")

    assert "<script" not in page
    assert "http://" not in page
    assert "https://" not in page
    assert "@import" not in page
    assert "url(" not in page
    assert "<svg viewBox=" in page
    assert "CDNLITE_STATUS_URL" in page
    assert "CDNLITE_DOCS_URL" in page
    assert "CDNLITE_SUPPORT_URL" in page
