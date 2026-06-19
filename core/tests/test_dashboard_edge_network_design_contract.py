from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_edge_network_dashboard_has_operational_sections_and_states():
    view = (ROOT / "dash/src/views/EdgeNetworkView.vue").read_text()

    assert 'title="Nodes"' in view
    assert "Search nodes, regions, or IPs" in view
    assert ">Pools<" in view
    assert ">Platform DNS<" in view
    assert ">Network health<" in view
    assert "Config snapshot status" in view
    assert "No pools configured" in view
    assert "No DNS records generated" in view
    assert "Not synced" in view
    assert "Up to date" in view
    assert "visibleDnsRecords" in view
    assert "Show less" in view
    assert "Show all ${dns.records.length} records" in view
    assert ':aria-expanded="showAllDnsRecords"' in view
    assert "radius: ['58%', '78%']" in view


def test_edge_network_keeps_existing_api_calls_and_export():
    view = (ROOT / "dash/src/views/EdgeNetworkView.vue").read_text()

    assert "edgesApi.list()" in view
    assert "edgesApi.pools()" in view
    assert "edgesApi.dns()" in view
    assert "configSnapshotsApi.list()" in view
    assert "<ReportExportButton" in view
