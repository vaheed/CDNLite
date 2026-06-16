from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_openresty_uses_origin_host_header_sni_and_docker_visible_logs():
    nginx = read("edge/openresty/nginx.conf")
    compose = read("docker-compose.yml")
    entrypoint = read("edge/docker-entrypoint.sh")

    assert "error_log /dev/stderr __CDNLITE_EDGE_ERROR_LOG_LEVEL__;" in nginx
    assert "access_log /dev/stdout __CDNLITE_EDGE_ACCESS_LOG_FORMAT__;" in nginx
    assert "access_log /var/log/openresty/access.log" not in nginx
    assert "log_format cdnlite_json escape=json" in nginx
    assert '"request_id":"$request_id"' in nginx
    assert '"upstream_status":"$upstream_status"' in nginx
    assert '"origin_id":"$target_origin_id"' in nginx
    assert "proxy_set_header Host $target_origin_host_header" in nginx
    assert "proxy_ssl_name $target_origin_sni" in nginx
    assert "proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;" in nginx
    assert "proxy_ssl_verify on;" in nginx
    assert "proxy_ssl_verify off;" in nginx
    assert "@cdnlite_noverify" in nginx
    assert "@cdnlite_backup_noverify" in nginx
    assert "@cdnlite_tls_noverify" in nginx
    assert "@cdnlite_tls_backup_noverify" in nginx
    assert "ngx.var.target_origin_tls_verify == 'ignore'" in nginx
    assert "set $target_origin_id '';" in nginx
    assert nginx.count("set $target_origin_id '';") == 2
    assert "set $target_domain_id '';" in nginx
    assert nginx.count("set $target_domain_id '';") == 2
    assert "set $target_origin_host '';" in nginx
    assert "set $target_backup_origin_host '';" in nginx
    assert "set $target_backup_origin_id '';" in nginx
    assert nginx.count("set $target_backup_origin_id '';") == 2
    assert "primary_origin_unavailable" in nginx
    assert "missing_backup_origin" not in nginx
    assert "env CDNLITE_EDGE_LOG_LEVEL;" in nginx
    assert "CDNLITE_EDGE_LOG_FORMAT: ${CDNLITE_EDGE_LOG_FORMAT:-json}" in compose
    assert "CDNLITE_EDGE_LOG_LEVEL: ${CDNLITE_EDGE_LOG_LEVEL:-info}" in compose
    assert "log_format=\"${CDNLITE_EDGE_LOG_FORMAT:-json}\"" in entrypoint
    assert "access_log_format=\"combined\"" in entrypoint


def test_edge_log_smoke_script_covers_docker_visible_diagnostics():
    script = read("ci/edge_log_smoke.sh")

    assert "EDGE_LOG_SMOKE_VALID_HOST" in script
    assert "EDGE_LOG_SMOKE_DOWN_HOST" in script
    assert "docker compose logs --no-color --tail" in script
    assert "valid-proxied-request" in script
    assert "unknown-host-request" in script
    assert "origin-down-request" in script
    assert '"request_id":' in script
    assert "router_error" in script
    assert "origin_id" in script
    assert "upstream_status" in script
    assert "secret-smoke-token" in script
    assert "assert_edge_log_not_contains" in script


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


def test_phase3_e2e_covers_https_sni_and_preserve_host_runtime_cases():
    e2e = read("ci/e2e.sh")
    origin_mock = read("ci/origin-mock/nginx.conf")

    assert '"origin_sni":"$ssl_server_name"' in origin_mock
    assert "origin-https-sni" in e2e
    assert '"origin_sni":"phase3-sni.local"' in e2e
    assert "origin-host-header-own" in e2e
    assert '"origin_host":"origin-http"' in e2e
    assert "origin-host-header-cdn" in e2e
    assert '\\"preserve_host\\":true' in e2e
    assert '\\"origin_host\\":\\"${TEST_DOMAIN}\\"' in e2e
    assert "verify mode should reject the self-signed certificate" in e2e
    assert 'assert_eq "$origin_verify_status" "502"' in e2e
    assert "verify-mode 502 should include X-CDNLITE-Request-Id" in e2e


def test_router_proxy_and_metrics_expose_phase3_diagnostics():
    router = read("edge/openresty/lua/router.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    metrics = read("edge/openresty/lua/metrics.lua")
    edge_log = read("edge/openresty/lua/edge_log.lua")
    rules = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")

    assert "edge_log.info('origin_selected'" in router
    assert "edge_log.warn('router_error'" in router
    assert "ngx.ctx.origin = scheme_or_error or {}" in router
    assert "type(backup_meta) ~= 'table'" in router
    assert "ngx.ctx.backup_origin = backup_meta" in router

    assert "ngx.var.target_origin_host_header" in proxy
    assert "ngx.var.target_origin_sni" in proxy
    assert "ngx.var.target_origin_id" in proxy
    assert "ngx.var.target_domain_id" in proxy
    assert "ngx.var.target_origin_host" in proxy
    assert "ngx.var.target_backup_origin_host" in proxy
    assert "cache_settings.default_edge_ttl_seconds" in proxy
    assert "cache_settings.cache_authorized_requests" in proxy
    assert "not cache_rules_enabled" in proxy
    assert "has_host_rules" in router
    assert "ngx.header['X-Accel-Expires'] = tostring(edge_ttl)" in proxy
    assert "setDomainCacheSettings" in rules and "invalidateConfigSnapshot" in rules.split("public function setDomainCacheSettings", 1)[1].split("public function createCachePurgeRequest", 1)[0]
    assert "ngx.var.target_backup_origin_host_header" in proxy
    assert "edge_log.debug('proxy_forward'" in proxy

    assert "router_error" in metrics
    assert "origin_id" in metrics
    assert "ngx.var.target_domain_id" in metrics
    assert "ngx.var.target_origin_host" in metrics
    assert "upstream_status" in metrics
    assert "upstream_response_time" in metrics
    assert "upstream_addr" in metrics
    assert "edge_log.redacted_query()" in metrics

    assert "authorization = true" in edge_log
    assert "cookie = true" in edge_log
    assert "signature = true" in edge_log
    assert "function M.redacted_query()" in edge_log
    assert "local function safe_var(name)" in edge_log
    assert "local function safe_ctx(name)" in edge_log
    assert "local function safe_method()" in edge_log
    assert "pcall(function()" in edge_log


def test_origin_diagnostic_and_route_debug_api_contract():
    public_index = read("core/public_index.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/OriginController.php")
    origins = read("core/app/Modules/Proxy/Services/OriginHealthService.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")
    api = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "/api/v1/domains/{domainId}/origins/{originId}/test" in public_index
    assert "/api/v1/domains/{domainId}/route-debug" in public_index
    assert "public function test(string $domainId, string $originId)" in controller
    assert "public function test(string $domainId, string $originId): ?array" in origins
    assert "private function probeDetailed" in origins
    for field in ("dns", "tcp", "tls", "http", "duration_ms", "host_header", "sni"):
        assert field in origins
    assert "stream_socket_client" in origins
    assert "stream_socket_enable_crypto" in origins
    assert "'peer_name' => $sni" in origins

    assert "public function debugRoute(string $domainId, array $input): array" in config
    assert "selected_origin" in config
    assert "backup_origin" in config
    assert "cache_rules_count" in config
    assert "router_error" in config

    assert "/api/v1/domains/{domainId}/origins/{originId}/test" in api
    assert "/api/v1/domains/{domainId}/route-debug" in api
    assert "/api/v1/domains/{domainId}/origins/{originId}/test:" in openapi
    assert "/api/v1/domains/{domainId}/route-debug:" in openapi
