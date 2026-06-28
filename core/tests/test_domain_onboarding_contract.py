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
    assert "enabled automatically" in wizard
    assert "<AddDomainWizard" in domains


def test_nameserver_lifecycle_is_automatic_and_scheduled():
    verification = (ROOT / "core/app/Modules/Domains/Services/DomainVerificationService.php").read_text()
    builder = (ROOT / "core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php").read_text()
    artisan = (ROOT / "core/artisan").read_text()
    compose = (ROOT / "docker-compose.yml").read_text()

    assert "$status === 'verified' ? 'active' : 'pending_nameserver'" in verification
    assert "public function verifyAll()" in verification
    assert "@dns_get_record($domain, DNS_NS)" in verification
    assert "d.nameserver_status = 'verified'" in builder
    assert "cdn:domains:verify-all" in artisan
    assert "cdn:scheduler:run" in compose
    assert "CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS" in compose


def test_pending_domains_keep_authority_zone_without_user_records():
    builder = (ROOT / "core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php").read_text()
    verification = (ROOT / "core/app/Modules/Domains/Services/DomainVerificationService.php").read_text()
    e2e = (ROOT / "ci/dns_e2e.sh").read_text()

    assert "customerZoneAuthorityRrsets" in builder
    assert "'SELECT domain FROM domains ORDER BY domain'" in builder
    assert "'customer_zone_nameservers'" in builder
    assert "AND d.nameserver_status = 'verified'" in builder
    assert "AND d.status = 'active'" in builder
    assert "UPDATE domains SET nameserver_status = :nameserver_status, status = :status" in verification
    assert "DELETE FROM dns_records" not in verification
    assert "pending domain zone should exist with authority records only" in e2e
    assert "customer rrsets remain published after nameserver delegation loss" in e2e


def test_domain_mutations_queue_powerdns_when_not_strict():
    service = (ROOT / "core/app/Modules/Domains/Services/DomainService.php").read_text()

    assert "private function reconcileDns" in service
    assert "new PowerDnsService()" in service
    assert "dns.reconcile.queued" in service
    assert "'strict' => $powerDns->isStrict()" in service
    assert "Domain mutations never publish user rrsets by themselves" in service


def test_domain_updates_audit_defined_before_and_after_states():
    service = (ROOT / "core/app/Modules/Domains/Services/DomainService.php").read_text()

    assert "AuditLog::write('domain.update', 'domain', $domainId, $domainId, $existing, $updated);" in service
    ensure_zone_ready = service.split("public function ensureZoneReady", 1)[1].split("public function update", 1)[0]
    assert "$existing" not in ensure_zone_ready


def test_core_container_uses_fresh_schema_bootstrap():
    dockerfile = (ROOT / "core/Dockerfile").read_text()
    entrypoint = (ROOT / "core/docker-entrypoint.sh").read_text()

    assert 'ENTRYPOINT ["/app/docker-entrypoint.sh"]' in dockerfile
    assert "cdn:migrate" not in entrypoint
    assert 'exec "$@"' in entrypoint
