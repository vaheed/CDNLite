import json
import os
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
TEST_ENV = {
    "DB_HOST": os.getenv("DB_HOST", "127.0.0.1"),
    "DB_PORT": os.getenv("DB_PORT", "5432"),
    "DB_DATABASE": os.getenv("DB_DATABASE", "cdnlite"),
    "DB_USERNAME": os.getenv("DB_USERNAME", "cdnlite"),
    "DB_PASSWORD": os.getenv("DB_PASSWORD", "cdnlite"),
}


def run_php(script: str, extra_env: dict | None = None) -> dict:
    env = {**os.environ, **TEST_ENV}
    if extra_env:
        env.update(extra_env)
    proc = subprocess.run(
        ["php", "-r", script],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env=env,
    )
    return json.loads(proc.stdout)


def test_api_auth_class_contract():
    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

putenv('CDNLITE_API_TOKEN=');
$open = App\Support\ApiAuth::isValid('');
putenv('CDNLITE_API_TOKEN=secret-token');
$deny = App\Support\ApiAuth::isValid('wrong');
$allow = App\Support\ApiAuth::isValid('secret-token');

putenv('APP_ENV=production');
putenv('CDNLITE_API_TOKEN=');
$prod_fail = App\Support\ApiAuth::productionMissingToken();
putenv('CDNLITE_API_TOKEN=secret-token');
$prod_ok = App\Support\ApiAuth::productionMissingToken();

echo json_encode([
  'open' => $open,
  'deny' => $deny,
  'allow' => $allow,
  'prod_fail' => $prod_fail,
  'prod_ok' => $prod_ok,
], JSON_UNESCAPED_SLASHES);
'''
    out = run_php(script)

    assert out["open"] is True
    assert out["deny"] is False
    assert out["allow"] is True
    assert out["prod_fail"] is True
    assert out["prod_ok"] is False
