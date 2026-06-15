from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_top_status_bar_groups_glanceable_health_and_actions():
    bar = (ROOT / "dash/src/components/layout/TopStatusBar.vue").read_text()

    for label in ["Core", "Core", "Edge", "Admin session active"]:
        assert label in bar
    for icon in ["Clock3", "RefreshCw", "Moon", "Command", "LogOut"]:
        assert icon in bar
    assert 'aria-label="Dashboard actions"' in bar
    assert "animate-spin" in bar


def test_status_actions_have_hover_focus_active_and_logout_states():
    styles = (ROOT / "dash/src/styles.css").read_text()

    assert ".status-action {" in styles
    assert "active:translate-y-px" in styles
    assert "focus:ring-4" in styles
    assert ".status-action-logout {" in styles
