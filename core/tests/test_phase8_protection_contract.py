from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase8_schema_links_simple_protection_to_advanced_rules():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000006_protection_contract.sql")

    for table in (
        "protection_profiles",
        "protection_intents",
        "managed_rule_links",
        "profile_change_history",
        "profile_rollback_points",
    ):
        assert f"CREATE TABLE IF NOT EXISTS {table}" in schema
        assert f"CREATE TABLE IF NOT EXISTS {table}" in migration

    for technical_table in (
        "waf_rules",
        "rate_limit_rules",
        "domain_ip_rules",
        "cache_rules",
        "domain_header_rules",
    ):
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS profile_id" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS intent_id" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS template_key" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS managed_by" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS user_modified" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS last_generated_at" in schema
        assert f"ALTER TABLE {technical_table} ADD COLUMN IF NOT EXISTS last_applied_at" in schema

    redirect_definition = schema.split("CREATE TABLE IF NOT EXISTS redirect_rules", 1)[1].split(");", 1)[0]
    assert "user_modified" not in redirect_definition


def test_phase8_rule_service_preserves_and_detaches_managed_metadata():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/public_index.php")

    assert "managedRulePayload" in service
    assert "markUserModifiedForManagedRule" in service
    assert "detachManagedRule" in service
    assert "protection_rule.detach" in service
    assert "'profile_id'" in service
    assert "'intent_id'" in service
    assert "'template_key'" in service
    assert "'managed_by'" in service
    assert "'user_modified'" in service
    assert "tableTracksUserModified" in service
    assert "'redirect_rules'" not in service.split("private function tableTracksUserModified", 1)[1].split("private function auditResource", 1)[0]

    assert "detachManagedRule" in controller
    assert "/api/v1/domains/{domainId}/waf-rules/{ruleId}/detach-managed" in routes
    assert "/api/v1/domains/{domainId}/rate-limits/{ruleId}/detach-managed" in routes


def test_phase8_intent_workflow_generates_real_rules_without_silent_overwrite():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")
    routes = read("core/public_index.php")
    api = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    for method in (
        "listProtectionIntents",
        "previewProtectionIntent",
        "enableProtectionIntent",
        "disableProtectionIntent",
        "undoProtectionIntent",
        "assertManagedRulesCanBeRegenerated",
        "profile_rollback_points",
        "profile_change_history",
    ):
        assert method in service

    for action in (
        "protection_intent.enable",
        "protection_intent.disable",
        "protection_intent.undo",
        "user_modified_rule_conflict",
    ):
        assert action in service

    assert "createWaf($domainId" in service
    assert "createRateLimit($domainId" in service
    assert "enabled=false" in service
    assert "last_applied_at" in service

    for method in (
        "listProtectionIntents",
        "previewProtectionIntent",
        "enableProtectionIntent",
        "disableProtectionIntent",
        "undoProtectionIntent",
    ):
        assert method in controller

    for route in (
        "/api/v1/domains/{domainId}/protection/intents",
        "/api/v1/domains/{domainId}/protection/intents/{intentKey}/preview",
        "/api/v1/domains/{domainId}/protection/intents/{intentKey}/enable",
        "/api/v1/domains/{domainId}/protection/intents/{intentId}/disable",
        "/api/v1/domains/{domainId}/protection/intents/{intentId}/undo",
    ):
        assert route in routes
        assert route in openapi

    assert "Protection intents" in api
    assert "Preview does not mutate" in api
    assert "User-modified managed rules return `409 user_modified_rule_conflict`" in api


def test_phase8_advanced_dashboard_and_docs_expose_managed_state():
    types = read("dash/src/types.ts")
    waf = read("dash/src/views/domain-tabs/DomainWafTab.vue")
    rate_limits = read("dash/src/views/domain-tabs/DomainRateLimitsTab.vue")
    rules_tab = read("dash/src/views/domain-tabs/DomainRulesTab.vue")
    api = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    for field in ("profile_id", "intent_id", "template_key", "managed_by", "user_modified"):
        assert field in types

    assert "Managed by" in rules_tab
    assert "Customized by user" in rules_tab
    assert "detachManaged" in waf
    assert "detachManaged" in rate_limits

    assert "detach-managed" in api
    assert "/api/v1/domains/{domainId}/waf-rules/{ruleId}/detach-managed:" in openapi
    assert "/api/v1/domains/{domainId}/rate-limits/{ruleId}/detach-managed:" in openapi


def test_phase8_roadmap_smoke_and_e2e_track_managed_contract():
    roadmap = read("docs/ROADMAP.md")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")

    assert "rate-limit advanced views" in roadmap
    assert "Remaining Phase 8 work" in roadmap
    assert "schema-protection-contract" in smoke
    assert "managed_rule_columns" in smoke
    assert "intent_columns" in smoke
    assert "rollback_columns" in smoke
    assert "history_columns" in smoke
    assert "rate-limit-managed-contract" in e2e
    assert "waf-managed-contract" in e2e
    assert "protection-intent-preview" in e2e
    assert "protection-intent-enable" in e2e
    assert "protection-intent-conflict" in e2e
    assert "protection-intent-disable" in e2e
    assert "protection-intent-undo" in e2e
    assert "/detach-managed" in e2e
    assert "managed_rule_links" in e2e
    assert "profile_change_history" in e2e
    assert "profile_rollback_points" in e2e
