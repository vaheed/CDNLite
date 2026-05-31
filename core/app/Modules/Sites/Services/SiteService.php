<?php

namespace App\Modules\Sites\Services;

use App\Support\Database;
use App\Support\Uuid;

class SiteService
{
    public function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM sites ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function create(array $input): array
    {
        $now = time();
        $id = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sites (id, user_id, name, domain, origin_scheme, origin_host, origin_port, geo_origins_json, proxy_enabled, status, created_at, updated_at)
             VALUES (:id, :user_id, :name, :domain, :origin_scheme, :origin_host, :origin_port, :geo_origins_json, :proxy_enabled, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => (int) ($input['user_id'] ?? 1),
            ':name' => (string) $input['name'],
            ':domain' => (string) $input['domain'],
            ':origin_scheme' => (string) ($input['origin_scheme'] ?? 'http'),
            ':origin_host' => (string) $input['origin_host'],
            ':origin_port' => (int) ($input['origin_port'] ?? 8080),
            ':geo_origins_json' => $this->encodeGeoOrigins($input['geo_origins'] ?? null),
            ':proxy_enabled' => (int) ((bool) ($input['proxy_enabled'] ?? true)),
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->find($id);
    }

    public function update(string $siteId, array $input): ?array
    {
        $existing = $this->find($siteId);
        if ($existing === null) {
            return null;
        }

        $patch = [
            'name' => $existing['name'],
            'domain' => $existing['domain'],
            'origin_scheme' => $existing['origin_scheme'],
            'origin_host' => $existing['origin_host'],
            'origin_port' => $existing['origin_port'],
            'geo_origins_json' => $this->encodeGeoOrigins($existing['geo_origins']),
            'proxy_enabled' => (int) $existing['proxy_enabled'],
            'status' => $existing['status'],
        ];

        foreach (['name', 'domain', 'origin_scheme', 'origin_host', 'status'] as $field) {
            if (isset($input[$field])) {
                $patch[$field] = (string) $input[$field];
            }
        }
        if (isset($input['origin_port'])) {
            $patch['origin_port'] = (int) $input['origin_port'];
        }
        if (array_key_exists('geo_origins', $input)) {
            $patch['geo_origins_json'] = $this->encodeGeoOrigins($input['geo_origins']);
        }
        if (isset($input['proxy_enabled'])) {
            $patch['proxy_enabled'] = (int) ((bool) $input['proxy_enabled']);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE sites SET
                name = :name,
                domain = :domain,
                origin_scheme = :origin_scheme,
                origin_host = :origin_host,
                origin_port = :origin_port,
                geo_origins_json = :geo_origins_json,
                proxy_enabled = :proxy_enabled,
                status = :status,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $siteId,
            ':name' => $patch['name'],
            ':domain' => $patch['domain'],
            ':origin_scheme' => $patch['origin_scheme'],
            ':origin_host' => $patch['origin_host'],
            ':origin_port' => $patch['origin_port'],
            ':geo_origins_json' => $patch['geo_origins_json'],
            ':proxy_enabled' => $patch['proxy_enabled'],
            ':status' => $patch['status'],
            ':updated_at' => time(),
        ]);

        return $this->find($siteId);
    }

    public function delete(string $siteId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM sites WHERE id = :id');
        $stmt->execute([':id' => $siteId]);
        return $stmt->rowCount() > 0;
    }

    public function setProxy(string $siteId, bool $enabled): ?array
    {
        return $this->update($siteId, ['proxy_enabled' => $enabled]);
    }

    public function find(string $siteId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sites WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $siteId]);
        $row = $stmt->fetch();
        return $row ? $this->castRow($row) : null;
    }

    public function findByDomain(string $domain): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sites WHERE lower(domain) = lower(:domain) LIMIT 1');
        $stmt->execute([':domain' => $domain]);
        $row = $stmt->fetch();
        return $row ? $this->castRow($row) : null;
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['origin_port'] = (int) $row['origin_port'];
        $row['geo_origins'] = $this->decodeGeoOrigins($row['geo_origins_json'] ?? null);
        unset($row['geo_origins_json']);
        $row['proxy_enabled'] = ((int) $row['proxy_enabled']) === 1;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }

    private function decodeGeoOrigins(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeGeoOrigins(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || $value === []) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
