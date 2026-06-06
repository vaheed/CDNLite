<?php

namespace App\Modules\Domains\Services;

use App\Modules\Dns\Services\PowerDnsService;
use App\Modules\Settings\Repositories\SettingsRepository;
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
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO domains (id, user_id, name, domain, origin_scheme, origin_host, origin_port, origin_shield_header_name, origin_shield_header_value_hash, geo_origins_json, proxy_enabled, status, nameserver_status, verification_token, created_at, updated_at)
             VALUES (:id, :user_id, :name, :domain, :origin_scheme, :origin_host, :origin_port, :origin_shield_header_name, :origin_shield_header_value_hash, :geo_origins_json, :proxy_enabled, :status, :nameserver_status, :verification_token, :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
            ':id' => $id,
            ':user_id' => (string) ($input['user_id'] ?? Uuid::v4()),
            ':name' => (string) ($input['name'] ?? $input['domain']),
            ':domain' => (string) $input['domain'],
            ':origin_scheme' => (string) ($input['origin_scheme'] ?? 'http'),
            ':origin_host' => (string) ($input['origin_host'] ?? ''),
            ':origin_port' => (int) ($input['origin_port'] ?? 8080),
            ':origin_shield_header_name' => array_key_exists('origin_shield_header_name', $input) ? (string) $input['origin_shield_header_name'] : null,
            ':origin_shield_header_value_hash' => array_key_exists('origin_shield_header_value_hash', $input) ? (string) $input['origin_shield_header_value_hash'] : null,
            ':geo_origins_json' => $this->encodeGeoOrigins($input['geo_origins'] ?? null),
            ':proxy_enabled' => (int) ((bool) ($input['proxy_enabled'] ?? true)),
            ':status' => 'pending_nameserver',
            ':nameserver_status' => 'unknown',
            ':verification_token' => bin2hex(random_bytes(16)),
            ':created_at' => $now,
            ':updated_at' => $now,
            ]);
            $nameservers = (array) (new SettingsRepository())->value('platform.nameservers', 'hostnames');
            $insertNs = $pdo->prepare(
                'INSERT INTO domain_nameservers (id, domain_id, hostname, expected, observed, last_checked_at)
                 VALUES (:id, :domain_id, :hostname, true, false, NULL)'
            );
            foreach ($nameservers as $hostname) {
                $hostname = trim((string) $hostname);
                if ($hostname !== '') {
                    $insertNs->execute(['id' => Uuid::v4(), 'domain_id' => $id, 'hostname' => $hostname]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $domain = $this->find($id);
        if ($domain === null) {
            throw new \RuntimeException('domain_create_failed');
        }

        return $domain;
    }

    public function activate(string $domainId, bool $override = false): ?array
    {
        $domain = $this->find($domainId);
        if ($domain === null) {
            return null;
        }
        if (!$override && $domain['nameserver_status'] !== 'verified') {
            throw new \RuntimeException('nameservers_not_verified');
        }
        return $this->update($domainId, ['status' => 'active']);
    }

    public function ensureZoneReady(string $domainId): ?array
    {
        $domain = $this->find($domainId);
        if ($domain === null || !$this->powerDns->isEnabled() || $domain['powerdns_zone_created']) {
            return $domain;
        }
        $result = $this->powerDns->ensureZone((string) $domain['domain']);
        if (($result['ok'] ?? false) !== true) {
            if ($this->powerDns->isStrict()) {
                throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
            }
            return $domain;
        }
        $stmt = Database::pdo()->prepare('UPDATE domains SET powerdns_zone_created = true, updated_at = :updated_at WHERE id = :id');
        $stmt->execute(['updated_at' => time(), 'id' => $domainId]);
        return $this->find($domainId);
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
        $row['powerdns_zone_created'] = ((int) ($row['powerdns_zone_created'] ?? 0)) === 1;
        $row['last_ns_check_at'] = $row['last_ns_check_at'] === null ? null : (int) $row['last_ns_check_at'];
        $ns = Database::pdo()->prepare('SELECT hostname, expected, observed, last_checked_at FROM domain_nameservers WHERE domain_id = :domain_id ORDER BY hostname');
        $ns->execute(['domain_id' => $row['id']]);
        $row['nameservers'] = array_map(static function (array $item): array {
            $item['expected'] = ((int) $item['expected']) === 1;
            $item['observed'] = ((int) $item['observed']) === 1;
            $item['last_checked_at'] = $item['last_checked_at'] === null ? null : (int) $item['last_checked_at'];
            return $item;
        }, $ns->fetchAll());
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
