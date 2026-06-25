from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase2_schema_defines_async_jobs_cache_and_idempotent_rollups():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000022_phase2_analytics_async_aggregation.sql")
    for source in (schema, migration):
        assert "CREATE TABLE IF NOT EXISTS analytics_rollup_jobs" in source
        assert "CREATE TABLE IF NOT EXISTS analytics_query_cache" in source
        assert "CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled'))" in source
    assert "UNIQUE(bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)" in schema


def test_phase2_recalculate_is_async_and_worker_upserts():
    service = read("core/app/Modules/Collector/Services/CollectorService.php")
    controller = read("core/app/Modules/Collector/Http/Controllers/CollectorController.php")
    router = read("core/public_index.php")
    command = read("core/app/Console/Commands/CdnUsageRecalculateCommand.php")
    assert "accepted' => true" in service
    assert "'status' => 202" in service
    assert 'assert_http_status "$HTTP_CODE" "202" "usage recalculate failed"' in read("ci/e2e.sh")
    assert "ON CONFLICT (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)" in service
    assert "DELETE FROM usage_aggregates" not in service
    assert "runNextRollupJob" in command
    assert "rollupJob" in controller
    assert "/api/v1/usage/recalculate/{jobId}" in router


def test_phase2_summary_contract_is_bounded_and_metadata_rich():
    service = read("core/app/Modules/Collector/Services/CollectorService.php")
    dashboard = read("dash/src/components/analytics/AnalyticsDashboard.vue")
    types = read("dash/src/types.ts")
    for token in (
        "DEFAULT_ANALYTICS_POINTS = 500",
        "effective_range",
        "point_count",
        "freshness",
        "aggregation_watermark",
        "partial_data",
        "query_id",
        "cache_status",
        "LIMIT :limit_points",
    ):
        assert token in service
    assert "Recalculation queued as" in dashboard
    assert "UsageRecalculateAccepted" in types


def test_phase2_docs_and_changelog_track_completion():
    roadmap = read("docs/ROADMAP.md")
    changelog = read("CHANGELOG.md")
    api = read("docs/api/api.md")
    operations = read("docs/operations/database-architecture.md")
    assert "| 2. Analytics scalability and asynchronous aggregation | P0 | Complete |" in roadmap
    assert "Phase 2 — Analytics scalability and asynchronous aggregation" in roadmap
    assert "Phase 2 analytics scalability" in changelog
    assert "/api/v1/usage/recalculate/{jobId}" in api
    assert "analytics_rollup_jobs" in operations
