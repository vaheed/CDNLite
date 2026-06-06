from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_usage_summary_returns_canonical_time_series_points():
    service = (REPO_ROOT / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    dashboard = (REPO_ROOT / "dash" / "src" / "components" / "analytics" / "AnalyticsDashboard.vue").read_text()

    assert "GROUP BY bucket_ts ORDER BY bucket_ts ASC" in service
    assert "'bucket_ts' => (int) $point['bucket_ts']" in service
    assert "'requests_count' => (int) $point['requests_count']" in service
    assert "point.requests_count" in dashboard
    assert "formatBucketTime(point.bucket_ts)" in dashboard


def test_usage_cli_and_frontend_e2e_seed_cache_analytics():
    command = (REPO_ROOT / "core" / "app" / "Console" / "Commands" / "CdnUsageIngestCommand.php").read_text()
    seed = (REPO_ROOT / "ci" / "seed_frontend_e2e.sh").read_text()
    spec = (REPO_ROOT / "dash" / "tests" / "e2e" / "analytics-domain-filter.spec.ts").read_text()

    assert "cache_status" in command
    assert "Invalid --cache_status" in command
    assert "frontend-analytics-" in seed
    assert "pg_isready" in seed
    assert "php artisan cdn:migrate" in seed
    for expected in ["'Requests', '30'", "'HIT', '17'", "'BYPASS', '3'", "'UNKNOWN', '1'"]:
        assert expected in spec
