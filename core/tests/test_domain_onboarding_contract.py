from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_domain_onboarding_schema_and_routes():
    schema = (ROOT / "core/database/schema.sql").read_text()
    routes = (ROOT / "core/routes/api.php").read_text()
    service = (ROOT / "core/app/Services/ControlPlane/DomainLifecycleService.php").read_text()

    assert "CREATE TABLE IF NOT EXISTS domain_nameservers" in schema
    assert "nameserver_status TEXT NOT NULL DEFAULT 'unknown'" in schema
    assert "nameservers/verify" in routes
    assert "verify-nameservers" not in routes
    assert "/activate" in routes
    assert "'pending_nameserver'" in service
    assert "syncPowerDnsZoneCreate" not in service
    assert "afterDomainMutation" in service
    assert "queueForDomain" in service


def test_dashboard_has_four_step_onboarding_wizard():
    wizard = (ROOT / "dash/src/components/domains/AddDomainWizard.vue").read_text()
    domains = (ROOT / "dash/src/views/DomainsView.vue").read_text()

    assert "Step {{ step }} of 4" in wizard
    assert "Copy nameserver" in wizard
    assert "Check nameservers" in wizard
    assert "enabled automatically" in wizard
    assert "<AddDomainWizard" in domains


def test_nameserver_lifecycle_is_automatic_and_scheduled():
    verification = (ROOT / "core/app/Services/ControlPlane/DomainNameserverVerifier.php").read_text()
    builder = (ROOT / "core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php").read_text()

    assert "$status === 'verified' ? 'active' : 'pending_nameserver'" in verification
    assert "@dns_get_record($domain, DNS_NS)" in verification
    assert "d.nameserver_status = 'verified'" in builder


def test_pending_domains_keep_authority_zone_without_user_records():
    builder = (ROOT / "core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php").read_text()
    verification = (ROOT / "core/app/Services/ControlPlane/DomainNameserverVerifier.php").read_text()
    e2e = (ROOT / "ci/dns_e2e.sh").read_text()

    assert "customerZoneAuthorityRrsets" in builder
    assert "'SELECT domain FROM domains ORDER BY domain'" in builder
    assert "'customer_zone_nameservers'" in builder
    assert "AND d.nameserver_status = 'verified'" in builder
    assert "AND d.status = 'active'" in builder
    assert "'nameserver_status' => $status" in verification
    assert "DELETE FROM dns_records" not in verification
    assert "pending domain zone should exist with authority records only" in e2e
    assert "customer rrsets remain published after nameserver delegation loss" in e2e


def test_domain_mutations_queue_powerdns_when_not_strict():
    service = (ROOT / "core/app/Services/ControlPlane/DnsReconcileQueue.php").read_text()

    assert "public function queueForDomain" in service
    assert "dns.reconcile.queued" in service
    assert "'strict' => $this->powerDnsStrict()" in service


def test_domain_updates_audit_defined_before_and_after_states():
    service = (ROOT / "core/app/Services/ControlPlane/DomainLifecycleService.php").read_text()

    assert "$this->audit->write('domain.update', 'domain', $domainId, $existing, $updated" in service


def test_core_container_uses_fresh_schema_bootstrap():
    dockerfile = (ROOT / "core/Dockerfile").read_text()
    entrypoint = (ROOT / "core/docker-entrypoint.sh").read_text()

    assert 'ENTRYPOINT ["/app/docker-entrypoint.sh"]' in dockerfile
    assert "cdn:migrate" not in entrypoint
    assert 'exec "$@"' in entrypoint
