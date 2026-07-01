from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_domain_mutations_invalidate_config_before_reconcile():
    service = read("core/app/Modules/Domains/Services/DomainService.php")

    assert "private function invalidateConfigSnapshot" in service
    assert "ConfigService::markDirty('domain.changed')" in service
    assert "AuditLog::write('domain.create'" in service
    assert "AuditLog::write('domain.update'" in service
    assert "AuditLog::write('domain.delete'" in service
    assert "$this->invalidateConfigSnapshot();\n        $this->reconcileDns(" in service
    assert "private function reconcileDns" in service
    assert "(new DnsReconciler())->reconcile();" in service


def test_dns_and_geo_route_mutations_invalidate_config():
    dns_service = read("core/app/Modules/Dns/Services/DnsService.php")
    geo_service = read("core/app/Modules/Dns/Services/GeoRoutingService.php")
    dns_controller = read("core/app/Http/Controllers/Api/DomainController.php")
    laravel_dns_service = read("core/app/Services/ControlPlane/DnsRecordService.php")
    routes = read("core/routes/api.php")
    api_docs = read("docs/api/api.md")
    openapi = read("docs/public/api/openapi.yaml")

    assert "private function invalidateConfigSnapshot" in dns_service
    assert "ConfigService::markDirty('dns.changed')" in dns_service
    assert "DnsReconciler" in dns_service
    assert "for ($attempt = 1; $attempt <= 3; $attempt++)" in dns_service
    assert "dns_reconciler_busy" in dns_service
    assert "usleep(250000 * $attempt)" in dns_service
    assert "AuditLog::write('dns.reconcile.failed'" in dns_service
    assert "'local_state_saved' => true" in dns_service
    assert "private function invalidateConfigSnapshot" in geo_service
    assert "ConfigService::markDirty('dns.geo_routes.changed')" in geo_service
    assert "AuditLog::write('dns.geo_routes.update'" in geo_service
    assert "$this->invalidateConfigSnapshot();\n        (new DnsReconciler())->reconcile();" in geo_service
    assert "private function dnsError" in dns_controller
    assert "$this->dnsReconcile->queueForDomain($domainId)" in laravel_dns_service
    assert "$this->config->markDirty('dns.record.changed')" in laravel_dns_service
    assert "/domains/{domainId}/dns/records/{recordId}/reconcile" in routes
    assert "reconcileDnsRecord(Request $request, string $domainId, string $recordId" in dns_controller
    assert "/domains/{domainId}/dns/records/{recordId}/reconcile" in api_docs
    assert "/domains/{domainId}/dns/records/{recordId}/reconcile:" in openapi
