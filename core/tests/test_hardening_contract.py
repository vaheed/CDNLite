import json
import os
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CORE_DIR = REPO_ROOT / "core"
ARTISAN = CORE_DIR / "artisan"
TEST_ENV = {
    "DB_HOST": os.getenv("DB_HOST", "127.0.0.1"),
    "DB_PORT": os.getenv("DB_PORT", "5432"),
    "DB_DATABASE": os.getenv("DB_DATABASE", "cdnlite"),
    "DB_USERNAME": os.getenv("DB_USERNAME", "cdnlite"),
    "DB_PASSWORD": os.getenv("DB_PASSWORD", "cdnlite"),
}


def run_artisan(*args: str) -> dict:
    cmd = ["php", str(ARTISAN), *args]
    env = {**os.environ, **TEST_ENV}
    proc = subprocess.run(cmd, cwd=str(REPO_ROOT), capture_output=True, text=True, check=True, env=env)
    return json.loads(proc.stdout)


def reset_db() -> None:
    script = r'''
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 5432);
$database = getenv('DB_DATABASE') ?: 'cdnlite';
$username = getenv('DB_USERNAME') ?: 'cdnlite';
$password = getenv('DB_PASSWORD') ?: 'cdnlite';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = [
  'usage_aggregates',
  'usage_ingest_keys',
  'usage_rollups',
  'edge_request_nonces',
  'edge_tokens',
  'edge_nodes',
  'dns_records',
  'sites',
  'config_snapshots',
  'config_state',
];
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
    subprocess.run(
        ["php", "-r", script],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, **TEST_ENV},
    )


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
    assert not_modified["version"] == first["version"]
    assert (not_modified.get("not_modified") is True) or (not_modified.get("reused") is True)


def test_usage_recalculate_materializes_minute_hour_day_aggregates():
    reset_db()

    run_artisan(
        "cdn:site:create",
        "--name=agg-demo",
        "--domain=agg-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )

    run_artisan(
        "cdn:usage:ingest",
        "--site_id=1",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--ts=60",
    )
    run_artisan(
        "cdn:usage:ingest",
        "--site_id=1",
        "--edge_node_id=edge-1",
        "--requests_count=5",
        "--bytes_in=40",
        "--bytes_out=140",
        "--status=200",
        "--ts=70",
    )
    run_artisan(
        "cdn:usage:ingest",
        "--site_id=1",
        "--edge_node_id=edge-1",
        "--requests_count=7",
        "--bytes_in=70",
        "--bytes_out=280",
        "--status=200",
        "--ts=125",
    )

    recalc = run_artisan("cdn:usage:recalculate")
    minute = run_artisan("cdn:usage:summary", "--bucket=minute")
    hour = run_artisan("cdn:usage:summary", "--bucket=hour")
    day = run_artisan("cdn:usage:summary", "--bucket=day")

    assert recalc["ok"] is True
    assert recalc["inserted"]["minute"] == 2
    assert recalc["inserted"]["hour"] == 1
    assert recalc["inserted"]["day"] == 1

    assert minute["data"]["requests_count"] == 22
    assert minute["data"]["records"] == 2
    assert hour["data"]["requests_count"] == 22
    assert hour["data"]["records"] == 1
    assert day["data"]["requests_count"] == 22
    assert day["data"]["records"] == 1
