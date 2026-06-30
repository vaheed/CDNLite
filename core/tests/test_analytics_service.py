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
$pdo = new PDO(
    "pgsql:host=" . (getenv("DB_HOST") ?: "127.0.0.1") .
    ";port=" . (getenv("DB_PORT") ?: "5432") .
    ";dbname=" . (getenv("DB_DATABASE") ?: "cdnlite"),
    getenv("DB_USERNAME") ?: "cdnlite",
    getenv("DB_PASSWORD") ?: "cdnlite"
);
$uuid = function (): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
};
$domainId = $uuid();
$now = time();
$pdo->prepare(
    'INSERT INTO domains (id, user_id, name, domain, origin_shield_header_name, origin_shield_header_value_hash, status, created_at, updated_at)
     VALUES (:id, :user_id, :name, :domain, :origin_shield_header_name, :origin_shield_header_value_hash, :status, :created_at, :updated_at)'
)->execute([
    ':id' => $domainId,
    ':user_id' => $uuid(),
    ':name' => 'Cache Demo',
    ':domain' => 'cache-demo-' . substr($domainId, 0, 8) . '.local',
    ':origin_shield_header_name' => null,
    ':origin_shield_header_value_hash' => null,
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
            ':id' => $uuid(),
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
        ':id' => $uuid(),
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

$analyticsRows = $pdo->query(
    "SELECT COALESCE(cache_status, 'UNKNOWN') AS cache_status,
            COALESCE(SUM(requests_count),0) AS count,
            COALESCE(SUM(bytes_out),0) AS bytes_out
     FROM usage_rollups
     WHERE domain_id = '" . $domainId . "'
     GROUP BY COALESCE(cache_status, 'UNKNOWN')
     ORDER BY cache_status"
)->fetchAll(PDO::FETCH_ASSOC);
$analytics = ['rows' => $analyticsRows, 'hit' => 0, 'bypass' => 0, 'unknown' => 0, 'hit_ratio' => 0];
foreach ($analyticsRows as $row) {
    $key = strtolower((string) $row['cache_status']);
    $analytics[$key] = (int) $row['count'];
}
$analytics['hit_ratio'] = $analytics['hit'] > 0 ? 1.0 : 0.0;
$pdo->exec(
    "INSERT INTO usage_aggregates
     (id, bucket, bucket_ts, domain_id, edge_node_id, status, cache_status, requests_count, bytes_in, bytes_out, created_at, updated_at)
     SELECT md5(('minute:' || ((ts / 60) * 60) || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || COALESCE(cache_status, 'UNKNOWN'))::text),
            'minute', ((ts / 60) * 60), domain_id, edge_node_id, status, COALESCE(cache_status, 'UNKNOWN'),
            COALESCE(SUM(requests_count),0), COALESCE(SUM(bytes_in),0), COALESCE(SUM(bytes_out),0), {$now}, {$now}
     FROM usage_rollups
     WHERE domain_id = '" . $domainId . "'
     GROUP BY ((ts / 60) * 60), domain_id, edge_node_id, status, COALESCE(cache_status, 'UNKNOWN')
     ON CONFLICT (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)
     DO UPDATE SET requests_count=EXCLUDED.requests_count, bytes_in=EXCLUDED.bytes_in, bytes_out=EXCLUDED.bytes_out, updated_at=EXCLUDED.updated_at"
);
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
