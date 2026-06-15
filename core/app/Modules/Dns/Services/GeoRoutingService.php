<?php

namespace App\Modules\Dns\Services;

use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class GeoRoutingService
{
    public function countries(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT CASE
                      WHEN country ~ '^[A-Za-z]{2}$' THEN upper(country)
                      WHEN lower(region) IN ('local', 'localhost') THEN 'LOCAL'
                    END AS country_code,
                    COUNT(*) AS node_count,
                    COUNT(NULLIF(public_ipv4, '')) AS ipv4_count,
                    COUNT(NULLIF(public_ipv6, '')) AS ipv6_count
             FROM edge_nodes
             WHERE is_enabled = true AND status = 'online' AND geo_enabled = true
               AND (country ~ '^[A-Za-z]{2}$' OR lower(region) IN ('local', 'localhost'))
             GROUP BY country_code ORDER BY country_code"
        );
        return array_map(static fn(array $row): array => [
            'country_code' => (string) $row['country_code'],
            'name' => $row['country_code'] === 'LOCAL' ? 'Local development' : (string) $row['country_code'],
            'node_count' => (int) $row['node_count'],
            'has_ipv4' => (int) $row['ipv4_count'] > 0,
            'has_ipv6' => (int) $row['ipv6_count'] > 0,
        ], $stmt->fetchAll());
    }

    public function list(string $domainId, string $recordId): ?array
    {
        if (!$this->recordExists($domainId, $recordId)) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM dns_record_geo_routes WHERE dns_record_id = :record_id
             ORDER BY country_code NULLS LAST, priority, id'
        );
        $stmt->execute(['record_id' => $recordId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function replace(string $domainId, string $recordId, array $routes): ?array
    {
        if (!$this->recordExists($domainId, $recordId)) {
            return null;
        }
        if (!array_filter($routes, static fn(array $route): bool => empty($route['country_code']))) {
            throw new \RuntimeException('geo_default_route_required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM dns_record_geo_routes WHERE dns_record_id = :record_id')
                ->execute(['record_id' => $recordId]);
            $stmt = $pdo->prepare(
                'INSERT INTO dns_record_geo_routes
                 (id, dns_record_id, country_code, edge_node_id, edge_pool_id, answer_type, answer_value,
                  priority, weight, enabled, created_at, updated_at)
                 VALUES (:id, :record_id, :country_code, :edge_node_id, :edge_pool_id, :answer_type,
                         :answer_value, :priority, :weight, :enabled, :created_at, :updated_at)'
            );
            foreach ($routes as $route) {
                $country = strtoupper(trim((string) ($route['country_code'] ?? '')));
                if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                    throw new \RuntimeException('invalid_country_code');
                }
                $edgeCountry = strtoupper(trim((string) ($route['edge_country_code'] ?? $route['answer_value'] ?? '')));
                if (preg_match('/^[A-Z]{2}$/', $edgeCountry) !== 1 && $edgeCountry !== 'LOCAL') {
                    throw new \RuntimeException('invalid_edge_country_code');
                }
                if (!$this->countryAvailable($edgeCountry)) {
                    throw new \RuntimeException('edge_country_unavailable');
                }
                $now = time();
                $stmt->execute([
                    'id' => Uuid::v4(), 'record_id' => $recordId, 'country_code' => $country === '' ? null : $country,
                    'edge_node_id' => null, 'edge_pool_id' => null,
                    'answer_type' => 'EDGE_PROXY',
                    'answer_value' => $edgeCountry, 'priority' => (int) ($route['priority'] ?? 0),
                    'weight' => (int) ($route['weight'] ?? 100), 'enabled' => (int) ($route['enabled'] ?? true),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $updated = $this->list($domainId, $recordId);
        AuditLog::write('dns.geo_routes.update', 'dns_record', $recordId, $domainId, null, $updated);
        $this->invalidateConfigSnapshot();
        (new DnsReconciler())->reconcile();
        return $updated;
    }

    private function invalidateConfigSnapshot(): void
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
    }

    private function recordExists(string $domainId, string $recordId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM dns_records WHERE domain_id = :domain_id AND id = :record_id'
        );
        $stmt->execute(['domain_id' => $domainId, 'record_id' => $recordId]);
        return $stmt->fetchColumn() !== false;
    }

    private function cast(array $row): array
    {
        foreach (['priority', 'weight', 'created_at', 'updated_at'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['enabled'] = ((int) $row['enabled']) === 1;
        $row['is_default'] = $row['country_code'] === null;
        $row['edge_country_code'] = (string) ($row['answer_value'] ?? '');
        unset($row['edge_node_id'], $row['edge_pool_id'], $row['answer_value']);
        return $row;
    }

    private function countryAvailable(string $country): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM edge_nodes
             WHERE (upper(country) = :country OR (:country = 'LOCAL' AND lower(region) IN ('local', 'localhost')))
               AND is_enabled = true
               AND status = 'online' AND geo_enabled = true
             LIMIT 1"
        );
        $stmt->execute(['country' => $country]);
        return $stmt->fetchColumn() !== false;
    }
}
