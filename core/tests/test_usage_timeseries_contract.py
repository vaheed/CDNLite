from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_usage_summary_returns_canonical_time_series_points():
    controller = (REPO_ROOT / "core" / "app" / "Http" / "Controllers" / "Api" / "CollectorController.php").read_text()
    dashboard = (REPO_ROOT / "dash" / "src" / "components" / "analytics" / "AnalyticsDashboard.vue").read_text()

    assert "public function usageSummary(Request $request)" in controller
    assert "private function usageSummaryPayload" in controller
    assert "'requests_count' => (int) ($row['requests_count'] ?? 0)" in controller
    assert "point.requests_count" in dashboard
    assert "formatBucketTime(point.bucket_ts)" in dashboard


def test_usage_cli_supports_cache_analytics():
    command = (REPO_ROOT / "core" / "routes" / "console.php").read_text()

    assert "Artisan::command('cdn:usage:ingest" in command
    assert "cache_status" in command
    assert "invalid_cache_status" in command
