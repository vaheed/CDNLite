import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_edge_identity_is_shared_and_has_no_local_fallback():
    identity = (ROOT / "edge/openresty/lua/identity.lua").read_text()
    metrics = (ROOT / "edge/openresty/lua/metrics.lua").read_text()
    router = (ROOT / "edge/openresty/lua/router.lua").read_text()
    nginx = (ROOT / "edge/openresty/nginx.conf").read_text()

    assert "os.getenv('EDGE_ID')" in identity
    assert "'unknown'" in identity
    assert "identity.get()" in metrics
    assert "identity.get()" in router
    assert "env EDGE_ID;" in nginx
    for path in (ROOT / "edge/openresty/lua").glob("*.lua"):
        assert "os.getenv('EDGE_ID') or 'edge-local-1'" not in path.read_text()


def test_edge_agent_rejects_empty_identity_outside_dev_mode(tmp_path):
    env = {
        **os.environ,
        "EDGE_ID": "",
        "DEV_MODE": "0",
        "EDGE_CONFIG_PATH": str(tmp_path / "config.json"),
        "METRIC_PATH": str(tmp_path / "metrics.ndjson"),
    }
    result = subprocess.run(
        ["sh", str(ROOT / "edge/agent/run.sh")],
        env=env,
        text=True,
        capture_output=True,
        timeout=5,
    )
    assert result.returncode != 0
    assert "EDGE_ID is required" in result.stderr


def test_health_service_flags_suspicious_identity():
    php = r"""
require 'core/app/Services/ControlPlane/EdgeHealthService.php';
$health = new App\Services\ControlPlane\EdgeHealthService();
echo json_encode([
  $health->identityStatus('unknown'),
  $health->identityStatus('openresty'),
  $health->identityStatus('edge-local-1'),
]);
"""
    result = subprocess.run(
        ["php", "-r", php],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=True,
    )
    assert result.stdout == '["warning","warning","ok"]'
