import json
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CORE_DIR = REPO_ROOT / "core"
DB_PATH = REPO_ROOT / "storage" / "cdnt.sqlite"
ARTISAN = CORE_DIR / "artisan"


def run_artisan(*args: str) -> dict:
    cmd = ["php", str(ARTISAN), *args]
    proc = subprocess.run(cmd, cwd=str(REPO_ROOT), capture_output=True, text=True, check=True)
    return json.loads(proc.stdout)


def reset_db() -> None:
    if DB_PATH.exists():
        DB_PATH.unlink()


def test_usage_ingest_idempotency_key_deduplicates_retries():
    reset_db()

    run_artisan(
        "cdn:site:create",
        "--name=demo",
        "--domain=demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )

    first = run_artisan(
        "cdn:usage:ingest",
        "--site_id=1",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--idempotency_key=req-1",
    )
    second = run_artisan(
        "cdn:usage:ingest",
        "--site_id=1",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--idempotency_key=req-1",
    )
    summary = run_artisan("cdn:usage:summary")

    assert first["ingested"] == 1
    assert first["duplicate"] is False
    assert second["ingested"] == 0
    assert second["duplicate"] is True
    assert summary["data"]["records"] == 1
    assert summary["data"]["requests_count"] == 10


def test_edge_sync_config_reuses_version_when_unchanged():
    reset_db()

    run_artisan(
        "cdn:site:create",
        "--name=demo2",
        "--domain=demo2.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
        "--proxy_enabled=1",
    )

    first = run_artisan("cdn:edge:sync-config")
    second = run_artisan("cdn:edge:sync-config")
    not_modified = run_artisan(f"cdn:edge:sync-config", f"--if_version={first['version']}")

    assert first["version"] >= 1
    assert second["version"] == first["version"]
    assert second["reused"] is True
    assert not_modified["not_modified"] is True
    assert not_modified["version"] == first["version"]
