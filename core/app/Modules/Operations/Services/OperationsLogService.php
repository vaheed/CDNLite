<?php

namespace App\Modules\Operations\Services;

use App\Support\Database;
use PDO;

class OperationsLogService
{
    private const SECURITY_EVENTS = ['waf_match', 'rate_limited', 'bot_match', 'geo_block'];
    private const ACTIVE_JOB_STATUSES = ['queued', 'checking_dns', 'creating_order', 'validating_challenge', 'issuing', 'installing'];

    public function securityEvents(array $filters): array
    {
        $filters['events'] = self::SECURITY_EVENTS;
        $filters['type'] = $filters['type'] ?? null;
        return $this->list($filters, true);
    }

    public function securitySummary(array $filters): array
    {
        [$where, $params] = $this->where([
            'events' => self::SECURITY_EVENTS,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], true);
        $pdo = Database::pdo();

        $byType = [];
        $stmt = $pdo->prepare("SELECT a.event, COUNT(*) AS count FROM audit_log a {$where} GROUP BY a.event ORDER BY count DESC");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $byType[(string) $row['event']] = (int) $row['count'];
        }

        return [
            'total' => array_sum($byType),
            'by_type' => $byType,
            'top_ips' => $this->topJsonValue($where, $params, 'ip'),
            'top_domains' => $this->topDomains($where, $params),
        ];
    }

    public function audit(array $filters): array
    {
        return $this->list($filters, false);
    }

    public function events(array $filters): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $rows = array_merge(
            $this->eventAuditRows($filters),
            $this->eventDnsRows($filters),
            $this->eventJobRows($filters)
        );
        usort($rows, static fn (array $a, array $b): int => ((int) $b['created_at'] <=> (int) $a['created_at']) ?: strcmp((string) $b['id'], (string) $a['id']));

        return [
            'items' => array_slice($rows, $offset, $limit),
            'total' => count($rows),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function jobs(array $filters): array
    {
        [$where, $params] = $this->jobWhere($filters);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $pdo = Database::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM ssl_jobs j LEFT JOIN domains d ON d.id=j.domain_id {$where}");
        $count->execute($params);

        $stmt = $pdo->prepare(
            "SELECT j.*, d.name AS domain_name, d.domain AS domain_hostname
             FROM ssl_jobs j LEFT JOIN domains d ON d.id=j.domain_id
             {$where} ORDER BY j.created_at DESC, j.id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->mapJob($row), $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function list(array $filters, bool $security): array
    {
        [$where, $params] = $this->where($filters, $security);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $pdo = Database::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM audit_log a {$where}");
        $count->execute($params);

        $sql = "SELECT a.*, d.name AS domain_name
                FROM audit_log a LEFT JOIN domains d ON d.id=a.domain_id
                {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn (array $row): array => $this->map($row), $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function where(array $filters, bool $security): array
    {
        $clauses = [];
        $params = [];
        $prefix = 'a.';
        if (!empty($filters['events'])) {
            $eventParams = [];
            foreach (array_values($filters['events']) as $index => $event) {
                $key = ':event_' . $index;
                $eventParams[] = $key;
                $params[$key] = $event;
            }
            $clauses[] = $prefix . 'event IN (' . implode(',', $eventParams) . ')';
        }
        $equals = [
            'domain_id' => 'domain_id',
            'edge_id' => 'actor_id',
            'type' => 'event',
            'action' => 'action',
            'resource_type' => 'resource_type',
        ];
        foreach ($equals as $filter => $column) {
            if (isset($filters[$filter]) && trim((string) $filters[$filter]) !== '') {
                $clauses[] = $prefix . $column . '=:' . $filter;
                $params[':' . $filter] = trim((string) $filters[$filter]);
            }
        }
        if (isset($filters['actor']) && trim((string) $filters['actor']) !== '') {
            $clauses[] = '(' . $prefix . 'actor_id ILIKE :actor OR ' . $prefix . 'actor_type ILIKE :actor)';
            $params[':actor'] = '%' . trim((string) $filters['actor']) . '%';
        }
        if (isset($filters['ip']) && trim((string) $filters['ip']) !== '') {
            $clauses[] = $prefix . "details_json::jsonb->>'ip' ILIKE :ip";
            $params[':ip'] = trim((string) $filters['ip']) . '%';
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $clauses[] = '(' . $prefix . 'details_json ILIKE :search OR ' .
                $prefix . 'action ILIKE :search OR ' . $prefix . 'resource_type ILIKE :search OR ' .
                $prefix . 'event ILIKE :search)';
            $params[':search'] = '%' . trim((string) $filters['search']) . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter]) && is_numeric($filters[$filter])) {
                $clauses[] = $prefix . "created_at {$operator} :{$filter}";
                $params[':' . $filter] = (int) $filters[$filter];
            }
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function map(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'actor_type' => (string) $row['actor_type'],
            'actor_id' => $row['actor_id'],
            'action' => (string) $row['action'],
            'resource_type' => (string) $row['resource_type'],
            'resource_id' => $row['resource_id'],
            'domain_id' => $row['domain_id'],
            'domain_name' => $row['domain_name'] ?? $row['domain_id'] ?? null,
            'type' => $row['event'],
            'details' => $this->decode($row['details_json'] ?? null),
            'before' => $this->decode($row['before_json'] ?? null),
            'after' => $this->decode($row['after_json'] ?? null),
            'created_at' => (int) $row['created_at'],
        ];
    }

    private function decode(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function topJsonValue(string $where, array $params, string $key): array
    {
        $sql = "SELECT details_json::jsonb->>'{$key}' AS value, COUNT(*) AS count
                FROM audit_log a {$where} AND details_json::jsonb->>'{$key}' IS NOT NULL
                GROUP BY value ORDER BY count DESC LIMIT 10";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'count' => (int) $row['count']], $stmt->fetchAll());
    }

    private function topDomains(string $where, array $params): array
    {
        $sql = "SELECT a.domain_id, COALESCE(d.name, a.domain_id) AS name, COUNT(*) AS count
                FROM audit_log a LEFT JOIN domains d ON d.id=a.domain_id {$where}
                GROUP BY a.domain_id, d.name ORDER BY count DESC LIMIT 10";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $row): array => ['domain_id' => $row['domain_id'], 'name' => $row['name'], 'count' => (int) $row['count']], $stmt->fetchAll());
    }

    private function eventAuditRows(array $filters): array
    {
        $result = $this->list(array_merge($filters, ['limit' => 500, 'offset' => 0]), false);
        return array_map(function (array $row): array {
            $security = in_array((string) ($row['type'] ?? ''), self::SECURITY_EVENTS, true);
            return [
                'id' => 'audit:' . $row['id'],
                'source' => $security ? 'security' : 'audit',
                'type' => $row['type'] ?: $row['action'],
                'severity' => $security ? 'warning' : 'info',
                'status' => $row['action'],
                'summary' => $security
                    ? sprintf('%s security decision recorded.', $row['type'])
                    : sprintf('%s changed %s%s.', $row['action'], $row['resource_type'], $row['resource_id'] ? ' ' . $row['resource_id'] : ''),
                'domain_id' => $row['domain_id'],
                'domain_name' => $row['domain_name'],
                'created_at' => (int) $row['created_at'],
                'details' => $row,
            ];
        }, $result['items']);
    }

    private function eventDnsRows(array $filters): array
    {
        [$where, $params] = $this->dnsWhere($filters);
        $stmt = Database::pdo()->prepare(
            "SELECT e.*, d.id AS domain_id, d.name AS domain_name
             FROM dns_sync_events e LEFT JOIN domains d ON d.domain=e.zone_name
             {$where} ORDER BY e.created_at DESC, e.id DESC LIMIT 500"
        );
        $stmt->execute($params);
        return array_map(fn (array $row): array => [
            'id' => 'dns:' . (string) $row['id'],
            'source' => 'dns',
            'type' => 'dns.' . (string) $row['action'],
            'severity' => ((string) $row['status'] === 'success' || (string) $row['status'] === 'verified') ? 'info' : 'warning',
            'status' => (string) $row['status'],
            'summary' => sprintf('DNS %s for %s %s.', (string) $row['action'], (string) ($row['rrset_type'] ?? 'record'), (string) ($row['rrset_name'] ?? $row['zone_name'])),
            'domain_id' => $row['domain_id'] ?? null,
            'domain_name' => $row['domain_name'] ?? $row['zone_name'],
            'created_at' => (int) $row['created_at'],
            'details' => $this->mapDnsEvent($row),
        ], $stmt->fetchAll());
    }

    private function eventJobRows(array $filters): array
    {
        $result = $this->jobs(array_merge($filters, ['limit' => 500, 'offset' => 0]));
        return array_map(static fn (array $row): array => [
            'id' => 'job:' . $row['id'],
            'source' => 'job',
            'type' => 'ssl.job',
            'severity' => in_array($row['status'], ['failed', 'cancelled'], true) ? 'critical' : (in_array($row['status'], ['issued'], true) ? 'info' : 'warning'),
            'status' => $row['status'],
            'summary' => $row['message'] ?: 'SSL job status changed.',
            'domain_id' => $row['domain_id'],
            'domain_name' => $row['domain_name'],
            'created_at' => (int) $row['updated_at'],
            'details' => $row,
        ], $result['items']);
    }

    private function dnsWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (isset($filters['domain_id']) && trim((string) $filters['domain_id']) !== '') {
            $clauses[] = 'd.id=:domain_id';
            $params[':domain_id'] = trim((string) $filters['domain_id']);
        }
        if (isset($filters['type']) && trim((string) $filters['type']) !== '') {
            $clauses[] = 'e.action=:dns_type';
            $params[':dns_type'] = str_replace('dns.', '', trim((string) $filters['type']));
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $clauses[] = '(e.zone_name ILIKE :dns_search OR e.rrset_name ILIKE :dns_search OR e.rrset_type ILIKE :dns_search OR e.action ILIKE :dns_search OR e.status ILIKE :dns_search OR e.error ILIKE :dns_search)';
            $params[':dns_search'] = '%' . trim((string) $filters['search']) . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter]) && is_numeric($filters[$filter])) {
                $clauses[] = "e.created_at {$operator} :dns_{$filter}";
                $params[':dns_' . $filter] = (int) $filters[$filter];
            }
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function jobWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        foreach (['domain_id' => 'j.domain_id', 'status' => 'j.status'] as $filter => $column) {
            if (isset($filters[$filter]) && trim((string) $filters[$filter]) !== '') {
                $clauses[] = "{$column}=:" . $filter;
                $params[':' . $filter] = trim((string) $filters[$filter]);
            }
        }
        if (!empty($filters['active'])) {
            $keys = [];
            foreach (self::ACTIVE_JOB_STATUSES as $index => $status) {
                $key = ':active_status_' . $index;
                $keys[] = $key;
                $params[$key] = $status;
            }
            $clauses[] = 'j.status IN (' . implode(',', $keys) . ')';
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $clauses[] = '(j.id ILIKE :job_search OR j.status ILIKE :job_search OR j.message ILIKE :job_search OR j.error_code ILIKE :job_search OR j.error_detail ILIKE :job_search OR j.hostnames_json ILIKE :job_search OR d.name ILIKE :job_search OR d.domain ILIKE :job_search)';
            $params[':job_search'] = '%' . trim((string) $filters['search']) . '%';
        }
        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter]) && is_numeric($filters[$filter])) {
                $clauses[] = "j.created_at {$operator} :job_{$filter}";
                $params[':job_' . $filter] = (int) $filters[$filter];
            }
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function mapJob(array $row): array
    {
        $hostnames = $this->decode($row['hostnames_json'] ?? '[]');
        return [
            'id' => (string) $row['id'],
            'domain_id' => (string) $row['domain_id'],
            'domain_name' => $row['domain_name'] ?? $row['domain_hostname'] ?? $row['domain_id'],
            'status' => (string) $row['status'],
            'progress_percent' => (int) $row['progress_percent'],
            'message' => (string) $row['message'],
            'error_code' => $row['error_code'],
            'error_detail' => $row['error_detail'],
            'hostnames' => is_array($hostnames) ? $hostnames : [],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'finished_at' => $row['finished_at'] === null ? null : (int) $row['finished_at'],
        ];
    }

    private function mapDnsEvent(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'zone_name' => (string) $row['zone_name'],
            'rrset_name' => $row['rrset_name'],
            'rrset_type' => $row['rrset_type'],
            'action' => (string) $row['action'],
            'status' => (string) $row['status'],
            'status_code' => $row['status_code'] === null ? null : (int) $row['status_code'],
            'error' => $row['error'],
            'desired_hash' => $row['desired_hash'],
            'applied_hash' => $row['applied_hash'],
            'generation_id' => $row['generation_id'] === null ? null : (int) $row['generation_id'],
            'created_at' => (int) $row['created_at'],
        ];
    }
}
