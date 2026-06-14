from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_openresty_uses_origin_host_header_sni_and_docker_visible_logs():
    nginx = read("edge/openresty/nginx.conf")

    assert "error_log /dev/stderr info;" in nginx
    assert "access_log /dev/stdout cdnlite_json;" in nginx
    assert "log_format cdnlite_json escape=json" in nginx
    assert '"request_id":"$request_id"' in nginx
    assert '"upstream_status":"$upstream_status"' in nginx
    assert '"origin_id":"$target_origin_id"' in nginx
    assert "proxy_set_header Host $target_origin_host_header" in nginx
    assert "proxy_ssl_name $target_origin_sni" in nginx
    assert "set $target_origin_id '';" in nginx
    assert "set $target_backup_origin_id '';" in nginx
    assert "env CDNLITE_EDGE_LOG_LEVEL;" in nginx


def test_origin_selector_returns_routing_metadata_without_silent_guessing():
    selector = read("edge/openresty/lua/origin_selector.lua")

    assert "local function first_enabled_by_role" in selector
    assert "local selected = selected_origin(domain, country, role)" in selector
    assert "scheme == 'http' or scheme == 'https'" in selector
    assert "scheme ~= 'auto'" in selector
    assert "invalid_origin_scheme" in selector
    assert "host_header = origin.host" in selector
    assert "origin.preserve_host == true" in selector
    assert "sni = origin_sni(origin, host_header)" in selector
    assert "tls_verify = tostring(origin.tls_verify or 'verify')" in selector


def test_router_proxy_and_metrics_expose_phase3_diagnostics():
    router = read("edge/openresty/lua/router.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    metrics = read("edge/openresty/lua/metrics.lua")
    edge_log = read("edge/openresty/lua/edge_log.lua")

    assert "edge_log.info('origin_selected'" in router
    assert "edge_log.warn('router_error'" in router
    assert "ngx.ctx.origin = scheme_or_error or {}" in router
    assert "type(backup_meta) ~= 'table'" in router
    assert "ngx.ctx.backup_origin = backup_meta" in router

    assert "ngx.var.target_origin_host_header" in proxy
    assert "ngx.var.target_origin_sni" in proxy
    assert "ngx.var.target_origin_id" in proxy
    assert "ngx.var.target_backup_origin_host_header" in proxy
    assert "edge_log.debug('proxy_forward'" in proxy

    assert "router_error" in metrics
    assert "origin_id" in metrics
    assert "upstream_status" in metrics
    assert "upstream_response_time" in metrics
    assert "upstream_addr" in metrics
    assert "edge_log.redacted_query()" in metrics

    assert "authorization = true" in edge_log
    assert "cookie = true" in edge_log
    assert "signature = true" in edge_log
    assert "function M.redacted_query()" in edge_log
