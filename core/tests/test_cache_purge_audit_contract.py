from pathlib import Path


def test_cache_purge_audit_contract():
    repo_root = Path(__file__).resolve().parents[2]
    traffic_rules_service = (repo_root / "core" / "app" / "Modules" / "Proxy" / "Services" / "TrafficRulesService.php").read_text()
    api_reference = (repo_root / "docs" / "api/api.md").read_text()

    assert "cache_purge_requested" in traffic_rules_service
    assert "version_before" in traffic_rules_service
    assert "version_after" in traffic_rules_service
    assert "cache_purge_requested" in api_reference
