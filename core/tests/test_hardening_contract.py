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

    site = run_artisan(
        "cdn:site:create",
        "--name=demo",
        "--domain=demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    site_id = site["data"]["id"]

    first = run_artisan(
        "cdn:usage:ingest",
        f"--site_id={site_id}",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--idempotency_key=req-1",
    )
    second = run_artisan(
        "cdn:usage:ingest",
        f"--site_id={site_id}",
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


def test_dns_record_update_command_patches_existing_record():
    reset_db()

    site = run_artisan(
        "cdn:site:create",
        "--name=dns-update-demo",
        "--domain=dns-update-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    site_id = site["data"]["id"]
    record = run_artisan(
        "cdn:dns:add-record",
        f"--site_id={site_id}",
        "--type=A",
        "--name=@",
        "--content=127.0.0.1",
        "--ttl=300",
    )
    record_id = record["data"]["id"]

    updated = run_artisan(
        "cdn:dns:update-record",
        f"--site_id={site_id}",
        f"--record_id={record_id}",
        "--content=127.0.0.2",
        "--ttl=120",
        "--proxied=1",
    )

    assert updated["data"]["id"] == record_id
    assert updated["data"]["content"] == "127.0.0.2"
    assert updated["data"]["ttl"] == 120
    assert updated["data"]["proxied"] is True


def test_edge_heartbeat_updates_public_ip_for_powerdns_refresh():
    reset_db()

    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$edge = new App\Modules\Edge\Services\EdgeService();
$edge->register([
  'edge_id' => 'edge-ip-1',
  'hostname' => 'edge-ip-1',
  'public_ip' => '198.51.100.10',
  'region' => 'US',
  'version' => 'v1',
]);
$ok = $edge->heartbeat([
  'edge_id' => 'edge-ip-1',
  'hostname' => 'edge-ip-1',
  'public_ip' => '198.51.100.11',
  'region' => 'US',
  'version' => 'v2',
]);
$nodes = $edge->list();

echo json_encode([
  'ok' => $ok,
  'node' => $nodes[0],
], JSON_UNESCAPED_SLASHES);
'''

    out = run_php(script)

    assert out["ok"] is True
    assert out["node"]["public_ip"] == "198.51.100.11"
    assert out["node"]["version"] == "v2"


def test_usage_recalculate_materializes_minute_hour_day_aggregates():
    reset_db()

    site = run_artisan(
        "cdn:site:create",
        "--name=agg-demo",
        "--domain=agg-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    site_id = site["data"]["id"]

    run_artisan(
        "cdn:usage:ingest",
        f"--site_id={site_id}",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--ts=60",
    )
    run_artisan(
        "cdn:usage:ingest",
        f"--site_id={site_id}",
        "--edge_node_id=edge-1",
        "--requests_count=5",
        "--bytes_in=40",
        "--bytes_out=140",
        "--status=200",
        "--ts=70",
    )
    run_artisan(
        "cdn:usage:ingest",
        f"--site_id={site_id}",
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
