from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_proxied_dns_empty_geo_routes_do_not_create_post_write_error():
    controller = read("core/app/Modules/Dns/Http/Controllers/DnsController.php")
    assert "private function hasExplicitGeoRoutes" in controller
    assert "&& $this->hasExplicitGeoRoutes($geoRoutes)" in controller
    assert "if (!empty($record['proxied'])) {\n                    return ['error' => 'proxy_and_geodns_are_mutually_exclusive'" not in controller


def test_initial_managed_ssl_uses_ephemeral_powerdns_challenges_without_bootstrap_rows():
    domains = read("core/app/Modules/Domains/Services/DomainService.php")
    verification = read("core/app/Modules/Domains/Services/DomainVerificationService.php")
    traffic = read("core/app/Services/ControlPlane/TrafficRulesService.php")
    renewal = read("core/app/Modules/Proxy/Services/CertRenewalService.php")
    issuer = read("core/app/Modules/Proxy/Services/AcmeIssuerService.php")
    schema = read("core/database/schema.sql")
    desired = read("core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php")

    assert "enqueueInitialManagedSsl($id)" not in domains
    assert "queueManagedWildcardSsl($domainId)" in verification
    assert "nameserver_status" in traffic
    assert "(string) ($domain['nameserver_status'] ?? '') !== 'verified'" in traffic
    assert "(string) ($domain['nameserver_status'] ?? '') !== 'verified'" in issuer
    assert "createTemporarySslBootstrapApexIfNeeded" not in renewal
    assert "deleteTemporarySslBootstrapApex" not in renewal
    assert "hasActiveApexRecord" not in traffic
    assert "managed_by='ssl_bootstrap'" not in traffic
    assert "'ssl_bootstrap','disabled'" not in traffic
    assert "managed_by TEXT NULL" in schema
    assert "WHERE managed_by = 'ssl_bootstrap'" not in schema
    assert "WHERE r.status = 'active'" in desired


def test_initial_ssl_reuses_existing_active_job():
    traffic = read("core/app/Services/ControlPlane/TrafficRulesService.php")
    assert "hasActiveManagedCertificate($domainId, $hostnames) || $this->hasActiveSslJob($domainId, $hostnames)" in traffic
