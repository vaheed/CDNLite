from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase0_repro_script_covers_reported_failures():
    script = read("ci/phase0_repro.sh")

    expected_checks = [
        "nameserver-refresh-trace",
        "force-verify-non-admin",
        "force-verify-admin",
        "multiple-proxied-record-create",
        "multiple-proxied-record-list",
        "ssl-request-progress",
        "activity-detail",
        "edge-origin-proxy-200",
    ]
    for check in expected_checks:
        assert check in script

    assert "phase0_expect_failure" in script
    assert "/verify-nameservers" in script
    assert "/nameservers/force-verify" in script
    assert "/ssl/request" in script
    assert "/ssl/request-cert" not in script
    assert "/activity?limit=10" in script
    assert "Host: ${TEST_DOMAIN}" in script


def test_phase0_diagnostics_collect_required_artifacts():
    lib = read("ci/lib.sh")

    expected_artifacts = [
        "compose-ps.txt",
        "compose-logs.txt",
        "latest-edge-config.json",
        "metrics.ndjson",
        "security-events.ndjson",
        "powerdns-dry-run.txt",
        "powerdns-force-sync-dry-run.txt",
    ]
    for artifact in expected_artifacts:
        assert artifact in lib

    assert "docker compose logs --no-color --tail=200" in lib
    assert "cdn:powerdns:dry-run" in lib
    assert "cdn:powerdns:force-sync --dry-run" in lib
