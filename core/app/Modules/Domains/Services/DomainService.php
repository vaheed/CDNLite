<?php

namespace App\Modules\Domains\Services;

use App\Modules\Dns\Services\DnsReconciler;
use App\Modules\Dns\Services\PowerDnsService;
use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;
use App\Support\AuditLog;
use App\Support\Uuid;

class DomainService
{
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
            'INSERT INTO domains (id, user_id, name, domain, origin_shield_header_name, origin_shield_header_value_hash, status, nameserver_status, verification_token, created_at, updated_at)
             VALUES (:id, :user_id, :name, :domain, :origin_shield_header_name, :origin_shield_header_value_hash, :status, :nameserver_status, :verification_token, :created_at, :updated_at)'
        );
        try {
            $stmt->execute([
            ':id' => $id,
            ':user_id' => (string) ($input['user_id'] ?? Uuid::v4()),
            ':name' => (string) ($input['name'] ?? $input['domain']),
            ':domain' => (string) $input['domain'],
            ':origin_shield_header_name' => array_key_exists('origin_shield_header_name', $input) ? (string) $input['origin_shield_header_name'] : null,
            ':origin_shield_header_value_hash' => array_key_exists('origin_shield_header_value_hash', $input) ? (string) $input['origin_shield_header_value_hash'] : null,
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

        AuditLog::write('domain.create', 'domain', $id, $id, null, $domain);
        $this->invalidateConfigSnapshot();
        $this->reconcileDns($id);
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
        (new DnsReconciler())->reconcile();
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
            'origin_shield_header_name' => $existing['origin_shield_header_name'] ?? null,
            'origin_shield_header_value_hash' => $existing['origin_shield_header_value_hash'] ?? null,
            'status' => $existing['status'],
        ];

        foreach (['name', 'domain', 'origin_shield_header_name', 'origin_shield_header_value_hash'] as $field) {
            if (isset($input[$field])) {
                $patch[$field] = (string) $input[$field];
            }
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE domains SET
                name = :name,
                domain = :domain,
                origin_shield_header_name = :origin_shield_header_name,
                origin_shield_header_value_hash = :origin_shield_header_value_hash,
                status = :status,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $domainId,
            ':name' => $patch['name'],
            ':domain' => $patch['domain'],
            ':origin_shield_header_name' => $patch['origin_shield_header_name'],
            ':origin_shield_header_value_hash' => $patch['origin_shield_header_value_hash'],
            ':status' => $patch['status'],
            ':updated_at' => time(),
        ]);

        $updated = $this->find($domainId);
        AuditLog::write('domain.update', 'domain', $domainId, $domainId, $existing, $updated);
        $this->invalidateConfigSnapshot();
        $this->reconcileDns($domainId);
        return $updated;
    }

    public function delete(string $domainId): bool
    {
        $existing = $this->find($domainId);
        if ($existing === null) {
            return false;
        }
        AuditLog::write('domain.delete', 'domain', $domainId, $domainId, $existing, null);
        $stmt = Database::pdo()->prepare('DELETE FROM domains WHERE id = :id');
        $stmt->execute([':id' => $domainId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->invalidateConfigSnapshot();
            $this->reconcileDns($domainId);
        }
        return $deleted;
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

    private function invalidateConfigSnapshot(): void
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
    }

    private function reconcileDns(string $domainId): void
    {
        $powerDns = new PowerDnsService();
        if (!$powerDns->isEnabled()) {
            return;
        }

        // Domain mutations never publish user rrsets by themselves. Keep the
        // database write fast and let the scheduled reconciler create or prune
        // the authority-only zone; DNS record writes still enforce strict
        // PowerDNS behavior when user records are published.
        AuditLog::write('dns.reconcile.queued', 'dns', 'powerdns', $domainId, null, [
            'local_state_saved' => true,
            'strict' => $powerDns->isStrict(),
        ], 'system');
    }

}
