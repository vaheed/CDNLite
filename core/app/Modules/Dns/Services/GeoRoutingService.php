<?php

namespace App\Modules\Dns\Services;

use App\Modules\Proxy\Services\ConfigService;
use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class GeoRoutingService
{
    public const CONTINENTS = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];
    public const COUNTRIES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
        'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
        'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
        'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    public function countries(): array
    {
        return array_map(static fn(string $code): array => [
            'country_code' => $code,
            'name' => $code,
            'node_count' => 0,
            'has_ipv4' => true,
            'has_ipv6' => true,
        ], self::COUNTRIES);
    }

    public function list(string $domainId, string $recordId): ?array
    {
        if (!$this->recordExists($domainId, $recordId)) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM dns_record_geo_routes WHERE dns_record_id = :record_id
             ORDER BY CASE route_scope WHEN \'default\' THEN 0 WHEN \'country\' THEN 1 ELSE 2 END,
                      country_code NULLS LAST, continent_code NULLS LAST, priority, id'
        );
        $stmt->execute(['record_id' => $recordId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function replace(string $domainId, string $recordId, array $routes): ?array
    {
        $record = $this->record($domainId, $recordId);
        if ($record === null) {
            return null;
        }
        if (!empty($record['proxied'])) {
            throw new \RuntimeException('proxy_and_geodns_are_mutually_exclusive');
        }
        $recordType = strtoupper((string) $record['type']);
        if (!in_array($recordType, ['A', 'AAAA'], true)) {
            throw new \RuntimeException('geodns_record_type_not_supported');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM dns_record_geo_routes WHERE dns_record_id = :record_id')
                ->execute(['record_id' => $recordId]);
            if ($routes === []) {
                $pdo->commit();
                $updated = [];
                AuditLog::write('dns.geo_routes.update', 'dns_record', $recordId, $domainId, null, $updated);
                $this->invalidateConfigSnapshot();
                (new DnsReconciler())->reconcile();
                return $updated;
            }
            if (!array_filter($routes, static fn(array $route): bool => ($route['route_scope'] ?? '') === 'default' || (empty($route['country_code']) && empty($route['continent_code'])))) {
                throw new \RuntimeException('geo_default_route_required');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO dns_record_geo_routes
                 (id, dns_record_id, route_scope, country_code, continent_code, edge_node_id, edge_pool_id, answer_type, answer_value,
                  priority, weight, enabled, created_at, updated_at)
                 VALUES (:id, :record_id, :route_scope, :country_code, :continent_code, :edge_node_id, :edge_pool_id, :answer_type,
                         :answer_value, :priority, :weight, :enabled, :created_at, :updated_at)'
            );
            foreach ($routes as $route) {
                $scope = (string) ($route['route_scope'] ?? '');
                $country = strtoupper(trim((string) ($route['country_code'] ?? '')));
                $continent = strtoupper(trim((string) ($route['continent_code'] ?? '')));
                if ($scope === '') {
                    $scope = $country !== '' ? 'country' : ($continent !== '' ? 'continent' : 'default');
                }
                if (!in_array($scope, ['default', 'country', 'continent'], true)) {
                    throw new \RuntimeException('invalid_geo_route_scope');
                }
                if ($scope === 'country' && !in_array($country, self::COUNTRIES, true)) {
                    throw new \RuntimeException('invalid_country_code');
                }
                if ($scope === 'continent' && !in_array($continent, self::CONTINENTS, true)) {
                    throw new \RuntimeException('invalid_continent_code');
                }
                $answerType = strtoupper(trim((string) ($route['answer_type'] ?? $recordType)));
                if ($answerType !== $recordType) {
                    throw new \RuntimeException('geo_answer_type_mismatch');
                }
                $answer = trim((string) ($route['answer_value'] ?? ''));
                $this->assertValidAnswer($recordType, $answer);
                $now = time();
                $stmt->execute([
                    'id' => Uuid::v4(), 'record_id' => $recordId, 'route_scope' => $scope,
                    'country_code' => $scope === 'country' ? $country : null,
                    'continent_code' => $scope === 'continent' ? $continent : null,
                    'edge_node_id' => null, 'edge_pool_id' => null,
                    'answer_type' => $recordType,
                    'answer_value' => $answer, 'priority' => (int) ($route['priority'] ?? 0),
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
        ConfigService::markDirty('dns.geo_routes.changed');
    }

    private function recordExists(string $domainId, string $recordId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM dns_records WHERE domain_id = :domain_id AND id = :record_id'
        );
        $stmt->execute(['domain_id' => $domainId, 'record_id' => $recordId]);
        return $stmt->fetchColumn() !== false;
    }

    private function record(string $domainId, string $recordId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, type, proxied FROM dns_records WHERE domain_id = :domain_id AND id = :record_id LIMIT 1'
        );
        $stmt->execute(['domain_id' => $domainId, 'record_id' => $recordId]);
        $row = $stmt->fetch();
        return $row === false ? null : (array) $row;
    }

    private function cast(array $row): array
    {
        foreach (['priority', 'weight', 'created_at', 'updated_at'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['enabled'] = ((int) $row['enabled']) === 1;
        $row['route_scope'] = (string) ($row['route_scope'] ?? ($row['country_code'] === null ? 'default' : 'country'));
        $row['is_default'] = $row['route_scope'] === 'default';
        unset($row['edge_node_id'], $row['edge_pool_id']);
        return $row;
    }

    private function assertValidAnswer(string $recordType, string $answer): void
    {
        $flag = $recordType === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if ($answer === '' || filter_var($answer, FILTER_VALIDATE_IP, $flag) === false) {
            throw new \RuntimeException($recordType === 'AAAA' ? 'invalid_geodns_ipv6_answer' : 'invalid_geodns_ipv4_answer');
        }
    }
}
