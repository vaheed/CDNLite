import json
import os
import subprocess
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


def run_php(script: str) -> dict:
    proc = subprocess.run(
        ["php", "-r", script],
        cwd=str(REPO_ROOT),
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, **TEST_ENV},
    )
    return json.loads(proc.stdout)


def test_cache_analytics_groups_by_cache_status_and_defaults_unknown():
    require_db_or_skip()

    script = r'''
require __DIR__ . '/core/app/Support/bootstrap.php';

$pdo = App\Support\Database::pdo();
$domainId = App\Support\Uuid::v4();
$now = time();
$pdo->prepare(
    'INSERT INTO domains (id, user_id, name, domain, origin_scheme, origin_host, origin_port, origin_shield_header_name, origin_shield_header_value_hash, geo_origins_json, proxy_enabled, status, created_at, updated_at)
     VALUES (:id, :user_id, :name, :domain, :origin_scheme, :origin_host, :origin_port, :origin_shield_header_name, :origin_shield_header_value_hash, :geo_origins_json, :proxy_enabled, :status, :created_at, :updated_at)'
)->execute([
    ':id' => $domainId,
    ':user_id' => App\Support\Uuid::v4(),
    ':name' => 'Cache Demo',
    ':domain' => 'cache-demo-' . substr($domainId, 0, 8) . '.local',
    ':origin_scheme' => 'http',
    ':origin_host' => 'core',
    ':origin_port' => 8080,
    ':origin_shield_header_name' => null,
    ':origin_shield_header_value_hash' => null,
    ':geo_origins_json' => null,
    ':proxy_enabled' => 1,
    ':status' => 'active',
    ':created_at' => $now,
    ':updated_at' => $now,
]);

$rows = [
    ['cache_status' => 'HIT', 'requests_count' => 7, 'bytes_out' => 70],
    ['cache_status' => 'BYPASS', 'requests_count' => 3, 'bytes_out' => 30],
    ['cache_status' => null, 'requests_count' => 2, 'bytes_out' => 20],
];

foreach ($rows as $i => $row) {
    if ($row['cache_status'] === null) {
        $pdo->prepare(
            'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
             VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
        )->execute([
            ':id' => App\Support\Uuid::v4(),
            ':ts' => $now + $i,
            ':domain_id' => $domainId,
            ':edge_node_id' => 'edge-local-1',
            ':requests_count' => $row['requests_count'],
            ':bytes_in' => 10,
            ':bytes_out' => $row['bytes_out'],
            ':status' => 200,
        ]);
        continue;
    }

    $pdo->prepare(
        'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status, cache_status)
         VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status, :cache_status)'
    )->execute([
        ':id' => App\Support\Uuid::v4(),
        ':ts' => $now + $i,
        ':domain_id' => $domainId,
        ':edge_node_id' => 'edge-local-1',
        ':requests_count' => $row['requests_count'],
        ':bytes_in' => 10,
        ':bytes_out' => $row['bytes_out'],
        ':status' => 200,
        ':cache_status' => $row['cache_status'],
    ]);
}

$service = new App\Modules\Collector\Services\CollectorService();
$analytics = $service->cacheAnalytics($domainId);
$service->rebuildAggregates($domainId);
$aggregateRows = $pdo->query(
    "SELECT cache_status, SUM(requests_count) AS count, SUM(bytes_out) AS bytes_out
     FROM usage_aggregates
     WHERE domain_id = '" . $domainId . "'
       AND bucket = 'minute'
     GROUP BY cache_status
     ORDER BY cache_status"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'analytics' => $analytics,
    'aggregates' => $aggregateRows,
], JSON_UNESCAPED_SLASHES);
'''
    out = run_php(script)

    rows = {row["cache_status"]: row for row in out["analytics"]["rows"]}
    assert rows["HIT"]["count"] == 7
    assert rows["BYPASS"]["count"] == 3
    assert rows["UNKNOWN"]["count"] == 2
    assert out["analytics"]["hit"] == 7
    assert out["analytics"]["bypass"] == 3
    assert out["analytics"]["unknown"] == 2
    assert out["analytics"]["hit_ratio"] == 1.0

    aggregates = {row["cache_status"]: row for row in out["aggregates"]}
    assert int(aggregates["HIT"]["count"]) == 7
    assert int(aggregates["BYPASS"]["count"]) == 3
    assert int(aggregates["UNKNOWN"]["count"]) == 2
