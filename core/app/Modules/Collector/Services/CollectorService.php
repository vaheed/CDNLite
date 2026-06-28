<?php

namespace App\Modules\Collector\Services;

use App\Support\Database;
use App\Support\DatabaseWorkload;
use App\Support\Uuid;

class CollectorService
{
    private ?bool $usageRollupCacheColumnsAvailable = null;
    private ?bool $usageRollupClientIpColumnAvailable = null;
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

    private const DEFAULT_ANALYTICS_POINTS = 500;

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
        if ($this->usageRollupCacheColumnsAvailable()) {
            $clientIpColumn = $this->usageRollupClientIpColumnAvailable() ? 'client_ip, ' : '';
            $clientIpParam = $this->usageRollupClientIpColumnAvailable() ? ':client_ip, ' : '';
            $stmt = $pdo->prepare(sprintf(
                'INSERT INTO usage_rollups
                 (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status,
                  cache_status, rule_id, request_id, origin_status, origin_time_ms,
                  host, method, path, query_redacted, %sclient_country, origin_id, origin_host,
                  upstream_status, upstream_response_time_ms, upstream_addr, request_time_ms,
                  router_error, security_event_type)
                 VALUES
                 (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status,
                  :cache_status, :rule_id, :request_id, :origin_status, :origin_time_ms,
                  :host, :method, :path, :query_redacted, %s:client_country, :origin_id, :origin_host,
                  :upstream_status, :upstream_response_time_ms, :upstream_addr, :request_time_ms,
                  :router_error, :security_event_type)',
                $clientIpColumn,
                $clientIpParam
            ));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO usage_rollups (id, ts, domain_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
                 VALUES (:id, :ts, :domain_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
            );
        }

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
                    if ($this->usageRollupClientIpColumnAvailable()) {
                        $params[':client_ip'] = isset($item['client_ip']) ? (string) $item['client_ip'] : null;
                    }
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
                $this->recordOriginObservation($domainId, $item, $now);
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

    public function originHealth(string $domainId): array
    {
        $pdo = Database::pdo();
        $origins = $pdo->prepare(
            'SELECT id, host, role, enabled, health_check_enabled, health_status, last_check_at, last_error
             FROM domain_origins
             WHERE domain_id=:domain_id
             ORDER BY enabled DESC, role ASC, host ASC, id ASC'
        );
        $origins->execute([':domain_id' => $domainId]);
        $rows = $origins->fetchAll();

        $observations = $pdo->prepare(
            'SELECT oho.*, en.region, en.country, en.hostname
             FROM origin_health_observations oho
             LEFT JOIN edge_nodes en ON en.edge_id=oho.edge_node_id
             WHERE oho.domain_id=:domain_id
             ORDER BY oho.last_observed_at DESC, oho.origin_id ASC, oho.edge_node_id ASC'
        );
        $observations->execute([':domain_id' => $domainId]);
        $byOrigin = [];
        foreach ($observations->fetchAll() as $row) {
            $originId = (string) $row['origin_id'];
            $byOrigin[$originId] ??= [];
            $byOrigin[$originId][] = $this->castOriginObservation($row);
        }

        $items = [];
        foreach ($rows as $origin) {
            $originId = (string) $origin['id'];
            $edgeRows = $byOrigin[$originId] ?? [];
            $items[] = [
                'origin_id' => $originId,
                'host' => (string) $origin['host'],
                'role' => (string) ($origin['role'] ?? 'primary'),
                'enabled' => in_array($origin['enabled'], [true, 1, '1', 't', 'true'], true),
                'health_check_enabled' => in_array($origin['health_check_enabled'], [true, 1, '1', 't', 'true'], true),
                'status' => (string) ($origin['health_status'] ?? 'unknown'),
                'last_check_at' => $origin['last_check_at'] === null ? null : (int) $origin['last_check_at'],
                'last_error' => $origin['last_error'] === null ? null : (string) $origin['last_error'],
                'edge_count' => count($edgeRows),
                'healthy_edges' => count(array_filter($edgeRows, static fn (array $r): bool => $r['status'] === 'healthy')),
                'slow_edges' => count(array_filter($edgeRows, static fn (array $r): bool => $r['status'] === 'slow')),
                'unhealthy_edges' => count(array_filter($edgeRows, static fn (array $r): bool => $r['status'] === 'unhealthy')),
                'max_latency_ms' => $this->maxObservationValue($edgeRows, 'latency_ms'),
                'max_jitter_ms' => $this->maxObservationValue($edgeRows, 'jitter_ms'),
                'edges' => $edgeRows,
            ];
        }

        return [
            'items' => $items,
            'source' => 'edge_observations',
            'core_active_checks' => false,
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
            $batchSize = $this->retentionBatchSize();
            // Keep the legacy single-table prune bounded so manual runs do not
            // hold one oversized transaction on high-volume usage_rollups.
            do {
                $deleteStmt = $pdo->prepare(
                    "WITH doomed AS (
                        SELECT ctid FROM usage_rollups
                        WHERE ts < :cutoff
                        ORDER BY ts ASC
                        LIMIT {$batchSize}
                    )
                    DELETE FROM usage_rollups
                    WHERE ctid IN (SELECT ctid FROM doomed)"
                );
                $deleteStmt->execute([':cutoff' => $cutoff]);
                $batchDeleted = $deleteStmt->rowCount();
                $deleted += $batchDeleted;
            } while ($batchDeleted === $batchSize);
        }

        return [
            'retention_days' => $days,
            'cutoff' => $cutoff,
            'dry_run' => $dryRun,
            'matching' => $matching,
            'deleted' => $deleted,
        ];
    }

    public function pruneOperationalRetention(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $batchSize = max(100, min(50000, (int) ($options['batch_size'] ?? $this->retentionBatchSize())));
        $usageDays = $this->retentionDays($options['usage_days'] ?? null, 'CDNLITE_ANALYTICS_RETENTION_DAYS', 30, 1, 3650);
        $securityDays = $this->retentionDays($options['security_days'] ?? null, 'CDNLITE_SECURITY_EVENT_RETENTION_DAYS', 90, 1, 3650);
        $dnsDays = $this->retentionDays($options['dns_days'] ?? null, 'CDNLITE_DNS_EVENT_RETENTION_DAYS', 30, 1, 3650);
        $sslJobDays = $this->retentionDays($options['ssl_job_days'] ?? null, 'CDNLITE_SSL_JOB_RETENTION_DAYS', 180, 1, 3650);
        $idempotencyDays = $this->retentionDays($options['idempotency_days'] ?? null, 'CDNLITE_INGEST_KEY_RETENTION_DAYS', 7, 1, 3650);
        $now = time();

        return [
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'usage_rollups' => $this->pruneTableByCutoff(
                'usage_rollups',
                'ts',
                $now - ($usageDays * 86400),
                $usageDays,
                $dryRun,
                $batchSize,
            ),
            'security_events' => $this->pruneTableByCutoff(
                'audit_log',
                'created_at',
                $now - ($securityDays * 86400),
                $securityDays,
                $dryRun,
                $batchSize,
                "event IN ('waf_match','rate_limited','bot_match','geo_block')",
            ),
            'dns_sync_events' => $this->pruneTableByCutoff(
                'dns_sync_events',
                'created_at',
                $now - ($dnsDays * 86400),
                $dnsDays,
                $dryRun,
                $batchSize,
                "status IN ('success','verified')",
            ),
            'ssl_jobs' => $this->pruneTableByCutoff(
                'ssl_jobs',
                'created_at',
                $now - ($sslJobDays * 86400),
                $sslJobDays,
                $dryRun,
                $batchSize,
                "status IN ('issued','failed','cancelled')",
            ),
            'usage_ingest_keys' => $this->pruneTableByCutoff(
                'usage_ingest_keys',
                'created_at',
                $now - ($idempotencyDays * 86400),
                $idempotencyDays,
                $dryRun,
                $batchSize,
            ),
            'edge_request_nonces' => $this->pruneTableByCutoff(
                'edge_request_nonces',
                'expires_at',
                $now,
                0,
                $dryRun,
                $batchSize,
            ),
        ];
    }

    private function pruneTableByCutoff(
        string $table,
        string $column,
        int $cutoff,
        int $retentionDays,
        bool $dryRun,
        int $batchSize,
        ?string $extraWhere = null,
    ): array {
        $pdo = Database::pdo();
        $where = "{$column} < :cutoff" . ($extraWhere !== null ? " AND {$extraWhere}" : '');
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $countStmt->execute([':cutoff' => $cutoff]);
        $matching = (int) $countStmt->fetchColumn();
        $deleted = 0;

        if (!$dryRun && $matching > 0) {
            // Delete in bounded batches so a production prune does not hold one
            // large table lock or generate a single oversized transaction.
            do {
                $deleteStmt = $pdo->prepare(
                    "WITH doomed AS (
                        SELECT ctid FROM {$table}
                        WHERE {$where}
                        ORDER BY {$column} ASC
                        LIMIT {$batchSize}
                    )
                    DELETE FROM {$table}
                    WHERE ctid IN (SELECT ctid FROM doomed)"
                );
                $deleteStmt->execute([':cutoff' => $cutoff]);
                $batchDeleted = $deleteStmt->rowCount();
                $deleted += $batchDeleted;
            } while ($batchDeleted === $batchSize);
        }

        return [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff,
            'matching' => $matching,
            'deleted' => $deleted,
        ];
    }

    private function retentionDays(mixed $value, string $envName, int $default, int $min, int $max): int
    {
        if ($value !== null && is_numeric($value)) {
            return max($min, min($max, (int) $value));
        }
        $env = getenv($envName);
        if ($env !== false && is_numeric($env)) {
            return max($min, min($max, (int) $env));
        }
        return $default;
    }

    private function retentionBatchSize(): int
    {
        $env = getenv('CDNLITE_RETENTION_BATCH_SIZE');
        return $env !== false && is_numeric($env) ? (int) $env : 5000;
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
            "SELECT " . $this->usageRollupRequestSelectColumns() . "
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
                $friendly = $this->friendlyRequestActivity($request);
                $items[] = [
                    'id' => 'request:' . $request['id'],
                    'type' => ((int) ($request['status'] ?? 0) >= 500 || !empty($request['router_error'])) ? 'error' : 'request',
                    'ts' => (int) $request['ts'],
                    'title' => $friendly['title'],
                    'summary' => $friendly['summary'],
                    'request_id' => $request['request_id'] ?? null,
                    'friendly' => $friendly,
                    'details' => $request,
                ];
            }
        }
        if ($type === '' || $type === 'audit' || $type === 'security') {
            $total += $this->countTimelineAudit($domainId, $cursor, $from, $to, $search, $type);
            foreach ($this->timelineAudit($domainId, $limit + $offset, $cursor, $from, $to, $search, $type) as $row) {
                $event = (string) ($row['event'] ?? '');
                $details = $this->decodeJson($row['details_json'] ?? null);
                $friendly = $this->friendlyAuditActivity($event, (string) $row['action'], (string) $row['resource_type'], is_array($details) ? $details : []);
                $items[] = [
                    'id' => 'audit:' . (string) $row['id'],
                    'type' => in_array($event, ['waf_match', 'rate_limited', 'bot_match', 'geo_block'], true) ? 'security' : 'audit',
                    'ts' => (int) $row['created_at'],
                    'title' => $friendly['title'],
                    'summary' => $friendly['summary'],
                    'request_id' => $this->requestIdFromDetails($row['details_json'] ?? null),
                    'friendly' => $friendly,
                    'details' => [
                        'id' => (string) $row['id'],
                        'actor_type' => (string) $row['actor_type'],
                        'actor_id' => $row['actor_id'],
                        'action' => (string) $row['action'],
                        'resource_type' => (string) $row['resource_type'],
                        'resource_id' => $row['resource_id'],
                        'event' => $row['event'],
                        'details' => $details,
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
            'top_countries' => $this->topUsageDimension('client_country', $whereSql, $params),
            'top_origins' => $this->topUsageDimension('origin_id', $whereSql, $params),
            'top_edge_nodes' => $this->topUsageDimension('edge_node_id', $whereSql, $params),
            'recent_origin_errors' => $this->recentOriginErrors($whereSql, $params),
            'beginner' => $this->beginnerActivitySummary($domainId, $filters, (int) ($row['status_5xx'] ?? 0)),
        ];
    }

    public function findRequest(string $domainId, string $requestId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT " . $this->usageRollupRequestSelectColumns() . "
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
            DatabaseWorkload::apply($pdo, DatabaseWorkload::REPORTING);
            $range = $this->analyticsRange([], $bucket);
            if ($domainId !== null) {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(requests_count),0) requests_count,
                            COALESCE(SUM(bytes_in),0) bytes_in,
                            COALESCE(SUM(bytes_out),0) bytes_out,
                            COUNT(*) records
                    FROM usage_aggregates
                    WHERE bucket = :bucket AND domain_id = :domain_id AND bucket_ts >= :from AND bucket_ts < :to'
                );
                $stmt->execute([':bucket' => $bucket, ':domain_id' => $domainId, ':from' => $range['from'], ':to' => $range['to']]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(requests_count),0) requests_count,
                            COALESCE(SUM(bytes_in),0) bytes_in,
                            COALESCE(SUM(bytes_out),0) bytes_out,
                            COUNT(*) records
                    FROM usage_aggregates
                    WHERE bucket = :bucket AND bucket_ts >= :from AND bucket_ts < :to'
                );
                $stmt->execute([':bucket' => $bucket, ':from' => $range['from'], ':to' => $range['to']]);
            }
            $row = (array) $stmt->fetch();

            if ((int) ($row['records'] ?? 0) === 0) {
                // Legacy CLI and contract tests may summarize manually seeded
                // historical buckets, while dashboard analytics stay bounded by
                // the points query below.
                if ($domainId !== null) {
                    $fallbackStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(requests_count),0) requests_count,
                                COALESCE(SUM(bytes_in),0) bytes_in,
                                COALESCE(SUM(bytes_out),0) bytes_out,
                                COUNT(*) records
                        FROM usage_aggregates
                        WHERE bucket = :bucket AND domain_id = :domain_id'
                    );
                    $fallbackStmt->execute([':bucket' => $bucket, ':domain_id' => $domainId]);
                } else {
                    $fallbackStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(requests_count),0) requests_count,
                                COALESCE(SUM(bytes_in),0) bytes_in,
                                COALESCE(SUM(bytes_out),0) bytes_out,
                                COUNT(*) records
                        FROM usage_aggregates
                        WHERE bucket = :bucket'
                    );
                    $fallbackStmt->execute([':bucket' => $bucket]);
                }
                $row = (array) $fallbackStmt->fetch();
            }

            $pointsSql = 'SELECT bucket_ts,
                                 COALESCE(SUM(requests_count),0) requests_count,
                                 COALESCE(SUM(bytes_in),0) bytes_in,
                                 COALESCE(SUM(bytes_out),0) bytes_out
                          FROM usage_aggregates
                          WHERE bucket = :bucket AND bucket_ts >= :from AND bucket_ts < :to';
            $pointParams = [':bucket' => $bucket, ':from' => $range['from'], ':to' => $range['to']];
            if ($domainId !== null) {
                $pointsSql .= ' AND domain_id = :domain_id';
                $pointParams[':domain_id'] = $domainId;
            }
            $pointsSql .= ' GROUP BY bucket_ts ORDER BY bucket_ts ASC LIMIT :limit_points';
            $pointsStmt = $pdo->prepare($pointsSql);
            foreach ($pointParams as $key => $value) {
                $pointsStmt->bindValue($key, $value);
            }
            $pointsStmt->bindValue(':limit_points', $range['limit_points'], \PDO::PARAM_INT);
            $pointsStmt->execute();
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
                'effective_range' => ['from' => $range['from'], 'to' => $range['to'], 'timezone' => 'UTC'],
                'point_count' => count($points),
                'limit_points' => $range['limit_points'],
                'freshness' => $this->analyticsFreshness($domainId, $bucket),
                'aggregation_watermark' => $this->aggregationWatermark($domainId, $bucket),
                'partial_data' => count($points) >= $range['limit_points'],
                'query_id' => sha1(json_encode([$domainId, $bucket, $range])),
                'cache_status' => 'miss',
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

    public function rebuildAggregates(?string $domainId = null, ?string $bucket = null): array
    {
        if ($bucket !== null && !isset($this->bucketSeconds[$bucket])) {
            throw new \InvalidArgumentException('invalid_bucket');
        }
        $jobId = Uuid::v4();
        $now = time();
        $range = $bucket === null ? ['from' => null, 'to' => null] : $this->analyticsRange([], $bucket);
        $stmt = Database::pdo()->prepare(
            "INSERT INTO analytics_rollup_jobs
             (id, domain_id, bucket, range_start, range_end, status, requested_by, progress_json, created_at, updated_at)
             VALUES (:id, :domain_id, :bucket, :range_start, :range_end, 'queued', 'api', '{}'::jsonb, :created_at, :updated_at)"
        );
        $stmt->execute([
            ':id' => $jobId,
            ':domain_id' => $domainId,
            ':bucket' => $bucket,
            ':range_start' => $range['from'],
            ':range_end' => $range['to'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $run = $this->runNextRollupJob('inline', $jobId);

        return [
            'ok' => true,
            'accepted' => true,
            'status' => 202,
            'job_id' => $jobId,
            'domain_id' => $domainId,
            'bucket' => $bucket,
            'range' => ['from' => $range['from'], 'to' => $range['to']],
            'job_status' => ($run['ran'] ?? false) ? 'succeeded' : 'queued',
            'inserted' => $run['inserted'] ?? [],
        ];
    }

    public function rollupJob(string $jobId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM analytics_rollup_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();
        return $row ? $this->castRollupJob((array) $row) : null;
    }

    public function runNextRollupJob(string $workerId = 'local-worker', ?string $jobId = null): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        if ($jobId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM analytics_rollup_jobs WHERE id = :id AND status = 'queued' LIMIT 1 FOR UPDATE SKIP LOCKED");
            $stmt->execute([':id' => $jobId]);
        } else {
            $stmt = $pdo->query("SELECT * FROM analytics_rollup_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        }
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->commit();
            return ['ok' => true, 'ran' => false, 'reason' => 'no_queued_jobs'];
        }
        $job = (array) $row;
        $now = time();
        $update = $pdo->prepare("UPDATE analytics_rollup_jobs SET status='running', locked_by=:worker, locked_at=:now, started_at=:now, updated_at=:now WHERE id=:id");
        $update->execute([':worker' => $workerId, ':now' => $now, ':id' => $job['id']]);
        $pdo->commit();

        try {
            $inserted = $this->runIncrementalAggregates($job['domain_id'] ?? null, $job['bucket'] ?? null, isset($job['range_start']) ? (int) $job['range_start'] : null, isset($job['range_end']) ? (int) $job['range_end'] : null);
            $finished = time();
            $done = $pdo->prepare("UPDATE analytics_rollup_jobs SET status='succeeded', progress_json=CAST(:progress AS jsonb), finished_at=:now, updated_at=:now WHERE id=:id");
            $done->execute([':progress' => json_encode(['inserted' => $inserted]), ':now' => $finished, ':id' => $job['id']]);
            return ['ok' => true, 'ran' => true, 'job_id' => $job['id'], 'inserted' => $inserted];
        } catch (\Throwable $e) {
            $failed = $pdo->prepare("UPDATE analytics_rollup_jobs SET status='failed', error=:error, updated_at=:now WHERE id=:id");
            $failed->execute([':error' => $e->getMessage(), ':now' => time(), ':id' => $job['id']]);
            throw $e;
        }
    }

    private function runIncrementalAggregates(?string $domainId = null, ?string $onlyBucket = null, ?int $rangeStart = null, ?int $rangeEnd = null): array
    {
        $pdo = Database::pdo();
        DatabaseWorkload::apply($pdo, DatabaseWorkload::JOBS);
        $now = time();
        $inserted = [];
        $pdo->beginTransaction();
        try {
            $inserted = [];
            foreach ($this->bucketSeconds as $bucket => $seconds) {
                if ($onlyBucket !== null && $bucket !== $onlyBucket) {
                    continue;
                }
                $whereParts = [];
                if ($domainId !== null) {
                    $whereParts[] = 'domain_id = :domain_id_filter';
                }
                if ($rangeStart !== null) {
                    $whereParts[] = 'ts >= :range_start';
                }
                if ($rangeEnd !== null) {
                    $whereParts[] = 'ts < :range_end';
                }
                $where = $whereParts === [] ? '' : 'WHERE ' . implode(' AND ', $whereParts);
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
                    SELECT md5((:bucket_hash || ':' || bucket_ts || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || COALESCE(cache_status, 'UNKNOWN'))::text),
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
                    GROUP BY bucket_ts, domain_id, edge_node_id, status, cache_status
                    ON CONFLICT (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)
                    DO UPDATE SET requests_count = EXCLUDED.requests_count,
                                  bytes_in = EXCLUDED.bytes_in,
                                  bytes_out = EXCLUDED.bytes_out,
                                  updated_at = EXCLUDED.updated_at",
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
                if ($rangeStart !== null) {
                    $params[':range_start'] = $rangeStart;
                }
                if ($rangeEnd !== null) {
                    $params[':range_end'] = $rangeEnd;
                }
                $stmt->execute($params);
                $inserted[$bucket] = $stmt->rowCount();
                $this->updateRollupWatermark($domainId, $bucket, $now);
            }

            $pdo->commit();
            return $inserted;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function analyticsRange(array $query, string $bucket): array
    {
        $budget = DatabaseWorkload::budget(DatabaseWorkload::REPORTING);
        $now = time();
        $to = isset($query['to']) && is_numeric($query['to']) ? (int) $query['to'] : $now;
        $from = isset($query['from']) && is_numeric($query['from']) ? (int) $query['from'] : $to - 86400;
        $maxRange = (int) ($budget['max_query_range_seconds'] ?? 31622400);
        if ($from >= $to || ($to - $from) > $maxRange) {
            $from = $to - min(86400, $maxRange);
        }
        $limit = isset($query['limit_points']) && is_numeric($query['limit_points']) ? (int) $query['limit_points'] : self::DEFAULT_ANALYTICS_POINTS;
        return [
            'from' => (int) floor($from / $this->bucketSeconds[$bucket]) * $this->bucketSeconds[$bucket],
            'to' => $to,
            'limit_points' => max(1, min(self::DEFAULT_ANALYTICS_POINTS, $limit)),
        ];
    }

    private function analyticsFreshness(?string $domainId, string $bucket): array
    {
        $sql = 'SELECT COALESCE(MAX(bucket_ts), 0) FROM usage_aggregates WHERE bucket = :bucket';
        $params = [':bucket' => $bucket];
        if ($domainId !== null) {
            $sql .= ' AND domain_id = :domain_id';
            $params[':domain_id'] = $domainId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $latest = (int) $stmt->fetchColumn();
        return ['latest_bucket_ts' => $latest, 'lag_seconds' => $latest > 0 ? max(0, time() - $latest) : null];
    }

    private function aggregationWatermark(?string $domainId, string $bucket): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT source_watermark_ts, last_success_at, last_error
             FROM reporting_rollup_watermarks
             WHERE stream='usage' AND bucket=:bucket AND domain_id=:domain_id LIMIT 1"
        );
        $stmt->execute([':bucket' => $bucket, ':domain_id' => $domainId ?? '*']);
        $row = $stmt->fetch();
        return $row ? ['source_watermark_ts' => (int) $row['source_watermark_ts'], 'last_success_at' => (int) $row['last_success_at'], 'last_error' => $row['last_error']] : null;
    }

    private function updateRollupWatermark(?string $domainId, string $bucket, int $now): void
    {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO reporting_rollup_watermarks (stream, bucket, domain_id, source_watermark_ts, last_success_at, last_error, updated_at)
             VALUES ('usage', :bucket, :domain_id, :watermark, :now, NULL, :now)
             ON CONFLICT (stream, bucket, domain_id)
             DO UPDATE SET source_watermark_ts=EXCLUDED.source_watermark_ts, last_success_at=EXCLUDED.last_success_at, last_error=NULL, updated_at=EXCLUDED.updated_at"
        );
        $stmt->execute([':bucket' => $bucket, ':domain_id' => $domainId ?? '*', ':watermark' => $now, ':now' => $now]);
    }

    private function castRollupJob(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'domain_id' => $row['domain_id'],
            'bucket' => $row['bucket'],
            'range_start' => $row['range_start'] === null ? null : (int) $row['range_start'],
            'range_end' => $row['range_end'] === null ? null : (int) $row['range_end'],
            'status' => (string) $row['status'],
            'progress' => json_decode((string) ($row['progress_json'] ?? '{}'), true) ?: [],
            'error' => $row['error'],
            'cancel_requested' => (bool) $row['cancel_requested'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'started_at' => $row['started_at'] === null ? null : (int) $row['started_at'],
            'finished_at' => $row['finished_at'] === null ? null : (int) $row['finished_at'],
        ];
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

    private function usageRollupClientIpColumnAvailable(): bool
    {
        if ($this->usageRollupClientIpColumnAvailable !== null) {
            return $this->usageRollupClientIpColumnAvailable;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema='public' AND table_name='usage_rollups'
             AND column_name = 'client_ip'"
        );
        $stmt->execute();
        $this->usageRollupClientIpColumnAvailable = (int) $stmt->fetchColumn() === 1;
        return $this->usageRollupClientIpColumnAvailable;
    }

    private function usageRollupRequestSelectColumns(): string
    {
        $clientIp = $this->usageRollupClientIpColumnAvailable() ? 'client_ip' : 'NULL AS client_ip';
        return "id, ts, request_id, domain_id, edge_node_id, host, method, path,
                query_redacted, {$clientIp}, client_country, status, bytes_in, bytes_out,
                cache_status, origin_id, origin_host, upstream_status,
                upstream_response_time_ms, upstream_addr, request_time_ms,
                router_error, security_event_type, rule_id";
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

    /**
     * Beginner Activity labels are derived from raw events so advanced exports
     * keep their original request, audit, and security payloads unchanged.
     */
    private function friendlyRequestActivity(array $request): array
    {
        $status = (int) ($request['status'] ?? 0);
        $cache = strtoupper((string) ($request['cache_status'] ?? 'UNKNOWN'));
        $methodPath = trim(((string) ($request['method'] ?? 'GET')) . ' ' . ((string) ($request['path'] ?? '/')));
        if ($status >= 500 || !empty($request['router_error'])) {
            return [
                'category' => 'origin',
                'intent' => 'origin_health',
                'label' => 'Origin error detected',
                'title' => 'Origin error detected',
                'summary' => 'CDNLite saw HTTP ' . $status . ' while serving ' . ($methodPath !== '' ? $methodPath : 'a request') . '.',
                'severity' => 'warning',
                'recommendation' => 'Check the origin health and recent deploys.',
            ];
        }
        if ($cache === 'HIT') {
            return [
                'category' => 'cache',
                'intent' => 'cache_static_assets',
                'label' => 'Cached request served',
                'title' => 'Cached request served',
                'summary' => 'A request was served from cache without reaching the origin.',
                'severity' => 'info',
                'recommendation' => null,
            ];
        }
        return [
            'category' => 'request',
            'intent' => 'traffic',
            'label' => 'Request served',
            'title' => trim(((string) ($request['method'] ?? 'GET')) . ' ' . ((string) ($request['host'] ?? '')) . ((string) ($request['path'] ?? ''))),
            'summary' => 'HTTP ' . (string) $status . ' / ' . $cache,
            'severity' => 'info',
            'recommendation' => null,
        ];
    }

    private function friendlyAuditActivity(string $event, string $action, string $resourceType, array $details): array
    {
        if ($event === 'waf_match') {
            return [
                'category' => 'waf',
                'intent' => (string) ($details['group_id'] ?? 'common_exploits'),
                'label' => 'Blocked exploit attempt',
                'title' => 'Blocked exploit attempt',
                'summary' => $this->decisionSummary($details, 'A WAF rule matched suspicious traffic.'),
                'severity' => (string) ($details['severity'] ?? 'warning'),
                'recommendation' => null,
            ];
        }
        if ($event === 'rate_limited') {
            $path = (string) ($details['path'] ?? '');
            $login = preg_match('~/(login|wp-login|admin|session|auth)~i', $path) === 1;
            return [
                'category' => 'rate_limit',
                'intent' => $login ? 'login_shield' : 'smart_rate_limiting',
                'label' => $login ? 'Stopped too many login requests' : 'Stopped too many requests',
                'title' => $login ? 'Stopped too many login requests' : 'Stopped too many requests',
                'summary' => $this->decisionSummary($details, 'A rate limit protected the origin from repeated requests.'),
                'severity' => 'warning',
                'recommendation' => $login ? 'Enable Login Shield' : null,
            ];
        }
        if ($event === 'bot_match') {
            return [
                'category' => 'bot',
                'intent' => 'bot_shield',
                'label' => 'Challenged suspicious bot',
                'title' => 'Challenged suspicious bot',
                'summary' => $this->decisionSummary($details, 'Bot protection identified suspicious automation.'),
                'severity' => 'warning',
                'recommendation' => 'Review Bot Shield mode.',
            ];
        }
        if (str_starts_with($event, 'ssl.')) {
            return [
                'category' => 'ssl',
                'intent' => 'ssl_lifecycle',
                'label' => $event === 'ssl.issued' ? 'SSL certificate issued' : 'SSL action recorded',
                'title' => $event === 'ssl.issued' ? 'SSL certificate issued' : 'SSL action recorded',
                'summary' => 'Certificate automation recorded ' . $event . '.',
                'severity' => $event === 'ssl.failed' ? 'warning' : 'info',
                'recommendation' => $event === 'ssl.failed' ? 'Check SSL validation details.' : null,
            ];
        }
        if (str_starts_with($event, 'dns.') || str_contains($resourceType, 'dns')) {
            return [
                'category' => 'dns',
                'intent' => 'dns_publishing',
                'label' => 'DNS change published',
                'title' => 'DNS change published',
                'summary' => 'DNS publishing recorded ' . ($event !== '' ? $event : $action) . '.',
                'severity' => 'info',
                'recommendation' => null,
            ];
        }
        if (str_contains($event . '.' . $action . '.' . $resourceType, 'cache')) {
            return [
                'category' => 'cache',
                'intent' => 'cache_static_assets',
                'label' => 'Cache action recorded',
                'title' => 'Cache action recorded',
                'summary' => 'A cache setting, rule, or purge changed.',
                'severity' => 'info',
                'recommendation' => null,
            ];
        }
        return [
            'category' => 'audit',
            'intent' => 'change_log',
            'label' => 'Change recorded',
            'title' => (string) ($event !== '' ? $event : $action),
            'summary' => $resourceType !== '' ? $resourceType : 'Administrative change',
            'severity' => 'info',
            'recommendation' => null,
        ];
    }

    private function decisionSummary(array $details, string $fallback): string
    {
        $decision = trim((string) ($details['decision'] ?? $details['bot_action'] ?? ''));
        $path = trim((string) ($details['path'] ?? ''));
        if ($decision !== '' && $path !== '') {
            return ucfirst($decision) . ' on ' . $path . '.';
        }
        if ($path !== '') {
            return $fallback . ' Path: ' . $path . '.';
        }
        return $fallback;
    }

    private function beginnerActivitySummary(string $domainId, array $filters, int $originErrorCount): array
    {
        $where = ['domain_id = :domain_id'];
        $params = [':domain_id' => $domainId];
        $this->applyTimeFilters($where, $params, $filters, 'created_at');
        $stmt = Database::pdo()->prepare(
            "SELECT event, COUNT(*) AS count
             FROM audit_log
             WHERE " . implode(' AND ', $where) . "
             AND event IN ('waf_match','rate_limited','bot_match','ssl.issued','ssl.failed')
             GROUP BY event"
        );
        $stmt->execute($params);
        $counts = [
            'exploit_attempts' => 0,
            'suspicious_bots' => 0,
            'login_abuse_attempts' => 0,
            'origin_errors' => $originErrorCount,
            'ssl_actions' => 0,
            'dns_changes' => 0,
            'cache_actions' => 0,
            'audit_changes' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $event = (string) $row['event'];
            $count = (int) $row['count'];
            if ($event === 'waf_match') {
                $counts['exploit_attempts'] += $count;
            } elseif ($event === 'bot_match') {
                $counts['suspicious_bots'] += $count;
            } elseif ($event === 'rate_limited') {
                $counts['login_abuse_attempts'] += $count;
            } elseif (str_starts_with($event, 'ssl.')) {
                $counts['ssl_actions'] += $count;
            }
        }

        $this->beginnerAuditCategoryCounts($where, $params, $counts);
        $cards = [
            ['key' => 'exploit_attempts', 'label' => 'exploit attempts', 'count' => $counts['exploit_attempts'], 'category' => 'waf'],
            ['key' => 'suspicious_bots', 'label' => 'suspicious bots', 'count' => $counts['suspicious_bots'], 'category' => 'bot'],
            ['key' => 'login_abuse_attempts', 'label' => 'login abuse attempts', 'count' => $counts['login_abuse_attempts'], 'category' => 'rate_limit'],
            ['key' => 'origin_errors', 'label' => 'origin errors', 'count' => $counts['origin_errors'], 'category' => 'origin'],
            ['key' => 'ssl_actions', 'label' => 'SSL actions', 'count' => $counts['ssl_actions'], 'category' => 'ssl'],
            ['key' => 'dns_changes', 'label' => 'DNS changes', 'count' => $counts['dns_changes'], 'category' => 'dns'],
            ['key' => 'cache_actions', 'label' => 'cache actions', 'count' => $counts['cache_actions'], 'category' => 'cache'],
        ];

        return [
            'headline' => 'Today CDNLite protected and monitored your site.',
            'counts' => $counts,
            'cards' => $cards,
            'recommendations' => $this->beginnerRecommendations($counts),
        ];
    }

    private function beginnerAuditCategoryCounts(array $where, array $params, array &$counts): void
    {
        $stmt = Database::pdo()->prepare(
            "SELECT event, action, resource_type, COUNT(*) AS count
             FROM audit_log
             WHERE " . implode(' AND ', $where) . "
             GROUP BY event, action, resource_type"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $text = strtolower((string) ($row['event'] ?? '') . '.' . (string) ($row['action'] ?? '') . '.' . (string) ($row['resource_type'] ?? ''));
            $count = (int) $row['count'];
            if (str_contains($text, 'dns')) {
                $counts['dns_changes'] += $count;
            } elseif (str_contains($text, 'cache')) {
                $counts['cache_actions'] += $count;
            } elseif (!str_contains($text, 'waf_match') && !str_contains($text, 'rate_limited') && !str_contains($text, 'bot_match')) {
                $counts['audit_changes'] += $count;
            }
        }
    }

    private function beginnerRecommendations(array $counts): array
    {
        $recommendations = [];
        if ($counts['login_abuse_attempts'] > 0) {
            $recommendations[] = ['type' => 'login_shield', 'label' => 'Enable Login Shield', 'reason' => 'Recent login paths were rate limited.'];
        }
        if ($counts['suspicious_bots'] > 0) {
            $recommendations[] = ['type' => 'bot_shield', 'label' => 'Review Bot Shield mode', 'reason' => 'Suspicious automation was detected.'];
        }
        if ($counts['origin_errors'] > 0) {
            $recommendations[] = ['type' => 'origin_health', 'label' => 'Check origin health', 'reason' => 'Recent requests returned origin or router errors.'];
        }
        if ($counts['exploit_attempts'] > 0) {
            $recommendations[] = ['type' => 'common_exploits', 'label' => 'Keep exploit protection enabled', 'reason' => 'WAF rules matched exploit-looking traffic.'];
        }
        return $recommendations;
    }

    private function timelineRequests(string $domainId, int $limit, ?int $cursor, ?int $from, ?int $to, string $search, bool $errorsOnly): array
    {
        [$where, $params] = $this->timelineRequestWhere($domainId, $cursor, $from, $to, $search, $errorsOnly);
        $sql = "SELECT " . $this->usageRollupRequestSelectColumns() . "
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
            $clientIpSearch = $this->usageRollupClientIpColumnAvailable() ? ' OR client_ip ILIKE :search' : '';
            $where[] = '(request_id ILIKE :search OR host ILIKE :search OR path ILIKE :search' . $clientIpSearch . ' OR client_country ILIKE :search OR origin_id ILIKE :search OR router_error ILIKE :search)';
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
        $stmt = Database::pdo()->prepare("SELECT " . $this->usageRollupRequestSelectColumns() . "
                                          FROM usage_rollups {$whereSql}
                                          AND (status >= 500 OR router_error IS NOT NULL OR upstream_status LIKE '5%')
                                          ORDER BY ts DESC, id DESC
                                          LIMIT 10");
        $stmt->execute($params);
        return array_map([$this, 'castRequestActivity'], $stmt->fetchAll());
    }

    private function recordOriginObservation(string $domainId, array $item, int $now): void
    {
        $originId = trim((string) ($item['origin_id'] ?? ''));
        $edgeNodeId = trim((string) ($item['edge_node_id'] ?? ''));
        if ($originId === '' || $edgeNodeId === '') {
            return;
        }

        $origin = Database::pdo()->prepare('SELECT id, health_check_enabled FROM domain_origins WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $origin->execute([':domain_id' => $domainId, ':id' => $originId]);
        $originRow = $origin->fetch();
        if (!$originRow) {
            return;
        }

        $latencyMs = $this->durationMs($item['upstream_response_time'] ?? $item['origin_time_ms'] ?? null);
        $upstreamStatus = trim((string) ($item['upstream_status'] ?? ''));
        $routerError = trim((string) ($item['router_error'] ?? ''));
        [$status, $reason] = $this->originObservationStatus($upstreamStatus, $latencyMs, $routerError);
        $previous = $this->previousOriginObservation($domainId, $originId, $edgeNodeId);
        $jitterMs = ($latencyMs !== null && isset($previous['latency_ms']) && $previous['latency_ms'] !== null)
            ? abs($latencyMs - (int) $previous['latency_ms'])
            : null;
        if ($status === 'healthy' && $jitterMs !== null && $jitterMs >= 1000) {
            $status = 'slow';
            $reason = 'origin_jitter';
        }

        Database::pdo()->prepare(
            'INSERT INTO origin_health_observations
             (id,domain_id,origin_id,edge_node_id,status,reason,upstream_status,latency_ms,jitter_ms,sample_count,first_observed_at,last_observed_at,last_success_at,last_failure_at)
             VALUES (:id,:domain_id,:origin_id,:edge_node_id,:status,:reason,:upstream_status,:latency_ms,:jitter_ms,1,:first_observed_at,:last_observed_at,:last_success_at,:last_failure_at)
             ON CONFLICT (domain_id, origin_id, edge_node_id) DO UPDATE SET
               status=EXCLUDED.status,
               reason=EXCLUDED.reason,
               upstream_status=EXCLUDED.upstream_status,
               latency_ms=EXCLUDED.latency_ms,
               jitter_ms=EXCLUDED.jitter_ms,
               sample_count=LEAST(origin_health_observations.sample_count + 1, 1000000000),
               last_observed_at=EXCLUDED.last_observed_at,
               last_success_at=COALESCE(EXCLUDED.last_success_at, origin_health_observations.last_success_at),
               last_failure_at=COALESCE(EXCLUDED.last_failure_at, origin_health_observations.last_failure_at)'
        )->execute([
            ':id' => Uuid::v4(),
            ':domain_id' => $domainId,
            ':origin_id' => $originId,
            ':edge_node_id' => $edgeNodeId,
            ':status' => $status,
            ':reason' => $reason,
            ':upstream_status' => $upstreamStatus !== '' ? $upstreamStatus : null,
            ':latency_ms' => $latencyMs,
            ':jitter_ms' => $jitterMs,
            ':first_observed_at' => $now,
            ':last_observed_at' => $now,
            ':last_success_at' => $status === 'healthy' || $status === 'slow' ? $now : null,
            ':last_failure_at' => $status === 'unhealthy' ? $now : null,
        ]);

        if (in_array($originRow['health_check_enabled'], [true, 1, '1', 't', 'true'], true)) {
            $this->refreshOriginStatusFromEdge($domainId, $originId, $now);
        }
    }

    private function originObservationStatus(string $upstreamStatus, ?int $latencyMs, string $routerError): array
    {
        if ($routerError !== '') {
            return ['unhealthy', $routerError];
        }
        if ($upstreamStatus === '' || $upstreamStatus === '-') {
            return ['unknown', 'no_upstream_status'];
        }
        $firstStatus = (int) strtok($upstreamStatus, ', ');
        if ($firstStatus >= 500 || $firstStatus === 0) {
            return ['unhealthy', 'http_' . $firstStatus];
        }
        if ($latencyMs !== null && $latencyMs >= 3000) {
            return ['slow', 'slow_origin'];
        }
        return ['healthy', 'edge_request_ok'];
    }

    private function previousOriginObservation(string $domainId, string $originId, string $edgeNodeId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT latency_ms FROM origin_health_observations WHERE domain_id=:domain_id AND origin_id=:origin_id AND edge_node_id=:edge_node_id LIMIT 1'
        );
        $stmt->execute([':domain_id' => $domainId, ':origin_id' => $originId, ':edge_node_id' => $edgeNodeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function refreshOriginStatusFromEdge(string $domainId, string $originId, int $now): void
    {
        $stmt = Database::pdo()->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE status='unhealthy') unhealthy,
                COUNT(*) FILTER (WHERE status='slow') slow,
                COUNT(*) FILTER (WHERE status='healthy') healthy,
                MAX(last_observed_at) last_observed_at
             FROM origin_health_observations
             WHERE domain_id=:domain_id AND origin_id=:origin_id"
        );
        $stmt->execute([':domain_id' => $domainId, ':origin_id' => $originId]);
        $row = $stmt->fetch() ?: [];
        $status = 'unknown';
        $error = null;
        if ((int) ($row['unhealthy'] ?? 0) > 0) {
            $status = 'unhealthy';
            $error = 'edge_reported_unhealthy';
        } elseif ((int) ($row['slow'] ?? 0) > 0) {
            $status = 'healthy';
            $error = 'edge_reported_slow';
        } elseif ((int) ($row['healthy'] ?? 0) > 0) {
            $status = 'healthy';
        }
        Database::pdo()->prepare(
            'UPDATE domain_origins SET health_status=:health_status,last_check_at=:last_check_at,last_error=:last_error,updated_at=:updated_at
             WHERE domain_id=:domain_id AND id=:origin_id'
        )->execute([
            ':domain_id' => $domainId,
            ':origin_id' => $originId,
            ':health_status' => $status,
            ':last_check_at' => $row['last_observed_at'] === null ? $now : (int) $row['last_observed_at'],
            ':last_error' => $error,
            ':updated_at' => $now,
        ]);
    }

    private function castOriginObservation(array $row): array
    {
        foreach (['latency_ms', 'jitter_ms', 'sample_count', 'first_observed_at', 'last_observed_at', 'last_success_at', 'last_failure_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return [
            'edge_node_id' => (string) $row['edge_node_id'],
            'edge_label' => (string) ($row['hostname'] ?? $row['edge_node_id']),
            'region' => $row['region'] === null ? null : (string) $row['region'],
            'country' => $row['country'] === null ? null : (string) $row['country'],
            'status' => (string) $row['status'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'upstream_status' => $row['upstream_status'] === null ? null : (string) $row['upstream_status'],
            'latency_ms' => $row['latency_ms'],
            'jitter_ms' => $row['jitter_ms'],
            'sample_count' => $row['sample_count'],
            'first_observed_at' => $row['first_observed_at'],
            'last_observed_at' => $row['last_observed_at'],
            'last_success_at' => $row['last_success_at'],
            'last_failure_at' => $row['last_failure_at'],
        ];
    }

    private function maxObservationValue(array $rows, string $key): ?int
    {
        $values = array_values(array_filter(array_map(static fn (array $row): ?int => $row[$key] ?? null, $rows), static fn (?int $v): bool => $v !== null));
        return $values === [] ? null : max($values);
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
