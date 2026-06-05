from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_usage_summary_returns_canonical_time_series_points():
    service = (REPO_ROOT / "core" / "app" / "Modules" / "Collector" / "Services" / "CollectorService.php").read_text()
    dashboard = (REPO_ROOT / "dash" / "src" / "views" / "UsageAnalyticsView.vue").read_text()

    assert "GROUP BY bucket_ts ORDER BY bucket_ts ASC" in service
    assert "'bucket_ts' => (int) $point['bucket_ts']" in service
    assert "'requests_count' => (int) $point['requests_count']" in service
    assert "p.requests_count" in dashboard
    assert "formatBucketTime(point.bucket_ts)" in dashboard
