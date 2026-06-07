from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_domains_dashboard_has_scannable_identity_status_and_actions():
    domains = (ROOT / "dash/src/views/DomainsView.vue").read_text()

    assert 'title="Domain inventory"' in domains
    assert "Search by name, domain, or ID" in domains
    assert "nameserver_status" in domains
    assert "NS unknown" in domains
    assert "ArrowUpRight" in domains
    assert "sr-only" in domains


def test_shared_table_keeps_search_sort_and_empty_state_accessible():
    table = (ROOT / "dash/src/components/ui/DataTable.vue").read_text()

    assert ":aria-label=\"`Search ${title}`\"" in table
    assert 'type="button"' in table
    assert "No matching records found." in table
    assert "sortable !== false" in table
