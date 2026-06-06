from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_domain_onboarding_schema_and_routes():
    schema = (ROOT / "core/database/schema.sql").read_text()
    routes = (ROOT / "core/public_index.php").read_text()
    service = (ROOT / "core/app/Modules/Domains/Services/DomainService.php").read_text()

    assert "CREATE TABLE IF NOT EXISTS domain_nameservers" in schema
    assert "nameserver_status TEXT NOT NULL DEFAULT 'unknown'" in schema
    assert "/verify-nameservers" in routes
    assert "/activate" in routes
    assert "'pending_nameserver'" in service
    assert "syncPowerDnsZoneCreate" not in service
    assert "function ensureZoneReady" in service


def test_dashboard_has_four_step_onboarding_wizard():
    wizard = (ROOT / "dash/src/components/domains/AddDomainWizard.vue").read_text()
    domains = (ROOT / "dash/src/views/DomainsView.vue").read_text()

    assert "Step {{ step }} of 4" in wizard
    assert "Copy nameserver" in wizard
    assert "Check nameservers" in wizard
    assert "Skip for dev" in wizard
    assert "<AddDomainWizard" in domains

