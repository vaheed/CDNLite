from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_domain_mutations_invalidate_config_before_reconcile():
    service = read("core/app/Modules/Domains/Services/DomainService.php")

    assert "private function invalidateConfigSnapshot" in service
    assert "UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1" in service
    assert "AuditLog::write('domain.create'" in service
    assert "AuditLog::write('domain.update'" in service
    assert "AuditLog::write('domain.delete'" in service
    assert "$this->invalidateConfigSnapshot();\n        (new DnsReconciler())->reconcile();" in service


def test_dns_and_geo_route_mutations_invalidate_config():
    dns_service = read("core/app/Modules/Dns/Services/DnsService.php")
    geo_service = read("core/app/Modules/Dns/Services/GeoRoutingService.php")
    dns_controller = read("core/app/Modules/Dns/Http/Controllers/DnsController.php")

    assert "private function invalidateConfigSnapshot" in dns_service
    assert "$this->invalidateConfigSnapshot();\n        $result = (new DnsReconciler())->reconcile();" in dns_service
    assert "AuditLog::write('dns.reconcile.failed'" in dns_service
    assert "'local_state_saved' => true" in dns_service
    assert "private function invalidateConfigSnapshot" in geo_service
    assert "AuditLog::write('dns.geo_routes.update'" in geo_service
    assert "$this->invalidateConfigSnapshot();\n        (new DnsReconciler())->reconcile();" in geo_service
    assert "private function dnsPublishFailure" in dns_controller
    assert "'error' => 'dns_publish_failed'" in dns_controller
    assert "'local_state_saved' => true" in dns_controller
    assert "'retry' => 'cdn:dns:reconcile'" in dns_controller
