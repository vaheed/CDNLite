import json
import os
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid
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


def test_security_events_endpoint_contract():
    require_db_or_skip()

    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {**os.environ, **TEST_ENV, "APP_ENV": "development", "CDNLITE_API_TOKEN": "stage9-token"}

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)
        domain_name = f"sec-{uuid.uuid4().hex}.test.local"
        domain_code, domain = request_json(
            base_url,
            "POST",
            "/api/v1/domains",
            body={"name": "Sec Demo", "domain": domain_name, "origin_host": "core"},
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert domain_code == 201
        domain_id = domain["data"]["id"]

        insert = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';
$pdo = App\Support\Database::pdo();
$now = time();
$domainId = $argv[1];
$q = $pdo->prepare('INSERT INTO audit_log (id, actor_type, actor_id, action, resource_type, resource_id, domain_id, details_json, event, created_at) VALUES (:id,:actor_type,:actor_id,:action,:resource_type,:resource_id,:domain_id,:details_json,:event,:created_at)');
$q->execute([':id'=>App\Support\Uuid::v4(),':actor_type'=>'system',':actor_id'=>'edge-1',':action'=>'inspect',':resource_type'=>'waf',':resource_id'=>'r1',':domain_id'=>$domainId,':details_json'=>'{"decision":"block"}',':event'=>'waf_match',':created_at'=>$now-1]);
$q->execute([':id'=>App\Support\Uuid::v4(),':actor_type'=>'system',':actor_id'=>'edge-1',':action'=>'inspect',':resource_type'=>'rate_limit',':resource_id'=>'r2',':domain_id'=>$domainId,':details_json'=>'{"decision":"block"}',':event'=>'rate_limited',':created_at'=>$now]);
echo "ok";
'''
        subprocess.run(["php", "-r", insert, domain_id], cwd=str(REPO_ROOT), check=True, capture_output=True, text=True, env=env)

        code, body = request_json(
            base_url,
            "GET",
            f"/api/v1/domains/{domain_id}/security/events?limit=1",
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert code == 200
        assert len(body["data"]) == 1
        assert body["data"][0]["type"] in {"waf_match", "rate_limited"}

        code2, body2 = request_json(
            base_url,
            "GET",
            f"/api/v1/domains/{domain_id}/security/events?type=waf_match",
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert code2 == 200
        assert len(body2["data"]) >= 1
        assert all(item["type"] == "waf_match" for item in body2["data"])

        global_code, global_body = request_json(
            base_url,
            "GET",
            f"/api/v1/security/events?domain_id={domain_id}&limit=10",
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert global_code == 200
        assert global_body["data"]["total"] >= 2
        assert all(item["domain_id"] == domain_id for item in global_body["data"]["items"])

        summary_code, summary = request_json(
            base_url,
            "GET",
            "/api/v1/security/summary",
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert summary_code == 200
        assert summary["data"]["by_type"]["waf_match"] >= 1
        assert summary["data"]["by_type"]["rate_limited"] >= 1

        audit_code, audit = request_json(
            base_url,
            "GET",
            f"/api/v1/audit?domain_id={domain_id}&limit=10",
            headers={"Authorization": "Bearer stage9-token"},
        )
        assert audit_code == 200
        assert audit["data"]["total"] >= 2
        assert all(item["domain_id"] == domain_id for item in audit["data"]["items"])
    finally:
        server.terminate()
        server.wait(timeout=5)
