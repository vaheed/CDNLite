<?php

namespace App\Modules\Edge\Services;

use App\Support\Database;
use App\Support\Uuid;

class EdgeService
{
    public function list(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM edge_nodes ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function register(array $input): array
    {
        $edgeId = (string) ($input['edge_id'] ?? '');
        $now = time();

        $publicIp = (string) ($input['public_ip'] ?? '');
        $publicIpv4 = (string) ($input['public_ipv4'] ?? $publicIp);
        $publicIpv6 = (string) ($input['public_ipv6'] ?? '');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO edge_nodes (
                id, edge_id, hostname, public_ip, public_ipv4, public_ipv6, region, country, continent,
                latitude, longitude, version, status, is_enabled, last_heartbeat, last_heartbeat_at,
                health_status, weight, priority, created_at, updated_at
             )
             VALUES (
                :id, :edge_id, :hostname, :public_ip, :public_ipv4, :public_ipv6, :region, :country, :continent,
                :latitude, :longitude, :version, :status, :is_enabled, :last_heartbeat, :last_heartbeat_at,
                :health_status, :weight, :priority, :created_at, :updated_at
             )
             ON CONFLICT(edge_id) DO UPDATE SET
                hostname = excluded.hostname,
                public_ip = excluded.public_ip,
                public_ipv4 = excluded.public_ipv4,
                public_ipv6 = excluded.public_ipv6,
                region = excluded.region,
                country = excluded.country,
                continent = excluded.continent,
                latitude = excluded.latitude,
                longitude = excluded.longitude,
                version = excluded.version,
                status = excluded.status,
                is_enabled = excluded.is_enabled,
                last_heartbeat = excluded.last_heartbeat,
                last_heartbeat_at = excluded.last_heartbeat_at,
                health_status = excluded.health_status,
                weight = excluded.weight,
                priority = excluded.priority,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':id' => Uuid::v4(),
            ':edge_id' => $edgeId,
            ':hostname' => (string) ($input['hostname'] ?? ''),
            ':public_ip' => $publicIp,
            ':public_ipv4' => $publicIpv4,
            ':public_ipv6' => $publicIpv6,
            ':region' => $this->normalizeRegion((string) ($input['region'] ?? 'unknown')),
            ':country' => $this->normalizeCode((string) ($input['country'] ?? '')),
            ':continent' => $this->normalizeCode((string) ($input['continent'] ?? '')),
            ':latitude' => isset($input['latitude']) ? (float) $input['latitude'] : null,
            ':longitude' => isset($input['longitude']) ? (float) $input['longitude'] : null,
            ':version' => (string) ($input['version'] ?? 'v1'),
            ':status' => 'online',
            ':is_enabled' => array_key_exists('is_enabled', $input) ? (int) ((bool) $input['is_enabled']) : 1,
            ':last_heartbeat' => $now,
            ':last_heartbeat_at' => $now,
            ':health_status' => (string) ($input['health_status'] ?? 'unknown'),
            ':weight' => (int) ($input['weight'] ?? 100),
            ':priority' => (int) ($input['priority'] ?? 100),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $stmt = Database::pdo()->prepare('SELECT * FROM edge_nodes WHERE edge_id = :edge_id LIMIT 1');
        $stmt->execute([':edge_id' => $edgeId]);
        return $this->castRow((array) $stmt->fetch());
    }

    public function heartbeat(array $input): bool
    {
        $publicIp = (string) ($input['public_ip'] ?? '');
        $publicIpv4 = (string) ($input['public_ipv4'] ?? $publicIp);
        $stmt = Database::pdo()->prepare(
            'UPDATE edge_nodes SET
                hostname = COALESCE(NULLIF(:hostname, \'\'), hostname),
                public_ip = COALESCE(NULLIF(:public_ip, \'\'), public_ip),
                public_ipv4 = COALESCE(NULLIF(:public_ipv4, \'\'), public_ipv4),
                public_ipv6 = COALESCE(NULLIF(:public_ipv6, \'\'), public_ipv6),
                region = COALESCE(NULLIF(:region, \'\'), region),
                country = COALESCE(NULLIF(:country, \'\'), country),
                continent = COALESCE(NULLIF(:continent, \'\'), continent),
                version = COALESCE(NULLIF(:version, \'\'), version),
                last_heartbeat = :last_heartbeat,
                last_heartbeat_at = :last_heartbeat_at,
                status = :status,
                health_status = COALESCE(NULLIF(:health_status, \'\'), health_status),
                updated_at = :updated_at
             WHERE edge_id = :edge_id'
        );
        $now = time();
        $stmt->execute([
            ':edge_id' => (string) ($input['edge_id'] ?? ''),
            ':hostname' => (string) ($input['hostname'] ?? ''),
            ':public_ip' => $publicIp,
            ':public_ipv4' => $publicIpv4,
            ':public_ipv6' => (string) ($input['public_ipv6'] ?? ''),
            ':region' => isset($input['region']) ? $this->normalizeRegion((string) $input['region']) : '',
            ':country' => isset($input['country']) ? $this->normalizeCode((string) $input['country']) : '',
            ':continent' => isset($input['continent']) ? $this->normalizeCode((string) $input['continent']) : '',
            ':version' => (string) ($input['version'] ?? ''),
            ':last_heartbeat' => $now,
            ':last_heartbeat_at' => $now,
            ':status' => 'online',
            ':health_status' => (string) ($input['health_status'] ?? ''),
            ':updated_at' => $now,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function registerToken(string $edgeId, string $token): void
    {
        $now = time();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO edge_tokens (edge_id, token_hash, created_at, updated_at)
             VALUES (:edge_id, :token_hash, :created_at, :updated_at)
             ON CONFLICT(edge_id) DO UPDATE SET
                token_hash = excluded.token_hash,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':edge_id' => $edgeId,
            ':token_hash' => password_hash($token, PASSWORD_BCRYPT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['is_enabled'] = ((int) ($row['is_enabled'] ?? 1)) === 1;
        $row['last_heartbeat'] = (int) $row['last_heartbeat'];
        $row['last_heartbeat_at'] = isset($row['last_heartbeat_at']) ? (int) $row['last_heartbeat_at'] : (int) $row['last_heartbeat'];
        $row['weight'] = isset($row['weight']) ? (int) $row['weight'] : 100;
        $row['priority'] = isset($row['priority']) ? (int) $row['priority'] : 100;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));
        $region = preg_replace('/[^a-z0-9-]/', '-', $region) ?? '';
        $region = trim($region, '-');
        return $region === '' ? 'unknown' : $region;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
    }
}
