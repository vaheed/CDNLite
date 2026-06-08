from pathlib import Path


def test_collector_skips_unknown_domains_contract():
    repo_root = Path(__file__).resolve().parents[2]
    collector_service = (
        repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php"
    ).read_text()
    api_reference = (repo_root / "docs" / "api/api.md").read_text()
    usage_docs = (repo_root / "docs" / "usage" / "admin.md").read_text()

    assert "domainExists" in collector_service
    assert "skipped_unknown_domains" in collector_service
    assert "SELECT 1 FROM domains WHERE id = :domain_id LIMIT 1" in collector_service
    assert "skipped_unknown_domains" in api_reference
    assert "stale edge config cannot fail an entire batch" in usage_docs
