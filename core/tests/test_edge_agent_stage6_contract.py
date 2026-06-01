from pathlib import Path


def test_stage6_agent_scripts_present_and_hardened_contract():
    repo_root = Path(__file__).resolve().parents[2]
    doctor = (repo_root / "edge" / "agent" / "doctor.sh").read_text()
    run = (repo_root / "edge" / "agent" / "run.sh").read_text()
    heartbeat = (repo_root / "edge" / "agent" / "heartbeat.sh").read_text()
    pull = (repo_root / "edge" / "agent" / "pull_config.sh").read_text()

    assert "checks" in doctor
    assert "last_error" in pull
    assert "config_version" in heartbeat
    assert "backoff" in run
