<?php

namespace App\Modules\Collector\Services;

use App\Support\Database;
use App\Support\Uuid;

class CollectorService
{
    private ?bool $usageRollupCacheColumnsAvailable = null;
    /** @var array<string,int> */
    private array $bucketSeconds = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
    ];

    public function ingest(array $items, ?string $idempotencyKey = null): array
    {
        $pdo = Database::pdo();
        $now = time();

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $checkStmt = $pdo->prepare('SELECT item_count FROM usage_ingest_keys WHERE idempotency_key = :idempotency_key LIMIT 1');
            $checkStmt->execute([':idempotency_key' => $idempotencyKey]);
            $existing = $checkStmt->fetch();
            if ($existing !== false) {
                return [
                    'ingested' => 0,
                    'duplicate' => true,
                    'idempotency_key' => $idempotencyKey,
                    'item_count' => (int) $existing['item_count'],
                ];
            }
        }

        $pdo->beginTransaction();
        $stmt = $this->usageRollupCacheColumnsAvailable()
            ? $pdo->prepare(
                'INSERT INTO usage_rollups (id, ts, site_id, edge_node_id, requests_count, bytes_in, bytes_out, status, cache_status, rule_id, request_id, origin_status, origin_time_ms)
                 VALUES (:id, :ts, :site_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status, :cache_status, :rule_id, :request_id, :origin_status, :origin_time_ms)'
            )
            : $pdo->prepare(
                'INSERT INTO usage_rollups (id, ts, site_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
                 VALUES (:id, :ts, :site_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
            );

        $count = 0;
        try {
            foreach ($items as $item) {
                $params = [
                    ':id' => Uuid::v4(),
                    ':ts' => (int) ($item['ts'] ?? $now),
                    ':site_id' => (string) ($item['site_id'] ?? ''),
                    ':edge_node_id' => (string) ($item['edge_node_id'] ?? ''),
                    ':requests_count' => (int) ($item['requests_count'] ?? 0),
                    ':bytes_in' => (int) ($item['bytes_in'] ?? 0),
                    ':bytes_out' => (int) ($item['bytes_out'] ?? 0),
                    ':status' => (int) ($item['status'] ?? 0),
                ];
                if ($this->usageRollupCacheColumnsAvailable()) {
                    $params[':cache_status'] = (string) ($item['cache_status'] ?? 'BYPASS');
                    $params[':rule_id'] = isset($item['rule_id']) ? (string) $item['rule_id'] : null;
                    $params[':request_id'] = isset($item['request_id']) ? (string) $item['request_id'] : null;
                    $params[':origin_status'] = isset($item['origin_status']) ? (int) $item['origin_status'] : null;
                    $params[':origin_time_ms'] = isset($item['origin_time_ms']) ? (int) $item['origin_time_ms'] : null;
                }
                $stmt->execute($params);
                $count++;
            }

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $markStmt = $pdo->prepare(
                    'INSERT INTO usage_ingest_keys (idempotency_key, item_count, created_at)
                     VALUES (:idempotency_key, :item_count, :created_at)'
                );
                $markStmt->execute([
                    ':idempotency_key' => $idempotencyKey,
                    ':item_count' => $count,
                    ':created_at' => $now,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'ingested' => $count,
            'duplicate' => false,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function ingestSecurityEvents(array $items, ?string $idempotencyKey = null): array
    {
        $pdo = Database::pdo();
        $now = time();
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $checkStmt = $pdo->prepare('SELECT item_count FROM usage_ingest_keys WHERE idempotency_key = :idempotency_key LIMIT 1');
            $checkStmt->execute([':idempotency_key' => 'sec:' . $idempotencyKey]);
            $existing = $checkStmt->fetch();
            if ($existing !== false) {
                return ['ingested' => 0, 'duplicate' => true, 'idempotency_key' => $idempotencyKey, 'item_count' => (int) $existing['item_count']];
            }
        }
        $pdo->beginTransaction();
        $count = 0;
        $stmt = $pdo->prepare('INSERT INTO audit_log (id, actor_type, actor_id, action, resource_type, resource_id, site_id, details_json, event, created_at) VALUES (:id,:actor_type,:actor_id,:action,:resource_type,:resource_id,:site_id,:details_json,:event,:created_at)');
        try {
            foreach ($items as $item) {
                $event = (string) ($item['type'] ?? '');
                if (!in_array($event, ['waf_match', 'rate_limited'], true)) {
                    continue;
                }
                $stmt->execute([
                    ':id' => Uuid::v4(),
                    ':actor_type' => 'system',
                    ':actor_id' => (string) ($item['edge_node_id'] ?? ''),
                    ':action' => 'inspect',
                    ':resource_type' => $event === 'waf_match' ? 'waf' : 'rate_limit',
                    ':resource_id' => (string) ($item['rule_id'] ?? ''),
                    ':site_id' => (string) ($item['site_id'] ?? ''),
                    ':details_json' => json_encode(['decision' => (string) ($item['action'] ?? ''), 'request_id' => (string) ($item['request_id'] ?? ''), 'path' => (string) ($item['path'] ?? ''), 'method' => (string) ($item['method'] ?? ''), 'client_ip' => (string) ($item['client_ip'] ?? '')], JSON_UNESCAPED_SLASHES),
                    ':event' => $event,
                    ':created_at' => (int) ($item['ts'] ?? $now),
                ]);
                $count++;
            }
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $markStmt = $pdo->prepare('INSERT INTO usage_ingest_keys (idempotency_key, item_count, created_at) VALUES (:idempotency_key, :item_count, :created_at)');
                $markStmt->execute([':idempotency_key' => 'sec:' . $idempotencyKey, ':item_count' => $count, ':created_at' => $now]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ingested' => $count, 'duplicate' => false, 'idempotency_key' => $idempotencyKey];
    }

    public function summary(?string $siteId = null, ?string $bucket = null): array
    {
        $pdo = Database::pdo();
        if ($bucket !== null) {
            if (!isset($this->bucketSeconds[$bucket])) {
                return [
                    'requests_count' => 0,
                    'bytes_in' => 0,
                    'bytes_out' => 0,
                    'records' => 0,
                ];
            }
            if ($siteId !== null) {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(requests_count),0) requests_count,
                            COALESCE(SUM(bytes_in),0) bytes_in,
                            COALESCE(SUM(bytes_out),0) bytes_out,
                            COUNT(*) records
                    FROM usage_aggregates
                    WHERE bucket = :bucket AND site_id = :site_id'
                );
                $stmt->execute([':bucket' => $bucket, ':site_id' => $siteId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(requests_count),0) requests_count,
                            COALESCE(SUM(bytes_in),0) bytes_in,
                            COALESCE(SUM(bytes_out),0) bytes_out,
                            COUNT(*) records
                    FROM usage_aggregates
                    WHERE bucket = :bucket'
                );
                $stmt->execute([':bucket' => $bucket]);
            }
            $row = (array) $stmt->fetch();
            return [
                'bucket' => $bucket,
                'requests_count' => (int) $row['requests_count'],
                'bytes_in' => (int) $row['bytes_in'],
                'bytes_out' => (int) $row['bytes_out'],
                'records' => (int) $row['records'],
            ];
        }

        if ($siteId !== null) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(requests_count),0) requests_count,
                        COALESCE(SUM(bytes_in),0) bytes_in,
                        COALESCE(SUM(bytes_out),0) bytes_out,
                        COUNT(*) records
                 FROM usage_rollups WHERE site_id = :site_id'
            );
            $stmt->execute([':site_id' => $siteId]);
        } else {
            $stmt = $pdo->query(
                'SELECT COALESCE(SUM(requests_count),0) requests_count,
                        COALESCE(SUM(bytes_in),0) bytes_in,
                        COALESCE(SUM(bytes_out),0) bytes_out,
                        COUNT(*) records
                 FROM usage_rollups'
            );
        }

        $row = (array) $stmt->fetch();
        return [
            'requests_count' => (int) $row['requests_count'],
            'bytes_in' => (int) $row['bytes_in'],
            'bytes_out' => (int) $row['bytes_out'],
            'records' => (int) $row['records'],
        ];
    }

    public function rebuildAggregates(?string $siteId = null): array
    {
        $pdo = Database::pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            if ($siteId !== null) {
                $deleteStmt = $pdo->prepare('DELETE FROM usage_aggregates WHERE site_id = :site_id');
                $deleteStmt->execute([':site_id' => $siteId]);
            } else {
                $pdo->exec('DELETE FROM usage_aggregates');
            }

            $inserted = [];
            foreach ($this->bucketSeconds as $bucket => $seconds) {
                $where = $siteId !== null ? 'WHERE site_id = :site_id' : '';
                $sql = sprintf(
                    'INSERT INTO usage_aggregates
                    (id, bucket, bucket_ts, site_id, edge_node_id, status, requests_count, bytes_in, bytes_out, created_at, updated_at)
                    SELECT md5((:bucket || \':\' || ((ts / %d) * %d) || \':\' || site_id || \':\' || edge_node_id || \':\' || status)::text),
                           :bucket,
                           (ts / %d) * %d AS bucket_ts,
                           site_id,
                           edge_node_id,
                           status,
                           COALESCE(SUM(requests_count),0) AS requests_count,
                           COALESCE(SUM(bytes_in),0) AS bytes_in,
                           COALESCE(SUM(bytes_out),0) AS bytes_out,
                           :now,
                           :now
                    FROM usage_rollups
                    %s
                    GROUP BY bucket_ts, site_id, edge_node_id, status',
                    $seconds,
                    $seconds,
                    $seconds,
                    $seconds,
                    $where
                );
                $stmt = $pdo->prepare($sql);
                $params = [':bucket' => $bucket, ':now' => $now];
                if ($siteId !== null) {
                    $params[':site_id'] = $siteId;
                }
                $stmt->execute($params);
                $inserted[$bucket] = $stmt->rowCount();
            }

            $pdo->commit();
            return [
                'ok' => true,
                'site_id' => $siteId,
                'inserted' => $inserted,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function cacheAnalytics(string $siteId): array
    {
        $pdo = Database::pdo();
        if (!$this->usageRollupCacheColumnsAvailable()) {
            return ['hit_ratio' => 0.0, 'requests' => 0, 'hit' => 0, 'miss' => 0, 'bypass' => 0, 'stale' => 0];
        }

        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS requests,
                SUM(CASE WHEN cache_status = :hit THEN requests_count ELSE 0 END) AS hit,
                SUM(CASE WHEN cache_status = :miss THEN requests_count ELSE 0 END) AS miss,
                SUM(CASE WHEN cache_status = :bypass THEN requests_count ELSE 0 END) AS bypass,
                SUM(CASE WHEN cache_status = :stale THEN requests_count ELSE 0 END) AS stale
             FROM usage_rollups
             WHERE site_id = :site_id'
        );
        $stmt->execute([
            ':hit' => 'HIT',
            ':miss' => 'MISS',
            ':bypass' => 'BYPASS',
            ':stale' => 'STALE',
            ':site_id' => $siteId,
        ]);
        $row = (array) $stmt->fetch();
        $requests = (int) ($row['requests'] ?? 0);
        $hit = (int) ($row['hit'] ?? 0);
        $miss = (int) ($row['miss'] ?? 0);
        $bypass = (int) ($row['bypass'] ?? 0);
        $stale = (int) ($row['stale'] ?? 0);
        $ratio = $requests > 0 ? round($hit / $requests, 4) : 0.0;

        return [
            'hit_ratio' => $ratio,
            'requests' => $requests,
            'hit' => $hit,
            'miss' => $miss,
            'bypass' => $bypass,
            'stale' => $stale,
        ];
    }

    private function usageRollupCacheColumnsAvailable(): bool
    {
        if ($this->usageRollupCacheColumnsAvailable !== null) {
            return $this->usageRollupCacheColumnsAvailable;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='public' AND table_name='usage_rollups'
             AND column_name IN ('cache_status', 'rule_id', 'request_id', 'origin_status', 'origin_time_ms')"
        );
        $stmt->execute();
        $this->usageRollupCacheColumnsAvailable = (int) $stmt->fetchColumn() === 5;
        return $this->usageRollupCacheColumnsAvailable;
    }
}
