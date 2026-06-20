from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_bot_shield_has_classified_policies_and_challenge_safe_search_claims():
    service = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000011_bot_protection.sql")
    verified_source_migration = read("core/database/migrations/000012_verified_bot_sources.sql")
    controller = read("core/app/Modules/Proxy/Http/Controllers/TrafficRulesController.php")

    for field in ("bot_class", "bot_score", "bot_action"):
        assert field in schema
        assert field in migration
        assert field in service
    assert "CREATE TABLE IF NOT EXISTS verified_bot_sources" in schema
    assert "CREATE TABLE IF NOT EXISTS verified_bot_sources" in verified_source_migration
    assert "listVerifiedBotSourcesForConfig" in service
    assert "'bot_class' => 'scraper'" in service
    assert "'bot_class' => 'unknown_automation'" in service
    assert "'action' => 'challenge'" in service
    assert "['block', 'log', 'allow', 'challenge']" in controller


def test_bot_events_flow_from_edge_to_collector_and_operations_ui():
    router = read("edge/openresty/lua/router.lua")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    operations = read("core/app/Modules/Operations/Services/OperationsLogService.php")
    traffic_rules = read("core/app/Modules/Proxy/Services/TrafficRulesService.php")
    dashboard = read("dash/src/views/SecurityEventsView.vue")
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")

    assert "security_event_type = 'bot_match'" in router
    assert "bot_challenge_required" in router
    assert "value == nil or value == cjson.null" in router
    assert "verified_bot_source_matches" in router
    assert "verified_bot_sources" in router
    assert "'verified_bot_sources' => $this->rules->listVerifiedBotSourcesForConfig" in config
    for field in ("bot_class", "bot_score", "bot_action"):
        assert field in router
        assert field in collector
    assert "'bot_match'" in operations
    assert "event IN ('waf_match','rate_limited','bot_match','geo_block')" in traffic_rules
    assert 'value="bot_match"' in dashboard
    assert "schema-bot-protection" in smoke
    assert "schema-verified-bot-sources" in smoke
    assert "edge-verified-bot-allow" in e2e
    assert "edge-bot-protection-runtime" in e2e
