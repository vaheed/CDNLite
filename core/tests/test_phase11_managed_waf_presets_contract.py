from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase11_managed_waf_presets_have_group_metadata_and_schema():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    migration = read("core/database/migrations/000007_managed_waf_metadata.sql")
    schema = read("core/database/schema.sql")

    for group_id in (
        "path_traversal",
        "scanner_recon",
        "api_method_probe",
        "suspicious_bot",
        "wordpress_exploit",
        "checkout_probe",
    ):
        assert f"'waf_group_id' => '{group_id}'" in service

    for field in ("waf_group_id", "waf_severity", "waf_confidence", "waf_safe_reason"):
        assert f"ALTER TABLE waf_rules ADD COLUMN IF NOT EXISTS {field}" in migration
        assert f"{field} TEXT NULL" in schema
        assert field in service


def test_phase11_generated_waf_metadata_flows_to_edge_events_and_docs():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    router = read("edge/openresty/lua/router.lua")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    docs = read("docs/api/api.md")
    roadmap = read("docs/ROADMAP.md")

    assert "wafMetadataPayload" in service
    assert "ngx.ctx.security_group_id" in router
    assert "ngx.ctx.security_confidence" in router
    assert "ngx.ctx.security_safe_reason" in router
    assert "'group_id' => (string) ($item['group_id'] ?? '')" in collector
    assert "'confidence' => (string) ($item['confidence'] ?? '')" in collector

    assert "Managed WAF presets" in docs
    assert "group_id" in docs
    assert "confidence" in docs
    assert "Phase 11 — Managed WAF Presets" in roadmap
    assert "Progress Notes" in roadmap


def test_phase11_exposes_read_only_managed_waf_preset_catalog():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/public_index.php")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "managedWafPresets" in service
    assert "managedWafGroups" in service
    assert "managedWafModes" in service
    assert "listManagedWafPresets" in controller
    assert "/api/v1/domains/{domainId}/protection/waf-presets" in routes
    assert "/api/v1/domains/{domainId}/protection/waf-presets:" in openapi

    for group_id in (
        "sql_injection",
        "cross_site_scripting",
        "path_traversal",
        "local_file_inclusion",
        "remote_file_inclusion",
        "command_injection",
        "php_exploit",
        "wordpress_exploit",
        "scanner_recon",
        "suspicious_encodings",
        "known_bad_user_agents",
    ):
        assert f"'{group_id}' => [" in service

    for mode in ("log_only", "challenge_suspicious", "block_high_confidence", "strict_block"):
        assert f"'{mode}' => [" in service

    assert "Managed WAF preset catalog" in docs
    assert "read-only" in docs
