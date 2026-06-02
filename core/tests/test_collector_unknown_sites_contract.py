from pathlib import Path


def test_collector_skips_unknown_sites_contract():
    repo_root = Path(__file__).resolve().parents[2]
    collector_service = (
        repo_root / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php"
    ).read_text()
    api_reference = (repo_root / "docs" / "api-reference.md").read_text()
    usage_docs = (repo_root / "docs" / "usage-and-metrics.md").read_text()

    assert "siteExists" in collector_service
    assert "skipped_unknown_sites" in collector_service
    assert "SELECT 1 FROM sites WHERE id = :site_id LIMIT 1" in collector_service
    assert "skipped_unknown_sites" in api_reference
    assert "stale edge config cannot fail an entire batch" in usage_docs
