from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_nameserver_verify_returns_trace_payload():
    service = read("core/app/Modules/Domains/Services/DomainVerificationService.php")
    controller = read("core/app/Modules/Domains/Http/Controllers/DomainController.php")
    routes = read("core/public_index.php")

    for field in [
        "expected_nameservers",
        "observed_nameservers",
        "matched_nameservers",
        "missing_nameservers",
        "checked_at",
        "resolver_errors",
    ]:
        assert field in service

    assert "verifyWithTrace" in service
    assert "$result['verification']" in controller
    assert "array_merge((array) $result['domain'], (array) $result['verification'])" in controller
    assert "/api/v1/domains/{domainId}/nameservers/verify" in routes
    assert "/api/v1/domains/{domainId}/verify-nameservers" in routes


def test_force_verify_requires_admin_session_and_audits_reason():
    service = read("core/app/Modules/Domains/Services/DomainVerificationService.php")
    controller = read("core/app/Modules/Domains/Http/Controllers/DomainController.php")
    routes = read("core/public_index.php")

    assert "/api/v1/domains/{domainId}/nameservers/force-verify" in routes
    assert "$adminAuth->userForToken(bearerToken())" in routes
    assert "admin_session_required" in routes
    assert "forceVerifyNameservers" in controller
    assert "Validator::requiredString($input, 'reason'" in controller
    assert "domain.nameserver.force_verify" in service
    assert "forced_verified" in service
    assert "UPDATE config_state SET active_snapshot_version = NULL" in service
    assert "(new DnsReconciler())->reconcile()" in service


def test_reseed_expected_nameservers_is_admin_only_and_audited():
    service = read("core/app/Modules/Domains/Services/DomainVerificationService.php")
    controller = read("core/app/Modules/Domains/Http/Controllers/DomainController.php")
    routes = read("core/public_index.php")
    dashboard = read("dash/src/views/DomainDetailView.vue")
    api = read("dash/src/lib/api/domains.ts")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "/api/v1/domains/{domainId}/nameservers/reseed-expected" in routes
    assert "$adminAuth->userForToken(bearerToken())" in routes
    assert "admin_session_required" in routes
    assert "reseedExpectedNameservers" in controller
    assert "SettingsRepository" in service
    assert "platform.nameservers" in service
    assert "DELETE FROM domain_nameservers WHERE domain_id" in service
    assert "domain.nameserver.reseed_expected" in service
    assert "reseeded_expected" in service
    assert "UPDATE config_state SET active_snapshot_version = NULL" in service
    assert "(new DnsReconciler())->reconcile()" in service

    assert "reseedExpectedNameservers" in api
    assert "Re-seed expected NS" in dashboard
    assert "nameservers/reseed-expected" in docs
    assert "/api/v1/domains/{domainId}/nameservers/reseed-expected" in openapi
