<?php

namespace App\Modules\Operations\Services;

use App\Support\Database;
use PDO;

class OperationsLogService
{
    private const SECURITY_EVENTS = ['waf_match', 'rate_limited', 'geo_block'];

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
            $clauses[] = $prefix . 'details_json ILIKE :search';
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
}
