from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_phase18_backend_maps_raw_activity_to_beginner_labels():
    collector = read("core/app/Modules/Collector/Services/CollectorService.php")

    assert "friendlyRequestActivity" in collector
    assert "friendlyAuditActivity" in collector
    assert "beginnerActivitySummary" in collector
    assert "'Blocked exploit attempt'" in collector
    assert "'Stopped too many login requests'" in collector
    assert "'Challenged suspicious bot'" in collector
    assert "'Origin error detected'" in collector
    assert "'SSL certificate issued'" in collector
    assert "'DNS change published'" in collector
    assert "'Cache action recorded'" in collector
    assert "'friendly' => $friendly" in collector
    assert "'beginner' => $this->beginnerActivitySummary" in collector
    assert "'details' => $details" in collector
    assert "'details' => $request" in collector


def test_phase18_dashboard_preserves_advanced_activity_and_adds_simple_view():
    activity = read("dash/src/views/domain-tabs/DomainActivityTab.vue")
    types = read("dash/src/types.ts")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "Simple view" in activity
    assert "Advanced view" in activity
    assert "Beginner Activity summary" in activity
    assert "Readable Activity cards" in activity
    assert "Request-id lookup" in activity
    assert "Export JSON" in activity
    assert "selected=request" in activity
    assert "JSON.stringify(selected, null, 2)" in activity
    assert "summary.value?.beginner?.cards" in activity
    assert "item.friendly?.label" in activity
    assert "export interface ActivityFriendly" in types
    assert "export interface BeginnerActivitySummary" in types
    assert "friendly?: ActivityFriendly" in types
    assert "beginner?: BeginnerActivitySummary" in types
    assert "friendly.category" in docs
    assert "summary.beginner.recommendations" in docs
    assert "friendly labels" in openapi


def test_phase18_smoke_and_e2e_cover_beginner_activity():
    smoke = read("ci/smoke.sh")
    e2e = read("ci/e2e.sh")

    assert "dashboard-beginner-activity-bundle" in smoke
    assert "Simple view" in smoke
    assert "Beginner Activity summary" in smoke
    assert "phase18-beginner-activity" in e2e
    assert "\"friendly\":" in e2e
    assert '"beginner":' in e2e
    assert '"Blocked exploit attempt"' in e2e
    assert '"Origin error detected"' in e2e
