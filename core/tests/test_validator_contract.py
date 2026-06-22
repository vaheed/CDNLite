import json
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def run_php(script: str) -> dict:
    proc = subprocess.run(["php", "-r", script], cwd=str(REPO_ROOT), capture_output=True, text=True, check=True)
    return json.loads(proc.stdout)


def test_validator_domain_and_int_range_contract():
    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$badDomain = App\Support\Validator::domain(['domain' => 'not-a-domain'], 'domain');
$okDomain = App\Support\Validator::domain(['domain' => 'Example.COM'], 'domain');
$badPort = App\Support\Validator::intRange(['origin_port' => 70000], 'origin_port', 1, 65535);
$okPort = App\Support\Validator::intRange(['origin_port' => 443], 'origin_port', 1, 65535);

echo json_encode([
  'badDomain' => $badDomain,
  'okDomain' => $okDomain,
  'badPort' => $badPort,
  'okPort' => $okPort,
], JSON_UNESCAPED_SLASHES);
'''
    out = run_php(script)

    assert out["badDomain"]["ok"] is False
    assert out["badDomain"]["error"] == "invalid_field"
    assert out["okDomain"]["ok"] is True
    assert out["okDomain"]["value"] == "example.com"

    assert out["badPort"]["ok"] is False
    assert out["badPort"]["field"] == "origin_port"
    assert out["okPort"]["ok"] is True
    assert out["okPort"]["value"] == 443


def test_dns_type_specific_content_validation_contract():
    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$aBad = App\Support\Validator::dnsRecordContent('A', 'not-ip');
$aOk = App\Support\Validator::dnsRecordContent('A', '127.0.0.1');
$aaaaBad = App\Support\Validator::dnsRecordContent('AAAA', 'not-ipv6');
$cnameBad = App\Support\Validator::dnsRecordContent('CNAME', 'bad host');
$originHostname = App\Support\Validator::originHost('origin.example.com', 'content');
$originServiceName = App\Support\Validator::originHost('origin-tls', 'content');
$originIp = App\Support\Validator::originHost('192.0.2.10', 'content');
$originBad = App\Support\Validator::originHost('bad host', 'content');

echo json_encode([
  'aBad' => $aBad,
  'aOk' => $aOk,
  'aaaaBad' => $aaaaBad,
  'cnameBad' => $cnameBad,
  'originHostname' => $originHostname,
  'originServiceName' => $originServiceName,
  'originIp' => $originIp,
  'originBad' => $originBad,
], JSON_UNESCAPED_SLASHES);
'''
    out = run_php(script)

    assert out["aBad"]["ok"] is False
    assert out["aBad"]["detail"] == "must_be_valid_ipv4"
    assert out["aOk"]["ok"] is True
    assert out["aaaaBad"]["detail"] == "must_be_valid_ipv6"
    assert out["cnameBad"]["detail"] == "must_be_valid_hostname"
    assert out["originHostname"]["value"] == "origin.example.com"
    assert out["originServiceName"]["value"] == "origin-tls"
    assert out["originIp"]["value"] == "192.0.2.10"
    assert out["originBad"]["detail"] == "must_be_valid_ip_or_hostname"
