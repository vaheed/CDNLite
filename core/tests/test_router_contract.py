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


def free_port() -> int:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.bind(("127.0.0.1", 0))
            return int(s.getsockname()[1])
    except PermissionError:
        pytest.skip("Socket bind is not permitted in this test environment")


def wait_for_server(base_url: str, timeout: float = 5.0) -> None:
    start = time.time()
    while time.time() - start < timeout:
        try:
            urllib.request.urlopen(base_url + "/health", timeout=0.5)
            return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError("php server did not start")


def test_core_exposes_origin_cdn_health_route():
    public_index = (REPO_ROOT / "core" / "public_index.php").read_text()
    assert "/cdn-health" in public_index


def test_domain_routes_are_registered():
    public_index = (REPO_ROOT / "core" / "public_index.php").read_text()
    assert "'/api/v1/domains'" in public_index
    assert "'/api/v1/domains/{domainId}'" in public_index
    assert "/api/v1/domains/{domainId}/dns/records/{recordId}/geo-routes" in public_index


def test_router_placeholders_accept_dotted_setting_group_names():
    router = (REPO_ROOT / "core" / "app" / "Support" / "Router.php").read_text()
    assert "[^\\/]+" in router


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


def test_router_returns_not_found_for_unknown_path():
    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {**os.environ, "APP_ENV": "development", "CDNLITE_API_TOKEN": "stage4-token"}

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)
        code, body = request_json(base_url, "GET", "/api/v1/does-not-exist")
        assert code == 404
        assert body["error"] == "not_found"
    finally:
        server.terminate()
        server.wait(timeout=5)


def test_router_parses_json_before_route_dispatch():
    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {**os.environ, "APP_ENV": "development", "CDNLITE_API_TOKEN": "stage4-token"}

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)
        req = urllib.request.Request(
            base_url + "/api/v1/does-not-exist",
            data=b"{bad-json",
            method="POST",
            headers={"Content-Type": "application/json"},
        )
        try:
            urllib.request.urlopen(req, timeout=5)
            raise AssertionError("expected HTTPError")
        except urllib.error.HTTPError as e:
            body = json.loads(e.read().decode("utf-8"))
            assert e.code == 400
            assert body["error"] == "invalid_json"
    finally:
        server.terminate()
        server.wait(timeout=5)


def test_router_applies_auth_flag_on_admin_routes():
    port = free_port()
    base_url = f"http://127.0.0.1:{port}"
    env = {**os.environ, "APP_ENV": "development", "CDNLITE_API_TOKEN": "stage4-token"}

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "core/public_index.php"],
        cwd=str(REPO_ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        env=env,
    )

    try:
        wait_for_server(base_url)
        code, body = request_json(base_url, "GET", "/api/v1/domains")
        assert code == 401
        assert body["error"] == "api_auth_required"
    finally:
        server.terminate()
        server.wait(timeout=5)
