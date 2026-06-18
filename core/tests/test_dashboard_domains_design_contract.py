from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_domains_dashboard_has_scannable_identity_status_and_actions():
    domains = (ROOT / "dash/src/views/DomainsView.vue").read_text()

    assert 'title="Domain inventory"' in domains
    assert "Search by name, domain, or ID" in domains
    assert "nameserver_status" in domains
    assert "compact :status" in domains
    assert "lifecycleLabel" in domains
    assert "nameserverSeverity" in domains
    assert "NS status" in domains
    assert "ArrowUpRight" in domains
    assert "sr-only" in domains


def test_domain_delete_requires_exact_domain_confirmation():
    domains = (ROOT / "dash/src/views/DomainsView.vue").read_text()

    assert "pendingDelete" in domains
    assert "deleteConfirmation" in domains
    assert "Type <b class=\"font-mono\">{{ pendingDelete.domain }}</b> to confirm deletion." in domains
    assert "deleteConfirmation.value.trim()===pendingDelete.value.domain" in domains
    assert ":disabled=\"!canConfirmDelete || deletingDomain\"" in domains
    assert "window.confirm" not in domains
    assert "ConfirmDangerButton" not in domains


def test_shared_table_keeps_search_sort_and_empty_state_accessible():
    table = (ROOT / "dash/src/components/ui/DataTable.vue").read_text()
    scroll_frame = (ROOT / "dash/src/components/ui/HorizontalScrollFrame.vue").read_text()

    assert ":aria-label=\"`Search ${title}`\"" in table
    assert 'type="button"' in table
    assert "No matching records found." in table
    assert "sortable !== false" in table
    assert "HorizontalScrollFrame" in table
    assert "canScrollLeft" in scroll_frame
    assert "canScrollRight" in scroll_frame
    assert "scrollBy({ left:" in scroll_frame
