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


def insert_usage_rows(domain_id: str) -> None:
    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';
$pdo = App\Support\Database::pdo();
$now = time();
$domainId = getenv('DOMAIN_ID');
foreach ([
    ['cache_status' => 'HIT', 'requests_count' => 7, 'bytes_out' => 70],
    ['cache_status' => 'BYPASS', 'requests_count' => 3, 'bytes_out' => 30],
] as $index => $row) {
    $pdo->prepare(
        'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status, cache_status)
         VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status, :cache_status)'
    )->execute([
        ':id' => App\Support\Uuid::v4(),
        ':ts' => $now + $index,
        ':domain_id' => $domainId,
        ':edge_node_id' => 'edge-local-1',
        ':requests_count' => $row['requests_count'],
        ':bytes_in' => 10,
        ':bytes_out' => $row['bytes_out'],
        ':status' => 200,
        ':cache_status' => $row['cache_status'],
    ]);
}
'''
    subprocess.run(
        ["php", "-r", script],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, **TEST_ENV, "DOMAIN_ID": domain_id},
    )


def test_cache_analytics_api_returns_cache_status_rows():
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

        domain_code, domain = request_json(
            base_url,
            "POST",
            "/api/v1/domains",
            body={"name": "Analytics Demo", "domain": "analytics-demo.local", "origin_host": "core"},
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert domain_code == 201
        domain_id = domain["data"]["id"]
        insert_usage_rows(domain_id)

        code, payload = request_json(
            base_url,
            "GET",
            f"/api/v1/analytics/cache?domain_id={domain_id}",
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert code == 200
        rows = {row["cache_status"]: row for row in payload["data"]["rows"]}
        assert rows["HIT"]["count"] == 7
        assert rows["BYPASS"]["count"] == 3
        assert payload["data"]["hit"] == 7
        assert payload["data"]["bypass"] == 3
        assert payload["data"]["hit_ratio"] == 1.0

        scoped_code, scoped_payload = request_json(
            base_url,
            "GET",
            f"/api/v1/domains/{domain_id}/analytics/cache",
            headers={"Authorization": "Bearer stage2-token"},
        )
        assert scoped_code == 200
        assert scoped_payload["data"]["rows"] == payload["data"]["rows"]
    finally:
        server.terminate()
        server.wait(timeout=5)
