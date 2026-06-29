from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase1_schema_defines_workload_boundaries_and_read_models():
    schema = read("core/database/schema.sql")
    migration = read("core/database/migrations/000021_phase1_reporting_foundation.sql")
    for source in (schema, migration):
        assert "CREATE TABLE IF NOT EXISTS database_workload_budgets" in source
        assert "telemetry_ingest_batches" in source
        assert "telemetry_rejected_events" in source
        assert "reporting_rollup_watermarks" in source
        assert "reporting_reconciliation_results" in source
        assert "CREATE MATERIALIZED VIEW IF NOT EXISTS reporting_current_platform_summary" in source
        assert "idx_usage_rollups_ts_brin" in source
        assert "CHECK (event_count BETWEEN 0 AND 1000)" in source
        assert "CHECK (payload_bytes BETWEEN 0 AND 1048576)" in source


def test_phase1_reporting_service_uses_budgeted_queries():
    service = read("core/app/Http/Controllers/Api/ReportController.php")
    workload = read("core/app/Support/DatabaseWorkload.php")
    assert "usage_rollups" in service
    assert "audit_log" in service
    assert "config_snapshots" in service
    assert "SET statement_timeout" in workload
    assert "SET lock_timeout" in workload
    for workload_name in ("control", "telemetry_ingest", "reporting", "jobs", "maintenance"):
        assert workload_name in workload


def test_phase1_one_shot_runner_manifest_and_stress_are_registered():
    runner = read("ci/phase.sh")
    manifest = read("ci/phases/phase-01.yml")
    stress = read("ci/stress-platform.sh")
    scenarios = read("ci/stress/scenarios.yml")
    assert "./ci/phase.sh 01 --profile full --clean" in read("docs/ROADMAP.md")
    assert "phase-01.yml" in runner
    assert "full profile requires --clean" in runner
    assert "ci/smoke.sh" in manifest
    assert "ci/e2e.sh" in manifest
    assert "phase1-reporting-foundation" in manifest
    assert "reporting_current_platform_summary" in stress
    assert "retention dry-run" in stress
    assert "phase1-reporting-foundation" in scenarios


def test_phase1_docs_and_changelog_track_in_progress_foundation():
    roadmap = read("docs/ROADMAP.md")
    changelog = read("CHANGELOG.md")
    architecture = read("docs/operations/database-architecture.md")
    assert "Database architecture and real-time reporting foundation" in roadmap
    assert "| 1. Database architecture and real-time reporting foundation | P0 | Complete |" in roadmap
    assert "Canonical file: `docs/ROADMAP.md`" in roadmap
    assert "database_workload_budgets" in architecture
    assert "telemetry_ingest_batches" in architecture
    assert "reporting_current_platform_summary" in architecture
    assert "Phase 1 closed on 2026-06-24" in read("docs/operations/phase-01-evidence.md")
    assert "Phase 1 database architecture foundation" in changelog


def test_docs_use_one_capitalized_roadmap_file():
    assert (ROOT / "docs/ROADMAP.md").exists()
    assert not (ROOT / "ROADMAP.md").exists()
    if (ROOT / "docs/roadmap.md").exists():
        assert "Canonical file: `docs/ROADMAP.md`" in read("docs/roadmap.md")
    assert not (ROOT / "docs/legacy-roadmap.md").exists()
