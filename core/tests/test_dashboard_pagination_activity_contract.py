from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_shared_tables_have_page_size_pagination():
    table = read("dash/src/components/ui/DataTable.vue")
    controls = read("dash/src/components/ui/PaginationControls.vue")

    assert "<PaginationControls" in table
    assert "currentPageSize" in table
    assert "[10, 25, 50, 100]" in controls
    assert "Page {{ page }} of {{ pageCount }}" in controls


def test_operations_pages_use_consistent_pagination_and_filters():
    for path in (
        "dash/src/views/EventViewerView.vue",
        "dash/src/views/JobQueueView.vue",
        "dash/src/views/SecurityEventsView.vue",
        "dash/src/views/AuditLogView.vue",
    ):
        view = read(path)
        assert "PaginationControls" in view

    events = read("dash/src/views/EventViewerView.vue")
    assert "operationsApi.events" in events
    assert "securityEventsApi.list" not in events
    assert "auditLogApi.list" not in events
    assert "loadSecurityEventsForDomains" not in events
    assert "From" in events and "To" in events

    jobs = read("dash/src/views/JobQueueView.vue")
    assert "operationsApi.jobs" in jobs
    assert "Status" in jobs and "Domain" in jobs


def test_domain_activity_has_filtered_independent_streams():
    detail = read("dash/src/views/DomainDetailView.vue")
    activity = read("dash/src/views/domain-tabs/DomainActivityTab.vue")

    assert "key: 'activity'" in detail
    assert activity.count("<PaginationControls") == 4
    assert "timelineOffset" in activity
    assert "requestsOffset" in activity
    assert "securityEventsApi.list({ domain_id: props.domainId" in activity
    assert "auditLogApi.list({ domain_id: props.domainId" in activity
    assert "type: typeFilter.value" in activity
    assert "showAuditSections" in activity
    assert "Search details" in activity
