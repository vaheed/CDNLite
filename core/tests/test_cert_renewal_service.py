from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_renewal_eligibility_logic():
    service = (ROOT / "core/app/Services/ControlPlane/SslRenewalService.php").read_text()

    assert "where('c.provider', 'acme')" in service
    assert "where('s.auto_renew', true)" in service
    assert "renewal_due_at" in service
    assert "where('c.status', '<>', 'revoked')" in service


def test_phase18_ssl_automation_contract():
    service = (ROOT / "core/app/Modules/Proxy/Services/CertRenewalService.php").read_text()
    routes = (ROOT / "core/routes/api.php").read_text()
    console = (ROOT / "core/routes/console.php").read_text()
    schema = (ROOT / "core/database/schema.sql").read_text()
    readiness = (ROOT / "core/app/Modules/Health/Services/ReadinessService.php").read_text()

    for route in ("/ssl/request", "/ssl/renew", "/ssl/acme-status"):
        assert route in routes
    assert "cdn:ssl:renew-due" in console
    assert "ssl_renewal_history" in schema
    assert "auto_renew" in schema
    assert "status=:certificate_status" in service
    assert "'ssl_expiry'" in readiness


def test_dashboard_ssl_automation_controls():
    tab = (ROOT / "dash/src/views/domain-tabs/DomainSslTab.vue").read_text()
    api = (ROOT / "dash/src/lib/api/ssl.ts").read_text()

    for label in ("Auto-renew", "Request Certificate", "Force Renew", "ACME challenge status", "Renewal history"):
        assert label in tab
    assert "/ssl/request" in api
    assert "/ssl/request-cert" not in api
    assert "/ssl/acme/issue" not in api
    assert "/ssl/renew" in api
    assert "/ssl/acme-status" in api
