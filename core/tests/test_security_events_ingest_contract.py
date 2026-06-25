from pathlib import Path


def test_security_events_ingest_route_contract():
    repo_root = Path(__file__).resolve().parents[2]
    public_index = (repo_root / "core" / "public_index.php").read_text()
    collector_controller = (repo_root / "core" / "app" / "Modules" / "Collector" / "Http" / "Controllers" / "CollectorController.php").read_text()
    collector_service = (repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    agent_run = (repo_root / "edge" / "agent" / "run.sh").read_text()
    agent_push = (repo_root / "edge" / "agent" / "push_security_events.sh").read_text()
    router = (repo_root / "edge" / "openresty" / "lua" / "router.lua").read_text()
    telemetry_queue = (repo_root / "edge" / "openresty" / "lua" / "telemetry_queue.lua").read_text()

    assert "/api/v1/collector/security-events" in public_index
    assert "ingestSecurityEvents" in collector_controller
    assert "ingestSecurityEvents" in collector_service
    assert "push_security_events.sh" in agent_run
    assert "chmod 666" in agent_push
    assert 'mkdir "$lock_dir"' in agent_push
    assert "trap 'rmdir" in agent_push
    assert "' 0 HUP INT TERM" in agent_push
    assert "telemetry_queue.enqueue_and_flush('security_events'" in router
    assert "security-events.ndjson" in telemetry_queue


def test_e2e_global_waf_assertion_filters_newer_rate_limit_events():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    assert "/api/v1/security/events?domain_id=${DOMAIN_ID}&type=waf_match&limit=10" in e2e


def test_e2e_security_ingest_refreshes_edge_config_after_dns_mutations():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    section_start = e2e.index("# Security events should be ingested from edge runtime decisions via agent push.")
    section_end = e2e.index('record_step PASS "security-events"', section_start)
    section = e2e[section_start:section_end]

    assert "cdn:edge:sync-config" in section
    assert "agent_exec '/agent/pull_config.sh' >/dev/null" in section
    assert 'edge_wait_config_host "${TEST_DOMAIN}"' in section


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
