import json
import os
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DB_PATH = Path("/tmp/cdnlite-test-edge-auth.sqlite")
TEST_ENV = {
    "DB_DRIVER": "sqlite",
    "DB_DATABASE": str(DB_PATH),
}


def reset_db() -> None:
    if DB_PATH.exists():
        DB_PATH.unlink()


def run_php(script: str) -> dict:
    proc = subprocess.run(
        ["php", "-r", script],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, **TEST_ENV},
    )
    return json.loads(proc.stdout)


def test_edge_auth_rejects_missing_token_and_replay_nonce():
    reset_db()

    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$edge = new App\Modules\Edge\Services\EdgeService();
$auth = new App\Modules\Edge\Services\EdgeAuthService();
$edge->registerToken('edge-auth-1', 'edge-token-1');

$method = 'POST';
$path = '/api/v1/edge/heartbeat';
$body = '{"edge_id":"edge-auth-1"}';
$ts = time();
$nonce = 'nonce-1';
$canonical = $method . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
$signature = hash_hmac('sha256', $canonical, hash('sha256', 'edge-token-1'));

$missing = $auth->authenticate('', '', $ts, '', $method, $path, $body, '');
$first = $auth->authenticate('edge-auth-1', 'edge-token-1', $ts, $nonce, $method, $path, $body, $signature);
$replay = $auth->authenticate('edge-auth-1', 'edge-token-1', $ts, $nonce, $method, $path, $body, $signature);

echo json_encode([
  'missing' => $missing,
  'first' => $first,
  'replay' => $replay,
], JSON_UNESCAPED_SLASHES);
'''

    out = run_php(script)

    assert out["missing"]["ok"] is False
    assert out["missing"]["error"] == "edge_auth_required"
    assert out["missing"]["status"] == 401

    assert out["first"]["ok"] is True

    assert out["replay"]["ok"] is False
    assert out["replay"]["error"] == "edge_auth_replay_detected"
    assert out["replay"]["status"] == 409
