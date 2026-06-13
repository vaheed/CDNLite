from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_header_and_ip_rule_schema_contract():
    schema = read("core/database/schema.sql")

    assert "CREATE TABLE IF NOT EXISTS domain_header_rules" in schema
    assert "operation TEXT NOT NULL" in schema
    assert "CHECK (operation IN ('set', 'remove', 'append'))" in schema
    assert "CREATE TABLE IF NOT EXISTS domain_ip_rules" in schema
    assert "CHECK (rule_type IN ('allow', 'block'))" in schema


def test_header_and_ip_rule_api_and_cli_contract():
    public_index = read("core/public_index.php")
    artisan = read("core/artisan")

    assert "/api/v1/domains/{domainId}/headers" in public_index
    assert "/api/v1/domains/{domainId}/headers/{ruleId}" in public_index
    assert "/api/v1/domains/{domainId}/ip-rules" in public_index
    assert "/api/v1/domains/{domainId}/ip-rules/{ruleId}" in public_index
    assert "cdn:header:create" in artisan
    assert "cdn:header:list" in artisan
    assert "cdn:ip-rule:create" in artisan
    assert "cdn:ip-rule:list" in artisan


def test_header_and_ip_rule_config_snapshot_contract():
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "listHeaderRules" in config
    assert "listIpRules" in config
    assert "'header_rules' => $headerRules" in config
    assert "'ip_rules' => $ipRules" in config
    assert "$hosts[$host]['header_rules'][]" in config
    assert "$hosts[$host]['ip_rules'][]" in config


def test_edge_enforces_header_and_ip_rules():
    nginx = read("edge/openresty/nginx.conf")
    router = read("edge/openresty/lua/router.lua")
    header_rules = read("edge/openresty/lua/header_rules.lua")
    ip_rules = read("edge/openresty/lua/ip_rules.lua")

    assert "require('header_rules')" in nginx
    assert "header_rules.apply()" in nginx
    assert "local ip_rules = require('ip_rules')" in router
    assert "ngx.ctx.header_rules = domain.header_rules or {}" in router
    assert "ip_rules.apply(domain)" in router
    assert "operation == 'remove'" in header_rules
    assert "append_header" in header_rules
    assert "blocked_by_ip_rule" in ip_rules
    assert "not_allowed_by_ip_rule" in ip_rules


def test_dashboard_exposes_headers_and_ip_access_tabs():
    detail = read("dash/src/views/DomainDetailView.vue")
    headers_tab = read("dash/src/views/domain-tabs/DomainHeadersTab.vue")
    ip_tab = read("dash/src/views/domain-tabs/DomainIpRulesTab.vue")

    assert "DomainHeadersTab" in detail
    assert "DomainIpRulesTab" in detail
    assert "label: 'Headers'" in detail
    assert "label: 'IP Access'" in detail
    assert "const wafTabs" in detail
    assert "Strict-Transport-Security" in headers_tab
    assert "Content-Security-Policy" in headers_tab
    assert "Bulk import" in ip_tab
    assert "one CIDR per line" in ip_tab
