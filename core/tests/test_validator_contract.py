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
