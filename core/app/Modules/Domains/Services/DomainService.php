<?php

namespace App\Modules\Domains\Services;

use App\Modules\Dns\Services\PowerDnsService;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Uuid;

class DomainService
{
    private PowerDnsService $powerDns;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
    }

    public function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM domains ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function create(array $input): array
    {
        $now = time();
        $id = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO domains (id, user_id, name, domain, origin_scheme, origin_host, origin_port, origin_shield_header_name, origin_shield_header_value_hash, geo_origins_json, proxy_enabled, status, created_at, updated_at)
             VALUES (:id, :user_id, :name, :domain, :origin_scheme, :origin_host, :origin_port, :origin_shield_header_name, :origin_shield_header_value_hash, :geo_origins_json, :proxy_enabled, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => (string) ($input['user_id'] ?? Uuid::v4()),
            ':name' => (string) $input['name'],
            ':domain' => (string) $input['domain'],
            ':origin_scheme' => (string) ($input['origin_scheme'] ?? 'http'),
            ':origin_host' => (string) $input['origin_host'],
            ':origin_port' => (int) ($input['origin_port'] ?? 8080),
            ':origin_shield_header_name' => array_key_exists('origin_shield_header_name', $input) ? (string) $input['origin_shield_header_name'] : null,
            ':origin_shield_header_value_hash' => array_key_exists('origin_shield_header_value_hash', $input) ? (string) $input['origin_shield_header_value_hash'] : null,
            ':geo_origins_json' => $this->encodeGeoOrigins($input['geo_origins'] ?? null),
            ':proxy_enabled' => (int) ((bool) ($input['proxy_enabled'] ?? true)),
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $domain = $this->find($id);
        if ($domain === null) {
            throw new \RuntimeException('domain_create_failed');
        }

        $this->syncPowerDnsZoneCreate($domain);
        return $domain;
    }

    public function update(string $domainId, array $input): ?array
    {
        $existing = $this->find($domainId);
        if ($existing === null) {
            return null;
        }

        $patch = [
            'name' => $existing['name'],
            'domain' => $existing['domain'],
            'origin_scheme' => $existing['origin_scheme'],
            'origin_host' => $existing['origin_host'],
            'origin_port' => $existing['origin_port'],
            'origin_shield_header_name' => $existing['origin_shield_header_name'] ?? null,
            'origin_shield_header_value_hash' => $existing['origin_shield_header_value_hash'] ?? null,
            'geo_origins_json' => $this->encodeGeoOrigins($existing['geo_origins']),
            'proxy_enabled' => (int) $existing['proxy_enabled'],
            'status' => $existing['status'],
        ];

        foreach (['name', 'domain', 'origin_scheme', 'origin_host', 'status', 'origin_shield_header_name', 'origin_shield_header_value_hash'] as $field) {
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
            'UPDATE domains SET
                name = :name,
                domain = :domain,
                origin_scheme = :origin_scheme,
                origin_host = :origin_host,
                origin_port = :origin_port,
                origin_shield_header_name = :origin_shield_header_name,
                origin_shield_header_value_hash = :origin_shield_header_value_hash,
                geo_origins_json = :geo_origins_json,
                proxy_enabled = :proxy_enabled,
                status = :status,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $domainId,
            ':name' => $patch['name'],
            ':domain' => $patch['domain'],
            ':origin_scheme' => $patch['origin_scheme'],
            ':origin_host' => $patch['origin_host'],
            ':origin_port' => $patch['origin_port'],
            ':origin_shield_header_name' => $patch['origin_shield_header_name'],
            ':origin_shield_header_value_hash' => $patch['origin_shield_header_value_hash'],
            ':geo_origins_json' => $patch['geo_origins_json'],
            ':proxy_enabled' => $patch['proxy_enabled'],
            ':status' => $patch['status'],
            ':updated_at' => time(),
        ]);

        return $this->find($domainId);
    }

    public function delete(string $domainId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM domains WHERE id = :id');
        $stmt->execute([':id' => $domainId]);
        return $stmt->rowCount() > 0;
    }

    public function setProxy(string $domainId, bool $enabled): ?array
    {
        return $this->update($domainId, ['proxy_enabled' => $enabled]);
    }

    public function find(string $domainId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domains WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $domainId]);
        $row = $stmt->fetch();
        return $row ? $this->castRow($row) : null;
    }

    public function findByDomain(string $domain): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domains WHERE lower(domain) = lower(:domain) LIMIT 1');
        $stmt->execute([':domain' => $domain]);
        $row = $stmt->fetch();
        return $row ? $this->castRow($row) : null;
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['user_id'] = (string) $row['user_id'];
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

    private function syncPowerDnsZoneCreate(array $domain): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $result = $this->powerDns->ensureZone((string) $domain['domain']);
        if (($result['ok'] ?? false) === true) {
            return;
        }

        Logger::error('powerdns_zone_create_failed', [
            'domain_id' => (string) $domain['id'],
            'domain' => (string) $domain['domain'],
            'status' => (int) ($result['status'] ?? 0),
            'error' => (string) ($result['error'] ?? 'powerdns_sync_failed'),
            'response' => (string) ($result['response'] ?? ''),
        ]);

        if ($this->powerDns->isStrict()) {
            $this->delete((string) $domain['id']);
            throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
        }
    }
}
