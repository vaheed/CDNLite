from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_mutation_services_write_before_after_audit_rows():
    audit = (ROOT / "core/app/Support/AuditLog.php").read_text()
    domains = (ROOT / "core/app/Modules/Domains/Services/DomainService.php").read_text()
    rules = (ROOT / "core/app/Modules/Proxy/Services/TrafficRulesService.php").read_text()
    settings = (ROOT / "core/app/Modules/Settings/Repositories/SettingsRepository.php").read_text()

    assert "before_json" in audit and "after_json" in audit
    assert "domain.create" in domains
    assert "domain.update" in domains
    assert "domain.delete" in domains
    for resource in ("rate_limit", "waf_rule", "redirect", "page_rule"):
        assert resource in rules
    assert "AuditLog::write" in rules
    assert "settings.update" in settings


def test_snapshot_history_routes_and_activation_pointer_are_present():
    routes = (ROOT / "core/public_index.php").read_text()
    service = (ROOT / "core/app/Modules/Proxy/Services/ConfigService.php").read_text()
    schema = (ROOT / "core/database/schema.sql").read_text()

    for route in (
        "/api/v1/config/snapshots",
        "/api/v1/config/snapshots/{version}",
        "/api/v1/config/snapshots/diff",
        "/api/v1/config/snapshots/{version}/rollback",
        "/api/v1/config/snapshots/rebuild",
    ):
        assert route in routes
    assert "active_snapshot_version" in schema
    assert "public function diff" in service
    assert "public function rollback" in service
    assert "public function rebuild" in service
    assert "private function activeSnapshot" in service
    assert "private function activateSnapshotVersion" in service
    assert "$this->activateSnapshotVersion($version)" in service


def test_snapshot_dashboard_exposes_view_diff_rollback_and_rebuild():
    view = (ROOT / "dash/src/views/ConfigSnapshotsView.vue").read_text()
    api = (ROOT / "dash/src/lib/api/configSnapshots.ts").read_text()
    router = (ROOT / "dash/src/router/index.ts").read_text()
    nav = (ROOT / "dash/src/components/layout/nav.ts").read_text()

    for label in ("View", "Rollback", "Rebuild", "Compare selected"):
        assert label in view
    for label in ("Snapshot History", "Active version", "Latest generated"):
        assert label in view
    for label in ("Before", "After", "bg-red-50", "bg-emerald-50"):
        assert label in view
    assert "HorizontalScrollFrame" in view
    for operation in ("list:", "get:", "diff:", "rollback:", "rebuild:"):
        assert operation in api
    assert "/config-snapshots" in router
    assert "/config-snapshots" in nav
