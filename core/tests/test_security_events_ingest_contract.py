from pathlib import Path


def test_security_events_ingest_route_contract():
    repo_root = Path(__file__).resolve().parents[2]
    routes = (repo_root / "core" / "routes" / "api.php").read_text()
    collector_controller = (repo_root / "core" / "app" / "Modules" / "Collector" / "Http" / "Controllers" / "CollectorController.php").read_text()
    collector_service = (repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    agent_run = (repo_root / "edge" / "agent" / "run.sh").read_text()
    agent_push = (repo_root / "edge" / "agent" / "push_security_events.sh").read_text()
    router = (repo_root / "edge" / "openresty" / "lua" / "router.lua").read_text()
    telemetry_queue = (repo_root / "edge" / "openresty" / "lua" / "telemetry_queue.lua").read_text()

    assert "/collector/security-events" in routes
    assert "ingestSecurityEvents" in collector_controller
    assert "ingestSecurityEvents" in collector_service
    assert "push_security_events.sh" in agent_run
    assert "chmod 666" in agent_push
    assert 'mkdir "$lock_dir"' in agent_push
    assert "trap 'rmdir" in agent_push
    assert "' 0 HUP INT TERM" in agent_push
    assert "telemetry_queue.write_now('security_events'" in router
    assert "security-events.ndjson" in telemetry_queue


def test_e2e_global_waf_assertion_filters_newer_rate_limit_events():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    assert "/api/v1/security/events?domain_id=${DOMAIN_ID}&type=waf_match&limit=10" in e2e


def test_e2e_security_ingest_refreshes_edge_config_after_dns_mutations():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()
    loader = (repo_root / "edge" / "openresty" / "lua" / "config_loader.lua").read_text()

    section_start = e2e.index("# Security events should be ingested from edge runtime decisions via agent push.")
    section_end = e2e.index('record_step PASS "security-events"', section_start)
    section = e2e[section_start:section_end]
    helper_start = e2e.index("edge_pull_config()")
    helper_end = e2e.index("\n}\n\nedge_config_origin_json", helper_start)
    helper = e2e[helper_start:helper_end]

    assert "cdn:edge:sync-config" in section
    assert "edge_pull_config" in section
    assert "agent_exec '/agent/pull_config.sh' >/dev/null" in helper
    assert "edge_reload_config" in helper
    assert 'edge_wait_config_host "${TEST_DOMAIN}"' in section
    assert "agent_exec '/agent/push_security_events.sh' >/dev/null || true" in section
    assert "edge-ready-security-events.json" in section
    assert "security-events-db-before-fail.json" in section
    assert "active_mtime == mtime" not in loader
    assert "now - active_loaded_at < refresh_interval()" in loader


def test_e2e_collects_failure_diagnostics_before_cleanup():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    on_exit_start = e2e.index("on_exit()")
    on_exit_end = e2e.index("\n}\ntrap on_exit EXIT", on_exit_start)
    on_exit = e2e[on_exit_start:on_exit_end]

    assert on_exit.index("collect_diagnostics") < on_exit.index("cleanup")


def test_e2e_challenge_event_retry_pushes_before_polling_api():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    helper_start = e2e.index("challenge_security_event_visible()")
    helper_end = e2e.index("\n}\n\nedge_is_healthy", helper_start)
    helper = e2e[helper_start:helper_end]

    assert "agent_exec '/agent/push_security_events.sh' >/dev/null" in helper
    assert "security/events?type=rate_limited&limit=100" in helper
    assert '"\\"decision\\":\\"challenge\\""' in helper
    assert '"\\"rate_limit_id\\":\\"${CHALLENGE_RATE_LIMIT_RULE_ID}\\""' in helper
