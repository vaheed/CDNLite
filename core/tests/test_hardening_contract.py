import json
import os
import subprocess
import time
from pathlib import Path

from db_test_utils import run_php_with_deadlock_retry

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


def run_php(script: str, *args: str) -> dict:
    proc = subprocess.run(
        ["php", "-r", script, *args],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, **TEST_ENV},
    )
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
  'platform_settings_audit',
  'platform_settings',
  'usage_aggregates',
  'usage_ingest_keys',
  'usage_rollups',
  'edge_request_nonces',
  'edge_tokens',
  'desired_dns_rrsets',
  'edge_nodes',
  'dns_records',
  'domains',
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
$pdo->exec("ALTER TABLE domain_origins DROP CONSTRAINT IF EXISTS domain_origins_role_check");
$pdo->exec("ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_role_check CHECK (role IN ('primary', 'backup', 'shield'))");
'''
    run_php_with_deadlock_retry(script, TEST_ENV)


def test_usage_ingest_idempotency_key_deduplicates_retries():
    reset_db()

    domain = run_artisan(
        "cdn:domain:create",
        "--name=demo",
        "--domain=demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    domain_id = domain["data"]["id"]

    first = run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--idempotency_key=req-1",
    )
    second = run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
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
        "cdn:domain:create",
        "--name=demo2",
        "--domain=demo2.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
        "--proxy_enabled=1",
    )

    first = run_artisan("cdn:edge:sync-config")
    time.sleep(1)
    second = run_artisan("cdn:edge:sync-config")
    not_modified = run_artisan(f"cdn:edge:sync-config", f"--if_version={first['version']}")

    assert first["version"] >= 1
    assert second["version"] == first["version"]
    assert "reused" not in second
    assert second["generated_at"] == first["generated_at"]
    assert not_modified["version"] == first["version"]
    assert (not_modified.get("not_modified") is True) or (not_modified.get("reused") is True)

    row = run_php(
        r'''
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 5432);
$database = getenv('DB_DATABASE') ?: 'cdnlite';
$username = getenv('DB_USERNAME') ?: 'cdnlite';
$password = getenv('DB_PASSWORD') ?: 'cdnlite';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->prepare('SELECT generated_at, payload_json FROM config_snapshots WHERE version = :version');
$stmt->execute([':version' => (int) $argv[1]]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode([
    'generated_at' => (int) $row['generated_at'],
    'payload_generated_at' => (int) (json_decode($row['payload_json'], true)['generated_at'] ?? 0),
]);
''',
        str(first["version"]),
    )
    assert row["generated_at"] == first["generated_at"]
    assert row["payload_generated_at"] == first["generated_at"]


def test_smoke_and_e2e_cover_edge_config_visibility_and_publish_audit():
    smoke = (REPO_ROOT / "ci" / "smoke.sh").read_text()
    e2e = (REPO_ROOT / "ci" / "e2e.sh").read_text()

    assert "schema-edge-config-visibility" in smoke
    assert "schema-config-publish-audit" in smoke
    assert "Config snapshot status" in smoke
    assert "config_apply_error" in smoke
    assert "config.publish" in e2e
    assert "edge-config-version" in e2e
    assert "applied_config_version" in e2e
    assert 'Cache-Control: no-cache' in e2e
    assert 'did not become' in e2e


def test_config_republish_uses_new_version_for_reactivated_old_content():
    service = (REPO_ROOT / "core/app/Modules/Proxy/Services/ConfigService.php").read_text()
    schema = (REPO_ROOT / "core/database/schema.sql").read_text()
    migration = (REPO_ROOT / "core/database/migrations/000020_config_snapshot_republish.sql").read_text()

    assert "findReusableActiveSnapshot($previousActiveVersion, $contentHash)" in service
    assert "WHERE s.version = :version AND s.content_hash = :content_hash" in service
    assert "ON CONFLICT (content_hash)" not in service
    assert "active_snapshot_version = NULL" not in service
    assert "content_hash TEXT NOT NULL UNIQUE" not in schema
    assert "DROP CONSTRAINT IF EXISTS config_snapshots_content_hash_key" in migration


def test_edge_config_hosts_are_published_per_proxied_record():
    service = (REPO_ROOT / "core/app/Modules/Proxy/Services/ConfigService.php").read_text()

    assert "$hosts[$domainHost] = $baseConfig" not in service
    assert "foreach ($this->proxiedRecordHosts($domainHost, $records, $configuredOrigins) as $recordHost => $recordOrigins)" in service
    assert "recordHost($domainHost" in service


def test_core_agent_config_if_version_contract():
    reset_db()

    run_artisan(
        "cdn:domain:create",
        "--name=agent-config-contract",
        "--domain=agent-config-contract.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
        "--proxy_enabled=1",
    )

    first = run_artisan("cdn:edge:sync-config")
    unchanged = run_artisan("cdn:edge:sync-config", f"--if_version={first['version']}")
    stale = run_artisan("cdn:edge:sync-config", "--if_version=0")

    assert "version" in first
    assert "hosts" in first
    assert unchanged == {"not_modified": True, "version": first["version"]}
    assert stale["version"] == first["version"]
    assert "hosts" in stale


def test_core_collector_idempotency_contract_for_agent_retries():
    reset_db()

    domain = run_artisan(
        "cdn:domain:create",
        "--name=agent-metrics-contract",
        "--domain=agent-metrics-contract.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    domain_id = domain["data"]["id"]

    first = run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
        "--edge_node_id=edge-1",
        "--requests_count=1",
        "--bytes_in=2",
        "--bytes_out=3",
        "--status=200",
        "--idempotency_key=agent-retry-key",
    )
    retry = run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
        "--edge_node_id=edge-1",
        "--requests_count=1",
        "--bytes_in=2",
        "--bytes_out=3",
        "--status=200",
        "--idempotency_key=agent-retry-key",
    )
    summary = run_artisan("cdn:usage:summary", f"--domain_id={domain_id}")

    assert first["ingested"] == 1
    assert first["duplicate"] is False
    assert retry["ingested"] == 0
    assert retry["duplicate"] is True
    assert retry["idempotency_key"] == "agent-retry-key"
    assert retry["item_count"] == 1
    assert summary["data"]["records"] == 1
    assert summary["data"]["requests_count"] == 1


def test_dns_record_update_command_patches_existing_record():
    reset_db()

    domain = run_artisan(
        "cdn:domain:create",
        "--name=dns-update-demo",
        "--domain=dns-update-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    domain_id = domain["data"]["id"]
    record = run_artisan(
        "cdn:dns:add-record",
        f"--domain_id={domain_id}",
        "--type=A",
        "--name=@",
        "--content=127.0.0.1",
        "--ttl=300",
    )
    record_id = record["data"]["id"]

    run_php(
        r'''
$pdo = new PDO(
    "pgsql:host=" . (getenv("DB_HOST") ?: "127.0.0.1") .
    ";port=" . (getenv("DB_PORT") ?: "5432") .
    ";dbname=" . (getenv("DB_DATABASE") ?: "cdnlite"),
    getenv("DB_USERNAME") ?: "cdnlite",
    getenv("DB_PASSWORD") ?: "cdnlite"
);
$now = time();
$pdo->prepare("INSERT INTO edge_nodes (id, edge_id, hostname, public_ip, public_ipv4, region, country, version, status, is_enabled, last_heartbeat, health_status, created_at, updated_at) VALUES (:id, 'edge-dns-1', 'edge-dns-1', '198.51.100.10', '198.51.100.10', 'US', 'US', 'v1', 'online', true, :now, 'healthy', :now, :now)")->execute([
    ':id' => 'edge-dns-1',
    ':now' => $now,
]);
echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
'''
    )

    updated = run_artisan(
        "cdn:dns:update-record",
        f"--domain_id={domain_id}",
        f"--record_id={record_id}",
        "--content=127.0.0.2",
        "--ttl=120",
        "--proxied=1",
    )

    assert updated["data"]["id"] == record_id
    assert updated["data"]["content"] == "127.0.0.2"
    assert updated["data"]["ttl"] == 120
    assert updated["data"]["proxied"] is True
    assert updated["data"]["origin_type"] == "A"
    assert updated["data"]["origin_content"] == "127.0.0.2"
    assert updated["data"]["public_type"] == "LUA"
    assert updated["data"]["public_content"] == "managed edge pool"
    assert "canonical_edge_hostname" not in updated["data"]

    non_apex = run_artisan(
        "cdn:dns:add-record",
        f"--domain_id={domain_id}",
        "--type=A",
        "--name=www",
        "--content=127.0.0.3",
        "--proxied=1",
    )
    assert non_apex["data"]["public_type"] == "CNAME"
    assert non_apex["data"]["public_content"] == f"site-{domain_id}.cdn.example.net."
    assert "canonical_edge_hostname" not in non_apex["data"]


def test_dns_record_crud_supports_every_public_type_and_conflict_rule():
    reset_db()
    domain = run_artisan(
        "cdn:domain:create",
        "--name=dns-types-demo",
        "--domain=dns-types-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    domain_id = domain["data"]["id"]
    cases = [
        ("A", "a", "192.0.2.10", None),
        ("AAAA", "aaaa", "2001:db8::10", None),
        ("CNAME", "alias", "target.example.net", None),
        ("TXT", "txt", "verification=value", None),
        ("MX", "mail", "mail.example.net", "10"),
        ("CAA", "caa", '0 issue "letsencrypt.org"', None),
        ("NS", "delegated", "ns1.example.net", None),
        ("SRV", "_sip._tcp", "10 5 5060 sip.example.net", None),
    ]
    created = []
    for record_type, name, content, priority in cases:
        args = [
            "cdn:dns:add-record",
            f"--domain_id={domain_id}",
            f"--type={record_type}",
            f"--name={name}",
            f"--content={content}",
        ]
        if priority is not None:
            args.append(f"--priority={priority}")
        record = run_artisan(*args)["data"]
        assert record["type"] == record_type
        created.append(record)

    updated = run_artisan(
        "cdn:dns:update-record",
        f"--domain_id={domain_id}",
        f"--record_id={created[0]['id']}",
        "--content=192.0.2.11",
        "--name=dns-types-demo.local",
    )
    assert updated["data"]["name"] == "@"
    assert updated["data"]["content"] == "192.0.2.11"

    duplicate = subprocess.run(
        [
            "php",
            "core/artisan",
            "cdn:dns:add-record",
            f"--domain_id={domain_id}",
            "--type=A",
            "--name=@",
            "--content=192.0.2.11",
        ],
        cwd=REPO_ROOT,
        env={**os.environ, **TEST_ENV},
        text=True,
        capture_output=True,
    )
    assert duplicate.returncode == 1
    assert "dns_record_duplicate" in duplicate.stderr

    conflict = subprocess.run(
        [
            "php",
            "core/artisan",
            "cdn:dns:add-record",
            f"--domain_id={domain_id}",
            "--type=TXT",
            "--name=alias",
            "--content=conflict",
        ],
        cwd=REPO_ROOT,
        env={**os.environ, **TEST_ENV},
        text=True,
        capture_output=True,
    )
    assert conflict.returncode == 1
    assert "dns_record_name_conflict" in conflict.stderr

    for record in created:
        deleted = run_artisan(
            "cdn:dns:delete-record",
            f"--domain_id={domain_id}",
            f"--record_id={record['id']}",
        )
        assert deleted["ok"] is True


def test_edge_heartbeat_updates_public_ip_for_edge_dns_sync():
    reset_db()

    script = r'''
$pdo = new PDO(
    "pgsql:host=" . (getenv("DB_HOST") ?: "127.0.0.1") .
    ";port=" . (getenv("DB_PORT") ?: "5432") .
    ";dbname=" . (getenv("DB_DATABASE") ?: "cdnlite"),
    getenv("DB_USERNAME") ?: "cdnlite",
    getenv("DB_PASSWORD") ?: "cdnlite"
);
$now = time();
$pdo->prepare("INSERT INTO edge_nodes (id, edge_id, hostname, public_ip, public_ipv4, region, version, status, is_enabled, last_heartbeat, health_status, created_at, updated_at) VALUES (:id, 'edge-ip-1', 'edge-ip-1', '198.51.100.10', '198.51.100.10', 'US', 'v1', 'online', true, :now, 'healthy', :now, :now)")->execute([':id' => 'edge-ip-1', ':now' => $now]);
$pdo->prepare("UPDATE edge_nodes SET public_ip='198.51.100.11', public_ipv4='198.51.100.11', version='v2', updated_at=:now WHERE edge_id='edge-ip-1'")->execute([':now' => $now + 1]);
$node = (array) $pdo->query("SELECT * FROM edge_nodes WHERE edge_id='edge-ip-1'")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'ok' => true,
  'node' => $node,
], JSON_UNESCAPED_SLASHES);
'''

    out = run_php(script)

    assert out["ok"] is True
    assert out["node"]["public_ip"] == "198.51.100.11"
    assert out["node"]["public_ipv4"] == "198.51.100.11"
    assert out["node"]["version"] == "v2"


def test_usage_recalculate_materializes_minute_hour_day_aggregates():
    reset_db()

    domain = run_artisan(
        "cdn:domain:create",
        "--name=agg-demo",
        "--domain=agg-demo.local",
        "--origin_host=origin.local",
        "--origin_port=8080",
    )
    domain_id = domain["data"]["id"]

    run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
        "--edge_node_id=edge-1",
        "--requests_count=10",
        "--bytes_in=100",
        "--bytes_out=500",
        "--status=200",
        "--ts=60",
    )
    run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
        "--edge_node_id=edge-1",
        "--requests_count=5",
        "--bytes_in=40",
        "--bytes_out=140",
        "--status=200",
        "--ts=70",
    )
    run_artisan(
        "cdn:usage:ingest",
        f"--domain_id={domain_id}",
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
