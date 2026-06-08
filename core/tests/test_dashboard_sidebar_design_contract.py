from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_desktop_sidebar_active_item_uses_background_without_left_border():
    sidebar = (ROOT / "dash/src/components/layout/Sidebar.vue").read_text()

    assert "border-l-2" not in sidebar
    assert "!border-cyan" not in sidebar
    assert 'active-class="!bg-cyan-50 !text-cyan-800 dark:!bg-cyan-400/10 dark:!text-cyan-200"' in sidebar
