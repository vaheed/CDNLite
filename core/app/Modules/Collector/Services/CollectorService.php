<?php

namespace App\Modules\Collector\Services;

use App\Support\Database;
use App\Support\Uuid;

class CollectorService
{
    private ?bool $usageRollupCacheColumnsAvailable = null;
    /** @var array<string,bool> */
    private array $knownDomainIds = [];
    /** @var array<string,int> */
    private array $bucketSeconds = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
    ];
    /** @var array<int,string> */
    private array $cacheStatusOrder = [
        'HIT',
        'MISS',
        'EXPIRED',
        'STALE',
        'BYPASS',
        'UNKNOWN',
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
                'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status, cache_status, rule_id, request_id, origin_status, origin_time_ms)
                 VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status, :cache_status, :rule_id, :request_id, :origin_status, :origin_time_ms)'
            )
            : $pdo->prepare(
                'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
                 VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
            );

        $count = 0;
        $skippedUnknownDomains = 0;
        try {
            foreach ($items as $item) {
                $domainId = (string) ($item['domain_id'] ?? '');
                if (!$this->domainExists($domainId)) {
                    $skippedUnknownDomains++;
                    continue;
                }

                $params = [
                    ':id' => Uuid::v4(),
                    ':ts' => (int) ($item['ts'] ?? $now),
                    ':domain_id' => $domainId,
                    ':edge_node_id' => (string) ($item['edge_node_id'] ?? ''),
                    ':requests_count' => (int) ($item['requests_count'] ?? 0),
                    ':bytes_in' => (int) ($item['bytes_in'] ?? 0),
                    ':bytes_out' => (int) ($item['bytes_out'] ?? 0),
                    ':status' => (int) ($item['status'] ?? 0),
                ];
                if ($this->usageRollupCacheColumnsAvailable()) {
                    $params[':cache_status'] = (string) ($item['cache_status'] ?? 'UNKNOWN');
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
            'skipped_unknown_domains' => $skippedUnknownDomains,
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
        $skippedUnknownDomains = 0;
        $stmt = $pdo->prepare('INSERT INTO audit_log (id, actor_type, actor_id, action, resource_type, resource_id, domain_id, details_json, event, created_at) VALUES (:id,:actor_type,:actor_id,:action,:resource_type,:resource_id,:domain_id,:details_json,:event,:created_at)');
        try {
            foreach ($items as $item) {
                $event = (string) ($item['type'] ?? '');
                if (!in_array($event, ['waf_match', 'rate_limited'], true)) {
                    continue;
                }
                $domainId = (string) ($item['domain_id'] ?? '');
                if (!$this->domainExists($domainId)) {
                    $skippedUnknownDomains++;
                    continue;
                }
                $stmt->execute([
                    ':id' => Uuid::v4(),
                    ':actor_type' => 'system',
                    ':actor_id' => (string) ($item['edge_node_id'] ?? ''),
                    ':action' => 'inspect',
                    ':resource_type' => $event === 'waf_match' ? 'waf' : 'rate_limit',
                    ':resource_id' => (string) ($item['rule_id'] ?? ''),
                    ':domain_id' => $domainId,
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
        return ['ingested' => $count, 'skipped_unknown_domains' => $skippedUnknownDomains, 'duplicate' => false, 'idempotency_key' => $idempotencyKey];
    }

    public function summary(?string $domainId = null, ?string $bucket = null): array
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
            if ($domainId !== null) {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(requests_count),0) requests_count,
                            COALESCE(SUM(bytes_in),0) bytes_in,
                            COALESCE(SUM(bytes_out),0) bytes_out,
                            COUNT(*) records
                    FROM usage_aggregates
                    WHERE bucket = :bucket AND domain_id = :domain_id'
                );
                $stmt->execute([':bucket' => $bucket, ':domain_id' => $domainId]);
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

        if ($domainId !== null) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(requests_count),0) requests_count,
                        COALESCE(SUM(bytes_in),0) bytes_in,
                        COALESCE(SUM(bytes_out),0) bytes_out,
                        COUNT(*) records
                 FROM usage_rollups WHERE domain_id = :domain_id'
            );
            $stmt->execute([':domain_id' => $domainId]);
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

    public function rebuildAggregates(?string $domainId = null): array
    {
        $pdo = Database::pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            if ($domainId !== null) {
                $deleteStmt = $pdo->prepare('DELETE FROM usage_aggregates WHERE domain_id = :domain_id');
                $deleteStmt->execute([':domain_id' => $domainId]);
            } else {
                $pdo->exec('DELETE FROM usage_aggregates');
            }

            $inserted = [];
            foreach ($this->bucketSeconds as $bucket => $seconds) {
                $where = $domainId !== null ? 'WHERE domain_id = :domain_id' : '';
                $sql = sprintf(
                    "WITH source AS (
                        SELECT
                            ((ts / %d) * %d) AS bucket_ts,
                            domain_id,
                            edge_node_id,
                            status,
                            COALESCE(cache_status, 'UNKNOWN') AS cache_status,
                            requests_count,
                            bytes_in,
                            bytes_out
                        FROM usage_rollups
                        %s
                    )
                    INSERT INTO usage_aggregates
                    (id, bucket, bucket_ts, domain_id, edge_node_id, status, cache_status, requests_count, bytes_in, bytes_out, created_at, updated_at)
                    SELECT md5((:bucket || ':' || bucket_ts || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || cache_status)::text),
                           :bucket,
                           bucket_ts,
                           domain_id,
                           edge_node_id,
                           status,
                           cache_status,
                           COALESCE(SUM(requests_count),0) AS requests_count,
                           COALESCE(SUM(bytes_in),0) AS bytes_in,
                           COALESCE(SUM(bytes_out),0) AS bytes_out,
                           :now,
                           :now
                    FROM source
                    GROUP BY bucket_ts, domain_id, edge_node_id, status, cache_status",
                    $seconds,
                    $seconds,
                    $seconds,
                    $seconds,
                    $where
                );
                $stmt = $pdo->prepare($sql);
                $params = [':bucket' => $bucket, ':now' => $now];
                if ($domainId !== null) {
                    $params[':domain_id'] = $domainId;
                }
                $stmt->execute($params);
                $inserted[$bucket] = $stmt->rowCount();
            }

            $pdo->commit();
            return [
                'ok' => true,
                'domain_id' => $domainId,
                'inserted' => $inserted,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function cacheAnalytics(?string $domainId = null): array
    {
        $pdo = Database::pdo();
        if (!$this->usageRollupCacheColumnsAvailable()) {
            return [
                'rows' => [],
                'total_requests' => 0,
                'bytes_out' => 0,
                'hit' => 0,
                'miss' => 0,
                'expired' => 0,
                'stale' => 0,
                'bypass' => 0,
                'unknown' => 0,
                'hit_ratio' => 0.0,
            ];
        }

        $sql = "SELECT COALESCE(cache_status, 'UNKNOWN') AS cache_status,
                       COALESCE(SUM(requests_count), 0) AS count,
                       COALESCE(SUM(bytes_out), 0) AS bytes_out
                FROM usage_rollups";
        $params = [];
        if ($domainId !== null && trim($domainId) !== '') {
            $sql .= ' WHERE domain_id = :domain_id';
            $params[':domain_id'] = trim($domainId);
        }
        $sql .= " GROUP BY COALESCE(cache_status, 'UNKNOWN')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        $rowMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = strtoupper(trim((string) ($row['cache_status'] ?? 'UNKNOWN')));
            $rowMap[$status] = [
                'cache_status' => $status,
                'count' => (int) ($row['count'] ?? 0),
                'bytes_out' => (int) ($row['bytes_out'] ?? 0),
            ];
        }

        foreach ($this->cacheStatusOrder as $status) {
            $rows[] = $rowMap[$status] ?? [
                'cache_status' => $status,
                'count' => 0,
                'bytes_out' => 0,
            ];
            unset($rowMap[$status]);
        }
        foreach ($rowMap as $row) {
            $rows[] = $row;
        }

        $hit = 0;
        $miss = 0;
        $expired = 0;
        $stale = 0;
        $bypass = 0;
        $unknown = 0;
        $totalRequests = 0;
        $bytesOut = 0;
        foreach ($rows as $row) {
            $status = (string) $row['cache_status'];
            $count = (int) $row['count'];
            $bytes = (int) $row['bytes_out'];
            $totalRequests += $count;
            $bytesOut += $bytes;
            match ($status) {
                'HIT' => $hit += $count,
                'MISS' => $miss += $count,
                'EXPIRED' => $expired += $count,
                'STALE' => $stale += $count,
                'BYPASS' => $bypass += $count,
                'UNKNOWN' => $unknown += $count,
                default => $unknown += $count,
            };
        }

        $denominator = $hit + $miss + $expired + $stale;
        $ratio = $denominator > 0 ? round($hit / $denominator, 4) : 0.0;

        return [
            'rows' => $rows,
            'total_requests' => $totalRequests,
            'bytes_out' => $bytesOut,
            'hit' => $hit,
            'miss' => $miss,
            'expired' => $expired,
            'stale' => $stale,
            'bypass' => $bypass,
            'unknown' => $unknown,
            'hit_ratio' => $ratio,
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

    private function domainExists(string $domainId): bool
    {
        if ($domainId === '') {
            return false;
        }
        if (array_key_exists($domainId, $this->knownDomainIds)) {
            return $this->knownDomainIds[$domainId];
        }
        $stmt = Database::pdo()->prepare('SELECT 1 FROM domains WHERE id = :domain_id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId]);
        $this->knownDomainIds[$domainId] = $stmt->fetchColumn() !== false;
        return $this->knownDomainIds[$domainId];
    }
}
