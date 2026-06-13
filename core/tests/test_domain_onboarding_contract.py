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
    assert "nameserver-scheduler:" in compose
    assert "CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS" in compose


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
