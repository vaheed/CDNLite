<?php

namespace App\Modules\Reports\Services;

use App\Support\Database;
use App\Support\DatabaseWorkload;
use App\Support\Logger;
use InvalidArgumentException;
use PDO;
use PDOException;

class ReportService
{
    private const BUCKET_SECONDS = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
    private const CACHE_STATUSES = ['HIT', 'MISS', 'BYPASS', 'EXPIRED', 'STALE', 'UNKNOWN'];
    private const SECURITY_EVENTS = ['waf_match', 'rate_limited', 'bot_match', 'geo_block'];
    private ?bool $usageRollupClientIpColumnAvailable = null;

    public function summary(array $query): array
    {
        $range = $this->range($query);
        $kpis = $this->summaryKpis($range);
        $payload = [
            'time_range' => $this->publicRange($range),
            'previous_time_range' => null,
            'kpis' => $kpis,
            'deltas' => null,
            'warnings' => $this->warnings($range, $kpis),
            'generated_at' => time(),
        ];

        if ($range['compare']) {
            $previous = $this->previousRange($range);
            $previousKpis = $this->summaryKpis($previous);
            $payload['previous_time_range'] = $this->publicRange($previous);
            $payload['deltas'] = $this->deltas($kpis, $previousKpis);
        }

        return $payload;
    }

    public function traffic(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        return [
            'time_range' => $this->publicRange($range),
            'requests' => $this->usageSeries($range, 'requests_count'),
            'bandwidth' => [
                'in' => $this->usageSeries($range, 'bytes_in'),
                'out' => $this->usageSeries($range, 'bytes_out'),
            ],
            'cache_hit_ratio' => $this->cacheHitRatioSeries($range),
            'status_distribution' => $this->statusDistribution($range),
            'top_domains' => $this->topDomainsByRequests($range, $limit),
            'top_paths' => $this->topUsageDimension($range, 'path', $limit),
            'top_countries' => $this->topUsageDimension($range, 'client_country', $limit),
            'top_edge_nodes' => $this->topEdgeTraffic($range, $limit),
            'recent_problem_requests' => $this->recentProblemRequests($range, $limit),
            'generated_at' => time(),
        ];
    }

    public function cache(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        $bytes = $this->cacheBytes($range);
        return [
            'time_range' => $this->publicRange($range),
            'status_distribution' => $this->cacheStatusDistribution($range),
            'hit_ratio_trend' => $this->cacheHitRatioSeries($range),
            'bytes' => $bytes,
            'top_uncached_paths' => $this->topUncachedPaths($range, $limit),
            'purge_timeline' => $this->purgeTimeline($range),
            'cache_rule_match_counts' => null,
            'unavailable' => ['cache_rule_match_counts' => 'Edge usage ingest does not currently include matched cache rule identifiers for every request.'],
            'generated_at' => time(),
        ];
    }

    public function edge(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        return [
            'time_range' => $this->publicRange($range),
            'counts' => $this->edgeCounts(),
            'by_region' => $this->edgeGrouped('region'),
            'by_country' => $this->edgeGrouped('country'),
            'last_heartbeat_age' => $this->edgeHeartbeatAges(),
            'config_version_drift' => $this->configVersionDrift(),
            'failed_config_pulls' => $this->failedConfigPulls(),
            'traffic_by_edge_node' => $this->topEdgeTraffic($range, $limit),
            'error_rate_by_edge_node' => $this->edgeErrorRates($range, $limit),
            'nodes' => $this->edgeNodeTable(),
            'generated_at' => time(),
        ];
    }

    public function security(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        return [
            'time_range' => $this->publicRange($range),
            'events_over_time' => $this->auditSeries($range, self::SECURITY_EVENTS),
            'by_severity' => $this->securitySeverity($range),
            'by_type' => $this->auditDistribution($range, 'event', self::SECURITY_EVENTS),
            'waf_actions' => $this->securityActions($range, 'waf_match'),
            'rate_limit_actions' => $this->securityActions($range, 'rate_limited'),
            'top_attacking_ips' => $this->topSecurityJson($range, 'client_ip', $limit),
            'top_attacked_domains' => $this->topSecurityDomains($range, $limit),
            'recent_critical_events' => $this->recentSecurityEvents($range, $limit),
            'unavailable' => ['waf_allows' => 'Security ingest records WAF matches and decisions, but it does not emit a complete allow log stream.'],
            'generated_at' => time(),
        ];
    }

    public function reliability(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        return [
            'time_range' => $this->publicRange($range),
            'ssl_statuses' => $this->sslStatuses($range),
            'certificates_expiring_soon' => $this->certificatesExpiringSoon($range, $limit),
            'acme_job_progress' => $this->jobsByStatus($range),
            'dns_zones' => $this->dnsZoneCounts($range),
            'powerdns_sync_status' => $this->powerDnsSyncStatus($range),
            'nameserver_verification_status' => $this->nameserverVerificationStatus($range),
            'recent_dns_errors' => $this->recentDnsErrors($range, $limit),
            'pending_dns_changes' => $this->pendingDnsChanges($range, $limit),
            'origin_health_counts' => $this->originHealthCounts($range),
            'generated_at' => time(),
        ];
    }

    public function operations(array $query): array
    {
        $range = $this->range($query);
        $limit = $this->limit($query);
        $unavailable = [];
        $recentJobs = $this->operationSection('recent_jobs', fn (): array => $this->recentJobs($range, $limit), [], $unavailable);
        $recentAuditEntries = $this->operationSection('recent_audit_entries', fn (): array => $this->recentAuditEntries($range, $limit), [], $unavailable);
        $recentDnsErrors = $this->operationSection('recent_dns_errors', fn (): array => $this->recentDnsErrors($range, $limit), [], $unavailable);
        return [
            'time_range' => $this->publicRange($range),
            'job_queue_status_counts' => $this->operationSection('job_queue_status_counts', fn (): array => $this->jobsByStatus($range), [], $unavailable),
            'failed_jobs_over_time' => $this->operationSection('failed_jobs_over_time', fn (): array => $this->jobSeries($range, ['failed', 'cancelled']), [], $unavailable),
            'recent_jobs' => $recentJobs,
            'event_timeline' => $this->operationsTimeline($recentAuditEntries, $recentJobs, $recentDnsErrors, $limit),
            'recent_audit_entries' => $recentAuditEntries,
            'most_active_actors' => $this->operationSection('most_active_actors', fn (): array => $this->recentAuditGroup($range, 'actor_id', $limit), [], $unavailable),
            'most_changed_resources' => $this->operationSection('most_changed_resources', fn (): array => $this->recentAuditGroup($range, 'resource_type', $limit), [], $unavailable),
            'recent_config_snapshots' => $this->operationSection('recent_config_snapshots', fn (): array => $this->recentConfigSnapshots($limit), [], $unavailable),
            'unavailable' => $unavailable,
            'generated_at' => time(),
        ];
    }

    private function range(array $query): array
    {
        $budget = DatabaseWorkload::budget(DatabaseWorkload::REPORTING);
        $now = time();
        $to = isset($query['to']) && $query['to'] !== '' ? $this->timestamp($query['to'], 'to') : $now;
        $from = isset($query['from']) && $query['from'] !== '' ? $this->timestamp($query['from'], 'from') : $to - 86400;
        if ($from >= $to) {
            throw new InvalidArgumentException('invalid_time_range');
        }
        $maxRange = (int) ($budget['max_query_range_seconds'] ?? (366 * 86400));
        if (($to - $from) > $maxRange) {
            throw new InvalidArgumentException('time_range_too_large');
        }
        $bucket = (string) ($query['bucket'] ?? 'hour');
        if (!isset(self::BUCKET_SECONDS[$bucket])) {
            throw new InvalidArgumentException('invalid_bucket');
        }
        $domainId = isset($query['domain_id']) && trim((string) $query['domain_id']) !== '' ? trim((string) $query['domain_id']) : null;
        if ($domainId !== null && !$this->domainExists($domainId)) {
            throw new InvalidArgumentException('domain_not_found');
        }
        return [
            'from' => $from,
            'to' => $to,
            'bucket' => $bucket,
            'bucket_seconds' => self::BUCKET_SECONDS[$bucket],
            'domain_id' => $domainId,
            'compare' => filter_var($query['compare'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function timestamp(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
        $timestamp = (int) $value;
        if ($timestamp < 0 || $timestamp > 4102444800) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
        return $timestamp;
    }

    private function limit(array $query): int
    {
        $budget = DatabaseWorkload::budget(DatabaseWorkload::REPORTING);
        $maxRows = max(1, min(1000, (int) ($budget['max_result_rows'] ?? 100)));
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;
        return max(1, min($maxRows, $limit));
    }

    private function publicRange(array $range): array
    {
        return ['from' => $range['from'], 'to' => $range['to'], 'bucket' => $range['bucket'], 'domain_id' => $range['domain_id']];
    }

    private function previousRange(array $range): array
    {
        $duration = $range['to'] - $range['from'];
        return $range + ['from' => $range['from'] - $duration, 'to' => $range['from'], 'compare' => false];
    }

    private function usageWhere(array $range, string $alias = 'u'): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $where = [$prefix . 'ts >= :from', $prefix . 'ts <= :to'];
        $params = [':from' => $range['from'], ':to' => $range['to']];
        if ($range['domain_id'] !== null) {
            $where[] = $prefix . 'domain_id = :domain_id';
            $params[':domain_id'] = $range['domain_id'];
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function auditWhere(array $range, array $events = [], string $alias = 'a'): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $where = [$prefix . 'created_at >= :from', $prefix . 'created_at <= :to'];
        $params = [':from' => $range['from'], ':to' => $range['to']];
        if ($range['domain_id'] !== null) {
            $where[] = $prefix . 'domain_id = :domain_id';
            $params[':domain_id'] = $range['domain_id'];
        }
        if ($events !== []) {
            $keys = [];
            foreach (array_values($events) as $index => $event) {
                $key = ':event_' . $index;
                $keys[] = $key;
                $params[$key] = $event;
            }
            $where[] = $prefix . 'event IN (' . implode(',', $keys) . ')';
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function jobWhere(array $range, ?array $statuses = null): array
    {
        $where = ['j.created_at >= :from', 'j.created_at <= :to'];
        $params = [':from' => $range['from'], ':to' => $range['to']];
        if ($range['domain_id'] !== null) {
            $where[] = 'j.domain_id = :domain_id';
            $params[':domain_id'] = $range['domain_id'];
        }
        if ($statuses !== null) {
            $keys = [];
            foreach ($statuses as $index => $status) {
                $key = ':status_' . $index;
                $keys[] = $key;
                $params[$key] = $status;
            }
            $where[] = 'j.status IN (' . implode(',', $keys) . ')';
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function rows(string $sql, array $params = []): array
    {
        $pdo = Database::pdo();
        DatabaseWorkload::apply($pdo, DatabaseWorkload::REPORTING);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function one(string $sql, array $params = []): array
    {
        $pdo = Database::pdo();
        DatabaseWorkload::apply($pdo, DatabaseWorkload::REPORTING);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (array) ($stmt->fetch() ?: []);
    }

    private function operationSection(string $section, callable $callback, array $fallback, array &$unavailable): array
    {
        try {
            return $callback();
        } catch (PDOException $error) {
            if (!$this->isStatementTimeout($error)) {
                throw $error;
            }
            $unavailable[$section] = 'The query exceeded the reporting statement timeout.';
            Logger::warn('operations_report_section_timeout', ['section' => $section, 'error' => $error->getMessage()]);
            return $fallback;
        }
    }

    private function isStatementTimeout(PDOException $error): bool
    {
        $info = $error->errorInfo;
        return ($info[0] ?? null) === '57014' || str_contains($error->getMessage(), 'statement timeout');
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

    private function domainExists(string $domainId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM domains WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $domainId]);
        return $stmt->fetchColumn() !== false;
    }

    private function summaryKpis(array $range): array
    {
        [$where, $params] = $this->usageWhere($range);
        $traffic = $this->one("SELECT COALESCE(SUM(requests_count),0) total_requests,
                                      COALESCE(SUM(bytes_in),0) bytes_in,
                                      COALESCE(SUM(bytes_out),0) bytes_out,
                                      COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) cache_hits,
                                      COALESCE(SUM(requests_count) FILTER (WHERE status >= 500 OR origin_status >= 500 OR router_error IS NOT NULL),0) origin_errors
                               FROM usage_rollups u {$where}", $params);
        [$securityWhere, $securityParams] = $this->auditWhere($range, self::SECURITY_EVENTS);
        $security = $this->one("SELECT COUNT(*) security_events,
                                       COUNT(*) FILTER (WHERE event='waf_match' AND COALESCE(details_json::jsonb->>'decision','') IN ('block','deny')) waf_blocks,
                                       COUNT(*) FILTER (WHERE event='rate_limited') rate_limited
                                FROM audit_log a {$securityWhere}", $securityParams);
        $domainsSql = $range['domain_id'] === null
            ? "SELECT COUNT(*) FILTER (WHERE status='active') active_domains FROM domains"
            : "SELECT COUNT(*) FILTER (WHERE status='active') active_domains FROM domains WHERE id=:domain_id";
        $domains = $this->one($domainsSql, $range['domain_id'] === null ? [] : [':domain_id' => $range['domain_id']]);
        $edges = $this->edgeCounts();
        $ssl = $this->one($range['domain_id'] === null
            ? 'SELECT COUNT(*) ssl_expiring FROM ssl_certificates WHERE not_after >= :now AND not_after < :expiry'
            : 'SELECT COUNT(*) ssl_expiring FROM ssl_certificates WHERE domain_id=:domain_id AND not_after >= :now AND not_after < :expiry',
            $range['domain_id'] === null
                ? [':now' => time(), ':expiry' => time() + 2592000]
                : [':domain_id' => $range['domain_id'], ':now' => time(), ':expiry' => time() + 2592000]);
        $pendingDns = $this->one('SELECT COALESCE(SUM(pending_changes),0) pending FROM dns_sync_state' . ($range['domain_id'] === null ? '' : ' s JOIN domains d ON d.domain=s.zone_name WHERE d.id=:domain_id'), $range['domain_id'] === null ? [] : [':domain_id' => $range['domain_id']]);
        [$jobWhere, $jobParams] = $this->jobWhere($range, ['failed', 'cancelled']);
        $failedJobs = $this->one("SELECT COUNT(*) failed FROM ssl_jobs j {$jobWhere}", $jobParams);
        $requests = (int) ($traffic['total_requests'] ?? 0);
        return [
            'total_requests' => $requests,
            'bandwidth_in_bytes' => (int) ($traffic['bytes_in'] ?? 0),
            'bandwidth_out_bytes' => (int) ($traffic['bytes_out'] ?? 0),
            'cache_hit_ratio' => $requests > 0 ? round(((int) ($traffic['cache_hits'] ?? 0)) / $requests, 4) : 0.0,
            'active_domains' => (int) ($domains['active_domains'] ?? 0),
            'online_edges' => (int) ($edges['online'] ?? 0),
            'offline_edges' => (int) ($edges['offline'] ?? 0),
            'security_events' => (int) ($security['security_events'] ?? 0),
            'waf_blocks' => (int) ($security['waf_blocks'] ?? 0),
            'rate_limited_requests' => (int) ($security['rate_limited'] ?? 0),
            'origin_errors' => (int) ($traffic['origin_errors'] ?? 0),
            'ssl_expiring_count' => (int) ($ssl['ssl_expiring'] ?? 0),
            'pending_dns_changes' => (int) ($pendingDns['pending'] ?? 0),
            'failed_jobs' => (int) ($failedJobs['failed'] ?? 0),
        ];
    }

    private function deltas(array $current, array $previous): array
    {
        $deltas = [];
        foreach ($current as $key => $value) {
            $old = $previous[$key] ?? 0;
            $deltas[$key] = ['absolute' => is_float($value) || is_float($old) ? round($value - $old, 4) : $value - $old, 'percent' => $old != 0 ? round((($value - $old) / $old) * 100, 2) : null];
        }
        return $deltas;
    }

    private function warnings(array $range, array $kpis): array
    {
        $warnings = [];
        foreach ([
            ['key' => 'offline_edges', 'severity' => 'critical', 'message' => 'edge nodes offline', 'link' => '/edge-nodes'],
            ['key' => 'failed_jobs', 'severity' => 'critical', 'message' => 'failed jobs in range', 'link' => '/jobs'],
            ['key' => 'pending_dns_changes', 'severity' => 'warning', 'message' => 'pending DNS changes', 'link' => '/dns-operations'],
            ['key' => 'ssl_expiring_count', 'severity' => 'warning', 'message' => 'certificates expiring within 30 days', 'link' => '/domains'],
            ['key' => 'origin_errors', 'severity' => 'warning', 'message' => 'origin or edge errors in range', 'link' => '/usage'],
        ] as $rule) {
            $count = (int) ($kpis[$rule['key']] ?? 0);
            if ($count > 0) {
                $warnings[] = ['severity' => $rule['severity'], 'message' => $count . ' ' . $rule['message'], 'link' => $rule['link'], 'count' => $count];
            }
        }
        usort($warnings, static fn (array $a, array $b): int => ['critical' => 0, 'warning' => 1, 'info' => 2][$a['severity']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b['severity']]);
        return $warnings;
    }

    private function usageSeries(array $range, string $column): array
    {
        [$where, $params] = $this->usageWhere($range);
        $sql = "SELECT ((u.ts / :bucket_seconds) * :bucket_seconds) bucket_ts, COALESCE(SUM({$column}),0) value
                FROM usage_rollups u {$where}
                GROUP BY 1 ORDER BY 1 ASC";
        $params[':bucket_seconds'] = $range['bucket_seconds'];
        return array_map(static fn (array $row): array => ['bucket_ts' => (int) $row['bucket_ts'], 'value' => (int) $row['value']], $this->rows($sql, $params));
    }

    private function cacheHitRatioSeries(array $range): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':bucket_seconds'] = $range['bucket_seconds'];
        return array_map(static function (array $row): array {
            $total = (int) $row['total'];
            return ['bucket_ts' => (int) $row['bucket_ts'], 'value' => $total > 0 ? round(((int) $row['hits']) / $total, 4) : 0.0];
        }, $this->rows("SELECT ((u.ts / :bucket_seconds) * :bucket_seconds) bucket_ts,
                              COALESCE(SUM(requests_count),0) total,
                              COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) hits
                       FROM usage_rollups u {$where}
                       GROUP BY 1 ORDER BY 1 ASC", $params));
    }

    private function statusDistribution(array $range): array
    {
        [$where, $params] = $this->usageWhere($range);
        return array_map(static fn (array $row): array => ['status_class' => (string) $row['status_class'], 'count' => (int) $row['count']], $this->rows("SELECT CASE
                    WHEN status BETWEEN 200 AND 299 THEN '2xx'
                    WHEN status BETWEEN 300 AND 399 THEN '3xx'
                    WHEN status BETWEEN 400 AND 499 THEN '4xx'
                    WHEN status >= 500 THEN '5xx'
                    ELSE 'unknown'
                END status_class, COALESCE(SUM(requests_count),0) count
                FROM usage_rollups u {$where}
                GROUP BY 1 ORDER BY 1", $params));
    }

    private function topDomainsByRequests(array $range, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['domain_id' => (string) $row['domain_id'], 'name' => (string) ($row['name'] ?: $row['domain']), 'domain' => (string) $row['domain'], 'requests' => (int) $row['requests']], $this->rows("SELECT u.domain_id, d.name, d.domain, COALESCE(SUM(u.requests_count),0) requests
                FROM usage_rollups u JOIN domains d ON d.id=u.domain_id {$where}
                GROUP BY u.domain_id,d.name,d.domain ORDER BY requests DESC LIMIT :limit", $params));
    }

    private function topUsageDimension(array $range, string $column, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'requests' => (int) $row['requests'], 'bytes_out' => (int) $row['bytes_out']], $this->rows("SELECT COALESCE(NULLIF({$column},''),'unknown') value,
                    COALESCE(SUM(requests_count),0) requests, COALESCE(SUM(bytes_out),0) bytes_out
                FROM usage_rollups u {$where}
                GROUP BY 1 ORDER BY requests DESC LIMIT :limit", $params));
    }

    private function topEdgeTraffic(array $range, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['edge_node_id' => (string) $row['edge_node_id'], 'hostname' => $row['hostname'] ?? null, 'requests' => (int) $row['requests'], 'bytes_out' => (int) $row['bytes_out']], $this->rows("SELECT u.edge_node_id, e.hostname, COALESCE(SUM(u.requests_count),0) requests, COALESCE(SUM(u.bytes_out),0) bytes_out
                FROM usage_rollups u LEFT JOIN edge_nodes e ON e.edge_id=u.edge_node_id {$where}
                GROUP BY u.edge_node_id,e.hostname ORDER BY bytes_out DESC, requests DESC LIMIT :limit", $params));
    }

    private function recentProblemRequests(array $range, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        $clientIp = $this->usageRollupClientIpColumnAvailable() ? 'client_ip' : 'NULL AS client_ip';
        return array_map([$this, 'requestRow'], $this->rows("SELECT id, ts, request_id, domain_id, edge_node_id, host, method, path, {$clientIp}, client_country, status, bytes_in, bytes_out, cache_status, origin_id, origin_host, upstream_status, upstream_response_time_ms, request_time_ms, router_error
                FROM usage_rollups u {$where}
                  AND (status >= 500 OR origin_status >= 500 OR router_error IS NOT NULL OR request_time_ms >= 1000)
                ORDER BY ts DESC, id DESC LIMIT :limit", $params));
    }

    private function requestRow(array $row): array
    {
        foreach (['ts', 'status', 'bytes_in', 'bytes_out', 'upstream_response_time_ms', 'request_time_ms'] as $field) {
            if (isset($row[$field]) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    private function cacheStatusDistribution(array $range): array
    {
        [$where, $params] = $this->usageWhere($range);
        $rows = $this->rows("SELECT UPPER(COALESCE(cache_status,'UNKNOWN')) status, COALESCE(SUM(requests_count),0) count, COALESCE(SUM(bytes_out),0) bytes_out
                FROM usage_rollups u {$where} GROUP BY 1", $params);
        $map = [];
        foreach ($rows as $row) {
            $status = in_array((string) $row['status'], self::CACHE_STATUSES, true) ? (string) $row['status'] : 'UNKNOWN';
            $map[$status] = ['status' => $status, 'count' => ($map[$status]['count'] ?? 0) + (int) $row['count'], 'bytes_out' => ($map[$status]['bytes_out'] ?? 0) + (int) $row['bytes_out']];
        }
        return array_map(static fn (string $status): array => $map[$status] ?? ['status' => $status, 'count' => 0, 'bytes_out' => 0], self::CACHE_STATUSES);
    }

    private function cacheBytes(array $range): array
    {
        [$where, $params] = $this->usageWhere($range);
        $row = $this->one("SELECT COALESCE(SUM(bytes_out) FILTER (WHERE UPPER(cache_status) IN ('HIT','STALE')),0) cache_bytes_out,
                                  COALESCE(SUM(bytes_out) FILTER (WHERE UPPER(cache_status) NOT IN ('HIT','STALE')),0) origin_bytes_out
                           FROM usage_rollups u {$where}", $params);
        return ['served_from_cache_bytes' => (int) ($row['cache_bytes_out'] ?? 0), 'served_from_origin_bytes' => (int) ($row['origin_bytes_out'] ?? 0)];
    }

    private function topUncachedPaths(array $range, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['path' => (string) $row['path'], 'requests' => (int) $row['requests'], 'bytes_out' => (int) $row['bytes_out']], $this->rows("SELECT COALESCE(NULLIF(path,''),'unknown') path, COALESCE(SUM(requests_count),0) requests, COALESCE(SUM(bytes_out),0) bytes_out
                FROM usage_rollups u {$where} AND UPPER(cache_status) IN ('MISS','BYPASS','EXPIRED','UNKNOWN')
                GROUP BY 1 ORDER BY requests DESC LIMIT :limit", $params));
    }

    private function purgeTimeline(array $range): array
    {
        $where = ['created_at >= :from', 'created_at <= :to'];
        $params = [':from' => $range['from'], ':to' => $range['to'], ':bucket_seconds' => $range['bucket_seconds']];
        if ($range['domain_id'] !== null) {
            $where[] = 'domain_id = :domain_id';
            $params[':domain_id'] = $range['domain_id'];
        }
        return array_map(static fn (array $row): array => ['bucket_ts' => (int) $row['bucket_ts'], 'count' => (int) $row['count']], $this->rows('SELECT ((created_at / :bucket_seconds) * :bucket_seconds) bucket_ts, COUNT(*) count FROM cache_purge_requests WHERE ' . implode(' AND ', $where) . ' GROUP BY 1 ORDER BY 1 ASC', $params));
    }

    private function edgeCounts(): array
    {
        $cutoff = time() - 300;
        $row = $this->one("SELECT COUNT(*) FILTER (WHERE is_enabled=true AND COALESCE(last_heartbeat_at,last_heartbeat)>=:cutoff) online,
                                  COUNT(*) FILTER (WHERE is_enabled=true AND COALESCE(last_heartbeat_at,last_heartbeat)<:cutoff) offline,
                                  COUNT(*) total
                           FROM edge_nodes", [':cutoff' => $cutoff]);
        return ['online' => (int) ($row['online'] ?? 0), 'offline' => (int) ($row['offline'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }

    private function edgeGrouped(string $column): array
    {
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE(NULLIF({$column},''),'unknown') value, COUNT(*) count FROM edge_nodes GROUP BY 1 ORDER BY count DESC"));
    }

    private function edgeHeartbeatAges(): array
    {
        return array_map(static fn (array $row): array => ['edge_id' => (string) $row['edge_id'], 'hostname' => (string) $row['hostname'], 'age_seconds' => max(0, time() - (int) $row['last_seen'])], $this->rows('SELECT edge_id, hostname, COALESCE(last_heartbeat_at,last_heartbeat) last_seen FROM edge_nodes ORDER BY last_seen ASC'));
    }

    private function configVersionDrift(): array
    {
        $latest = (int) (Database::pdo()->query('SELECT COALESCE(MAX(version),0) FROM config_snapshots')->fetchColumn() ?: 0);
        return array_map(static fn (array $row): array => ['edge_id' => (string) $row['edge_id'], 'hostname' => (string) $row['hostname'], 'applied_config_version' => $row['applied_config_version'] === null ? null : (int) $row['applied_config_version'], 'latest_config_version' => $latest, 'drift' => $latest > 0 && (int) ($row['applied_config_version'] ?? 0) < $latest], $this->rows('SELECT edge_id, hostname, applied_config_version FROM edge_nodes ORDER BY edge_id ASC'));
    }

    private function failedConfigPulls(): array
    {
        return array_map(static fn (array $row): array => ['edge_id' => (string) $row['edge_id'], 'hostname' => (string) $row['hostname'], 'config_apply_error' => (string) $row['config_apply_error'], 'last_config_pull_at' => $row['last_config_pull_at'] === null ? null : (int) $row['last_config_pull_at']], $this->rows("SELECT edge_id, hostname, config_apply_error, last_config_pull_at FROM edge_nodes WHERE config_apply_error IS NOT NULL AND config_apply_error <> '' ORDER BY last_config_pull_at DESC NULLS LAST"));
    }

    private function edgeErrorRates(array $range, int $limit): array
    {
        [$where, $params] = $this->usageWhere($range);
        $params[':limit'] = $limit;
        return array_map(static function (array $row): array {
            $requests = (int) $row['requests'];
            return ['edge_node_id' => (string) $row['edge_node_id'], 'hostname' => $row['hostname'] ?? null, 'requests' => $requests, 'errors' => (int) $row['errors'], 'error_rate' => $requests > 0 ? round(((int) $row['errors']) / $requests, 4) : 0.0];
        }, $this->rows("SELECT u.edge_node_id, e.hostname, COALESCE(SUM(u.requests_count),0) requests,
                    COALESCE(SUM(u.requests_count) FILTER (WHERE u.status >= 500 OR u.router_error IS NOT NULL),0) errors
                FROM usage_rollups u LEFT JOIN edge_nodes e ON e.edge_id=u.edge_node_id {$where}
                GROUP BY u.edge_node_id,e.hostname ORDER BY errors DESC LIMIT :limit", $params));
    }

    private function edgeNodeTable(): array
    {
        return array_map(static fn (array $row): array => [
            'edge_id' => (string) $row['edge_id'], 'health' => (string) $row['health_status'], 'hostname' => (string) $row['hostname'],
            'public_ip' => (string) $row['public_ip'], 'region' => (string) $row['region'], 'country' => $row['country'],
            'version' => (string) $row['version'], 'last_heartbeat' => (int) ($row['last_heartbeat_at'] ?? $row['last_heartbeat']),
            'applied_config_version' => $row['applied_config_version'] === null ? null : (int) $row['applied_config_version'],
            'status' => (string) $row['status'],
        ], $this->rows('SELECT * FROM edge_nodes ORDER BY hostname ASC'));
    }

    private function auditSeries(array $range, array $events): array
    {
        [$where, $params] = $this->auditWhere($range, $events);
        $params[':bucket_seconds'] = $range['bucket_seconds'];
        return array_map(static fn (array $row): array => ['bucket_ts' => (int) $row['bucket_ts'], 'count' => (int) $row['count']], $this->rows("SELECT ((a.created_at / :bucket_seconds) * :bucket_seconds) bucket_ts, COUNT(*) count FROM audit_log a {$where} GROUP BY 1 ORDER BY 1 ASC", $params));
    }

    private function auditDistribution(array $range, string $column, array $events): array
    {
        [$where, $params] = $this->auditWhere($range, $events);
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE({$column},'unknown') value, COUNT(*) count FROM audit_log a {$where} GROUP BY 1 ORDER BY count DESC", $params));
    }

    private function securitySeverity(array $range): array
    {
        [$where, $params] = $this->auditWhere($range, self::SECURITY_EVENTS);
        return array_map(static fn (array $row): array => ['severity' => (string) $row['severity'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE(NULLIF(details_json::jsonb->>'severity',''),'unknown') severity, COUNT(*) count FROM audit_log a {$where} GROUP BY 1 ORDER BY count DESC", $params));
    }

    private function securityActions(array $range, string $event): array
    {
        [$where, $params] = $this->auditWhere($range, [$event]);
        return array_map(static fn (array $row): array => ['action' => (string) $row['action'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE(NULLIF(details_json::jsonb->>'decision',''),'unknown') action, COUNT(*) count FROM audit_log a {$where} GROUP BY 1 ORDER BY count DESC", $params));
    }

    private function topSecurityJson(array $range, string $key, int $limit): array
    {
        if (!in_array($key, ['client_ip', 'severity', 'decision', 'path'], true)) {
            throw new InvalidArgumentException('invalid_security_dimension');
        }
        [$where, $params] = $this->auditWhere($range, self::SECURITY_EVENTS);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $this->rows("SELECT details_json::jsonb->>'{$key}' value, COUNT(*) count FROM audit_log a {$where} AND details_json::jsonb->>'{$key}' IS NOT NULL GROUP BY 1 ORDER BY count DESC LIMIT :limit", $params));
    }

    private function topSecurityDomains(array $range, int $limit): array
    {
        [$where, $params] = $this->auditWhere($range, self::SECURITY_EVENTS);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['domain_id' => $row['domain_id'], 'name' => $row['name'], 'count' => (int) $row['count']], $this->rows("SELECT a.domain_id, COALESCE(d.name,d.domain,a.domain_id) name, COUNT(*) count FROM audit_log a LEFT JOIN domains d ON d.id=a.domain_id {$where} GROUP BY a.domain_id,d.name,d.domain ORDER BY count DESC LIMIT :limit", $params));
    }

    private function recentSecurityEvents(array $range, int $limit): array
    {
        [$where, $params] = $this->auditWhere($range, self::SECURITY_EVENTS);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'type' => $row['event'], 'domain_id' => $row['domain_id'], 'actor_id' => $row['actor_id'], 'details' => json_decode((string) ($row['details_json'] ?? ''), true) ?: null, 'created_at' => (int) $row['created_at']], $this->rows("SELECT * FROM audit_log a {$where} ORDER BY created_at DESC, id DESC LIMIT :limit", $params));
    }

    private function sslStatuses(array $range): array
    {
        $where = $range['domain_id'] === null ? '' : 'WHERE domain_id=:domain_id';
        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']], $this->rows("SELECT status, COUNT(*) count FROM ssl_certificates {$where} GROUP BY status ORDER BY count DESC", $range['domain_id'] === null ? [] : [':domain_id' => $range['domain_id']]));
    }

    private function certificatesExpiringSoon(array $range, int $limit): array
    {
        $where = ['not_after >= :now', 'not_after < :expiry'];
        $params = [':now' => time(), ':expiry' => time() + 2592000, ':limit' => $limit];
        if ($range['domain_id'] !== null) {
            $where[] = 'c.domain_id = :domain_id';
            $params[':domain_id'] = $range['domain_id'];
        }
        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'domain_id' => (string) $row['domain_id'], 'domain_name' => $row['domain_name'], 'hostname' => (string) $row['hostname'], 'status' => (string) $row['status'], 'not_after' => (int) $row['not_after'], 'days_until_expiry' => (int) floor(((int) $row['not_after'] - time()) / 86400)], $this->rows('SELECT c.id,c.domain_id,d.name domain_name,c.hostname,c.status,c.not_after FROM ssl_certificates c JOIN domains d ON d.id=c.domain_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.not_after ASC LIMIT :limit', $params));
    }

    private function jobsByStatus(array $range): array
    {
        [$where, $params] = $this->jobWhere($range);
        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']], $this->rows("SELECT j.status, COUNT(*) count FROM ssl_jobs j {$where} GROUP BY j.status ORDER BY count DESC", $params));
    }

    private function dnsZoneCounts(array $range): array
    {
        $rows = $this->rows('SELECT COUNT(*) total, COUNT(*) FILTER (WHERE pending_changes=0 AND last_error IS NULL) converged, COUNT(*) FILTER (WHERE pending_changes>0 OR last_error IS NOT NULL) pending FROM dns_sync_state');
        return ['total' => (int) ($rows[0]['total'] ?? 0), 'converged' => (int) ($rows[0]['converged'] ?? 0), 'pending' => (int) ($rows[0]['pending'] ?? 0)];
    }

    private function powerDnsSyncStatus(array $range): array
    {
        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']], $this->rows('SELECT status, COUNT(*) count FROM dns_sync_state GROUP BY status ORDER BY count DESC'));
    }

    private function nameserverVerificationStatus(array $range): array
    {
        $where = $range['domain_id'] === null ? '' : 'WHERE id=:domain_id';
        return array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE(nameserver_status,'unknown') status, COUNT(*) count FROM domains {$where} GROUP BY 1 ORDER BY count DESC", $range['domain_id'] === null ? [] : [':domain_id' => $range['domain_id']]));
    }

    private function recentDnsErrors(array $range, int $limit): array
    {
        $params = [':from' => $range['from'], ':to' => $range['to'], ':limit' => $limit];
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'zone_name' => (string) $row['zone_name'], 'action' => (string) $row['action'], 'status' => (string) $row['status'], 'error' => $row['error'], 'created_at' => (int) $row['created_at']], $this->rows("SELECT * FROM dns_sync_events WHERE created_at>=:from AND created_at<=:to AND status NOT IN ('success','verified') ORDER BY created_at DESC LIMIT :limit", $params));
    }

    private function pendingDnsChanges(array $range, int $limit): array
    {
        $params = [':limit' => $limit];
        return array_map(static fn (array $row): array => ['zone_name' => (string) $row['zone_name'], 'status' => (string) $row['status'], 'pending_changes' => (int) $row['pending_changes'], 'last_error' => $row['last_error'], 'last_attempt_at' => $row['last_attempt_at'] === null ? null : (int) $row['last_attempt_at']], $this->rows('SELECT zone_name,status,pending_changes,last_error,last_attempt_at FROM dns_sync_state WHERE pending_changes > 0 OR last_error IS NOT NULL ORDER BY pending_changes DESC,last_attempt_at DESC NULLS LAST LIMIT :limit', $params));
    }

    private function originHealthCounts(array $range): array
    {
        $where = $range['domain_id'] === null ? '' : 'WHERE domain_id=:domain_id';
        return array_map(static fn (array $row): array => ['status' => (string) $row['health_status'], 'count' => (int) $row['count']], $this->rows("SELECT health_status, COUNT(*) count FROM domain_origins {$where} GROUP BY health_status ORDER BY count DESC", $range['domain_id'] === null ? [] : [':domain_id' => $range['domain_id']]));
    }

    private function jobSeries(array $range, array $statuses): array
    {
        [$where, $params] = $this->jobWhere($range, $statuses);
        $params[':bucket_seconds'] = $range['bucket_seconds'];
        return array_map(static fn (array $row): array => ['bucket_ts' => (int) $row['bucket_ts'], 'count' => (int) $row['count']], $this->rows("SELECT ((j.created_at / :bucket_seconds) * :bucket_seconds) bucket_ts, COUNT(*) count FROM ssl_jobs j {$where} GROUP BY 1 ORDER BY 1 ASC", $params));
    }

    private function recentJobs(array $range, int $limit): array
    {
        [$where, $params] = $this->jobWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'domain_id' => (string) $row['domain_id'], 'domain_name' => $row['domain_name'], 'status' => (string) $row['status'], 'progress_percent' => (int) $row['progress_percent'], 'message' => (string) $row['message'], 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at']], $this->rows("SELECT j.*,d.name domain_name FROM ssl_jobs j LEFT JOIN domains d ON d.id=j.domain_id {$where} ORDER BY j.created_at DESC LIMIT :limit", $params));
    }

    private function operationsTimeline(array $auditEntries, array $jobs, array $dnsErrors, int $limit): array
    {
        $events = array_merge($auditEntries, $jobs, $dnsErrors);
        usort($events, static fn (array $a, array $b): int => ((int) ($b['created_at'] ?? 0)) <=> ((int) ($a['created_at'] ?? 0)));
        return array_slice($events, 0, $limit);
    }

    private function recentAuditEntries(array $range, int $limit): array
    {
        [$where, $params] = $this->auditWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'actor_type' => (string) $row['actor_type'], 'actor_id' => $row['actor_id'], 'action' => (string) $row['action'], 'resource_type' => (string) $row['resource_type'], 'resource_id' => $row['resource_id'], 'domain_id' => $row['domain_id'], 'type' => $row['event'], 'created_at' => (int) $row['created_at']], $this->rows("SELECT * FROM audit_log a {$where} ORDER BY created_at DESC,id DESC LIMIT :limit", $params));
    }

    private function auditGroup(array $range, string $column, int $limit): array
    {
        [$where, $params] = $this->auditWhere($range);
        $params[':limit'] = $limit;
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $this->rows("SELECT COALESCE(NULLIF({$column},''),'unknown') value, COUNT(*) count FROM audit_log a {$where} GROUP BY 1 ORDER BY count DESC LIMIT :limit", $params));
    }

    private function recentAuditGroup(array $range, string $column, int $limit): array
    {
        if (!in_array($column, ['actor_id', 'resource_type'], true)) {
            throw new InvalidArgumentException('invalid_audit_dimension');
        }
        [$where, $params] = $this->auditWhere($range);
        $params[':limit'] = $limit;
        $params[':sample_limit'] = $this->operationsAuditSampleLimit($limit);
        // Operations rankings are dashboard diagnostics, so keep them bounded by
        // the most recent audit rows instead of grouping an entire busy audit log.
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $this->rows("WITH recent AS (
                    SELECT {$column} value FROM audit_log a {$where}
                    ORDER BY a.created_at DESC, a.id DESC LIMIT :sample_limit
                )
                SELECT COALESCE(NULLIF(value,''),'unknown') value, COUNT(*) count
                FROM recent GROUP BY 1 ORDER BY count DESC LIMIT :limit", $params));
    }

    private function operationsAuditSampleLimit(int $limit): int
    {
        $budget = DatabaseWorkload::budget(DatabaseWorkload::REPORTING);
        $maxRows = max(1, min(50000, (int) ($budget['max_result_rows'] ?? 500) * 100));
        return max(1000, min($maxRows, $limit * 250));
    }

    private function recentConfigSnapshots(int $limit): array
    {
        $limit = min($limit, 5);
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'generated_at' => (int) $row['generated_at'],
            'content_hash' => (string) $row['content_hash'],
            'size' => (int) $row['size'],
            'active' => in_array($row['active'], [true, 1, '1', 't', 'true'], true),
        ], $this->rows(
            'WITH recent AS (
                SELECT version, generated_at, content_hash, pg_column_size(payload_json) AS size
                FROM config_snapshots
                ORDER BY generated_at DESC, version DESC LIMIT :limit
             )
             SELECT r.version,r.generated_at,r.content_hash,r.size,
                    (r.version=cs.active_snapshot_version) AS active
             FROM recent r CROSS JOIN config_state cs
             WHERE cs.id=1 ORDER BY r.generated_at DESC,r.version DESC',
            [':limit' => $limit]
        ));
    }
}
