from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase6_schema_exposes_cache_correctness_controls():
    migration = read("core/database/migrations/000030_phase6_cache_correctness.sql")
    schema = read("core/database/schema.sql")

    for field in (
        "cache_methods_json TEXT NOT NULL DEFAULT '[\"GET\",\"HEAD\"]'",
        "cache_status_code_policy_json TEXT NOT NULL DEFAULT",
        "bypass_headers_json TEXT NOT NULL DEFAULT '[\"authorization\"]'",
        "bypass_cookies_json TEXT NOT NULL DEFAULT",
        "vary_headers_json TEXT NOT NULL DEFAULT '[\"accept-encoding\"]'",
        "cache_key_dimensions_json TEXT NOT NULL DEFAULT",
        "debug_headers_enabled BOOLEAN NOT NULL DEFAULT false",
        "stale_while_revalidate_seconds INTEGER NOT NULL DEFAULT 0",
        "negative_ttl_seconds INTEGER NOT NULL DEFAULT 0",
        "max_object_size_bytes BIGINT NOT NULL DEFAULT 104857600",
    ):
        assert field in migration
        assert field in schema

    assert "DROP TABLE" not in migration
    assert "TRUNCATE" not in migration


def test_migration_versions_are_unique():
    migrations = sorted((ROOT / "core/database/migrations").glob("*.sql"))
    versions = [path.name.split("_", 1)[0] for path in migrations]
    duplicates = sorted({version for version in versions if versions.count(version) > 1})
    assert duplicates == []


def test_phase6_api_snapshot_and_dashboard_surface_safe_cache_model():
    service = read("core/app/Services/ControlPlane/TrafficRulesService.php")
    config = read("core/app/Modules/Proxy/Services/ConfigService.php")
    dashboard = read("dash/src/views/domain-tabs/DomainCacheTab.vue")
    types = read("dash/src/types.ts")

    assert "cache_methods_json" in service
    assert "cache_key_dimensions_json" in service
    assert "cache_methods" in service
    assert "cache_key_dimensions" in service
    assert "debug_headers_enabled" in service
    assert "'cache' => $this->rules->getDomainCacheSettings" in config
    assert "Cache key preview" in dashboard
    assert "Personalized traffic bypasses by default" in dashboard
    assert "debug_headers_enabled" in dashboard
    assert "cache_key_dimensions" in dashboard
    assert "export interface CacheSettings" in types
    assert "cache_methods" in types
    assert "cache_key_dimensions" in types


def test_phase6_edge_cache_decisions_are_standards_aware_and_debuggable():
    proxy = read("edge/openresty/lua/proxy.lua")
    nginx = read("edge/openresty/nginx.conf")

    for token in (
        "header_has_cache_directive",
        "has_logged_in_cookie",
        "request_has_bypass_header",
        "build_cache_key",
        "cache_settings.cache_methods",
        "cache_settings.vary_headers",
        "cache_settings.cache_key_dimensions",
        "bypass_reason",
        "authorization",
        "request_cache_control",
        "geoip.request_country()",
        "X-Accel-Expires",
    ):
        assert token in proxy

    assert "proxy_ignore_headers X-Accel-Expires" not in nginx
    assert "proxy_cache_lock on" in nginx
    assert "proxy_cache_use_stale error timeout http_500 http_502 http_503 http_504" in nginx
    assert "X-CDNLite-Cache" in nginx
    assert "X-CDNLite-Cache-Reason" in nginx
    assert "X-CDNLite-Cache-Key" in nginx
    assert "CDNLITE_EDGE_DEBUG_HEADERS" in nginx


def test_phase6_runner_stress_docs_and_e2e_are_registered():
    phase = read("ci/phase.sh")
    manifest = read("ci/phases/phase-06.yml")
    stress = read("ci/stress-platform.sh")
    scenarios = read("ci/stress/scenarios.yml")
    e2e = read("ci/e2e.sh")
    docs = read("docs/api/api.md")
    roadmap = read("docs/ROADMAP.md")
    changelog = read("CHANGELOG.md")

    assert "06)" in phase
    assert "test_phase6_cache_correctness_contract.py" in phase
    assert "phase6-cache-correctness" in phase
    assert 'phase: "06"' in manifest
    assert "phase6-cache-correctness" in manifest
    assert "phase6-cache-correctness" in stress
    assert "phase6-cache-correctness" in scenarios
    assert "edge-cache-basic" in e2e
    assert "X-CDNLite-Cache-Key" in docs
    assert "Cache key preview" in docs
    assert "Phase 6 — Cache correctness foundation" in roadmap
    assert "| 6. Cache correctness foundation | P0 | Complete |" in roadmap
    assert "Phase 6 cache correctness" in changelog
