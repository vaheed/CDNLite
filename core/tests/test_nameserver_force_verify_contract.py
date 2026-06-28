from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_nameserver_verify_returns_trace_payload():
    service = read("core/app/Services/ControlPlane/DomainNameserverVerifier.php")
    controller = read("core/app/Http/Controllers/Api/DomainController.php")
    routes = read("core/routes/api.php")

    for field in [
        "expected_nameservers",
        "observed_nameservers",
        "matched_nameservers",
        "missing_nameservers",
        "checked_at",
        "resolver_errors",
    ]:
        assert field in service

    assert "public function verify" in service
    assert "$result['verification']" in controller
    assert "array_merge($domain, $result['verification'])" in controller
    assert "/domains/{domainId}/nameservers/verify" in routes
    assert "verify-nameservers" not in routes


def test_force_verify_requires_admin_session_and_audits_reason():
    service = read("core/app/Services/ControlPlane/DomainNameserverVerifier.php")
    controller = read("core/app/Http/Controllers/Api/DomainController.php")
    routes = read("core/routes/api.php")

    assert "/domains/{domainId}/nameservers/force-verify" in routes
    assert "admin.auth" in routes
    assert "forceVerifyNameservers" in controller
    assert "'reason' => ['required', 'string', 'max:1000']" in controller
    assert "domain.nameserver.force_verify" in service
    assert "forced_verified" in service
    assert "afterDomainMutation($domainId, 'domain.verification.changed')" in service


def test_reseed_expected_nameservers_is_admin_only_and_audited():
    service = read("core/app/Services/ControlPlane/DomainNameserverVerifier.php")
    controller = read("core/app/Http/Controllers/Api/DomainController.php")
    routes = read("core/routes/api.php")
    dashboard = read("dash/src/views/DomainDetailView.vue")
    api = read("dash/src/lib/api/domains.ts")
    docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "/domains/{domainId}/nameservers/reseed-expected" in routes
    assert "admin.auth" in routes
    assert "reseedExpectedNameservers" in controller
    assert "platform.nameservers" in service
    assert "where('domain_id', $domainId)->delete()" in service
    assert "domain.nameserver.reseed_expected" in service
    assert "reseeded_expected" in service
    assert "afterDomainMutation($domainId, 'domain.verification.changed')" in service

    assert "reseedExpectedNameservers" in api
    assert "Re-seed expected NS" in dashboard
    assert "nameservers/reseed-expected" in docs
    assert "/api/v1/domains/{domainId}/nameservers/reseed-expected" in openapi
