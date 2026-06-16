from pathlib import Path


def test_stage7_schema_and_request_id_contract():
    repo_root = Path(__file__).resolve().parents[2]
    config_service = (repo_root / "core" / "app" / "Modules" / "Proxy" / "Services" / "ConfigService.php").read_text()
    loader = (repo_root / "edge" / "openresty" / "lua" / "config_loader.lua").read_text()
    proxy = (repo_root / "edge" / "openresty" / "lua" / "proxy.lua").read_text()
    identity = (repo_root / "edge" / "openresty" / "lua" / "identity.lua").read_text()
    metrics = (repo_root / "edge" / "openresty" / "lua" / "metrics.lua").read_text()
    error_page = (repo_root / "edge" / "openresty" / "lua" / "error_page.lua").read_text()
    router = (repo_root / "edge" / "openresty" / "lua" / "router.lua").read_text()
    nginx = (repo_root / "edge" / "openresty" / "nginx.conf").read_text()

    assert "'schema_version' => 1" in config_service
    assert "'cache' => $this->rules->getDomainCacheSettings" in config_service
    assert "EXPECTED_SCHEMA_VERSION = 1" in loader
    assert "config_schema_unsupported" in loader
    assert "decoded.schema_version == nil" in loader
    assert "X-CDNLITE-Request-Id" in proxy
    assert "identity.apply()" in proxy
    assert "os.getenv('EDGE_ID')" in identity
    assert "request_id" in metrics
    assert "cache_status" in metrics
    assert "proxy_ignore_headers X-Accel-Expires;" in nginx
    assert "security_event_type" in metrics
    assert "security_action" in metrics
    assert "blocked_by_waf" in router
    assert "rate_limited" in router
    assert "identity.apply()" in router
    assert "lua_shared_dict cdnlite_limits" in nginx
    assert "server_tokens off;" in nginx
    assert "more_clear_headers Server;" in nginx
    assert "X-CDNLITE-Request-Id" in error_page
    assert "identity.apply()" in error_page
