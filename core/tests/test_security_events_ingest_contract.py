from pathlib import Path


def test_security_events_ingest_route_contract():
    repo_root = Path(__file__).resolve().parents[2]
    public_index = (repo_root / "core" / "public_index.php").read_text()
    collector_controller = (repo_root / "core" / "app" / "Modules" / "Collector" / "Http" / "Controllers" / "CollectorController.php").read_text()
    collector_service = (repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    agent_run = (repo_root / "edge" / "agent" / "run.sh").read_text()
    agent_push = (repo_root / "edge" / "agent" / "push_security_events.sh").read_text()
    router = (repo_root / "edge" / "openresty" / "lua" / "router.lua").read_text()

    assert "/api/v1/collector/security-events" in public_index
    assert "ingestSecurityEvents" in collector_controller
    assert "ingestSecurityEvents" in collector_service
    assert "push_security_events.sh" in agent_run
    assert "chmod 666" in agent_push
    assert "security-events.ndjson" in router


def test_e2e_global_waf_assertion_filters_newer_rate_limit_events():
    repo_root = Path(__file__).resolve().parents[2]
    e2e = (repo_root / "ci" / "e2e.sh").read_text()

    assert "/api/v1/security/events?domain_id=${DOMAIN_ID}&type=waf_match&limit=10" in e2e
