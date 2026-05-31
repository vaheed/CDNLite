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

        $stmt = Database::pdo()->prepare(
            'INSERT INTO edge_nodes (id, edge_id, hostname, public_ip, region, version, status, last_heartbeat, created_at, updated_at)
             VALUES (:id, :edge_id, :hostname, :public_ip, :region, :version, :status, :last_heartbeat, :created_at, :updated_at)
             ON CONFLICT(edge_id) DO UPDATE SET
                hostname = excluded.hostname,
                public_ip = excluded.public_ip,
                region = excluded.region,
                version = excluded.version,
                status = excluded.status,
                last_heartbeat = excluded.last_heartbeat,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':id' => Uuid::v4(),
            ':edge_id' => $edgeId,
            ':hostname' => (string) ($input['hostname'] ?? ''),
            ':public_ip' => (string) ($input['public_ip'] ?? ''),
            ':region' => (string) ($input['region'] ?? 'unknown'),
            ':version' => (string) ($input['version'] ?? 'v1'),
            ':status' => 'online',
            ':last_heartbeat' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $stmt = Database::pdo()->prepare('SELECT * FROM edge_nodes WHERE edge_id = :edge_id LIMIT 1');
        $stmt->execute([':edge_id' => $edgeId]);
        return $this->castRow((array) $stmt->fetch());
    }

    public function heartbeat(array $input): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE edge_nodes SET
                hostname = COALESCE(NULLIF(:hostname, \'\'), hostname),
                public_ip = COALESCE(NULLIF(:public_ip, \'\'), public_ip),
                region = COALESCE(NULLIF(:region, \'\'), region),
                version = COALESCE(NULLIF(:version, \'\'), version),
                last_heartbeat = :last_heartbeat,
                status = :status,
                updated_at = :updated_at
             WHERE edge_id = :edge_id'
        );
        $now = time();
        $stmt->execute([
            ':edge_id' => (string) ($input['edge_id'] ?? ''),
            ':hostname' => (string) ($input['hostname'] ?? ''),
            ':public_ip' => (string) ($input['public_ip'] ?? ''),
            ':region' => (string) ($input['region'] ?? ''),
            ':version' => (string) ($input['version'] ?? ''),
            ':last_heartbeat' => $now,
            ':status' => 'online',
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
        $row['last_heartbeat'] = (int) $row['last_heartbeat'];
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }
}
