from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_edge_config_visibility_smoke_and_e2e_wiring():
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    agent_lib = read("edge/agent/lib.sh")
    agent_heartbeat = read("edge/agent/heartbeat.sh")

    assert "schema-edge-config-visibility" in smoke
    assert "schema-config-publish-audit" in smoke
    assert "Config snapshot status" in smoke
    assert "config_apply_error" in smoke
    assert "config.publish" in e2e
    assert "config.publish.reused" in e2e
    assert "edge-config-version" in e2e
    assert "applied_config_version" in e2e
    assert "config.publish" in service
    assert "config.rollback" in service
    assert "cdnlite_config_apply_error" in agent_lib
    assert "config_apply_error" in agent_heartbeat
