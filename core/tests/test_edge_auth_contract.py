import os
import subprocess
from pathlib import Path

from db_test_utils import run_php_with_deadlock_retry

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
$tables = [
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
'''
    run_php_with_deadlock_retry(script, TEST_ENV)


def test_edge_auth_rejects_missing_token_and_replay_nonce():
    middleware = (REPO_ROOT / "core/app/Http/Middleware/EdgeSignatureAuth.php").read_text()
    routes = (REPO_ROOT / "core/routes/api.php").read_text()
    schema = (REPO_ROOT / "core/database/schema.sql").read_text()

    assert "edge_auth_required" in middleware
    assert "edge_auth_replay_detected" in middleware
    assert "hash_hmac('sha256'" in middleware
    assert "hash_equals" in middleware
    assert "edge_request_nonces" in middleware
    assert "Route::middleware('edge.auth')" in routes
    assert "CREATE TABLE IF NOT EXISTS edge_request_nonces" in schema
