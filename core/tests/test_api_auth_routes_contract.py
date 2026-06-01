import json
import os
import socket
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[2]
TEST_ENV = {
    "DB_HOST": os.getenv("DB_HOST", "127.0.0.1"),
    "DB_PORT": os.getenv("DB_PORT", "5432"),
    "DB_DATABASE": os.getenv("DB_DATABASE", "cdnlite"),
    "DB_USERNAME": os.getenv("DB_USERNAME", "cdnlite"),
    "DB_PASSWORD": os.getenv("DB_PASSWORD", "cdnlite"),
}


def reset_db() -> None:
    script = r'''
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 5432);
$database = getenv('DB_DATABASE') ?: 'cdnlite';
$username = getenv('DB_USERNAME') ?: 'cdnlite';
$password = getenv('DB_PASSWORD') ?: 'cdnlite';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = ['usage_aggregates','usage_ingest_keys','usage_rollups','edge_request_nonces','edge_tokens','geo_policies','edge_dns_state','edge_nodes','dns_records','sites','config_snapshots','config_state'];
$existing = [];
foreach ($tables as $table) {
  if ($pdo->query("SELECT to_regclass('public." . $table . "')")->fetchColumn()) {
    $existing[] = '"' . str_replace('"', '""', $table) . '"';
  }
}
if (!empty($existing)) {
  $pdo->exec("TRUNCATE TABLE " . implode(', ', $existing) . " RESTART IDENTITY CASCADE");
}
if ($pdo->query("SELECT to_regclass('public.config_state')")->fetchColumn()) {
  $pdo->exec("INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING");
}
'''
    subprocess.run(["php", "-r", script], cwd=str(REPO_ROOT), capture_output=True, text=True, check=True, env={**os.environ, **TEST_ENV})


def require_db_or_skip() -> None:
    try:
        subprocess.run(
            [
                "php",
                "-r",
                "new PDO(\"pgsql:host=\" . (getenv(\"DB_HOST\") ?: \"127.0.0.1\") . \";port=\" . (getenv(\"DB_PORT\") ?: \"5432\") . \";dbname=\" . (getenv(\"DB_DATABASE\") ?: \"cdnlite\"), getenv(\"DB_USERNAME\") ?: \"cdnlite\", getenv(\"DB_PASSWORD\") ?: \"cdnlite\"); echo \"ok\";",
            ],
            cwd=str(REPO_ROOT),
            capture_output=True,
            text=True,
            check=True,
            env={**os.environ, **TEST_ENV},
        )
    except subprocess.CalledProcessError:
        pytest.skip("PostgreSQL is not reachable in this test environment")


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def wait_for_server(base_url: str, timeout: float = 5.0) -> None:
    start = time.time()
    while time.time() - start < timeout:
        try:
            urllib.request.urlopen(base_url + "/health", timeout=0.5)
            return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError("php server did not start")


def request_json(base_url: str, method: str, path: str, body: dict | None = None, headers: dict | None = None) -> tuple[int, dict]:
    data = None
    req_headers = {"Content-Type": "application/json"}
    if headers:
        req_headers.update(headers)
    if body is not None:
        data = json.dumps(body).encode("utf-8")

    req = urllib.request.Request(base_url + path, data=data, method=method, headers=req_headers)
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode("utf-8"))


def test_api_token_enforced_on_admin_routes_but_not_edge_auth_contract():
    require_db_or_skip()
    reset_db()

    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {
        **os.environ,
        **TEST_ENV,
        "APP_ENV": "development",
        "CDNLITE_API_TOKEN": "stage2-token",
    }

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)

        site_payload = {
            "name": "Auth Demo",
            "domain": "auth-demo.local",
            "origin_host": "core",
            "origin_port": 8080,
        }

        denied_code, denied = request_json(base_url, "POST", "/api/v1/sites", body=site_payload)
        assert denied_code == 401
        assert denied["error"] == "api_auth_required"

        ok_code, created = request_json(
            base_url,
            "POST",
            "/api/v1/sites",
            body=site_payload,
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert ok_code == 201
        assert created["data"]["domain"] == "auth-demo.local"
        site_id = created["data"]["id"]

        dns_code, dns_denied = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/dns/records",
            body={"type": "A", "name": "@", "content": "127.0.0.1"},
        )
        assert dns_code == 401
        assert dns_denied["error"] == "api_auth_required"

        ready_code, ready = request_json(base_url, "GET", "/ready")
        assert ready_code == 200
        assert ready["checks"]["api_token"] == "ok"
    finally:
        server.terminate()
        server.wait(timeout=5)


def test_ready_fails_in_production_without_api_token():
    require_db_or_skip()
    reset_db()

    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {
        **os.environ,
        **TEST_ENV,
        "APP_ENV": "production",
        "CDNLITE_API_TOKEN": "",
    }

    router = REPO_ROOT / "core" / "tests" / "_tmp_ready_router.php"
    router.write_text(
        "<?php\n"
        "putenv('APP_ENV=production');\n"
        "putenv('CDNLITE_API_TOKEN=');\n"
        "require __DIR__ . '/../../public_index.php';\n",
        encoding="utf-8",
    )

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", str(router)],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)
        code, body = request_json(base_url, "GET", "/ready")
        assert code == 503
        assert body["status"] == "fail"
        assert body["checks"]["api_token"] == "fail"
    finally:
        server.terminate()
        server.wait(timeout=5)
        if router.exists():
            router.unlink()
