import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_renewal_eligibility_logic():
    php = r"""
require 'core/app/Support/bootstrap.php';
use App\Modules\Proxy\Services\CertRenewalService;
$now = 1700000000;
echo json_encode([
  CertRenewalService::isDue(['provider'=>'acme','status'=>'active','renewal_due_at'=>$now-1], $now),
  CertRenewalService::isDue(['provider'=>'acme','status'=>'active','renewal_due_at'=>$now+91*86400], $now),
  CertRenewalService::isDue(['provider'=>'manual','status'=>'active','renewal_due_at'=>$now-1], $now),
  CertRenewalService::isDue(['provider'=>'acme','status'=>'revoked','renewal_due_at'=>$now-1], $now),
]);
"""
    result = subprocess.run(["php", "-r", php], cwd=ROOT, text=True, capture_output=True, check=True)
    assert json.loads(result.stdout) == [True, False, False, False]


def test_phase18_ssl_automation_contract():
    service = (ROOT / "core/app/Modules/Proxy/Services/CertRenewalService.php").read_text()
    routes = (ROOT / "core/public_index.php").read_text()
    artisan = (ROOT / "core/artisan").read_text()
    schema = (ROOT / "core/database/schema.sql").read_text()
    readiness = (ROOT / "core/app/Modules/Health/Services/ReadinessService.php").read_text()

    for route in ("/ssl/request-cert", "/ssl/renew", "/ssl/acme-status"):
        assert route in routes
    assert "cdn:ssl:renew-due" in artisan
    assert "ssl_renewal_history" in schema
    assert "auto_renew" in schema
    assert "status=:certificate_status" in service
    assert "'ssl_expiry'" in readiness


def test_dashboard_ssl_automation_controls():
    tab = (ROOT / "dash/src/views/domain-tabs/DomainSslTab.vue").read_text()
    api = (ROOT / "dash/src/lib/api/ssl.ts").read_text()

    for label in ("Auto-renew", "Request Certificate", "Force Renew", "ACME challenge status", "Renewal history"):
        assert label in tab
    assert "/ssl/request-cert" in api
    assert "/ssl/renew" in api
    assert "/ssl/acme-status" in api
