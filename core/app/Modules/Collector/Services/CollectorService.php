<?php

namespace App\Modules\Collector\Services;

use App\Support\Database;
use App\Support\Uuid;

class CollectorService
{
    private ?bool $usageRollupCacheColumnsAvailable = null;
    /** @var array<string,bool> */
    private array $knownDomainIds = [];
    /** @var array<string,string> */
    private array $knownDomainHostIds = [];
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
                'INSERT INTO usage_rollups
                 (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status,
                  cache_status, rule_id, request_id, origin_status, origin_time_ms,
                  host, method, path, query_redacted, client_country, origin_id, origin_host,
                  upstream_status, upstream_response_time_ms, upstream_addr, request_time_ms,
                  router_error, security_event_type)
                 VALUES
                 (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status,
                  :cache_status, :rule_id, :request_id, :origin_status, :origin_time_ms,
                  :host, :method, :path, :query_redacted, :client_country, :origin_id, :origin_host,
                  :upstream_status, :upstream_response_time_ms, :upstream_addr, :request_time_ms,
                  :router_error, :security_event_type)'
            )
            : $pdo->prepare(
                'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
                 VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
            );

        $count = 0;
        $skippedUnknownDomains = 0;
        try {
            foreach ($items as $item) {
                $domainId = $this->domainIdFromItem($item);
                if ($domainId === '' || !$this->domainExists($domainId)) {
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
                    $params[':host'] = isset($item['host']) ? (string) $item['host'] : null;
                    $params[':method'] = isset($item['method']) ? (string) $item['method'] : null;
                    $params[':path'] = isset($item['path']) ? (string) $item['path'] : null;
                    $params[':query_redacted'] = isset($item['query']) ? json_encode($item['query'], JSON_UNESCAPED_SLASHES) : (isset($item['query_redacted']) ? json_encode($item['query_redacted'], JSON_UNESCAPED_SLASHES) : null);
                    $params[':client_country'] = isset($item['client_country']) ? (string) $item['client_country'] : null;
                    $params[':origin_id'] = isset($item['origin_id']) ? (string) $item['origin_id'] : null;
                    $params[':origin_host'] = isset($item['origin_host']) ? (string) $item['origin_host'] : null;
                    $params[':upstream_status'] = isset($item['upstream_status']) ? (string) $item['upstream_status'] : null;
                    $params[':upstream_response_time_ms'] = $this->durationMs($item['upstream_response_time'] ?? null);
                    $params[':upstream_addr'] = isset($item['upstream_addr']) ? (string) $item['upstream_addr'] : null;
                    $params[':request_time_ms'] = $this->durationMs($item['request_time'] ?? null);
                    $params[':router_error'] = isset($item['router_error']) ? (string) $item['router_error'] : null;
                    $params[':security_event_type'] = isset($item['security_event_type']) ? (string) $item['security_event_type'] : null;
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
                if (!in_array($event, ['waf_match', 'rate_limited', 'bot_match'], true)) {
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
                    ':resource_type' => $event === 'rate_limited' ? 'rate_limit' : 'waf',
                    ':resource_id' => (string) ($item['rule_id'] ?? ''),
                    ':domain_id' => $domainId,
                    ':details_json' => json_encode([
                        'decision' => (string) ($item['action'] ?? ''),
                        'request_id' => (string) ($item['request_id'] ?? ''),
                        'path' => (string) ($item['path'] ?? ''),
                        'method' => (string) ($item['method'] ?? ''),
                        'client_ip' => $this->securityClientIpValue($item['client_ip'] ?? null),
                        'rate_limit_id' => (string) ($item['rate_limit_id'] ?? $item['rule_id'] ?? ''),
                        'limit_key_type' => (string) ($item['limit_key_type'] ?? ''),
                        'threshold' => (int) ($item['threshold'] ?? 0),
                        'current_count' => (int) ($item['current_count'] ?? 0),
                        'window_seconds' => (int) ($item['window_seconds'] ?? 0),
                        'retry_after' => (int) ($item['retry_after'] ?? 0),
                        'group_id' => (string) ($item['group_id'] ?? ''),
                        'severity' => (string) ($item['severity'] ?? ''),
                        'confidence' => (string) ($item['confidence'] ?? ''),
                        'safe_reason' => (string) ($item['safe_reason'] ?? ''),
                        'bot_class' => (string) ($item['bot_class'] ?? ''),
                        'bot_score' => (int) ($item['bot_score'] ?? 0),
                        'bot_action' => (string) ($item['bot_action'] ?? ''),
                    ], JSON_UNESCAPED_SLASHES),
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

    public function pruneDetailedEvents(?int $retentionDays = null, bool $dryRun = false): array
    {
        $days = $retentionDays ?? $this->analyticsRetentionDays();
        $days = max(1, min(3650, $days));
        $cutoff = time() - ($days * 86400);
        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM usage_rollups WHERE ts < :cutoff');
        $countStmt->execute([':cutoff' => $cutoff]);
        $matching = (int) $countStmt->fetchColumn();

        $deleted = 0;
        if (!$dryRun && $matching > 0) {
            $deleteStmt = $pdo->prepare('DELETE FROM usage_rollups WHERE ts < :cutoff');
            $deleteStmt->execute([':cutoff' => $cutoff]);
            $deleted = $deleteStmt->rowCount();
        }

        return [
            'retention_days' => $days,
            'cutoff' => $cutoff,
            'dry_run' => $dryRun,
            'matching' => $matching,
            'deleted' => $deleted,
        ];
    }

    public function recentRequests(string $domainId, int $limit = 100, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));
        $offset = max(0, $offset);
        $type = isset($filters['type']) ? trim((string) $filters['type']) : '';
        if ($type !== '' && !in_array($type, ['request', 'error'], true)) {
            return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }
        $from = isset($filters['from']) && is_numeric($filters['from']) ? (int) $filters['from'] : null;
        $to = isset($filters['to']) && is_numeric($filters['to']) ? (int) $filters['to'] : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        [$where, $params] = $this->timelineRequestWhere($domainId, null, $from, $to, $search, $type === 'error');
        $count = Database::pdo()->prepare('SELECT COUNT(*) FROM usage_rollups WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $stmt = Database::pdo()->prepare(
            "SELECT id, ts, request_id, domain_id, edge_node_id, host, method, path,
                    query_redacted, client_country, status, bytes_in, bytes_out,
                    cache_status, origin_id, origin_host, upstream_status,
                    upstream_response_time_ms, upstream_addr, request_time_ms,
                    router_error, security_event_type, rule_id
             FROM usage_rollups
             WHERE " . implode(' AND ', $where) . "
             ORDER BY ts DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => array_map([$this, 'castRequestActivity'], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array{items: array<int,array<string,mixed>>, total:int, limit:int, offset:int, cursor:?string}
     */
    public function activityTimeline(string $domainId, array $filters = []): array
    {
        $limit = max(1, min(250, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $type = isset($filters['type']) ? trim((string) $filters['type']) : '';
        $cursor = isset($filters['cursor']) && ctype_digit((string) $filters['cursor']) ? (int) $filters['cursor'] : null;
        $from = isset($filters['from']) && is_numeric($filters['from']) ? (int) $filters['from'] : null;
        $to = isset($filters['to']) && is_numeric($filters['to']) ? (int) $filters['to'] : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        $items = [];
        $total = 0;
        if ($type === '' || $type === 'request' || $type === 'error') {
            $total += $this->countTimelineRequests($domainId, $cursor, $from, $to, $search, $type === 'error');
            foreach ($this->timelineRequests($domainId, $limit + $offset, $cursor, $from, $to, $search, $type === 'error') as $row) {
                $request = $this->castRequestActivity($row);
                $items[] = [
                    'id' => 'request:' . $request['id'],
                    'type' => ((int) ($request['status'] ?? 0) >= 500 || !empty($request['router_error'])) ? 'error' : 'request',
                    'ts' => (int) $request['ts'],
                    'title' => trim(((string) ($request['method'] ?? 'GET')) . ' ' . ((string) ($request['host'] ?? '')) . ((string) ($request['path'] ?? ''))),
                    'summary' => 'HTTP ' . (string) ($request['status'] ?? 0) . ' / ' . (string) ($request['cache_status'] ?? 'UNKNOWN'),
                    'request_id' => $request['request_id'] ?? null,
                    'details' => $request,
                ];
            }
        }
        if ($type === '' || $type === 'audit' || $type === 'security') {
            $total += $this->countTimelineAudit($domainId, $cursor, $from, $to, $search, $type);
            foreach ($this->timelineAudit($domainId, $limit + $offset, $cursor, $from, $to, $search, $type) as $row) {
                $event = (string) ($row['event'] ?? '');
                $items[] = [
                    'id' => 'audit:' . (string) $row['id'],
                    'type' => in_array($event, ['waf_match', 'rate_limited', 'bot_match', 'geo_block'], true) ? 'security' : 'audit',
                    'ts' => (int) $row['created_at'],
                    'title' => (string) ($row['event'] ?: $row['action']),
                    'summary' => (string) $row['resource_type'] . ($row['resource_id'] ? ' ' . (string) $row['resource_id'] : ''),
                    'request_id' => $this->requestIdFromDetails($row['details_json'] ?? null),
                    'details' => [
                        'id' => (string) $row['id'],
                        'actor_type' => (string) $row['actor_type'],
                        'actor_id' => $row['actor_id'],
                        'action' => (string) $row['action'],
                        'resource_type' => (string) $row['resource_type'],
                        'resource_id' => $row['resource_id'],
                        'event' => $row['event'],
                        'details' => $this->decodeJson($row['details_json'] ?? null),
                    ],
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => ($b['ts'] <=> $a['ts']) ?: strcmp((string) $b['id'], (string) $a['id']));
        $items = array_slice($items, $offset, $limit);
        $nextCursor = count($items) === $limit ? (string) min(array_map(static fn (array $item): int => (int) $item['ts'], $items)) : null;

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'cursor' => $nextCursor,
        ];
    }

    public function activitySummary(string $domainId, array $filters = []): array
    {
        $where = ['domain_id = :domain_id'];
        $params = [':domain_id' => $domainId];
        $this->applyTimeFilters($where, $params, $filters, 'ts');
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $pdo = Database::pdo();

        $totals = $pdo->prepare("SELECT COALESCE(SUM(requests_count),0) total_requests,
                                        COALESCE(SUM(bytes_in),0) bytes_in,
                                        COALESCE(SUM(bytes_out),0) bytes_out,
                                        COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 200 AND 299),0) status_2xx,
                                        COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 300 AND 399),0) status_3xx,
                                        COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 400 AND 499),0) status_4xx,
                                        COALESCE(SUM(requests_count) FILTER (WHERE status >= 500),0) status_5xx,
                                        COALESCE(SUM(requests_count) FILTER (WHERE status = 502),0) status_502,
                                        COALESCE(SUM(requests_count) FILTER (WHERE origin_id IS NOT NULL),0) forwarded_requests,
                                        COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) cache_hits,
                                        COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='MISS'),0) cache_misses
                                 FROM usage_rollups {$whereSql}");
        $totals->execute($params);
        $row = (array) $totals->fetch();
        $totalRequests = (int) ($row['total_requests'] ?? 0);
        $hitMiss = (int) ($row['cache_hits'] ?? 0) + (int) ($row['cache_misses'] ?? 0);

        return [
            'total_requests' => $totalRequests,
            'forwarded_requests' => (int) ($row['forwarded_requests'] ?? 0),
            'bytes_in' => (int) ($row['bytes_in'] ?? 0),
            'bytes_out' => (int) ($row['bytes_out'] ?? 0),
            'cache_hit_ratio' => $hitMiss > 0 ? round(((int) $row['cache_hits']) / $hitMiss, 4) : 0.0,
            'status_counts' => [
                '2xx' => (int) ($row['status_2xx'] ?? 0),
                '3xx' => (int) ($row['status_3xx'] ?? 0),
                '4xx' => (int) ($row['status_4xx'] ?? 0),
                '5xx' => (int) ($row['status_5xx'] ?? 0),
                '502' => (int) ($row['status_502'] ?? 0),
            ],
            'top_paths' => $this->topUsageDimension('path', $whereSql, $params),
            'top_origins' => $this->topUsageDimension('origin_id', $whereSql, $params),
            'top_edge_nodes' => $this->topUsageDimension('edge_node_id', $whereSql, $params),
            'recent_origin_errors' => $this->recentOriginErrors($whereSql, $params),
        ];
    }

    public function findRequest(string $domainId, string $requestId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, ts, request_id, domain_id, edge_node_id, host, method, path,
                    query_redacted, client_country, status, bytes_in, bytes_out,
                    cache_status, origin_id, origin_host, upstream_status,
                    upstream_response_time_ms, upstream_addr, request_time_ms,
                    router_error, security_event_type, rule_id
             FROM usage_rollups
             WHERE domain_id=:domain_id AND request_id=:request_id
             ORDER BY ts DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([':domain_id' => $domainId, ':request_id' => $requestId]);
        $row = $stmt->fetch();
        return $row ? $this->castRequestActivity($row) : null;
    }

    public function activityExport(string $domainId, array $filters = []): array
    {
        $timeline = $this->activityTimeline($domainId, ['limit' => $filters['limit'] ?? 250] + $filters);
        return [
            'domain_id' => $domainId,
            'generated_at' => time(),
            'format' => 'json',
            'items' => $timeline['items'],
        ];
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
            $pointsSql = 'SELECT bucket_ts,
                                 COALESCE(SUM(requests_count),0) requests_count,
                                 COALESCE(SUM(bytes_in),0) bytes_in,
                                 COALESCE(SUM(bytes_out),0) bytes_out
                          FROM usage_aggregates
                          WHERE bucket = :bucket';
            $pointParams = [':bucket' => $bucket];
            if ($domainId !== null) {
                $pointsSql .= ' AND domain_id = :domain_id';
                $pointParams[':domain_id'] = $domainId;
            }
            $pointsSql .= ' GROUP BY bucket_ts ORDER BY bucket_ts ASC';
            $pointsStmt = $pdo->prepare($pointsSql);
            $pointsStmt->execute($pointParams);
            $points = [];
            foreach ($pointsStmt->fetchAll() as $point) {
                $points[] = [
                    'bucket_ts' => (int) $point['bucket_ts'],
                    'requests_count' => (int) $point['requests_count'],
                    'bytes_in' => (int) $point['bytes_in'],
                    'bytes_out' => (int) $point['bytes_out'],
                ];
            }
            return [
                'bucket' => $bucket,
                'requests_count' => (int) $row['requests_count'],
                'bytes_in' => (int) $row['bytes_in'],
                'bytes_out' => (int) $row['bytes_out'],
                'records' => (int) $row['records'],
                'points' => $points,
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
                $where = $domainId !== null ? 'WHERE domain_id = :domain_id_filter' : '';
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
                    SELECT md5((:bucket_hash || ':' || bucket_ts || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || cache_status)::text),
                           :bucket_value,
                           bucket_ts,
                           domain_id,
                           edge_node_id,
                           status,
                           cache_status,
                           COALESCE(SUM(requests_count),0) AS requests_count,
                           COALESCE(SUM(bytes_in),0) AS bytes_in,
                           COALESCE(SUM(bytes_out),0) AS bytes_out,
                           :created_at,
                           :updated_at
                    FROM source
                    GROUP BY bucket_ts, domain_id, edge_node_id, status, cache_status",
                    $seconds,
                    $seconds,
                    $where
                );
                $stmt = $pdo->prepare($sql);
                $params = [
                    ':bucket_hash' => $bucket,
                    ':bucket_value' => $bucket,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ];
                if ($domainId !== null) {
                    $params[':domain_id_filter'] = $domainId;
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
             AND column_name IN (
                'cache_status', 'rule_id', 'request_id', 'origin_status', 'origin_time_ms',
                'host', 'method', 'path', 'query_redacted', 'client_country', 'origin_id',
                'origin_host', 'upstream_status', 'upstream_response_time_ms', 'upstream_addr',
                'request_time_ms', 'router_error', 'security_event_type'
             )"
        );
        $stmt->execute();
        $this->usageRollupCacheColumnsAvailable = (int) $stmt->fetchColumn() === 18;
        return $this->usageRollupCacheColumnsAvailable;
    }

    private function durationMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) round(((float) $value) * 1000);
    }

    private function analyticsRetentionDays(): int
    {
        $value = getenv('CDNLITE_ANALYTICS_RETENTION_DAYS');
        if ($value === false || !is_numeric($value)) {
            return 30;
        }
        return max(1, (int) $value);
    }

    private function securityClientIpValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ip = (string) $value;
        if (filter_var(getenv('CDNLITE_STORE_FULL_CLIENT_IP') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            return $ip;
        }
        return 'sha256:' . hash('sha256', $ip);
    }

    private function castRequestActivity(array $row): array
    {
        foreach (['ts', 'status', 'bytes_in', 'bytes_out', 'upstream_response_time_ms', 'request_time_ms'] as $key) {
            if (isset($row[$key]) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['query_redacted'] = isset($row['query_redacted']) && $row['query_redacted'] !== null
            ? (json_decode((string) $row['query_redacted'], true) ?: [])
            : [];
        return $row;
    }

    private function timelineRequests(string $domainId, int $limit, ?int $cursor, ?int $from, ?int $to, string $search, bool $errorsOnly): array
    {
        [$where, $params] = $this->timelineRequestWhere($domainId, $cursor, $from, $to, $search, $errorsOnly);
        $sql = "SELECT id, ts, request_id, domain_id, edge_node_id, host, method, path,
                       query_redacted, client_country, status, bytes_in, bytes_out,
                       cache_status, origin_id, origin_host, upstream_status,
                       upstream_response_time_ms, upstream_addr, request_time_ms,
                       router_error, security_event_type, rule_id
                FROM usage_rollups
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ts DESC, id DESC
                LIMIT {$limit}";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function countTimelineRequests(string $domainId, ?int $cursor, ?int $from, ?int $to, string $search, bool $errorsOnly): int
    {
        [$where, $params] = $this->timelineRequestWhere($domainId, $cursor, $from, $to, $search, $errorsOnly);
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM usage_rollups WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function timelineRequestWhere(string $domainId, ?int $cursor, ?int $from, ?int $to, string $search, bool $errorsOnly): array
    {
        $where = ['domain_id = :domain_id'];
        $params = [':domain_id' => $domainId];
        if ($cursor !== null) {
            $where[] = 'ts < :cursor';
            $params[':cursor'] = $cursor;
        }
        if ($from !== null) {
            $where[] = 'ts >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[] = 'ts <= :to';
            $params[':to'] = $to;
        }
        if ($errorsOnly) {
            $where[] = '(status >= 500 OR router_error IS NOT NULL OR upstream_status LIKE :upstream_error)';
            $params[':upstream_error'] = '5%';
        }
        if ($search !== '') {
            $where[] = '(request_id ILIKE :search OR host ILIKE :search OR path ILIKE :search OR origin_id ILIKE :search OR router_error ILIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        return [$where, $params];
    }

    private function timelineAudit(string $domainId, int $limit, ?int $cursor, ?int $from, ?int $to, string $search, string $type): array
    {
        [$where, $params] = $this->timelineAuditWhere($domainId, $cursor, $from, $to, $search, $type);
        $sql = "SELECT id, actor_type, actor_id, action, resource_type, resource_id, domain_id, details_json, event, created_at
                FROM audit_log
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function countTimelineAudit(string $domainId, ?int $cursor, ?int $from, ?int $to, string $search, string $type): int
    {
        [$where, $params] = $this->timelineAuditWhere($domainId, $cursor, $from, $to, $search, $type);
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function timelineAuditWhere(string $domainId, ?int $cursor, ?int $from, ?int $to, string $search, string $type): array
    {
        $where = ['domain_id = :domain_id'];
        $params = [':domain_id' => $domainId];
        if ($cursor !== null) {
            $where[] = 'created_at < :cursor';
            $params[':cursor'] = $cursor;
        }
        if ($from !== null) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $to;
        }
        if ($type === 'security') {
            $where[] = "event IN ('waf_match','rate_limited','bot_match','geo_block')";
        } elseif ($type === 'audit') {
            $where[] = "(event IS NULL OR event NOT IN ('waf_match','rate_limited','bot_match','geo_block'))";
        }
        if ($search !== '') {
            $where[] = '(details_json ILIKE :search OR event ILIKE :search OR action ILIKE :search OR resource_type ILIKE :search OR resource_id ILIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        return [$where, $params];
    }

    private function applyTimeFilters(array &$where, array &$params, array $filters, string $column): void
    {
        if (isset($filters['from']) && is_numeric($filters['from'])) {
            $where[] = "{$column} >= :from";
            $params[':from'] = (int) $filters['from'];
        }
        if (isset($filters['to']) && is_numeric($filters['to'])) {
            $where[] = "{$column} <= :to";
            $params[':to'] = (int) $filters['to'];
        }
    }

    private function topUsageDimension(string $column, string $whereSql, array $params): array
    {
        $stmt = Database::pdo()->prepare("SELECT COALESCE({$column}, 'unknown') AS value,
                                                 COALESCE(SUM(requests_count),0) AS count
                                          FROM usage_rollups {$whereSql}
                                          GROUP BY COALESCE({$column}, 'unknown')
                                          ORDER BY count DESC, value ASC
                                          LIMIT 10");
        $stmt->execute($params);
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $stmt->fetchAll());
    }

    private function recentOriginErrors(string $whereSql, array $params): array
    {
        $stmt = Database::pdo()->prepare("SELECT id, ts, request_id, domain_id, edge_node_id, host, method, path,
                                                 query_redacted, client_country, status, bytes_in, bytes_out,
                                                 cache_status, origin_id, origin_host, upstream_status,
                                                 upstream_response_time_ms, upstream_addr, request_time_ms,
                                                 router_error, security_event_type, rule_id
                                          FROM usage_rollups {$whereSql}
                                          AND (status >= 500 OR router_error IS NOT NULL OR upstream_status LIKE '5%')
                                          ORDER BY ts DESC, id DESC
                                          LIMIT 10");
        $stmt->execute($params);
        return array_map([$this, 'castRequestActivity'], $stmt->fetchAll());
    }

    private function decodeJson(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function requestIdFromDetails(?string $value): ?string
    {
        $decoded = $this->decodeJson($value);
        if (!is_array($decoded) || !isset($decoded['request_id'])) {
            return null;
        }
        return is_scalar($decoded['request_id']) ? (string) $decoded['request_id'] : null;
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

    /**
     * Prefer the explicit domain_id from the edge metric, but fall back to the
     * request host when internal redirects drop the original routing context.
     */
    private function domainIdFromItem(array $item): string
    {
        $domainId = trim((string) ($item['domain_id'] ?? ''));
        if ($domainId !== '') {
            return $domainId;
        }

        $host = trim((string) ($item['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        if (array_key_exists($host, $this->knownDomainHostIds)) {
            return $this->knownDomainHostIds[$host];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id FROM domains WHERE domain = :host OR name = :host LIMIT 1'
        );
        $stmt->execute([':host' => $host]);
        $resolved = $stmt->fetchColumn();
        if (!is_string($resolved) || trim($resolved) === '') {
            $this->knownDomainHostIds[$host] = '';
            return '';
        }

        $this->knownDomainIds[$resolved] = true;
        $this->knownDomainHostIds[$host] = $resolved;
        return $resolved;
    }
}
