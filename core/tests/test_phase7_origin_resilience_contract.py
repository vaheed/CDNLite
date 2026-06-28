from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase7_schema_exposes_origin_resilience_controls():
    migration = read("core/database/migrations/000031_phase7_origin_resilience.sql")
    schema = read("core/database/schema.sql")

    for field in (
        "load_balancing_algorithm TEXT NOT NULL DEFAULT 'weighted_hash'",
        "connection_timeout_seconds INTEGER NOT NULL DEFAULT 5",
        "response_timeout_seconds INTEGER NOT NULL DEFAULT 30",
        "retry_attempts INTEGER NOT NULL DEFAULT 1",
        "retry_budget_per_minute INTEGER NOT NULL DEFAULT 60",
        "circuit_breaker_enabled BOOLEAN NOT NULL DEFAULT true",
        "circuit_failure_threshold INTEGER NOT NULL DEFAULT 5",
        "circuit_recovery_seconds INTEGER NOT NULL DEFAULT 30",
        "max_concurrent_requests INTEGER NOT NULL DEFAULT 0",
        "drain BOOLEAN NOT NULL DEFAULT false",
        "shield_enabled BOOLEAN NOT NULL DEFAULT false",
        "CREATE TABLE IF NOT EXISTS origin_health_observations",
        "edge_node_id TEXT NOT NULL",
        "jitter_ms INTEGER NULL",
        "role IN ('primary', 'backup', 'shield')",
    ):
        assert field in migration
        assert field in schema

    assert "DROP TABLE" not in migration
    assert "TRUNCATE" not in migration


def test_phase7_api_snapshot_and_dashboard_types_include_resilience_model():
    controller = read("core/app/Modules/Proxy/Http/Controllers/OriginController.php")
    service = read("core/app/Modules/Proxy/Services/OriginHealthService.php")
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")
    public_index = read("core/public_index.php")
    types = read("dash/src/types.ts")

    assert "must_be_primary_backup_or_shield" in controller
    for token in (
        "retry_attempts",
        "retry_budget_per_minute",
        "circuit_breaker_enabled",
        "max_concurrent_requests",
        "drain",
        "shield_enabled",
    ):
        assert token in controller
        assert token in service
        assert token in config

    assert "load_balancing_algorithm" in controller
    assert "load_balancing_algorithm" in service
    assert "load_balancing_algorithm" in config
    assert "recordOriginObservation" in collector
    assert "origin_health_observations" in collector
    assert "refreshOriginStatusFromEdge" in collector
    assert "origin_jitter" in collector
    assert "jitterMs >= 1000" in collector
    assert "core_active_checks' => false" in service
    assert "/api/v1/domains/{domainId}/origins/health" in public_index

    assert "'role' => (string) ($origin['role'] ?? 'primary')" in config
    assert "empty($origin['enabled']) || !empty($origin['drain'])" in config
    assert "'health_check_path' => (string) ($origin['health_check_path'] ?? '/')" in config
    assert "export interface DomainOrigin" in types
    assert "export interface OriginHealthReport" in types
    assert "load_balancing_algorithm" in types
    assert "circuit_failure_threshold" in types


def test_phase7_edge_selection_is_weighted_bounded_and_backup_aware():
    selector = read("edge/openresty/lua/origin_selector.lua")
    proxy = read("edge/openresty/lua/proxy.lua")
    checker = read("edge/openresty/lua/origin_health_checker.lua")
    nginx = read("edge/openresty/nginx.conf")

    for token in (
        "role_rank",
        "weighted_pick",
        "healthy_primary",
        "healthy_backup",
        "unknown_backup",
        "origin.drain ~= true",
        "slot < seen",
        "retry_budget_per_minute",
        "circuit_breaker_enabled",
        "max_concurrent_requests",
        "shield_enabled",
    ):
        assert token in selector

    assert "method ~= 'GET' and method ~= 'HEAD'" in proxy
    assert "X-CDNLite-Origin-Retry-Attempts" in proxy
    assert "X-CDNLite-Origin-Circuit-Breaker" in proxy
    assert "set $target_origin_retry_attempts '0';" in nginx
    assert "require('origin_health_checker').start()" in nginx
    assert "lua_shared_dict cdnlite_origin_health" in nginx
    assert "origin.health_check_enabled == true" in checker
    assert "origin_health_probe = true" in checker
    assert "telemetry_queue.enqueue('metrics'" in checker


def test_phase7_runner_stress_docs_and_roadmap_are_registered():
    phase = read("ci/phase.sh")
    manifest = read("ci/phases/phase-07.yml")
    stress = read("ci/stress-platform.sh")
    scenarios = read("ci/stress/scenarios.yml")
    api = read("docs/api/api.md")
    architecture = read("docs/architecture.md")
    troubleshooting = read("docs/troubleshooting.md")
    roadmap = read("docs/ROADMAP.md")
    changelog = read("CHANGELOG.md")

    assert "07)" in phase
    assert "test_phase7_origin_resilience_contract.py" in phase
    assert "phase7-origin-resilience" in phase
    assert 'phase: "07"' in manifest
    assert "phase7-origin-resilience" in manifest
    assert "phase7-origin-resilience" in stress
    assert "phase7-origin-resilience" in scenarios
    assert "primary, backup, and shield" in api
    assert "Edge health observations" in api
    assert "bounded idempotent retries" in architecture
    assert "edge-origin health observations" in architecture
    assert "drain the origin" in troubleshooting
    assert "| 7. Origin routing, resilience, and shielding | P0 | Complete |" in roadmap
    assert "Phase 7 origin routing resilience" in changelog
