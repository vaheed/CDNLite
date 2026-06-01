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


def test_route_validation_returns_invalid_field_for_new_guards():
    require_db_or_skip()

    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {**os.environ, **TEST_ENV, "APP_ENV": "development", "CDNLITE_API_TOKEN": "stage2-token"}

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)

        site_code, site = request_json(
            base_url,
            "POST",
            "/api/v1/sites",
            body={"name": "Val Demo", "domain": "val-demo.local", "origin_host": "core"},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert site_code == 201
        site_id = site["data"]["id"]

        dns_code, dns_body = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/dns/records",
            body={"type": "A", "name": "@", "content": "not-an-ip"},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert dns_code == 422
        assert dns_body["error"] == "invalid_field"
        assert dns_body["field"] == "content"

        redirect_code, redirect_body = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/redirects",
            body={"source_path": "no-slash", "target_url": "https://example.com", "status_code": 302},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert redirect_code == 422
        assert redirect_body["error"] == "invalid_field"
        assert redirect_body["field"] == "source_path"

        cache_code, cache_body = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/cache-rules",
            body={"path_prefix": "/assets", "ttl_seconds": 0},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert cache_code == 422
        assert cache_body["error"] == "invalid_field"
        assert cache_body["field"] == "ttl_seconds"

        cache_settings_code, cache_settings_body = request_json(
            base_url,
            "PUT",
            f"/api/v1/sites/{site_id}/cache/settings",
            body={"default_edge_ttl_seconds": 0},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert cache_settings_code == 422
        assert cache_settings_body["error"] == "invalid_field"
        assert cache_settings_body["field"] == "default_edge_ttl_seconds"

        purge_code, purge_body = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/cache/purge",
            body={"type": "prefix"},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert purge_code == 422
        assert purge_body["error"] == "invalid_field"
        assert purge_body["field"] == "value"

        ssl_check_code, ssl_check_body = request_json(
            base_url,
            "POST",
            f"/api/v1/sites/{site_id}/ssl/check",
            body={"hostnames": [""]},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert ssl_check_code == 422
        assert ssl_check_body["error"] == "invalid_field"
        assert ssl_check_body["field"] == "hostnames"
    finally:
        server.terminate()
        server.wait(timeout=5)
